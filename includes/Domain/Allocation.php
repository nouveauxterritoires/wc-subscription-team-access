<?php
/**
 * Résultat immuable d'une répartition de places.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Compteurs de quota d'un abonnement.
 */
final class Allocation {

	/**
	 * Constructeur.
	 *
	 * @param int   $quota  Quota d'invités.
	 * @param int   $used   Places vivantes (en attente + acceptées).
	 * @param int[] $within Identifiants des places dans le quota.
	 * @param int[] $over   Identifiants des places en dépassement (suspendues).
	 */
	public function __construct(
		public readonly int $quota,
		public readonly int $used,
		public readonly array $within,
		public readonly array $over
	) {}

	/** Places encore disponibles. */
	public function available(): int {
		return max( 0, $this->quota - $this->used );
	}

	/** Nombre de places au-delà du quota. */
	public function overage(): int {
		return max( 0, $this->used - $this->quota );
	}

	/** Le quota est dépassé. */
	public function is_over_quota(): bool {
		return $this->used > $this->quota;
	}

	/** Une nouvelle invitation est possible. */
	public function can_invite(): bool {
		return $this->quota > 0 && $this->available() > 0;
	}

	/**
	 * Indique si une place est dans le quota.
	 *
	 * @param int $seat_id Identifiant.
	 */
	public function is_within( int $seat_id ): bool {
		return in_array( $seat_id, $this->within, true );
	}
}
