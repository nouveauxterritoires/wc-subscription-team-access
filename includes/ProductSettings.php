<?php
/**
 * Champ « Nombre maximal d'invités » sur les produits d'abonnement simples et variables.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\QuotaCalculator;

/**
 * Réglage du quota au niveau produit et variation.
 */
final class ProductSettings {

	public const META = '_wcsta_max_guests';

	/** Branche les crochets d'administration. */
	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_field' ), 20, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save_variation_field' ), 10, 2 );
	}

	/** Champ du produit (abonnement simple, ou valeur par défaut des variations). */
	public function render_product_field(): void {
		echo '<div class="options_group show_if_subscription show_if_variable-subscription">';
		woocommerce_wp_text_input(
			array(
				'id'                => self::META,
				'label'             => __( 'Maximum number of guests', 'wc-subscription-team-access' ),
				'description'       => __( 'Number of people the buyer can invite, in addition to themselves, to share the premium access granted by this subscription. 0 disables the feature. For variable subscriptions, this is the default value for variations that leave their own field empty.', 'wc-subscription-team-access' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'placeholder'       => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		echo '</div>';
	}

	/**
	 * Enregistre le champ produit.
	 *
	 * @param \WC_Product $product Produit.
	 */
	public function save_product_field( \WC_Product $product ): void {
		// La vérification du nonce est faite par WooCommerce avant ce crochet.
		$raw = isset( $_POST[ self::META ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->store( $product, $raw );
	}

	/**
	 * Champ de variation.
	 *
	 * @param int      $loop           Index.
	 * @param array    $variation_data Données.
	 * @param \WP_Post $variation      Variation.
	 */
	public function render_variation_field( $loop, $variation_data, $variation ): void {
		$product = wc_get_product( $variation->ID );
		$value   = $product ? (string) $product->get_meta( self::META ) : '';

		woocommerce_wp_text_input(
			array(
				'id'                => self::META . '_' . $loop,
				'name'              => self::META . '[' . $loop . ']',
				'value'             => $value,
				'label'             => __( 'Maximum number of guests', 'wc-subscription-team-access' ),
				'description'       => __( 'Leave empty to use the product value.', 'wc-subscription-team-access' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'wrapper_class'     => 'form-row form-row-full show_if_variable-subscription',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
	}

	/**
	 * Enregistre le champ de variation.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param int                   $index     Index.
	 */
	public function save_variation_field( \WC_Product_Variation $variation, int $index ): void {
		// Nonce vérifié par WooCommerce (save_variations).
		$values = isset( $_POST[ self::META ] ) && is_array( $_POST[ self::META ] ) ? wp_unslash( $_POST[ self::META ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw    = isset( $values[ $index ] ) ? sanitize_text_field( (string) $values[ $index ] ) : '';
		$this->store( $variation, $raw );
	}

	/**
	 * Quota propre d'un produit (null = non renseigné).
	 *
	 * @param \WC_Product $product Produit.
	 */
	public static function own_quota( \WC_Product $product ): ?int {
		return QuotaCalculator::parse( $product->get_meta( self::META ) );
	}

	/**
	 * Stocke la valeur (chaîne vide = non renseigné).
	 *
	 * @param \WC_Product $product Produit.
	 * @param string      $raw     Valeur saisie.
	 */
	private function store( \WC_Product $product, string $raw ): void {
		$value = QuotaCalculator::parse( $raw );
		if ( null === $value ) {
			$product->delete_meta_data( self::META );
			return;
		}
		$product->update_meta_data( self::META, (string) $value );
	}
}
