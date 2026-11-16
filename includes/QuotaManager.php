<?php
/**
 * Snapshot of the guest quota on the subscription, and controlled updates.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\QuotaCalculator;
use WCSubscriptionTeamAccess\Repository\Logger;

/**
 * The applicable quota is frozen in the subscription's metadata at purchase time, so that later
 * catalog changes do not affect existing subscriptions. It is only recomputed on a plan
 * switch, or overridden by an administrator.
 */
final class QuotaManager {

	public const META_QUOTA       = '_wcsta_quota';
	public const META_OVERRIDE    = '_wcsta_quota_override';
	public const META_SNAPSHOT_AT = '_wcsta_quota_snapshot_at';
	public const META_HISTORY     = '_wcsta_quota_history';

	private const HISTORY_MAX = 20;

	public function __construct( private readonly Logger $logger ) {}

	/** Registers the WooCommerce Subscriptions hooks. */
	public function register(): void {
		add_action( 'woocommerce_checkout_subscription_created', array( $this, 'on_checkout_subscription_created' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'on_status_updated' ), 5, 3 );
	}

	/**
	 * Snapshot when the subscription is created at payment.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function on_checkout_subscription_created( $subscription ): void {
		if ( $subscription instanceof \WC_Subscription ) {
			$this->snapshot( $subscription, 'checkout' );
		}
	}

	/**
	 * Snapshot on first activation (subscriptions created outside the checkout flow).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $new_status   New status.
	 * @param string           $old_status   Old status.
	 */
	public function on_status_updated( $subscription, $new_status, $old_status ): void {
		unset( $old_status );
		if ( $subscription instanceof \WC_Subscription && 'active' === $new_status && ! $this->has_snapshot( $subscription ) ) {
			$this->snapshot( $subscription, 'activation' );
		}
	}

	/**
	 * Applicable quota (administrator override > snapshot > frozen immediate computation).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function quota( \WC_Subscription $subscription ): int {
		$override = $this->override( $subscription );
		if ( null !== $override ) {
			return $override;
		}
		if ( $this->has_snapshot( $subscription ) ) {
			return (int) $subscription->get_meta( self::META_QUOTA );
		}
		return $this->snapshot( $subscription, 'lazy' );
	}

	/**
	 * Snapshot quota, without override.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function snapshot_value( \WC_Subscription $subscription ): ?int {
		return $this->has_snapshot( $subscription ) ? (int) $subscription->get_meta( self::META_QUOTA ) : null;
	}

	/**
	 * Administrator override (null = none).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function override( \WC_Subscription $subscription ): ?int {
		return QuotaCalculator::parse( $subscription->get_meta( self::META_OVERRIDE ) );
	}

	/**
	 * Sets or removes the administrator override.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int|null         $value        Enforced quota, or null to revert to the snapshot.
	 * @param int              $actor_id     Administrator.
	 */
	public function set_override( \WC_Subscription $subscription, ?int $value, int $actor_id ): void {
		$before = $this->quota( $subscription );
		if ( null === $value ) {
			$subscription->delete_meta_data( self::META_OVERRIDE );
		} else {
			$subscription->update_meta_data( self::META_OVERRIDE, (string) max( 0, $value ) );
		}
		$subscription->save();

		$after = $this->quota( $subscription );
		$this->record_history( $subscription, $before, $after, 'admin_override', $actor_id );
	}

	/**
	 * Recomputes the snapshot from the subscription's current line items.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $reason       Reason (checkout, activation, switch, lazy, admin).
	 * @return int New snapshot quota.
	 */
	public function snapshot( \WC_Subscription $subscription, string $reason ): int {
		$before = $this->snapshot_value( $subscription );
		$value  = $this->compute( $subscription );

		$subscription->update_meta_data( self::META_QUOTA, (string) $value );
		$subscription->update_meta_data( self::META_SNAPSHOT_AT, (string) time() );
		$subscription->save_meta_data();

		if ( null === $before || $before !== $value ) {
			$this->record_history( $subscription, $before, $value, $reason, get_current_user_id() );
		}

		return $value;
	}

	/**
	 * Computes the quota from the subscription's line items.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function compute( \WC_Subscription $subscription ): int {
		$lines = array();

		foreach ( $subscription->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$variation_id = (int) $item->get_variation_id();
			$product_id   = (int) $item->get_product_id();
			$own          = null;
			$parent       = null;

			if ( $variation_id > 0 ) {
				$variation = wc_get_product( $variation_id );
				$own       = $variation ? ProductSettings::own_quota( $variation ) : null;
				$product   = wc_get_product( $product_id );
				$parent    = $product ? ProductSettings::own_quota( $product ) : null;
			} else {
				$product = wc_get_product( $product_id );
				$own     = $product ? ProductSettings::own_quota( $product ) : null;
			}

			$lines[] = array(
				'quantity' => (int) $item->get_quantity(),
				'own'      => $own,
				'parent'   => $parent,
			);
		}

		/**
		 * Filters the computed quota for a subscription.
		 *
		 * @param int              $quota        Computed quota.
		 * @param \WC_Subscription $subscription Subscription.
		 */
		return max( 0, (int) apply_filters( 'wcsta_computed_quota', QuotaCalculator::total( $lines ), $subscription ) );
	}

	/**
	 * History of quota changes.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @return array<int, array<string, mixed>>
	 */
	public function history( \WC_Subscription $subscription ): array {
		$history = $subscription->get_meta( self::META_HISTORY );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * A snapshot exists.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private function has_snapshot( \WC_Subscription $subscription ): bool {
		return '' !== (string) $subscription->get_meta( self::META_QUOTA );
	}

	/**
	 * Logs a quota change.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int|null         $before       Before.
	 * @param int              $after        After.
	 * @param string           $reason       Reason.
	 * @param int              $actor_id     Actor.
	 */
	private function record_history( \WC_Subscription $subscription, ?int $before, int $after, string $reason, int $actor_id ): void {
		$history   = $this->history( $subscription );
		$history[] = array(
			'at'     => time(),
			'before' => $before,
			'after'  => $after,
			'reason' => $reason,
			'actor'  => $actor_id,
		);
		$subscription->update_meta_data( self::META_HISTORY, array_slice( $history, -self::HISTORY_MAX ) );
		$subscription->save_meta_data();

		$this->logger->info(
			'quota_changed',
			sprintf( 'Guest quota changed from %s to %d (%s).', null === $before ? 'none' : (string) $before, $after, $reason ),
			array(
				'subscription_id' => $subscription->get_id(),
				'user_id'         => $actor_id,
			)
		);
	}
}
