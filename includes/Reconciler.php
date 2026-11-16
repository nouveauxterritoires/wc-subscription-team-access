<?php
/**
 * Synchronisation idempotente : abonnement → places → sources de droits → adhésions.
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
 * Chaque synchronisation recalcule l'état cible à partir de l'état courant (et non d'un
 * événement) : rejouer un même événement, ou synchroniser plusieurs fois, ne crée ni doublon
 * ni droit supplémentaire.
 */
final class Reconciler {

	/**
	 * Constructeur.
	 *
	 * @param SeatRepository      $seats         Places.
	 * @param GrantRepository     $grants        Sources de droits.
	 * @param SubscriptionManager $subscriptions Abonnements.
	 * @param QuotaManager        $quotas        Quotas.
	 * @param MembershipManager   $memberships   Adhésions.
	 * @param Logger              $logger        Journal.
	 */
	public function __construct(
		private readonly SeatRepository $seats,
		private readonly GrantRepository $grants,
		private readonly SubscriptionManager $subscriptions,
		private readonly QuotaManager $quotas,
		private readonly MembershipManager $memberships,
		private readonly Logger $logger
	) {}

	/**
	 * Synchronise un abonnement et les adhésions de ses invités.
	 *
	 * @param int    $subscription_id Abonnement.
	 * @param string $trigger         Origine (pour le journal).
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

		if ( $result instanceof \WP_Error && 'wcsta_busy' === $result->get_error_code() ) {
			Scheduler::enqueue_subscription( $subscription_id );
		}

		return $result;
	}

	/**
	 * Synchronise un couple utilisateur / plan.
	 *
	 * @param int $user_id Utilisateur.
	 * @param int $plan_id Plan.
	 */
	public function sync_user_plan( int $user_id, int $plan_id ): string {
		return $this->memberships->reconcile( $user_id, $plan_id );
	}

	/**
	 * Calcule la répartition courante des places d'un abonnement (lecture seule).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param Seat[]|null      $live_seats   Places vivantes (lues si null).
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
	 * Abonnements ayant des places (pour le rattrapage par lots).
	 *
	 * @param int $after_id Dernier identifiant traité.
	 * @param int $limit    Taille du lot.
	 * @return int[]
	 */
	public function subscription_ids( int $after_id, int $limit ): array {
		return $this->seats->subscription_ids( $after_id, $limit );
	}

	/** Maintenance quotidienne : expirations, minimisation des données, purge du journal. */
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
	 * Adhésions gérées sans source de droits (événement manqué, place supprimée à la main…).
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
	 * Synchronisation sous verrou.
	 *
	 * @param int $subscription_id Abonnement.
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
			$done[ $key ] = $this->memberships->reconcile( $user_id, $plan_id );
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
	 * Calcule et enregistre l'accès d'une place et ses sources de droits.
	 *
	 * @param Seat        $seat     Place.
	 * @param AccessState $access   Droit conféré par l'abonnement.
	 * @param int[]       $plan_ids Plans partagés.
	 * @param bool        $within   Place dans le quota.
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
	 * État cible d'une place.
	 *
	 * @param Seat        $seat     Place.
	 * @param AccessState $access   Droit de l'abonnement.
	 * @param int[]       $plan_ids Plans.
	 * @param bool        $within   Dans le quota.
	 * @return array{0:string, 1:string, 2:AccessState} Accès affiché, raison, droit à accorder.
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
	 * Le titulaire de l'abonnement a pu changer (réattribution par un administrateur).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param Seat[]           $seats        Places.
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
	 * Abonnement supprimé définitivement : toutes les places vivantes prennent fin.
	 *
	 * @param int    $subscription_id Abonnement.
	 * @param string $reason          Motif.
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
