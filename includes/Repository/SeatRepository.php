<?php
/**
 * Accès aux places invitées. Toutes les requêtes sont préparées.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Repository;

use WCSubscriptionTeamAccess\Domain\SeatStatus;
use WCSubscriptionTeamAccess\Support\Dates;

/**
 * Dépôt de la table wcsta_seats.
 */
final class SeatRepository {

	/** Colonnes modifiables. */
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

	/** Nom de la table. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wcsta_seats';
	}

	/**
	 * Insère une place.
	 *
	 * @param array<string, mixed> $data Colonnes.
	 * @return int|\WP_Error Identifiant ou erreur (doublon notamment).
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
				return new \WP_Error( 'wcsta_duplicate', $error );
			}
			return new \WP_Error( 'wcsta_db_error', $error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Met à jour une place.
	 *
	 * @param int                  $id   Identifiant.
	 * @param array<string, mixed> $data Colonnes.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data               = array_intersect_key( $data, array_flip( self::WRITABLE ) );
		$data['updated_at'] = Dates::now();

		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	/**
	 * Lit une place.
	 *
	 * @param int $id Identifiant.
	 */
	public function find( int $id ): ?Seat {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? Seat::from_row( $row ) : null;
	}

	/**
	 * Place associée à un jeton (par son hachage).
	 *
	 * @param string $hash Hachage SHA-256.
	 */
	public function find_by_token_hash( string $hash ): ?Seat {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s', $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? Seat::from_row( $row ) : null;
	}

	/**
	 * Places d'un abonnement.
	 *
	 * @param int           $subscription_id Abonnement.
	 * @param string[]|null $statuses        Filtre de statuts.
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
	 * Places vivantes d'un abonnement.
	 *
	 * @param int $subscription_id Abonnement.
	 * @return Seat[]
	 */
	public function live_for_subscription( int $subscription_id ): array {
		return $this->for_subscription( $subscription_id, SeatStatus::live() );
	}

	/**
	 * Places vivantes d'un abonnement pour une adresse.
	 *
	 * @param int    $subscription_id Abonnement.
	 * @param string $email           Adresse normalisée.
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
	 * Place acceptée d'un utilisateur sur un abonnement.
	 *
	 * @param int $subscription_id Abonnement.
	 * @param int $user_id         Utilisateur.
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
	 * Places d'un utilisateur (en tant qu'invité) ou d'une adresse.
	 *
	 * @param int    $user_id Utilisateur (0 pour ignorer).
	 * @param string $email   Adresse normalisée ('' pour ignorer).
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
	 * Places dont l'utilisateur est titulaire.
	 *
	 * @param int $owner_id Titulaire.
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
	 * Nombre d'invitations créées par un acteur depuis une date.
	 *
	 * @param int $actor_id Acteur.
	 * @param int $since    Horodatage.
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
	 * Passe en « expirée » les invitations en attente dont le jeton est périmé.
	 *
	 * @param int $subscription_id Abonnement (0 = tous).
	 * @return int Nombre de places expirées.
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
	 * Abonnements ayant au moins une place, par lots.
	 *
	 * @param int $after_id Dernier identifiant traité.
	 * @param int $limit    Taille du lot.
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
	 * Anonymise les adresses des places terminées depuis longtemps (minimisation RGPD).
	 *
	 * @param int $older_than Horodatage limite de fin.
	 * @return int Lignes anonymisées.
	 */
	public function anonymize_ended_before( int $older_than ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET email = CONCAT('anonymized-', id, '@invalid'), updated_at = %s WHERE live IS NULL AND ended_at IS NOT NULL AND ended_at < %s AND email NOT LIKE 'anonymized-%%'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				Dates::now(),
				Dates::to_mysql( $older_than )
			)
		);
	}

	/**
	 * Recherche paginée pour l'administration.
	 *
	 * @param array{search?:string, status?:string} $filters Filtres.
	 * @param int                                   $limit   Taille.
	 * @param int                                   $offset  Décalage.
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
