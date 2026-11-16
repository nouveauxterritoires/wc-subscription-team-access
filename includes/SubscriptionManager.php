<?php
/**
 * Reads subscriptions through the public APIs of WooCommerce Subscriptions and Memberships.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\AccessState;
use WCSubscriptionTeamAccess\Domain\SubscriptionAccessResolver;
use WCSubscriptionTeamAccess\Repository\SeatRepository;

/**
 * Effective rights, granted plans and ownership of a subscription.
 */
final class SubscriptionManager {

	public function __construct(
		private readonly QuotaManager $quotas,
		private readonly SeatRepository $seats
	) {}

	/**
	 * Loads a subscription (HPOS compatible).
	 *
	 * @param int $subscription_id Identifier.
	 */
	public function get( int $subscription_id ): ?\WC_Subscription {
		if ( $subscription_id <= 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		return $subscription instanceof \WC_Subscription ? $subscription : null;
	}

	/**
	 * Effective right granted by the subscription at the present time.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function effective_access( \WC_Subscription $subscription ): AccessState {
		$state = SubscriptionAccessResolver::resolve(
			(string) $subscription->get_status(),
			(int) $subscription->get_time( 'end' ),
			time()
		);

		/**
		 * Filters the effective right of a subscription.
		 *
		 * @param AccessState      $state        Computed right.
		 * @param \WC_Subscription $subscription Subscription.
		 */
		$filtered = apply_filters( 'wcsta_subscription_effective_access', $state, $subscription );
		return $filtered instanceof AccessState ? $filtered : $state;
	}

	/**
	 * WooCommerce Memberships plans actually granted by the subscription.
	 *
	 * Sources: the holder's memberships linked to the subscription by Memberships, and the plans
	 * whose access products match the subscription's line items (the holder may have
	 * their membership linked to another one of their subscriptions).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @return int[]
	 */
	public function plan_ids( \WC_Subscription $subscription ): array {
		$plan_ids = array();

		$integration = $this->memberships_subscriptions_integration();
		if ( $integration && method_exists( $integration, 'get_memberships_from_subscription' ) ) {
			foreach ( (array) $integration->get_memberships_from_subscription( $subscription->get_id() ) as $membership ) {
				if ( $membership instanceof \WC_Memberships_User_Membership ) {
					$plan_ids[] = (int) $membership->get_plan_id();
				}
			}
		}

		$product_ids = array();
		foreach ( $subscription->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$product_ids[] = (int) $item->get_product_id();
				$product_ids[] = (int) $item->get_variation_id();
			}
		}
		$product_ids = array_values( array_filter( array_unique( $product_ids ) ) );

		if ( $product_ids && function_exists( 'wc_memberships_get_membership_plans' ) ) {
			foreach ( wc_memberships_get_membership_plans() as $plan ) {
				if ( 'purchase' !== $plan->get_access_method() ) {
					continue;
				}
				foreach ( $product_ids as $product_id ) {
					if ( $plan->has_product( $product_id ) ) {
						$plan_ids[] = (int) $plan->get_id();
						break;
					}
				}
			}
		}

		$plan_ids = array_values(
			array_filter(
				array_unique( array_map( 'intval', $plan_ids ) ),
				static fn( int $plan_id ): bool => $plan_id > 0 && (bool) wc_memberships_get_membership_plan( $plan_id )
			)
		);
		sort( $plan_ids );

		/**
		 * Filters the plans shared with the guests of a subscription.
		 *
		 * @param int[]            $plan_ids     Plans.
		 * @param \WC_Subscription $subscription Subscription.
		 */
		return array_values( array_map( 'intval', (array) apply_filters( 'wcsta_subscription_plan_ids', $plan_ids, $subscription ) ) );
	}

	/**
	 * The user can manage the subscription's guests (holder or shop manager).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $user_id      User.
	 */
	public function can_manage( \WC_Subscription $subscription, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return (int) $subscription->get_customer_id() === $user_id || user_can( $user_id, 'manage_woocommerce' );
	}

	/**
	 * The user is the holder.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $user_id      User.
	 */
	public function is_owner( \WC_Subscription $subscription, int $user_id ): bool {
		return $user_id > 0 && (int) $subscription->get_customer_id() === $user_id;
	}

	/**
	 * Subscriptions of a user involved in sharing (quota > 0 or existing guests).
	 *
	 * @param int $user_id Holder.
	 * @return \WC_Subscription[]
	 */
	public function team_subscriptions_for( int $user_id ): array {
		if ( $user_id <= 0 || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return array();
		}

		$with_seats = array();
		foreach ( $this->seats->for_owner( $user_id ) as $seat ) {
			$with_seats[ $seat->subscription_id ] = true;
		}

		$result = array();
		foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription ) {
				continue;
			}
			if ( isset( $with_seats[ $subscription->get_id() ] ) || $this->quotas->quota( $subscription ) > 0 ) {
				$result[] = $subscription;
			}
		}
		return $result;
	}

	/**
	 * Readable name of the subscription (products).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function display_name( \WC_Subscription $subscription ): string {
		$names = array();
		foreach ( $subscription->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		$names = array_filter( $names );

		return $names
			? implode( ' + ', $names )
			/* translators: %s: subscription number. */
			: sprintf( __( 'Subscription #%s', 'wc-subscription-team-access' ), $subscription->get_order_number() );
	}

	/**
	 * Translated status label.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function status_label( \WC_Subscription $subscription ): string {
		return function_exists( 'wcs_get_subscription_status_name' )
			? (string) wcs_get_subscription_status_name( $subscription->get_status() )
			: (string) $subscription->get_status();
	}

	/** Memberships' Subscriptions integration, if available. */
	private function memberships_subscriptions_integration(): ?object {
		if ( ! function_exists( 'wc_memberships' ) ) {
			return null;
		}
		$integrations = wc_memberships()->get_integrations_instance();
		if ( ! $integrations || ! method_exists( $integrations, 'get_subscriptions_instance' ) ) {
			return null;
		}
		$instance = $integrations->get_subscriptions_instance();
		return is_object( $instance ) ? $instance : null;
	}
}
