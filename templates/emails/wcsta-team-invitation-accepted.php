<?php
/**
 * E-mail au titulaire : invitation acceptée (HTML).
 *
 * Surcharge : votre-theme/woocommerce/emails/wcsta-team-invitation-accepted.php
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php esc_html_e( 'Hello,', 'wc-subscription-team-access' ); ?></p>
<p>
	<?php
	echo wp_kses(
		sprintf(
			/* translators: 1: guest email, 2: subscription name. */
			__( '<strong>%1$s</strong> accepted your invitation and now shares the premium access of your subscription <strong>%2$s</strong>.', 'wc-subscription-team-access' ),
			esc_html( $guest_email ),
			esc_html( $subscription_name )
		),
		array( 'strong' => array() )
	);
	?>
</p>
<p><a href="<?php echo esc_url( $manage_url ); ?>"><?php esc_html_e( 'Manage my guests', 'wc-subscription-team-access' ); ?></a></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
