<?php
/**
 * Idempotent synchronization: subscription -> seats -> rights sources -> memberships.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\AccessState;
use WCSubscriptionTeamAccess\Domain\SeatAllocator;
use WCSubscriptionTeamAccess\Domain\SeatStatus;
use WCSubscriptionTeamAccess\Repository\GrantRepository;
use WCSubscriptionTeamAccess\Repository\Logger;
use WCSubscriptionTeamAccess\Repository\Seat;
use WCSubscriptionTeamAccess\Repository\SeatRepository;
use WCSubscriptionTeamAccess\Support\Dates;
use WCSubscriptionTeamAccess\Support\Lock;

/**
 * Each synchronization recomputes the target state from the current state (not from an
 * event): replaying the same event, or synchronizing several times, creates neither duplicates
 * nor extra rights.
 */
final class Reconciler {

	/** Triggers that are already retries: no new queuing on failure. */
	private const RETRY_TRIGGERS = array( 'async', 'catch_up' );

	public function __construct(
		private readonly SeatRepository $seats,
		private readonly GrantRepository $grants,
		private readonly SubscriptionManager $subscriptions,
		private readonly QuotaManager $quotas,
		private readonly MembershipManager $memberships,
		private readonly Logger $logger
	) {}

	/**
	 * Synchronizes a subscription and its guests' memberships.
	 *
	 * @param int    $subscription_id Subscription.
	 * @param string $trigger         Origin (for the log).
	 * @return array{subscription_id:int, access:string, quota:int, used:int, over:int, plans:int[], memberships:array<string,string>}|\WP_Error
	 */
	public function sync_subscription( int $subscription_id, string $trigger = 'manual' ): array|\WP_Error {
		$result = Lock::run(
			"subscription_{$subscription_id}",
			function () use ( $subscription_id, $trigger ) {
				try {
					return $this->sync_locked( $subscription_id );
				} catch ( \Throwable $e ) {
					$this->logger->error(
						'subscription_sync_failed',
						$e->getMessage(),
						array(
							'subscription_id' => $subscription_id,
							'trigger'         => $trigger,
						)
					);
					return new \WP_Error( 'wcsta_sync_failed', $e->getMessage() );
				}
			}
		);

		// Any failed synchronization is replayed as a background task (only once per
		// trigger: a persistent failure is then picked up by the daily catch-up).
		if ( $result instanceof \WP_Error && ! in_array( $trigger, self::RETRY_TRIGGERS, true ) ) {
			Scheduler::enqueue_subscription( $subscription_id );
		}

		return $result;
	}

	/**
	 * Synchronizes a user / plan pair.
	 *
	 * @param int $user_id User.
	 * @param int $plan_id Plan.
	 */
	public function sync_user_plan( int $user_id, int $plan_id ): string {
		return $this->memberships->reconcile( $user_id, $plan_id );
	}

	/**
	 * Computes the current seat distribution of a subscription (read-only).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param Seat[]|null      $live_seats   Live seats (read if null).
	 */
	public function allocation( \WC_Subscription $subscription, ?array $live_seats = null ): \WCSubscriptionTeamAccess\Domain\Allocation {
		$live_seats = $live_seats ?? $this->seats->live_for_subscription( $subscription->get_id() );
		return SeatAllocator::allocate(
			array_map( static fn( Seat $seat ): array => $seat->to_allocation_row(), $live_seats ),
			$this->quotas->quota( $subscription ),
			(string) Settings::get( 'overage_policy' )
		);
	}

	/**
	 * Subscriptions that have seats (for batched catch-up).
	 *
	 * @param int $after_id Last processed identifier.
	 * @param int $limit    Batch size.
	 * @return int[]
	 */
	public function subscription_ids( int $after_id, int $limit ): array {
		return $this->seats->subscription_ids( $after_id, $limit );
	}

	/** Daily maintenance: expirations, data minimization, log purge. */
	public function maintenance(): void {
		$expired = $this->seats->expire_stale();
		if ( $expired > 0 ) {
			$this->logger->info( 'invitations_expired', sprintf( '%d pending invitation(s) expired.', $expired ) );
		}

		$days = (int) Settings::get( 'anonymize_after_days' );
		if ( $days > 0 ) {
			$this->seats->anonymize_ended_before( time() - $days * DAY_IN_SECONDS );
		}

		$this->logger->prune( 180 );
	}

	/**
	 * Managed memberships without a rights source (missed event, seat deleted by hand...).
	 */
	public function sweep_orphan_memberships(): void {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_author AS user_id, p.post_parent AS plan_id FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = '1'
				WHERE p.post_type = 'wc_user_membership'",
				MembershipManager::META_MANAGED
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$this->memberships->reconcile( (int) $row['user_id'], (int) $row['plan_id'] );
		}
	}

	/**
	 * Synchronization under lock.
	 *
	 * @param int $subscription_id Subscription.
	 * @return array{subscription_id:int, access:string, quota:int, used:int, over:int, plans:int[], memberships:array<string,string>}
	 */
	private function sync_locked( int $subscription_id ): array {
		$subscription = $this->subscriptions->get( $subscription_id );

		$this->seats->expire_stale( $subscription_id );

		$pairs = $this->grants->pairs_for_subscription( $subscription_id );

		if ( ! $subscription ) {
			$this->end_all_seats( $subscription_id, 'subscription_deleted' );
			$access   = AccessState::none();
			$plan_ids = array();
			$quota    = 0;
			$seats    = $this->seats->for_subscription( $subscription_id );
		} else {
			$access   = $this->subscriptions->effective_access( $subscription );
			$plan_ids = $this->subscriptions->plan_ids( $subscription );
			$quota    = $this->quotas->quota( $subscription );
			$seats    = $this->seats->for_subscription( $subscription_id );
			$this->sync_owner( $subscription, $seats );
		}

		$live       = array_values( array_filter( $seats, static fn( Seat $seat ): bool => $seat->is_live() ) );
		$allocation = SeatAllocator::allocate(
			array_map( static fn( Seat $seat ): array => $seat->to_allocation_row(), $live ),
			$quota,
			(string) Settings::get( 'overage_policy' )
		);

		foreach ( $seats as $seat ) {
			$this->sync_seat( $seat, $access, $plan_ids, $allocation->is_within( $seat->id ) );
		}

		$pairs = array_merge( $pairs, $this->grants->pairs_for_subscription( $subscription_id ) );
		$done  = array();
		foreach ( $pairs as [ $user_id, $plan_id ] ) {
			$key = $user_id . ':' . $plan_id;
			if ( isset( $done[ $key ] ) ) {
				continue;
			}
			// Each pair is isolated: a failure does not prevent processing the other guests,
			// and it is replayed as a background task.
			$done[ $key ] = $this->memberships->reconcile( $user_id, $plan_id );
			if ( MembershipManager::RESULT_ERROR === $done[ $key ] ) {
				Scheduler::enqueue_user_plan( $user_id, $plan_id );
			}
		}

		return array(
			'subscription_id' => $subscription_id,
			'access'          => $access->state,
			'quota'           => $quota,
			'used'            => $allocation->used,
			'over'            => $allocation->overage(),
			'plans'           => $plan_ids,
			'memberships'     => $done,
		);
	}

	/**
	 * Computes and stores a seat's access and its rights sources.
	 *
	 * @param Seat        $seat     Seat.
	 * @param AccessState $access   Right granted by the subscription.
	 * @param int[]       $plan_ids Shared plans.
	 * @param bool        $within   Seat within the quota.
	 */
	private function sync_seat( Seat $seat, AccessState $access, array $plan_ids, bool $within ): void {
		[ $seat_access, $reason, $grant ] = $this->seat_target( $seat, $access, $plan_ids, $within );

		if ( $seat->access !== $seat_access || $seat->access_reason !== $reason ) {
			$this->seats->update(
				$seat->id,
				array(
					'access'        => $seat_access,
					'access_reason' => $reason,
				)
			);
		}

		if ( $grant->is_none() || ! $plan_ids ) {
			$this->grants->delete_for_seat( $seat->id );
			return;
		}

		$this->grants->delete_for_seat( $seat->id, $plan_ids );
		foreach ( $plan_ids as $plan_id ) {
			$this->grants->upsert( $seat->id, $seat->subscription_id, $seat->user_id, $plan_id, $grant );
		}
	}

	/**
	 * Target state of a seat.
	 *
	 * @param Seat        $seat     Seat.
	 * @param AccessState $access   Subscription right.
	 * @param int[]       $plan_ids Plans.
	 * @param bool        $within   Within the quota.
	 * @return array{0:string, 1:string, 2:AccessState} Displayed access, reason, right to grant.
	 */
	private function seat_target( Seat $seat, AccessState $access, array $plan_ids, bool $within ): array {
		if ( ! $seat->is_accepted() || $seat->user_id <= 0 ) {
			return array( SeatStatus::ACCESS_NONE, SeatStatus::REASON_NONE, AccessState::none() );
		}
		if ( $access->is_none() ) {
			return array( SeatStatus::ACCESS_NONE, SeatStatus::REASON_SUBSCRIPTION_INACTIVE, AccessState::none() );
		}
		if ( ! $plan_ids ) {
			return array( SeatStatus::ACCESS_NONE, SeatStatus::REASON_NO_PLAN, AccessState::none() );
		}
		if ( ! $within ) {
			return array( SeatStatus::ACCESS_SUSPENDED, SeatStatus::REASON_OVER_QUOTA, AccessState::paused() );
		}
		if ( $access->is_paused() ) {
			return array( SeatStatus::ACCESS_SUSPENDED, SeatStatus::REASON_SUBSCRIPTION_PAUSED, AccessState::paused() );
		}
		return array( SeatStatus::ACCESS_ACTIVE, SeatStatus::REASON_NONE, $access );
	}

	/**
	 * The subscription holder may have changed (reassignment by an administrator).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param Seat[]           $seats        Seats.
	 */
	private function sync_owner( \WC_Subscription $subscription, array $seats ): void {
		$owner_id = (int) $subscription->get_customer_id();
		foreach ( $seats as $seat ) {
			if ( $seat->owner_id !== $owner_id ) {
				$this->seats->update( $seat->id, array( 'owner_id' => $owner_id ) );
			}
		}
	}

	/**
	 * Subscription permanently deleted: all live seats end.
	 *
	 * @param int    $subscription_id Subscription.
	 * @param string $reason          Reason.
	 */
	private function end_all_seats( int $subscription_id, string $reason ): void {
		foreach ( $this->seats->live_for_subscription( $subscription_id ) as $seat ) {
			$this->seats->update(
				$seat->id,
				array(
					'status'        => $seat->is_pending() ? SeatStatus::CANCELLED : SeatStatus::REVOKED,
					'live'          => null,
					'token_hash'    => null,
					'access'        => SeatStatus::ACCESS_NONE,
					'access_reason' => SeatStatus::REASON_SUBSCRIPTION_INACTIVE,
					'ended_at'      => Dates::now(),
				)
			);
			$this->logger->info(
				'seat_ended',
				sprintf( 'Seat ended (%s).', $reason ),
				array(
					'subscription_id' => $subscription_id,
					'seat_id'         => $seat->id,
				)
			);
		}
	}
}
