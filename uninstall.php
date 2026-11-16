<?php
/**
 * Uninstall: removes the plugin's tables, settings and scheduled tasks.
 *
 * The guests' WooCommerce Memberships are kept (they belong to the
 * Memberships engine); their _wcsta_managed meta is removed so they become regular memberships.
 * To remove all guest access first: revoke the guests, or deactivate each
 * shared subscription, before uninstalling.
 *
 * @package WCSubscriptionTeamAccess
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcsta_seats, {$wpdb->prefix}wcsta_grants, {$wpdb->prefix}wcsta_log" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wcsta_managed', '_wcsta_sources', '_wcsta_released_at')" );
// phpcs:enable

delete_option( 'wcsta_settings' );
delete_option( 'wcsta_db_version' );
delete_option( 'wcsta_flush_rewrite' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'wc-subscription-team-access' );
}
