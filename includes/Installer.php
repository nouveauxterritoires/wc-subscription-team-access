<?php
/**
 * Installation, mises à jour de schéma et désactivation.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

/**
 * Crée les tables (dbDelta) et gère la version du schéma.
 */
final class Installer {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'wcsta_db_version';
	public const FLUSH_OPTION      = 'wcsta_flush_rewrite';

	/** Activation de l'extension. */
	public static function activate(): void {
		self::install();
		// Les règles de réécriture sont enregistrées sur « init » : on vide le cache au prochain chargement.
		update_option( self::FLUSH_OPTION, 'yes', false );
	}

	/** Désactivation : on retire les tâches planifiées, sans toucher aux données. */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Scheduler::DAILY_HOOK, array(), Scheduler::GROUP );
		}
		flush_rewrite_rules();
	}

	/** Met le schéma à jour si nécessaire (appelé à chaque chargement, coût négligeable). */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/** Crée ou met à jour les tables. */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$seats   = $wpdb->prefix . 'wcsta_seats';
		$grants  = $wpdb->prefix . 'wcsta_grants';
		$log     = $wpdb->prefix . 'wcsta_log';

		// « live » vaut 1 pour une place vivante et NULL sinon : la clé unique empêche
		// deux invitations vivantes pour la même adresse sur un même abonnement, tout en
		// conservant l'historique (plusieurs NULL sont autorisés).
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

	/** Vide le cache des règles de réécriture si demandé à l'activation. */
	public static function maybe_flush_rewrite_rules(): void {
		if ( 'yes' === get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules();
		}
	}
}
