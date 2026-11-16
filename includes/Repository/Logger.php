<?php
/**
 * Log of sync events and errors (wcsta_log table + WooCommerce log).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Repository;

use WCSubscriptionTeamAccess\Support\Dates;

/**
 * Logging viewable from the admin area.
 */
final class Logger {

	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	private const SOURCE = 'wc-subscription-team-access';

	/** Table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wcsta_log';
	}

	/**
	 * Adds an entry.
	 *
	 * @param string               $level           Level.
	 * @param string               $event           Event code.
	 * @param string               $message         Human-readable message (untranslated, intended for administrators).
	 * @param array<string, mixed> $context         Context.
	 * @param int                  $subscription_id Subscription.
	 * @param int                  $seat_id         Seat.
	 * @param int                  $user_id         User.
	 */
	public function log( string $level, string $event, string $message, array $context = array(), int $subscription_id = 0, int $seat_id = 0, int $user_id = 0 ): void {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'level'           => $level,
				'event'           => substr( $event, 0, 60 ),
				'subscription_id' => $subscription_id,
				'seat_id'         => $seat_id,
				'user_id'         => $user_id,
				'message'         => $message,
				'context'         => $context ? (string) wp_json_encode( $context ) : null,
				'created_at'      => Dates::now(),
			)
		);

		if ( self::INFO !== $level && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log(
				self::ERROR === $level ? 'error' : 'warning',
				sprintf( '[%s] %s (subscription #%d, seat #%d, user #%d)', $event, $message, $subscription_id, $seat_id, $user_id ),
				array( 'source' => self::SOURCE )
			);
		}
	}

	/**
	 * Informational entry.
	 *
	 * @param string               $event   Code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $ids     IDs: subscription_id, seat_id, user_id, and free-form context.
	 */
	public function info( string $event, string $message, array $ids = array() ): void {
		$this->log_with_ids( self::INFO, $event, $message, $ids );
	}

	/**
	 * Warning.
	 *
	 * @param string               $event   Code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $ids     IDs and context.
	 */
	public function warning( string $event, string $message, array $ids = array() ): void {
		$this->log_with_ids( self::WARNING, $event, $message, $ids );
	}

	/**
	 * Error.
	 *
	 * @param string               $event   Code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $ids     IDs and context.
	 */
	public function error( string $event, string $message, array $ids = array() ): void {
		$this->log_with_ids( self::ERROR, $event, $message, $ids );
	}

	/**
	 * Paginated list.
	 *
	 * @param string $level  Level ('' = all, 'problems' = warnings and errors).
	 * @param int    $limit  Page size.
	 * @param int    $offset Offset.
	 * @param int    $subscription_id Subscription filter.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function query( string $level, int $limit, int $offset, int $subscription_id = 0 ): array {
		global $wpdb;

		$table = self::table();
		$where = '1=1';
		$args  = array();

		if ( 'problems' === $level ) {
			$where .= ' AND level IN (%s, %s)';
			$args[] = self::WARNING;
			$args[] = self::ERROR;
		} elseif ( '' !== $level ) {
			$where .= ' AND level = %s';
			$args[] = $level;
		}
		if ( $subscription_id > 0 ) {
			$where .= ' AND subscription_id = %d';
			$args[] = $subscription_id;
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows      = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Number of recent errors (admin notice).
	 *
	 * @param int $since Timestamp.
	 */
	public function count_errors_since( int $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE level = %s AND created_at >= %s', self::ERROR, Dates::to_mysql( $since ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Purges old entries.
	 *
	 * @param int $days Maximum age in days.
	 */
	public function prune( int $days ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', Dates::to_mysql( time() - $days * DAY_IN_SECONDS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Splits IDs from context.
	 *
	 * @param string               $level   Level.
	 * @param string               $event   Code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $ids     IDs and context.
	 */
	private function log_with_ids( string $level, string $event, string $message, array $ids ): void {
		$subscription_id = (int) ( $ids['subscription_id'] ?? 0 );
		$seat_id         = (int) ( $ids['seat_id'] ?? 0 );
		$user_id         = (int) ( $ids['user_id'] ?? 0 );
		unset( $ids['subscription_id'], $ids['seat_id'], $ids['user_id'] );

		$this->log( $level, $event, $message, $ids, $subscription_id, $seat_id, $user_id );
	}
}
