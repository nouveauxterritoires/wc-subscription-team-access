<?php
/**
 * État d'accès immuable : actif (avec date de fin éventuelle), en pause ou nul.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Objet valeur décrivant un droit d'accès à un instant donné.
 */
final class AccessState {

	public const ACTIVE = 'active';
	public const PAUSED = 'paused';
	public const NONE   = 'none';

	/**
	 * Constructeur privé : passer par les fabriques.
	 *
	 * @param string   $state  État.
	 * @param int|null $end_ts Fin des droits (horodatage UTC) ou null si illimité.
	 */
	private function __construct(
		public readonly string $state,
		public readonly ?int $end_ts
	) {}

	/**
	 * Accès actif.
	 *
	 * @param int|null $end_ts Fin des droits ou null.
	 */
	public static function active( ?int $end_ts = null ): self {
		return new self( self::ACTIVE, ( null !== $end_ts && $end_ts > 0 ) ? $end_ts : null );
	}

	/** Accès suspendu (récupérable). */
	public static function paused(): self {
		return new self( self::PAUSED, null );
	}

	/** Aucun accès. */
	public static function none(): self {
		return new self( self::NONE, null );
	}

	/**
	 * Reconstruit un état à partir de sa forme stockée.
	 *
	 * @param string   $state  État.
	 * @param int|null $end_ts Fin.
	 */
	public static function from( string $state, ?int $end_ts ): self {
		return match ( $state ) {
			self::ACTIVE => self::active( $end_ts ),
			self::PAUSED => self::paused(),
			default      => self::none(),
		};
	}

	/** Accès actif. */
	public function is_active(): bool {
		return self::ACTIVE === $this->state;
	}

	/** Accès suspendu. */
	public function is_paused(): bool {
		return self::PAUSED === $this->state;
	}

	/** Aucun accès. */
	public function is_none(): bool {
		return self::NONE === $this->state;
	}

	/**
	 * Égalité de valeur.
	 *
	 * @param self $other Autre état.
	 */
	public function equals( self $other ): bool {
		return $this->state === $other->state && $this->end_ts === $other->end_ts;
	}
}
