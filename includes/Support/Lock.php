<?php
/**
 * Verrous applicatifs MySQL/MariaDB (GET_LOCK) pour sérialiser les opérations concurrentes.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Support;

/**
 * GET_LOCK est partagé par toutes les connexions au serveur de base de données : deux
 * requêtes PHP simultanées (deux onglets, deux serveurs web) ne peuvent pas détenir le
 * même verrou. Le verrou est libéré automatiquement si la connexion se termine.
 */
final class Lock {

	private const TIMEOUT = 10;

	/** @var array<string, int> Verrous détenus par ce processus (réentrance). */
	private static array $held = array();

	/**
	 * Exécute une fonction en détenant le verrou.
	 *
	 * @template T
	 * @param string             $key      Clé logique.
	 * @param callable():T       $callback Traitement.
	 * @return T|\WP_Error
	 */
	public static function run( string $key, callable $callback ): mixed {
		$name = self::name( $key );

		if ( isset( self::$held[ $name ] ) ) {
			++self::$held[ $name ];
			try {
				return $callback();
			} finally {
				--self::$held[ $name ];
			}
		}

		if ( ! self::acquire( $name ) ) {
			return new \WP_Error(
				'wcsta_busy',
				__( 'Another operation is in progress on this subscription. Please try again in a moment.', 'wc-subscription-team-access' )
			);
		}

		self::$held[ $name ] = 1;
		try {
			return $callback();
		} finally {
			unset( self::$held[ $name ] );
			self::release( $name );
		}
	}

	/**
	 * Nom du verrou, unique par base et par préfixe de tables (64 caractères max).
	 *
	 * @param string $key Clé logique.
	 */
	private static function name( string $key ): string {
		global $wpdb;
		return 'wcsta_' . substr( md5( DB_NAME . '|' . $wpdb->prefix . '|' . $key ), 0, 32 );
	}

	/**
	 * Acquiert le verrou.
	 *
	 * @param string $name Nom.
	 */
	private static function acquire( string $name ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::TIMEOUT ) );
	}

	/**
	 * Libère le verrou.
	 *
	 * @param string $name Nom.
	 */
	private static function release( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
