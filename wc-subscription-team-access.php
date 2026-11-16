<?php
/**
 * Plugin Name:          WC Subscription Team Access
 * Plugin URI:           https://github.com/nouveauxterritoires/wc-subscription-team-access
 * Description:          Lets a WooCommerce Subscriptions holder share their WooCommerce Memberships access with guests who use their own accounts.
 * Version:              1.0.0
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Nouveaux Territoires
 * Author URI:           https://nouveauxterritoires.fr
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          wc-subscription-team-access
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.3
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'WCSTA_VERSION', '1.0.0' );
define( 'WCSTA_FILE', __FILE__ );
define( 'WCSTA_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCSTA_URL', plugin_dir_url( __FILE__ ) );

require_once WCSTA_DIR . 'includes/Autoloader.php';

\WCSubscriptionTeamAccess\Autoloader::register( WCSTA_DIR . 'includes/' );

register_activation_hook( __FILE__, array( \WCSubscriptionTeamAccess\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WCSubscriptionTeamAccess\Installer::class, 'deactivate' ) );

// Compatibilité déclarée avec le stockage des commandes HPOS.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( \WCSubscriptionTeamAccess\Plugin::class, 'boot' ), 20 );
