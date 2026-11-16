<?php
/**
 * Guest seat (row of the exprime_team_seats table).
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess\Repository;

use Exprime\TeamAccess\Domain\SeatStatus;
use Exprime\TeamAccess\Support\Dates;

/**
 * Immutable read object.
 */
final class Seat {

	/**
	 * @param int      $id               ID.
	 * @param int      $subscription_id  Subscription.
	 * @param int      $owner_id         Owner.
	 * @param string   $email            Guest address (normalized).
	 * @param int      $user_id          Guest account (0 until accepted).
	 * @param string   $status           Lifecycle status.
	 * @param string   $access           Computed effective access.
	 * @param string   $access_reason    Suspension reason.
	 * @param int|null $token_expires_at Token expiration.
	 * @param int      $sent_count       Number of sends.
	 * @param int|null $last_sent_at     Last send.
	 * @param int      $replaced_seat_id Replaced seat.
	 * @param int      $created_by       Invitation author.
	 * @param int      $created_at       Creation.
	 * @param int|null $accepted_at      Acceptance.
	 * @param int|null $ended_at         End (revocation, cancellation, expiration).
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $subscription_id,
		public readonly int $owner_id,
		public readonly string $email,
		public readonly int $user_id,
		public readonly string $status,
		public readonly string $access,
		public readonly string $access_reason,
		public readonly ?int $token_expires_at,
		public readonly int $sent_count,
		public readonly ?int $last_sent_at,
		public readonly int $replaced_seat_id,
		public readonly int $created_by,
		public readonly int $created_at,
		public readonly ?int $accepted_at,
		public readonly ?int $ended_at
	) {}

	/**
	 * Builds from a SQL row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['subscription_id'],
			(int) $row['owner_id'],
			(string) $row['email'],
			(int) $row['user_id'],
			(string) $row['status'],
			(string) $row['access'],
			(string) $row['access_reason'],
			Dates::from_mysql( $row['token_expires_at'] ?? null ),
			(int) $row['sent_count'],
			Dates::from_mysql( $row['last_sent_at'] ?? null ),
			(int) $row['replaced_seat_id'],
			(int) $row['created_by'],
			(int) Dates::from_mysql( (string) $row['created_at'] ),
			Dates::from_mysql( $row['accepted_at'] ?? null ),
			Dates::from_mysql( $row['ended_at'] ?? null )
		);
	}

	/** The seat consumes quota. */
	public function is_live(): bool {
		return SeatStatus::is_live( $this->status );
	}

	public function is_pending(): bool {
		return SeatStatus::PENDING === $this->status;
	}

	public function is_accepted(): bool {
		return SeatStatus::ACCEPTED === $this->status;
	}

	/**
	 * The token is expired.
	 *
	 * @param int $now Reference time.
	 */
	public function is_token_expired( int $now ): bool {
		return null === $this->token_expires_at || $this->token_expires_at <= $now;
	}

	/**
	 * Format expected by SeatAllocator.
	 *
	 * @return array{id:int, status:string, created_at:int, accepted_at:int|null}
	 */
	public function to_allocation_row(): array {
		return array(
			'id'          => $this->id,
			'status'      => $this->status,
			'created_at'  => $this->created_at,
			'accepted_at' => $this->accepted_at,
		);
	}
}
