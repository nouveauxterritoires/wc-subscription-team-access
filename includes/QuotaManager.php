<?php
/**
 * Instantané du quota d'invités sur l'abonnement et mise à jour contrôlée.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\QuotaCalculator;
use WCSubscriptionTeamAccess\Repository\Logger;

/**
 * Le quota applicable est figé dans les métadonnées de l'abonnement à l'achat, pour que les
 * modifications ultérieures du catalogue n'affectent pas les abonnements existants. Il n'est
 * recalculé que lors d'un changement de formule (switch), ou remplacé par un administrateur.
 */
final class QuotaManager {

	public const META_QUOTA       = '_wcsta_quota';
	public const META_OVERRIDE    = '_wcsta_quota_override';
	public const META_SNAPSHOT_AT = '_wcsta_quota_snapshot_at';
	public const META_HISTORY     = '_wcsta_quota_history';

	private const HISTORY_MAX = 20;

	/**
	 * Constructeur.
	 *
	 * @param Logger $logger Journal.
	 */
	public function __construct( private readonly Logger $logger ) {}

	/** Branche les crochets WooCommerce Subscriptions. */
	public function register(): void {
		add_action( 'woocommerce_checkout_subscription_created', array( $this, 'on_checkout_subscription_created' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'on_status_updated' ), 5, 3 );
	}

	/**
	 * Instantané à la création de l'abonnement au paiement.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	public function on_checkout_subscription_created( $subscription ): void {
		if ( $subscription instanceof \WC_Subscription ) {
			$this->snapshot( $subscription, 'checkout' );
		}
	}

	/**
	 * Instantané à la première activation (abonnements créés hors tunnel de commande).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param string           $new_status   Nouveau statut.
	 * @param string           $old_status   Ancien statut.
	 */
	public function on_status_updated( $subscription, $new_status, $old_status ): void {
		unset( $old_status );
		if ( $subscription instanceof \WC_Subscription && 'active' === $new_status && ! $this->has_snapshot( $subscription ) ) {
			$this->snapshot( $subscription, 'activation' );
		}
	}

	/**
	 * Quota applicable (remplacement administrateur > instantané > calcul immédiat figé).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
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
	 * Quota de l'instantané, sans remplacement.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	public function snapshot_value( \WC_Subscription $subscription ): ?int {
		return $this->has_snapshot( $subscription ) ? (int) $subscription->get_meta( self::META_QUOTA ) : null;
	}

	/**
	 * Remplacement administrateur (null = aucun).
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	public function override( \WC_Subscription $subscription ): ?int {
		return QuotaCalculator::parse( $subscription->get_meta( self::META_OVERRIDE ) );
	}

	/**
	 * Définit ou retire le remplacement administrateur.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param int|null         $value        Quota imposé, ou null pour revenir à l'instantané.
	 * @param int              $actor_id     Administrateur.
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
	 * Recalcule l'instantané depuis les lignes actuelles de l'abonnement.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param string           $reason       Motif (checkout, activation, switch, lazy, admin).
	 * @return int Nouveau quota de l'instantané.
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
	 * Calcule le quota depuis les lignes de l'abonnement.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
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
		 * Filtre le quota calculé pour un abonnement.
		 *
		 * @param int              $quota        Quota calculé.
		 * @param \WC_Subscription $subscription Abonnement.
		 */
		return max( 0, (int) apply_filters( 'wcsta_computed_quota', QuotaCalculator::total( $lines ), $subscription ) );
	}

	/**
	 * Historique des changements de quota.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @return array<int, array<string, mixed>>
	 */
	public function history( \WC_Subscription $subscription ): array {
		$history = $subscription->get_meta( self::META_HISTORY );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Un instantané existe.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 */
	private function has_snapshot( \WC_Subscription $subscription ): bool {
		return '' !== (string) $subscription->get_meta( self::META_QUOTA );
	}

	/**
	 * Journalise un changement de quota.
	 *
	 * @param \WC_Subscription $subscription Abonnement.
	 * @param int|null         $before       Avant.
	 * @param int              $after        Après.
	 * @param string           $reason       Motif.
	 * @param int              $actor_id     Auteur.
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
