<?php
/**
 * Uninstall: removes the plugin's tables, settings and scheduled tasks.
 *
 * The guests' WooCommerce Memberships are kept (they belong to the
 * Memberships engine); their _exprime_team_managed meta is removed so they become regular memberships.
 * To remove all guest access first: revoke the guests, or deactivate each
 * shared subscription, before uninstalling.
 *
 * @package ExprimeTeamAccess
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}exprime_team_seats, {$wpdb->prefix}exprime_team_grants, {$wpdb->prefix}exprime_team_log" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_exprime_team_managed', '_exprime_team_sources', '_exprime_team_released_at')" );
// phpcs:enable

delete_option( 'exprime_team_settings' );
delete_option( 'exprime_team_db_version' );
delete_option( 'exprime_team_flush_rewrite' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'exprime-team-access' );
}
