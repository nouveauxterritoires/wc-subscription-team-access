<?php
/**
 * Plugin Name:          Exprime Team Access
 * Plugin URI:           https://github.com/nouveauxterritoires/wc-subscription-team-access
 * Description:          Team access for WooCommerce Subscriptions: the subscriber shares their WooCommerce Memberships access with guests who use their own accounts. An independent extension, not developed or endorsed by the publisher of WooCommerce.
 * Version:              1.2.3
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Nouveaux Territoires
 * Author URI:           https://nouveauxterritoires.fr
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          exprime-team-access
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.3
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'EXPRIME_TEAM_ACCESS_VERSION', '1.2.3' );
define( 'EXPRIME_TEAM_ACCESS_FILE', __FILE__ );
define( 'EXPRIME_TEAM_ACCESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXPRIME_TEAM_ACCESS_URL', plugin_dir_url( __FILE__ ) );

require_once EXPRIME_TEAM_ACCESS_DIR . 'includes/Autoloader.php';

\Exprime\TeamAccess\Autoloader::register( EXPRIME_TEAM_ACCESS_DIR . 'includes/' );

register_activation_hook( __FILE__, array( \Exprime\TeamAccess\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Exprime\TeamAccess\Installer::class, 'deactivate' ) );

// Declared compatibility with HPOS order storage.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( \Exprime\TeamAccess\Plugin::class, 'boot' ), 20 );
