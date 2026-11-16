<?php
/**
 * MySQL/MariaDB application locks (GET_LOCK) to serialize concurrent operations.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Support;

/**
 * GET_LOCK is shared across all connections to the database server: two
 * simultaneous PHP requests (two tabs, two web servers) cannot hold the
 * same lock. The lock is released automatically when the connection ends.
 */
final class Lock {

	private const TIMEOUT = 10;

	/** @var array<string, int> Locks held by this process (reentrancy). */
	private static array $held = array();

	/**
	 * Runs a function while holding the lock.
	 *
	 * @template T
	 * @param string             $key      Logical key.
	 * @param callable():T       $callback Work to run.
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
	 * Lock name, unique per database and table prefix (64 characters max).
	 *
	 * @param string $key Logical key.
	 */
	private static function name( string $key ): string {
		global $wpdb;
		return 'wcsta_' . substr( md5( DB_NAME . '|' . $wpdb->prefix . '|' . $key ), 0, 32 );
	}

	/**
	 * Acquires the lock.
	 *
	 * @param string $name Name.
	 */
	private static function acquire( string $name ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::TIMEOUT ) );
	}

	/**
	 * Releases the lock.
	 *
	 * @param string $name Name.
	 */
	private static function release( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
