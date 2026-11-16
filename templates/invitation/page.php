<?php
/**
 * Invitation page template: theme header and footer around the content.
 *
 * Overriding by copying this file into your-theme/wc-subscription-team-access/invitation/page.php
 * is not supported (page template); override invitation/accept.php instead.
 *
 * @package WCSubscriptionTeamAccess
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'wcsta-invitation-page' ); ?>>
	<?php wp_body_open(); ?>
	<div class="wp-site-blocks">
		<?php block_template_part( 'header' ); ?>
	<?php
} else {
	get_header();
}
?>
<main id="primary" class="site-main wcsta-invitation-main">
	<div class="woocommerce wcsta-invitation">
		<?php
		if ( function_exists( 'wc_print_notices' ) ) {
			wc_print_notices();
		}
		\WCSubscriptionTeamAccess\Plugin::instance()->invitations()->render();
		?>
	</div>
</main>
<?php
if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
	block_template_part( 'footer' );
	?>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
} else {
	get_footer();
}
