<?php
/**
 * E-mail au titulaire : invitation acceptée (texte brut).
 *
 * Surcharge : votre-theme/woocommerce/emails/plain/wcsta-team-invitation-accepted.php
 *
 * @package WCSubscriptionTeamAccess
 *
 * @var string   $email_heading      Titre.
 * @var string   $additional_content Contenu additionnel.
 * @var string   $guest_email        Adresse de l'invité.
 * @var string   $subscription_name  Nom de l'abonnement.
 * @var string   $manage_url         Gestion des invités.
 * @var WC_Email $email              E-mail.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

esc_html_e( 'Hello,', 'wc-subscription-team-access' );
echo "\n\n";

echo esc_html(
	sprintf(
		/* translators: 1: guest email, 2: subscription name. */
		__( '%1$s accepted your invitation and now shares the premium access of your subscription %2$s.', 'wc-subscription-team-access' ),
		$guest_email,
		$subscription_name
	)
);
echo "\n\n";

esc_html_e( 'Manage my guests', 'wc-subscription-team-access' );
echo ': ' . esc_url_raw( $manage_url ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
