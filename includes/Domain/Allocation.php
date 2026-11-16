<?php
/**
 * Immutable result of a seat allocation.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Quota counters of a subscription.
 */
final class Allocation {

	/**
	 * Constructor.
	 *
	 * @param int   $quota  Guest quota.
	 * @param int   $used   Live seats (pending + accepted).
	 * @param int[] $within IDs of the seats within the quota.
	 * @param int[] $over   IDs of the over-quota seats (suspended).
	 */
	public function __construct(
		public readonly int $quota,
		public readonly int $used,
		public readonly array $within,
		public readonly array $over
	) {}

	/** Seats still available. */
	public function available(): int {
		return max( 0, $this->quota - $this->used );
	}

	/** Number of seats beyond the quota. */
	public function overage(): int {
		return max( 0, $this->used - $this->quota );
	}

	/** The quota is exceeded. */
	public function is_over_quota(): bool {
		return $this->used > $this->quota;
	}

	/** A new invitation is possible. */
	public function can_invite(): bool {
		return $this->quota > 0 && $this->available() > 0;
	}

	/**
	 * Tells whether a seat is within the quota.
	 *
	 * @param int $seat_id ID.
	 */
	public function is_within( int $seat_id ): bool {
		return in_array( $seat_id, $this->within, true );
	}
}
