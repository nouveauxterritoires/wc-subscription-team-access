<?php
/**
 * Email to the plan holder: invitation accepted (HTML).
 *
 * Override: your-theme/woocommerce/emails/exprime-team-invitation-accepted.php
 *
 * @package ExprimeTeamAccess
 *
 * @var string   $email_heading      Heading.
 * @var string   $additional_content Additional content.
 * @var string   $guest_email        Guest email address.
 * @var string   $subscription_name  Subscription name.
 * @var string   $manage_url         Guest management URL.
 * @var WC_Email $email              Email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php esc_html_e( 'Hello,', 'exprime-team-access' ); ?></p>
<p>
	<?php
	echo wp_kses(
		sprintf(
			/* translators: 1: guest email, 2: subscription name. */
			__( '<strong>%1$s</strong> accepted your invitation and now shares the premium access of your subscription <strong>%2$s</strong>.', 'exprime-team-access' ),
			esc_html( $guest_email ),
			esc_html( $subscription_name )
		),
		array( 'strong' => array() )
	);
	?>
</p>
<p><a href="<?php echo esc_url( $manage_url ); ?>"><?php esc_html_e( 'Manage my guests', 'exprime-team-access' ); ?></a></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
