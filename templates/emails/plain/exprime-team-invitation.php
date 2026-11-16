<?php
/**
 * Invitation email (plain text).
 *
 * Override: your-theme/woocommerce/emails/plain/exprime-team-invitation.php
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

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

esc_html_e( 'Hello,', 'exprime-team-access' );
echo "\n\n";

echo esc_html(
	sprintf(
		/* translators: 1: holder name, 2: subscription name, 3: site name. */
		__( '%1$s invites you to share the premium access of their subscription %2$s on %3$s.', 'exprime-team-access' ),
		$inviter_name,
		$subscription_name,
		wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
	)
);
echo "\n\n";

esc_html_e( 'To accept, follow the link below. You will be able to log in to your account or create one with this email address. No password is sent by email.', 'exprime-team-access' );
echo "\n\n" . esc_url_raw( $accept_url ) . "\n\n";

/* translators: %s: date. */
echo esc_html( sprintf( __( 'This link is personal and can be used only once. It expires on %s.', 'exprime-team-access' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires_at ) ) );
echo "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
