<?php
/**
 * Statuts du cycle de vie d'une place invitée.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Une place est « vivante » (elle consomme du quota) tant qu'elle est en attente ou acceptée.
 */
final class SeatStatus {

	public const PENDING   = 'pending';
	public const ACCEPTED  = 'accepted';
	public const REVOKED   = 'revoked';
	public const CANCELLED = 'cancelled';
	public const EXPIRED   = 'expired';

	/** Accès effectif calculé pour une place acceptée. */
	public const ACCESS_ACTIVE    = 'active';
	public const ACCESS_SUSPENDED = 'suspended';
	public const ACCESS_NONE      = 'none';

	/** Raisons de suspension ou d'absence d'accès. */
	public const REASON_NONE                  = '';
	public const REASON_SUBSCRIPTION_PAUSED   = 'subscription_paused';
	public const REASON_SUBSCRIPTION_INACTIVE = 'subscription_inactive';
	public const REASON_OVER_QUOTA            = 'over_quota';
	public const REASON_NO_PLAN               = 'no_plan';

	/**
	 * Statuts qui consomment une place.
	 *
	 * @return string[]
	 */
	public static function live(): array {
		return array( self::PENDING, self::ACCEPTED );
	}

	/**
	 * Indique si un statut consomme une place.
	 *
	 * @param string $status Statut.
	 */
	public static function is_live( string $status ): bool {
		return in_array( $status, self::live(), true );
	}

	/**
	 * Liste de tous les statuts connus.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PENDING, self::ACCEPTED, self::REVOKED, self::CANCELLED, self::EXPIRED );
	}
}
