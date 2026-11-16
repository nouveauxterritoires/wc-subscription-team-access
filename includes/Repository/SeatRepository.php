<?php
/**
 * Access to guest seats. All queries are prepared.
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess\Repository;

use Exprime\TeamAccess\Domain\SeatStatus;
use Exprime\TeamAccess\Support\Dates;

/**
 * Repository for the exprime_team_seats table.
 */
final class SeatRepository {

	/** Updatable columns. */
	private const WRITABLE = array(
		'subscription_id',
		'owner_id',
		'email',
		'user_id',
		'status',
		'live',
		'access',
		'access_reason',
		'token_hash',
		'token_expires_at',
		'sent_count',
		'last_sent_at',
		'replaced_seat_id',
		'created_by',
		'created_at',
		'accepted_at',
		'ended_at',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'exprime_team_seats';
	}

	/**
	 * Inserts a seat.
	 *
	 * @param array<string, mixed> $data Columns.
	 * @return int|\WP_Error ID or error (notably a duplicate).
	 */
	public function insert( array $data ): int|\WP_Error {
		global $wpdb;

		$now  = Dates::now();
		$data = array_merge(
			array(
				'created_at' => $now,
				'updated_at' => $now,
				'live'       => 1,
			),
			array_intersect_key( $data, array_flip( self::WRITABLE ) )
		);

		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->insert( self::table(), $data );
		$wpdb->suppress_errors( $suppress );

		if ( false === $result ) {
			$error = (string) $wpdb->last_error;
			if ( str_contains( $error, 'Duplicate' ) ) {
				return new \WP_Error( 'exprime_team_duplicate', $error );
			}
			return new \WP_Error( 'exprime_team_db_error', $error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a seat.
	 *
	 * @param int                  $id   ID.
	 * @param array<string, mixed> $data Columns.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data               = array_intersect_key( $data, array_flip( self::WRITABLE ) );
		$data['updated_at'] = Dates::now();

		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	/**
	 * Reads a seat.
	 *
	 * @param int $id ID.
	 */
	public function find( int $id ): ?Seat {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? Seat::from_row( $row ) : null;
	}

	/**
	 * Seat associated with a token (by its hash).
	 *
	 * @param string $hash SHA-256 hash.
	 */
	public function find_by_token_hash( string $hash ): ?Seat {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s', $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? Seat::from_row( $row ) : null;
	}

	/**
	 * Seats of a subscription.
	 *
	 * @param int           $subscription_id Subscription.
	 * @param string[]|null $statuses        Status filter.
	 * @return Seat[]
	 */
	public function for_subscription( int $subscription_id, ?array $statuses = null ): array {
		global $wpdb;

		$sql  = 'SELECT * FROM ' . self::table() . ' WHERE subscription_id = %d';
		$args = array( $subscription_id );

		if ( null !== $statuses && array() !== $statuses ) {
			$sql .= ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$args = array_merge( $args, array_values( $statuses ) );
		}
		$sql .= ' ORDER BY id ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( Seat::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Live seats of a subscription.
	 *
	 * @param int $subscription_id Subscription.
	 * @return Seat[]
	 */
	public function live_for_subscription( int $subscription_id ): array {
		return $this->for_subscription( $subscription_id, SeatStatus::live() );
	}

	/**
	 * Live seats of a subscription for an address.
	 *
	 * @param int    $subscription_id Subscription.
	 * @param string $email           Normalized address.
	 */
	public function live_for_email( int $subscription_id, string $email ): ?Seat {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE subscription_id = %d AND email = %s AND live = 1 LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$subscription_id,
				$email
			),
			ARRAY_A
		);
		return is_array( $row ) ? Seat::from_row( $row ) : null;
	}

	/**
	 * Accepted seat of a user on a subscription.
	 *
	 * @param int $subscription_id Subscription.
	 * @param int $user_id         User.
	 */
	public function accepted_for_user( int $subscription_id, int $user_id ): ?Seat {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE subscription_id = %d AND user_id = %d AND status = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$subscription_id,
				$user_id,
				SeatStatus::ACCEPTED
			),
			ARRAY_A
		);
		return is_array( $row ) ? Seat::from_row( $row ) : null;
	}

	/**
	 * Seats of a user (as a guest) or of an address.
	 *
	 * @param int    $user_id User (0 to ignore).
	 * @param string $email   Normalized address ('' to ignore).
	 * @return Seat[]
	 */
	public function for_user_or_email( int $user_id, string $email ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE ( user_id = %d AND user_id > 0 ) OR ( email = %s AND email <> \'\' ) ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user_id,
				$email
			),
			ARRAY_A
		);
		return array_map( array( Seat::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Seats owned by the user.
	 *
	 * @param int $owner_id Owner.
	 * @return Seat[]
	 */
	public function for_owner( int $owner_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE owner_id = %d ORDER BY id ASC', $owner_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return array_map( array( Seat::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Number of invitations created by an actor since a date.
	 *
	 * @param int $actor_id Actor.
	 * @param int $since    Timestamp.
	 */
	public function count_created_by_since( int $actor_id, int $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE created_by = %d AND created_at >= %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$actor_id,
				Dates::to_mysql( $since )
			)
		);
	}

	/**
	 * Marks as "expired" the pending invitations whose token has expired.
	 *
	 * @param int $subscription_id Subscription (0 = all).
	 * @return int Number of expired seats.
	 */
	public function expire_stale( int $subscription_id = 0 ): int {
		global $wpdb;

		$now  = Dates::now();
		$sql  = 'UPDATE ' . self::table() . ' SET status = %s, live = NULL, token_hash = NULL, ended_at = %s, updated_at = %s WHERE status = %s AND token_expires_at IS NOT NULL AND token_expires_at <= %s';
		$args = array( SeatStatus::EXPIRED, $now, $now, SeatStatus::PENDING, $now );

		if ( $subscription_id > 0 ) {
			$sql   .= ' AND subscription_id = %d';
			$args[] = $subscription_id;
		}

		return (int) $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Subscriptions with at least one seat, in batches.
	 *
	 * @param int $after_id Last ID processed.
	 * @param int $limit    Batch size.
	 * @return int[]
	 */
	public function subscription_ids( int $after_id = 0, int $limit = 200 ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT subscription_id FROM ' . self::table() . ' WHERE subscription_id > %d ORDER BY subscription_id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$after_id,
				$limit
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Anonymizes the addresses of seats that ended long ago (GDPR minimization).
	 *
	 * @param int $older_than End timestamp cutoff.
	 * @return int Rows anonymized.
	 */
	public function anonymize_ended_before( int $older_than ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET email = CONCAT('anonymized-', id, '@invalid'), updated_at = %s WHERE live IS NULL AND ended_at IS NOT NULL AND ended_at < %s AND email NOT LIKE %s",
				self::table(),
				Dates::now(),
				Dates::to_mysql( $older_than ),
				$wpdb->esc_like( 'anonymized-' ) . '%'
			)
		);
	}

	/**
	 * Paginated search for the admin area.
	 *
	 * @param array{search?:string, status?:string} $filters Filters.
	 * @param int                                   $limit   Page size.
	 * @param int                                   $offset  Offset.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function admin_subscription_summary( array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$table = self::table();
		$where = '1=1';
		$args  = array();

		if ( ! empty( $filters['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where .= ' AND ( email LIKE %s OR subscription_id = %d )';
			$args[] = $like;
			$args[] = (int) $filters['search'];
		}

		$sql_total = "SELECT COUNT(DISTINCT subscription_id) FROM {$table} WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql_total, $args ) ) : $wpdb->get_var( $sql_total ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql = "SELECT subscription_id, MAX(owner_id) AS owner_id,
				SUM( CASE WHEN status = 'pending' THEN 1 ELSE 0 END ) AS pending,
				SUM( CASE WHEN status = 'accepted' THEN 1 ELSE 0 END ) AS accepted,
				SUM( CASE WHEN status = 'accepted' AND access = 'suspended' THEN 1 ELSE 0 END ) AS suspended,
				MAX(updated_at) AS updated_at
			FROM {$table} WHERE {$where}
			GROUP BY subscription_id ORDER BY subscription_id DESC LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}
}
