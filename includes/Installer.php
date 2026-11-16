<?php
/**
 * Installation, schema upgrades and deactivation.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

/**
 * Creates the tables (dbDelta) and manages the schema version.
 */
final class Installer {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'wcsta_db_version';
	public const FLUSH_OPTION      = 'wcsta_flush_rewrite';

	public static function activate(): void {
		self::install();
		// Rewrite rules are registered on "init": flush the cache on the next load.
		update_option( self::FLUSH_OPTION, 'yes', false );
	}

	/** Deactivation: remove the scheduled tasks, leaving the data untouched. */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Scheduler::DAILY_HOOK, array(), Scheduler::GROUP );
		}
		flush_rewrite_rules();
	}

	/** Updates the schema if needed (called on every load, negligible cost). */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/** Creates or updates the tables. */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$seats   = $wpdb->prefix . 'wcsta_seats';
		$grants  = $wpdb->prefix . 'wcsta_grants';
		$log     = $wpdb->prefix . 'wcsta_log';

		// "live" is 1 for a live seat and NULL otherwise: the unique key prevents
		// two live invitations for the same address on the same subscription, while
		// keeping the history (multiple NULLs are allowed).
		dbDelta(
			"CREATE TABLE {$seats} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subscription_id bigint(20) unsigned NOT NULL,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(100) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				live tinyint(1) unsigned NULL DEFAULT 1,
				access varchar(20) NOT NULL DEFAULT 'none',
				access_reason varchar(40) NOT NULL DEFAULT '',
				token_hash char(64) NULL DEFAULT NULL,
				token_expires_at datetime NULL DEFAULT NULL,
				sent_count smallint(5) unsigned NOT NULL DEFAULT 0,
				last_sent_at datetime NULL DEFAULT NULL,
				replaced_seat_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				accepted_at datetime NULL DEFAULT NULL,
				ended_at datetime NULL DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY live_email (subscription_id,email,live),
				UNIQUE KEY token_hash (token_hash),
				KEY subscription_status (subscription_id,status),
				KEY user_id (user_id),
				KEY owner_id (owner_id),
				KEY email (email)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$grants} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				seat_id bigint(20) unsigned NOT NULL,
				subscription_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				plan_id bigint(20) unsigned NOT NULL,
				state varchar(10) NOT NULL,
				end_at datetime NULL DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY seat_plan (seat_id,plan_id),
				KEY user_plan (user_id,plan_id),
				KEY subscription_id (subscription_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				level varchar(10) NOT NULL,
				event varchar(60) NOT NULL,
				subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
				seat_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				message text NOT NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY level_created (level,created_at),
				KEY subscription_id (subscription_id)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/** Flushes the rewrite rules cache if requested at activation. */
	public static function maybe_flush_rewrite_rules(): void {
		if ( 'yes' === get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules();
		}
	}
}
