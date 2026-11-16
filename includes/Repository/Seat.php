<?php
/**
 * Place invitée (ligne de la table wcsta_seats).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Repository;

use WCSubscriptionTeamAccess\Domain\SeatStatus;
use WCSubscriptionTeamAccess\Support\Dates;

/**
 * Objet de lecture immuable.
 */
final class Seat {

	/**
	 * Constructeur.
	 *
	 * @param int      $id               Identifiant.
	 * @param int      $subscription_id  Abonnement.
	 * @param int      $owner_id         Titulaire.
	 * @param string   $email            Adresse invitée (normalisée).
	 * @param int      $user_id          Compte invité (0 tant que non acceptée).
	 * @param string   $status           Statut de cycle de vie.
	 * @param string   $access           Accès effectif calculé.
	 * @param string   $access_reason    Raison de suspension.
	 * @param int|null $token_expires_at Expiration du jeton.
	 * @param int      $sent_count       Nombre d'envois.
	 * @param int|null $last_sent_at     Dernier envoi.
	 * @param int      $replaced_seat_id Place remplacée.
	 * @param int      $created_by       Auteur de l'invitation.
	 * @param int      $created_at       Création.
	 * @param int|null $accepted_at      Acceptation.
	 * @param int|null $ended_at         Fin (révocation, annulation, expiration).
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
	 * Construit depuis une ligne SQL.
	 *
	 * @param array<string, mixed> $row Ligne.
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

	/** La place consomme du quota. */
	public function is_live(): bool {
		return SeatStatus::is_live( $this->status );
	}

	/** Invitation en attente. */
	public function is_pending(): bool {
		return SeatStatus::PENDING === $this->status;
	}

	/** Invitation acceptée. */
	public function is_accepted(): bool {
		return SeatStatus::ACCEPTED === $this->status;
	}

	/**
	 * Le jeton est expiré.
	 *
	 * @param int $now Instant de référence.
	 */
	public function is_token_expired( int $now ): bool {
		return null === $this->token_expires_at || $this->token_expires_at <= $now;
	}

	/**
	 * Forme attendue par SeatAllocator.
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
