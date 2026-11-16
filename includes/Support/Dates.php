<?php
/**
 * Conversion of stored dates (UTC, MySQL format).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Support;

/**
 * All of the plugin's dates are stored in UTC.
 */
final class Dates {

	/**
	 * Timestamp → UTC MySQL string.
	 *
	 * @param int|null $timestamp Timestamp or null.
	 */
	public static function to_mysql( ?int $timestamp ): ?string {
		return null === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * UTC MySQL string → timestamp.
	 *
	 * @param string|null $value Stored value.
	 */
	public static function from_mysql( ?string $value ): ?int {
		if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		$ts = strtotime( $value . ' UTC' );
		return false === $ts ? null : $ts;
	}

	/** Now, in UTC MySQL format. */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Localized display of a timestamp (site timezone).
	 *
	 * @param int|null $timestamp Timestamp.
	 * @param bool     $with_time Whether to show the time.
	 */
	public static function display( ?int $timestamp, bool $with_time = false ): string {
		if ( null === $timestamp ) {
			return '—';
		}
		$format = (string) get_option( 'date_format' );
		if ( $with_time ) {
			$format .= ' ' . (string) get_option( 'time_format' );
		}
		return wp_date( $format, $timestamp );
	}
}
