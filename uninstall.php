<?php
/**
 * Désinstallation : supprime les tables, réglages et tâches de l'extension.
 *
 * Les adhésions WooCommerce Memberships des invités sont conservées (elles appartiennent au
 * moteur Memberships) ; leur méta _wcsta_managed est retirée pour qu'elles deviennent ordinaires.
 * Pour retirer d'abord tous les accès invités : révoquer les invités, ou désactiver chaque
 * abonnement partagé, avant de désinstaller.
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
