<?php
/**
 * Management of guests' WooCommerce Memberships memberships, through the public APIs.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\AccessAggregator;
use WCSubscriptionTeamAccess\Domain\AccessState;
use WCSubscriptionTeamAccess\Repository\GrantRepository;
use WCSubscriptionTeamAccess\Repository\Logger;
use WCSubscriptionTeamAccess\Support\Lock;

/**
 * Assignment model (see README, "Rights model"):
 *
 * - Memberships only knows one "logical" membership per (user, plan): the most recent one
 *   (wc_memberships_get_user_membership / is_user_active_member); a later purchase reuses it.
 * - The plugin therefore creates at most ONE "managed" membership per (guest, plan), flagged by the
 *   _wcsta_managed meta, with no linked order or subscription. Its state is the aggregate of all
 *   rights sources (accepted seats) stored in the wcsta_grants table.
 * - An independent membership (purchased, granted by an administrator...) is never modified.
 *   If it is active, the managed membership is deleted: it must never hide an older personal
 *   membership.
 * - If Memberships reuses the managed membership for a purchase by the guest (linked order or
 *   subscription), it becomes independent: the plugin "releases" it and no longer touches it.
 */
final class MembershipManager {

	public const META_MANAGED  = '_wcsta_managed';
	public const META_SOURCES  = '_wcsta_sources';
	public const META_RELEASED = '_wcsta_released_at';

	public const RESULT_NONE      = 'none';
	public const RESULT_CREATED   = 'created';
	public const RESULT_UPDATED   = 'updated';
	public const RESULT_UNCHANGED = 'unchanged';
	public const RESULT_DELETED   = 'deleted';
	public const RESULT_COVERED   = 'covered';
	public const RESULT_SKIPPED   = 'skipped';
	public const RESULT_ERROR     = 'error';

	/** Native Memberships emails that must not be sent for a managed membership. */
	private const EMAIL_IDS = array(
		'WC_Memberships_User_Membership_Activated_Email',
		'WC_Memberships_User_Membership_Ended_Email',
		'WC_Memberships_User_Membership_Ending_Soon_Email',
		'WC_Memberships_User_Membership_Renewal_Reminder_Email',
	);

	/** Depth of operations in progress (events we trigger ourselves are ignored). */
	private static int $applying = 0;

	/** Managed membership creation in progress (the meta is not set yet). */
	private static bool $creating = false;

	/**
	 * Constructor.
	 *
	 * @param GrantRepository $grants Rights sources.
	 * @param Logger          $logger Logger.
	 */
	public function __construct(
		private readonly GrantRepository $grants,
		private readonly Logger $logger
	) {}

	/** Registers the Memberships hooks. */
	public function register(): void {
		foreach ( self::EMAIL_IDS as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( $this, 'filter_email_enabled' ), 99, 2 );
		}
		add_filter( 'wc_memberships_members_area_my-memberships_actions', array( $this, 'filter_member_actions' ), 99, 2 );
		add_filter( 'wc_memberships_members_area_my-membership-details_actions', array( $this, 'filter_member_actions' ), 99, 2 );

		add_action( 'wc_memberships_grant_membership_access_from_purchase', array( $this, 'on_granted_from_purchase' ), 5, 2 );
		add_action( 'wc_memberships_user_membership_created', array( $this, 'on_membership_created' ), 20, 2 );
		add_action( 'wc_memberships_user_membership_status_changed', array( $this, 'on_status_changed' ), 20, 3 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 2 );
	}

	/**
	 * Reconciles a guest's managed membership with the aggregate of their rights sources.
	 * Idempotent: a second call with no change in sources modifies nothing.
	 *
	 * @param int $user_id Guest.
	 * @param int $plan_id Plan.
	 * @return string Result (RESULT_* constants).
	 */
	public function reconcile( int $user_id, int $plan_id ): string {
		if ( ! function_exists( 'wc_memberships_get_membership_plan' ) || $user_id <= 0 || $plan_id <= 0 ) {
			return self::RESULT_SKIPPED;
		}

		$result = Lock::run(
			"user_plan_{$user_id}_{$plan_id}",
			function () use ( $user_id, $plan_id ): string {
				++self::$applying;
				try {
					return $this->apply( $user_id, $plan_id );
				} catch ( \Throwable $e ) {
					$this->logger->error(
						'membership_sync_failed',
						$e->getMessage(),
						array(
							'user_id' => $user_id,
							'plan_id' => $plan_id,
						)
					);
					return self::RESULT_ERROR;
				} finally {
					--self::$applying;
				}
			}
		);

		if ( $result instanceof \WP_Error ) {
			$this->logger->warning(
				'membership_sync_busy',
				'Membership sync postponed: lock busy.',
				array(
					'user_id' => $user_id,
					'plan_id' => $plan_id,
				)
			);
			Scheduler::enqueue_user_plan( $user_id, $plan_id );
			return self::RESULT_ERROR;
		}

		return $result;
	}

	/**
	 * Memberships of a user for a plan, from most recent to oldest
	 * (same order as Memberships).
	 *
	 * @param int $user_id User.
	 * @param int $plan_id Plan.
	 * @return \WC_Memberships_User_Membership[]
	 */
	public function memberships_for( int $user_id, int $plan_id ): array {
		$posts = get_posts(
			array(
				'author'           => $user_id,
				'post_type'        => 'wc_user_membership',
				'post_parent'      => $plan_id,
				'post_status'      => array_keys( wc_memberships_get_user_membership_statuses() ),
				'numberposts'      => -1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);

		$memberships = array();
		foreach ( $posts as $post ) {
			$membership = wc_memberships_get_user_membership( $post );
			if ( $membership instanceof \WC_Memberships_User_Membership ) {
				$memberships[] = $membership;
			}
		}
		return $memberships;
	}

	/**
	 * Managed membership of a guest for a plan, if there is one.
	 *
	 * @param int $user_id User.
	 * @param int $plan_id Plan.
	 */
	public function managed_membership( int $user_id, int $plan_id ): ?\WC_Memberships_User_Membership {
		foreach ( $this->memberships_for( $user_id, $plan_id ) as $membership ) {
			if ( $this->is_managed( $membership ) ) {
				return $membership;
			}
		}
		return null;
	}

	/**
	 * The membership is managed by the plugin.
	 *
	 * @param \WC_Memberships_User_Membership $membership Membership.
	 */
	public function is_managed( \WC_Memberships_User_Membership $membership ): bool {
		return '1' === (string) get_post_meta( $membership->get_id(), self::META_MANAGED, true );
	}

	/**
	 * Filter: disables the native Memberships emails for managed memberships.
	 *
	 * @param bool  $enabled Whether the email is enabled.
	 * @param mixed $object  Email object.
	 */
	public function filter_email_enabled( $enabled, $object = null ) {
		if ( ! $enabled || 'yes' !== Settings::get( 'suppress_membership_emails' ) ) {
			return $enabled;
		}
		if ( self::$creating ) {
			return false;
		}
		if ( $object instanceof \WC_Memberships_User_Membership && $this->is_managed( $object ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Filter: removes "Cancel" and "Renew" from the member area for a managed membership.
	 *
	 * @param array<string, mixed>            $actions    Actions.
	 * @param \WC_Memberships_User_Membership $membership Membership.
	 * @return array<string, mixed>
	 */
	public function filter_member_actions( $actions, $membership ) {
		if ( is_array( $actions ) && $membership instanceof \WC_Memberships_User_Membership && $this->is_managed( $membership ) ) {
			unset( $actions['cancel'], $actions['renew'] );
		}
		return $actions;
	}

	/**
	 * A purchase by the guest reused the managed membership: it becomes personal.
	 *
	 * @param \WC_Memberships_Membership_Plan $plan Plan.
	 * @param array<string, mixed>            $args Arguments (user_membership_id...).
	 */
	public function on_granted_from_purchase( $plan, $args ): void {
		$membership_id = (int) ( $args['user_membership_id'] ?? 0 );
		if ( $membership_id <= 0 ) {
			return;
		}
		$membership = wc_memberships_get_user_membership( $membership_id );
		if ( $membership instanceof \WC_Memberships_User_Membership && $this->is_managed( $membership ) ) {
			$this->release( $membership );
		}
	}

	/**
	 * An independent membership has just been created: re-evaluate immediately.
	 *
	 * @param \WC_Memberships_Membership_Plan $plan Plan.
	 * @param array<string, mixed>            $args Arguments (user_id, user_membership_id...).
	 */
	public function on_membership_created( $plan, $args ): void {
		if ( self::$applying > 0 || ! $plan instanceof \WC_Memberships_Membership_Plan ) {
			return;
		}
		$user_id = (int) ( $args['user_id'] ?? 0 );
		if ( $user_id > 0 && $this->grants->for_user_plan( $user_id, (int) $plan->get_id() ) ) {
			$this->reconcile( $user_id, (int) $plan->get_id() );
		}
	}

	/**
	 * Status change of a membership made by a third party.
	 *
	 * - independent membership: immediate re-evaluation (it may now cover the guest,
	 *   or no longer cover them);
	 * - managed membership edited by hand: the plugin is authoritative, and the re-evaluation is
	 *   scheduled as a background task (avoids any loop).
	 *
	 * @param \WC_Memberships_User_Membership $membership Membership.
	 * @param string                          $old_status Old status.
	 * @param string                          $new_status New status.
	 */
	public function on_status_changed( $membership, $old_status, $new_status ): void {
		unset( $old_status, $new_status );
		if ( self::$applying > 0 || ! $membership instanceof \WC_Memberships_User_Membership ) {
			return;
		}

		$user_id = (int) $membership->get_user_id();
		$plan_id = (int) $membership->get_plan_id();

		if ( $this->is_managed( $membership ) ) {
			Scheduler::enqueue_user_plan( $user_id, $plan_id );
			return;
		}
		if ( $this->grants->for_user_plan( $user_id, $plan_id ) ) {
			$this->reconcile( $user_id, $plan_id );
		}
	}

	/**
	 * Deletion of a membership by a third party: background re-evaluation.
	 *
	 * @param int           $post_id Identifier.
	 * @param \WP_Post|null $post    Deleted post.
	 */
	public function on_deleted_post( $post_id, $post = null ): void {
		if ( self::$applying > 0 || ! $post instanceof \WP_Post || 'wc_user_membership' !== $post->post_type ) {
			return;
		}
		if ( $this->grants->for_user_plan( (int) $post->post_author, (int) $post->post_parent ) ) {
			Scheduler::enqueue_user_plan( (int) $post->post_author, (int) $post->post_parent );
		}
	}

	/**
	 * Core of the reconciliation (called under lock).
	 *
	 * @param int $user_id Guest.
	 * @param int $plan_id Plan.
	 */
	private function apply( int $user_id, int $plan_id ): string {
		if ( ! wc_memberships_get_membership_plan( $plan_id ) || ! get_userdata( $user_id ) ) {
			return self::RESULT_SKIPPED;
		}

		$sources = $this->grants->for_user_plan( $user_id, $plan_id );
		$desired = AccessAggregator::aggregate( array_column( $sources, 'state' ) );

		$managed     = array();
		$independent = array();
		foreach ( $this->memberships_for( $user_id, $plan_id ) as $membership ) {
			if ( $this->is_managed( $membership ) && $this->is_claimed_by_purchase( $membership ) ) {
				$this->release( $membership );
			}
			if ( $this->is_managed( $membership ) ) {
				$managed[] = $membership;
			} else {
				$independent[] = $membership;
			}
		}

		$covered = false;
		foreach ( $independent as $membership ) {
			if ( $membership->is_active() ) {
				$covered = true;
				break;
			}
		}

		if ( $desired->is_none() || $covered ) {
			foreach ( $managed as $membership ) {
				$this->delete( $membership, $covered ? 'covered_by_independent_membership' : 'no_active_source' );
			}
			if ( $covered && ! $desired->is_none() ) {
				return self::RESULT_COVERED;
			}
			return $managed ? self::RESULT_DELETED : self::RESULT_NONE;
		}

		// Possible duplicates: keep the most recent one.
		$membership = array_shift( $managed );
		foreach ( $managed as $duplicate ) {
			$this->delete( $duplicate, 'duplicate' );
		}

		// A more recent (inactive) independent membership would hide ours: recreate it.
		if ( $membership && $this->is_shadowed( $membership, $user_id, $plan_id ) ) {
			$this->delete( $membership, 'shadowed' );
			$membership = null;
		}

		$result = self::RESULT_UNCHANGED;
		if ( ! $membership ) {
			$membership = $this->create( $user_id, $plan_id );
			$result     = self::RESULT_CREATED;
		}

		$changed = $this->apply_state( $membership, $desired );
		$this->update_sources( $membership, $sources );

		if ( self::RESULT_CREATED === $result ) {
			return $result;
		}
		return $changed ? self::RESULT_UPDATED : self::RESULT_UNCHANGED;
	}

	/**
	 * Creates the managed membership.
	 *
	 * @param int $user_id Guest.
	 * @param int $plan_id Plan.
	 * @throws \RuntimeException If Memberships refuses the creation.
	 */
	private function create( int $user_id, int $plan_id ): \WC_Memberships_User_Membership {
		// Memberships picks the membership with the most recent date: to the second, two
		// memberships would be tie-broken arbitrarily. Ours is dated strictly later.
		$latest = 0;
		foreach ( $this->memberships_for( $user_id, $plan_id ) as $existing ) {
			$latest = max( $latest, (int) strtotime( (string) get_post_field( 'post_date_gmt', $existing->get_id() ) . ' UTC' ) );
		}
		$created_at = max( time(), $latest + 1 );
		$set_date   = static function ( array $data ) use ( $created_at ): array {
			$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $created_at );
			$data['post_date']     = get_date_from_gmt( $data['post_date_gmt'] );
			return $data;
		};

		self::$creating = true;
		add_filter( 'wc_memberships_new_membership_data', $set_date, 999 );
		try {
			$membership = wc_memberships_create_user_membership(
				array(
					'plan_id' => $plan_id,
					'user_id' => $user_id,
				),
				'create'
			);
		} finally {
			remove_filter( 'wc_memberships_new_membership_data', $set_date, 999 );
			self::$creating = false;
		}

		if ( ! $membership instanceof \WC_Memberships_User_Membership ) {
			throw new \RuntimeException( 'WooCommerce Memberships did not return a user membership.' );
		}

		update_post_meta( $membership->get_id(), self::META_MANAGED, '1' );
		$membership->add_note( __( 'Guest membership created by WC Subscription Team Access: access is shared by a subscription holder.', 'wc-subscription-team-access' ) );

		$this->logger->info(
			'membership_created',
			sprintf( 'Guest membership #%d created.', $membership->get_id() ),
			array(
				'user_id' => $user_id,
				'plan_id' => $plan_id,
			)
		);

		return $membership;
	}

	/**
	 * Aligns the status and end date of the managed membership with the desired state.
	 *
	 * @param \WC_Memberships_User_Membership $membership Managed membership.
	 * @param AccessState                     $desired    Desired state (active or paused).
	 * @return bool Whether a change was made.
	 */
	private function apply_state( \WC_Memberships_User_Membership $membership, AccessState $desired ): bool {
		$changed = false;

		$current_end = $membership->get_end_date( 'timestamp', false );
		$current_end = $current_end ? (int) $current_end : null;

		// The end date is set before activation: a past date would make the membership expire.
		if ( $desired->is_active() && $current_end !== $desired->end_ts ) {
			$membership->set_end_date( null === $desired->end_ts ? '' : gmdate( 'Y-m-d H:i:s', $desired->end_ts ) );
			$changed = true;
		}

		$status = (string) $membership->get_status();

		if ( $desired->is_active() && 'active' !== $status ) {
			$membership->activate_membership( __( 'Guest access activated: the sharing subscription grants access.', 'wc-subscription-team-access' ) );
			$changed = true;
		} elseif ( $desired->is_paused() && 'paused' !== $status ) {
			$membership->pause_membership( __( 'Guest access suspended: the sharing subscription is on hold or the seat is over quota.', 'wc-subscription-team-access' ) );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Deletes a managed membership.
	 *
	 * @param \WC_Memberships_User_Membership $membership Membership.
	 * @param string                          $reason     Reason.
	 */
	private function delete( \WC_Memberships_User_Membership $membership, string $reason ): void {
		$id      = $membership->get_id();
		$user_id = (int) $membership->get_user_id();
		$plan_id = (int) $membership->get_plan_id();

		// Cancellation goes through the native status transition: Memberships recalculates the
		// member's role there (the "assign roles to members" option), taking their other active
		// memberships into account. A direct deletion would leave the member role in place.
		if ( ! in_array( (string) $membership->get_status(), array( 'cancelled', 'expired' ), true ) ) {
			$membership->cancel_membership( __( 'Guest access ended: no active sharing subscription for this member.', 'wc-subscription-team-access' ) );
		}

		wp_delete_post( $id, true );

		$this->logger->info(
			'membership_deleted',
			sprintf( 'Guest membership #%d deleted (%s).', $id, $reason ),
			array(
				'user_id' => $user_id,
				'plan_id' => $plan_id,
			)
		);
	}

	/**
	 * Releases a managed membership reused by a purchase: it becomes independent.
	 *
	 * @param \WC_Memberships_User_Membership $membership Membership.
	 */
	private function release( \WC_Memberships_User_Membership $membership ): void {
		delete_post_meta( $membership->get_id(), self::META_MANAGED );
		delete_post_meta( $membership->get_id(), self::META_SOURCES );
		update_post_meta( $membership->get_id(), self::META_RELEASED, (string) time() );

		++self::$applying;
		try {
			$membership->add_note( __( 'This membership is now linked to the member\'s own purchase and is no longer managed by WC Subscription Team Access.', 'wc-subscription-team-access' ) );
		} finally {
			--self::$applying;
		}

		$this->logger->info(
			'membership_released',
			sprintf( 'Guest membership #%d released to the member\'s own purchase.', $membership->get_id() ),
			array(
				'user_id' => (int) $membership->get_user_id(),
				'plan_id' => (int) $membership->get_plan_id(),
			)
		);
	}

	/**
	 * The managed membership has been taken over by a purchase (linked order, product or subscription).
	 *
	 * @param \WC_Memberships_User_Membership $membership Membership.
	 */
	private function is_claimed_by_purchase( \WC_Memberships_User_Membership $membership ): bool {
		if ( (int) $membership->get_order_id() > 0 || (int) $membership->get_product_id() > 0 ) {
			return true;
		}
		return function_exists( 'wc_memberships_is_user_membership_linked_to_subscription' )
			&& wc_memberships_is_user_membership_linked_to_subscription( $membership );
	}

	/**
	 * Another membership is more recent than the managed one: Memberships would not see ours.
	 *
	 * @param \WC_Memberships_User_Membership $membership Managed membership.
	 * @param int                             $user_id    User.
	 * @param int                             $plan_id    Plan.
	 */
	private function is_shadowed( \WC_Memberships_User_Membership $membership, int $user_id, int $plan_id ): bool {
		$newest = get_posts(
			array(
				'author'           => $user_id,
				'post_type'        => 'wc_user_membership',
				'post_parent'      => $plan_id,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		return ! empty( $newest ) && (int) $newest[0] !== $membership->get_id();
	}

	/**
	 * Stores the source subscriptions on the membership (administrator display).
	 *
	 * @param \WC_Memberships_User_Membership                                  $membership Membership.
	 * @param array<int, array{seat_id:int, subscription_id:int, state:AccessState}> $sources    Sources.
	 */
	private function update_sources( \WC_Memberships_User_Membership $membership, array $sources ): void {
		$subscription_ids = array_values( array_unique( array_map( 'intval', array_column( $sources, 'subscription_id' ) ) ) );
		sort( $subscription_ids );
		if ( get_post_meta( $membership->get_id(), self::META_SOURCES, true ) !== $subscription_ids ) {
			update_post_meta( $membership->get_id(), self::META_SOURCES, $subscription_ids );
		}
	}
}
