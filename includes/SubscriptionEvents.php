<?php
/**
 * Écoute les événements publics de WooCommerce Subscriptions et déclenche la synchronisation.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Repository\SeatRepository;

/**
 * Transitions gérées : active, on-hold, pending-cancel, cancelled, expired, trash, suppression,
 * changement de dates, renouvellement réussi ou échoué (via le statut) et changement de formule.
 * Chaque événement relance une synchronisation complète et idempotente de l'abonnement.
 */
final class SubscriptionEvents {

	/**
	 * Constructeur.
	 *
	 * @param Reconciler          $reconciler    Synchronisation.
	 * @param QuotaManager        $quotas        Quotas.
	 * @param SeatRepository      $seats         Places.
	 * @param SubscriptionManager $subscriptions Abonnements.
	 */
	public function __construct(
		private readonly Reconciler $reconciler,
		private readonly QuotaManager $quotas,
		private readonly SeatRepository $seats,
		private readonly SubscriptionManager $subscriptions
	) {}

	/** Branche les crochets. */
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
	 * Changement de statut.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param string           $new_status   Nouveau statut.
	 * @param string           $old_status   Ancien statut.
	 */
	public function on_status_updated( $subscription, $new_status, $old_status ): void {
		unset( $new_status, $old_status );
		if ( $subscription instanceof \WC_Subscription && $this->is_concerned( $subscription->get_id() ) ) {
			$this->reconciler->sync_subscription( $subscription->get_id(), 'status_updated' );
		}
	}

	/**
	 * Changement de la date de fin (fin de période payée, prolongation…).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param string           $date_type    Type de date.
	 */
	public function on_date_changed( $subscription, $date_type ): void {
		if ( $subscription instanceof \WC_Subscription && 'end' === $date_type && $this->is_concerned( $subscription->get_id() ) ) {
			$this->reconciler->sync_subscription( $subscription->get_id(), 'end_date_changed' );
		}
	}

	/**
	 * Changement de formule terminé : nouvel instantané du quota, puis réévaluation des plans.
	 *
	 * @param \WC_Order $order Commande de changement de formule.
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
	 * Mise à la corbeille, restauration ou suppression d'un abonnement.
	 *
	 * @param int $post_id Identifiant.
	 */
	public function on_post_changed( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id > 0 && $this->is_concerned( $post_id ) && $this->is_subscription_id( $post_id ) ) {
			$this->reconciler->sync_subscription( $post_id, 'trash_or_delete' );
		}
	}

	/**
	 * L'abonnement a (ou a eu) des invités.
	 *
	 * @param int $subscription_id Abonnement.
	 */
	private function is_concerned( int $subscription_id ): bool {
		return array() !== $this->seats->for_subscription( $subscription_id );
	}

	/**
	 * L'identifiant est (ou était) celui d'un abonnement.
	 *
	 * @param int $id Identifiant.
	 */
	private function is_subscription_id( int $id ): bool {
		// Une fois supprimé, l'abonnement n'est plus lisible : la présence de places suffit.
		$subscription = $this->subscriptions->get( $id );
		return null === $subscription || $subscription instanceof \WC_Subscription;
	}
}
