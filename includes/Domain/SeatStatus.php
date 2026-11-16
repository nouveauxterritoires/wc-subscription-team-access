<?php
/**
 * Lifecycle statuses of a guest seat.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * A seat is "live" (it consumes quota) as long as it is pending or accepted.
 */
final class SeatStatus {

	public const PENDING   = 'pending';
	public const ACCEPTED  = 'accepted';
	public const REVOKED   = 'revoked';
	public const CANCELLED = 'cancelled';
	public const EXPIRED   = 'expired';

	/** Effective access computed for an accepted seat. */
	public const ACCESS_ACTIVE    = 'active';
	public const ACCESS_SUSPENDED = 'suspended';
	public const ACCESS_NONE      = 'none';

	/** Reasons for suspension or lack of access. */
	public const REASON_NONE                  = '';
	public const REASON_SUBSCRIPTION_PAUSED   = 'subscription_paused';
	public const REASON_SUBSCRIPTION_INACTIVE = 'subscription_inactive';
	public const REASON_OVER_QUOTA            = 'over_quota';
	public const REASON_NO_PLAN               = 'no_plan';

	/**
	 * Statuses that consume a seat.
	 *
	 * @return string[]
	 */
	public static function live(): array {
		return array( self::PENDING, self::ACCEPTED );
	}

	/**
	 * Tells whether a status consumes a seat.
	 *
	 * @param string $status Status.
	 */
	public static function is_live( string $status ): bool {
		return in_array( $status, self::live(), true );
	}

	/**
	 * List of all known statuses.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PENDING, self::ACCEPTED, self::REVOKED, self::CANCELLED, self::EXPIRED );
	}
}
