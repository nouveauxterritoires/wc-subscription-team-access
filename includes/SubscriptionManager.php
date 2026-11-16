<?php
/**
 * Lecture des abonnements via les API publiques de WooCommerce Subscriptions et Memberships.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\AccessState;
use WCSubscriptionTeamAccess\Domain\SubscriptionAccessResolver;
use WCSubscriptionTeamAccess\Repository\SeatRepository;

/**
 * Droits effectifs, plans accordés et propriété d'un abonnement.
 */
final class SubscriptionManager {

	/**
	 * Constructeur.
	 *
	 * @param QuotaManager   $quotas Quotas.
	 * @param SeatRepository $seats  Places.
	 */
	public function __construct(
		private readonly QuotaManager $quotas,
		private readonly SeatRepository $seats
	) {}

	/**
	 * Charge un abonnement (compatible HPOS).
	 *
	 * @param int $subscription_id Identifiant.
	 */
	public function get( int $subscription_id ): ?\WC_Subscription {
		if ( $subscription_id <= 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		return $subscription instanceof \WC_Subscription ? $subscription : null;
	}

	/**
	 * Droit effectif conféré par l'abonnement à l'instant présent.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	public function effective_access( \WC_Subscription $subscription ): AccessState {
		$state = SubscriptionAccessResolver::resolve(
			(string) $subscription->get_status(),
			(int) $subscription->get_time( 'end' ),
			time()
		);

		/**
		 * Filtre le droit effectif d'un abonnement.
		 *
		 * @param AccessState      $state        Droit calculé.
		 * @param \WC_Subscription $subscription Abonnement.
		 */
		$filtered = apply_filters( 'wcsta_subscription_effective_access', $state, $subscription );
		return $filtered instanceof AccessState ? $filtered : $state;
	}

	/**
	 * Plans WooCommerce Memberships effectivement accordés par l'abonnement.
	 *
	 * Sources : les adhésions du titulaire liées à l'abonnement par Memberships, et les plans
	 * dont les produits d'accès correspondent aux lignes de l'abonnement (le titulaire peut avoir
	 * son adhésion liée à un autre de ses abonnements).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
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
		 * Filtre les plans partagés avec les invités d'un abonnement.
		 *
		 * @param int[]            $plan_ids     Plans.
		 * @param \WC_Subscription $subscription Abonnement.
		 */
		return array_values( array_map( 'intval', (array) apply_filters( 'wcsta_subscription_plan_ids', $plan_ids, $subscription ) ) );
	}

	/**
	 * L'utilisateur peut gérer les invités de l'abonnement (titulaire ou gestionnaire de la boutique).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param int              $user_id      Utilisateur.
	 */
	public function can_manage( \WC_Subscription $subscription, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return (int) $subscription->get_customer_id() === $user_id || user_can( $user_id, 'manage_woocommerce' );
	}

	/**
	 * L'utilisateur est le titulaire.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param int              $user_id      Utilisateur.
	 */
	public function is_owner( \WC_Subscription $subscription, int $user_id ): bool {
		return $user_id > 0 && (int) $subscription->get_customer_id() === $user_id;
	}

	/**
	 * Abonnements d'un utilisateur concernés par le partage (quota > 0 ou invités existants).
	 *
	 * @param int $user_id Titulaire.
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
	 * Nom lisible de l'abonnement (produits).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
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
	 * Libellé traduit du statut.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	public function status_label( \WC_Subscription $subscription ): string {
		return function_exists( 'wcs_get_subscription_status_name' )
			? (string) wcs_get_subscription_status_name( $subscription->get_status() )
			: (string) $subscription->get_status();
	}

	/** Intégration Subscriptions de Memberships, si disponible. */
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
