<?php
/**
 * Invitation page template: theme header and footer around the content.
 *
 * Overriding by copying this file into your-theme/exprime-team-access/invitation/page.php
 * is not supported (page template); override invitation/accept.php instead.
 *
 * @package ExprimeTeamAccess
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'exprime-team-invitation-page' ); ?>>
	<?php wp_body_open(); ?>
	<div class="wp-site-blocks">
		<?php block_template_part( 'header' ); ?>
	<?php
} else {
	get_header();
}
?>
<main id="primary" class="site-main exprime-team-invitation-main">
	<div class="woocommerce exprime-team-invitation">
		<?php
		if ( function_exists( 'wc_print_notices' ) ) {
			wc_print_notices();
		}
		\Exprime\TeamAccess\Plugin::instance()->invitations()->render();
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
