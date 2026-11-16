<?php
/**
 * Email to the plan holder: invitation accepted (plain text).
 *
 * Override: your-theme/woocommerce/emails/plain/exprime-team-invitation-accepted.php
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

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

esc_html_e( 'Hello,', 'exprime-team-access' );
echo "\n\n";

echo esc_html(
	sprintf(
		/* translators: 1: guest email, 2: subscription name. */
		__( '%1$s accepted your invitation and now shares the premium access of your subscription %2$s.', 'exprime-team-access' ),
		$guest_email,
		$subscription_name
	)
);
echo "\n\n";

esc_html_e( 'Manage my guests', 'exprime-team-access' );
echo ': ' . esc_url_raw( $manage_url ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
