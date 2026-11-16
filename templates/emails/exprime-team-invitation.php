<?php
/**
 * Invitation email (HTML).
 *
 * Override: your-theme/woocommerce/emails/exprime-team-invitation.php
 *
 * @package ExprimeTeamAccess
 *
 * @var string   $email_heading      Heading.
 * @var string   $additional_content Additional content.
 * @var string   $accept_url         Acceptance link.
 * @var string   $inviter_name       Plan holder name.
 * @var string   $subscription_name  Subscription name.
 * @var int      $expires_at         Expiration timestamp.
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
			/* translators: 1: holder name, 2: subscription name, 3: site name. */
			__( '<strong>%1$s</strong> invites you to share the premium access of their subscription <strong>%2$s</strong> on %3$s.', 'exprime-team-access' ),
			esc_html( $inviter_name ),
			esc_html( $subscription_name ),
			esc_html( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) )
		),
		array( 'strong' => array() )
	);
	?>
</p>
<p><?php esc_html_e( 'To accept, follow the link below. You will be able to log in to your account or create one with this email address. No password is sent by email.', 'exprime-team-access' ); ?></p>
<p style="margin: 24px 0;">
	<a href="<?php echo esc_url( $accept_url ); ?>" style="display:inline-block;padding:12px 20px;border-radius:4px;background:#1d1d1b;color:#ffffff;text-decoration:none;font-weight:bold;"><?php esc_html_e( 'Accept the invitation', 'exprime-team-access' ); ?></a>
</p>
<p style="font-size:13px;">
	<?php
	/* translators: %s: date. */
	echo esc_html( sprintf( __( 'This link is personal and can be used only once. It expires on %s.', 'exprime-team-access' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires_at ) ) );
	?>
</p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
