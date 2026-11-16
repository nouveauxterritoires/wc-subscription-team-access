<?php
/**
 * Simple fixed-window rate limiting, stored in transients.
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess\Support;

/**
 * Counts attempts per key over a time window.
 */
final class RateLimiter {

	/**
	 * Records an attempt and tells whether it is allowed.
	 *
	 * @param string $key    Key (action + actor).
	 * @param int    $limit  Maximum number of attempts per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( string $key, int $limit, int $window ): bool {
		$transient = self::transient( $key );
		$data      = get_transient( $transient );
		$now       = time();

		if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) || ( $now - (int) $data['start'] ) >= $window ) {
			$data = array(
				'start' => $now,
				'count' => 0,
			);
		}

		if ( (int) $data['count'] >= $limit ) {
			return false;
		}

		++$data['count'];
		set_transient( $transient, $data, $window );

		return true;
	}

	/**
	 * Tells whether the limit is reached, without consuming an attempt.
	 *
	 * @param string $key    Key.
	 * @param int    $limit  Limit.
	 * @param int    $window Window.
	 */
	public static function exceeded( string $key, int $limit, int $window ): bool {
		$data = get_transient( self::transient( $key ) );
		if ( ! is_array( $data ) || ( time() - (int) ( $data['start'] ?? 0 ) ) >= $window ) {
			return false;
		}
		return (int) ( $data['count'] ?? 0 ) >= $limit;
	}

	/**
	 * Resets a counter to zero.
	 *
	 * @param string $key Key.
	 */
	public static function reset( string $key ): void {
		delete_transient( self::transient( $key ) );
	}

	/**
	 * Transient key (bounded length).
	 *
	 * @param string $key Key.
	 */
	private static function transient( string $key ): string {
		return 'exprime_team_rl_' . md5( $key );
	}

	/**
	 * Anonymized client identifier (hashed IP address, never stored in clear text).
	 *
	 * Behind a reverse proxy or a CDN, REMOTE_ADDR is the proxy's: the
	 * "exprime_team_client_ip" filter lets you supply the real address taken from a trusted header.
	 */
	public static function client_fingerprint(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		/**
		 * Filters the client IP address used for rate limiting.
		 *
		 * @param string $ip Address (REMOTE_ADDR by default).
		 */
		$ip = (string) apply_filters( 'exprime_team_client_ip', $ip );
		return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}
}
