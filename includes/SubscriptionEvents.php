<?php
/**
 * Listens to the public WooCommerce Subscriptions events and triggers synchronization.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Repository\SeatRepository;

/**
 * Handled transitions: active, on-hold, pending-cancel, cancelled, expired, trash, deletion,
 * date changes, successful or failed renewal (via the status) and plan switch.
 * Each event triggers a full, idempotent synchronization of the subscription.
 */
final class SubscriptionEvents {

	/**
	 * Constructor.
	 *
	 * @param Reconciler          $reconciler    Synchronization.
	 * @param QuotaManager        $quotas        Quotas.
	 * @param SeatRepository      $seats         Seats.
	 * @param SubscriptionManager $subscriptions Subscriptions.
	 */
	public function __construct(
		private readonly Reconciler $reconciler,
		private readonly QuotaManager $quotas,
		private readonly SeatRepository $seats,
		private readonly SubscriptionManager $subscriptions
	) {}

	/** Registers the hooks. */
	public function register(): void {
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'on_status_updated' ), 20, 3 );
		add_action( 'woocommerce_subscription_date_updated', array( $this, 'on_date_changed' ), 20, 2 );
		add_action( 'woocommerce_subscription_date_deleted', array( $this, 'on_date_changed' ), 20, 2 );
		add_action( 'woocommerce_subscriptions_switch_completed', array( $this, 'on_switch_completed' ), 20 );
		add_action( 'trashed_post', array( $this, 'on_post_changed' ) );
		add_action( 'untrashed_post', array( $this, 'on_post_changed' ) );
		add_action( 'deleted_post', array( $this, 'on_post_changed' ) );
		add_action( 'woocommerce_trash_subscription', array( $this, 'on_post_changed' ) );
		add_action( 'woocommerce_untrash_subscription', array( $this, 'on_post_changed' ) );
		add_action( 'woocommerce_delete_subscription', array( $this, 'on_post_changed' ) );
	}

	/**
	 * Status change.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $new_status   New status.
	 * @param string           $old_status   Old status.
	 */
	public function on_status_updated( $subscription, $new_status, $old_status ): void {
		unset( $new_status, $old_status );
		if ( $subscription instanceof \WC_Subscription && $this->is_concerned( $subscription->get_id() ) ) {
			$this->reconciler->sync_subscription( $subscription->get_id(), 'status_updated' );
		}
	}

	/**
	 * End date change (end of paid period, extension...).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $date_type    Date type.
	 */
	public function on_date_changed( $subscription, $date_type ): void {
		if ( $subscription instanceof \WC_Subscription && 'end' === $date_type && $this->is_concerned( $subscription->get_id() ) ) {
			$this->reconciler->sync_subscription( $subscription->get_id(), 'end_date_changed' );
		}
	}

	/**
	 * Plan switch completed: new quota snapshot, then re-evaluation of the plans.
	 *
	 * @param \WC_Order $order Plan switch order.
	 */
	public function on_switch_completed( $order ): void {
		if ( ! function_exists( 'wcs_get_subscriptions_for_switch_order' ) ) {
			return;
		}
		foreach ( wcs_get_subscriptions_for_switch_order( $order ) as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription ) {
				continue;
			}
			$this->quotas->snapshot( $subscription, 'switch' );
			$this->reconciler->sync_subscription( $subscription->get_id(), 'switch' );
		}
	}

	/**
	 * Trashing, restoring or deleting a subscription.
	 *
	 * @param int $post_id Identifier.
	 */
	public function on_post_changed( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id > 0 && $this->is_concerned( $post_id ) && $this->is_subscription_id( $post_id ) ) {
			$this->reconciler->sync_subscription( $post_id, 'trash_or_delete' );
		}
	}

	/**
	 * The subscription has (or has had) guests.
	 *
	 * @param int $subscription_id Subscription.
	 */
	private function is_concerned( int $subscription_id ): bool {
		return array() !== $this->seats->for_subscription( $subscription_id );
	}

	/**
	 * The identifier is (or was) that of a subscription.
	 *
	 * @param int $id Identifier.
	 */
	private function is_subscription_id( int $id ): bool {
		// Once deleted, the subscription can no longer be read: the presence of seats is enough.
		$subscription = $this->subscriptions->get( $id );
		return null === $subscription || $subscription instanceof \WC_Subscription;
	}
}
