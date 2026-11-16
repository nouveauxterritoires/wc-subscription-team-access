<?php
/**
 * Immutable access state: active (with an optional end date), paused or none.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Value object describing an access entitlement at a given point in time.
 */
final class AccessState {

	public const ACTIVE = 'active';
	public const PAUSED = 'paused';
	public const NONE   = 'none';

	/**
	 * Private constructor: use the factory methods.
	 *
	 * @param string   $state  State.
	 * @param int|null $end_ts End of entitlement (UTC timestamp), or null if unlimited.
	 */
	private function __construct(
		public readonly string $state,
		public readonly ?int $end_ts
	) {}

	/**
	 * Active access.
	 *
	 * @param int|null $end_ts End of entitlement, or null.
	 */
	public static function active( ?int $end_ts = null ): self {
		return new self( self::ACTIVE, ( null !== $end_ts && $end_ts > 0 ) ? $end_ts : null );
	}

	/** Suspended access (recoverable). */
	public static function paused(): self {
		return new self( self::PAUSED, null );
	}

	/** No access. */
	public static function none(): self {
		return new self( self::NONE, null );
	}

	/**
	 * Rebuilds a state from its stored form.
	 *
	 * @param string   $state  State.
	 * @param int|null $end_ts End.
	 */
	public static function from( string $state, ?int $end_ts ): self {
		return match ( $state ) {
			self::ACTIVE => self::active( $end_ts ),
			self::PAUSED => self::paused(),
			default      => self::none(),
		};
	}

	/** Active access. */
	public function is_active(): bool {
		return self::ACTIVE === $this->state;
	}

	/** Suspended access. */
	public function is_paused(): bool {
		return self::PAUSED === $this->state;
	}

	/** No access. */
	public function is_none(): bool {
		return self::NONE === $this->state;
	}

	/**
	 * Value equality.
	 *
	 * @param self $other Other state.
	 */
	public function equals( self $other ): bool {
		return $this->state === $other->state && $this->end_ts === $other->end_ts;
	}
}
