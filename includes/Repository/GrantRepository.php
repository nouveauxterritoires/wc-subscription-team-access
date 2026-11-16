<?php
/**
 * Registre des sources de droits : une ligne par (place, plan) donnant un accès actif ou suspendu.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Repository;

use WCSubscriptionTeamAccess\Domain\AccessState;
use WCSubscriptionTeamAccess\Support\Dates;

/**
 * Dépôt de la table wcsta_grants.
 */
final class GrantRepository {

	/** Nom de la table. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wcsta_grants';
	}

	/**
	 * Crée ou met à jour une source de droits (idempotent grâce à la clé unique seat_plan).
	 *
	 * @param int         $seat_id         Place.
	 * @param int         $subscription_id Abonnement.
	 * @param int         $user_id         Invité.
	 * @param int         $plan_id         Plan Memberships.
	 * @param AccessState $state           État (actif ou suspendu).
	 */
	public function upsert( int $seat_id, int $subscription_id, int $user_id, int $plan_id, AccessState $state ): void {
		global $wpdb;

		$table = self::table();
		$end   = Dates::to_mysql( $state->end_ts );
		$now   = Dates::now();

		// prepare() ne sait pas produire NULL : la valeur littérale est choisie avant la préparation.
		$end_placeholder = null === $end ? 'NULL' : '%s';
		$args            = array( $seat_id, $subscription_id, $user_id, $plan_id, $state->state );
		if ( null !== $end ) {
			$args[] = $end;
		}
		$args[] = $now;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (seat_id, subscription_id, user_id, plan_id, state, end_at, updated_at) VALUES (%d, %d, %d, %d, %s, {$end_placeholder}, %s)
				ON DUPLICATE KEY UPDATE subscription_id = VALUES(subscription_id), user_id = VALUES(user_id), state = VALUES(state), end_at = VALUES(end_at), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args
			)
		);
	}

	/**
	 * Supprime les sources d'une place, sauf pour certains plans.
	 *
	 * @param int   $seat_id       Place.
	 * @param int[] $keep_plan_ids Plans à conserver.
	 */
	public function delete_for_seat( int $seat_id, array $keep_plan_ids = array() ): void {
		global $wpdb;

		$table = self::table();
		$sql   = "DELETE FROM {$table} WHERE seat_id = %d";
		$args  = array( $seat_id );

		$keep_plan_ids = array_values( array_filter( array_map( 'intval', $keep_plan_ids ) ) );
		if ( $keep_plan_ids ) {
			$sql .= ' AND plan_id NOT IN (' . implode( ',', array_fill( 0, count( $keep_plan_ids ), '%d' ) ) . ')';
			$args = array_merge( $args, $keep_plan_ids );
		}

		$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Couples (utilisateur, plan) concernés par un abonnement.
	 *
	 * @param int $subscription_id Abonnement.
	 * @return array<int, array{0:int, 1:int}>
	 */
	public function pairs_for_subscription( int $subscription_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT DISTINCT user_id, plan_id FROM ' . self::table() . ' WHERE subscription_id = %d', $subscription_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return array_map(
			static fn( array $row ): array => array( (int) $row['user_id'], (int) $row['plan_id'] ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Couples (utilisateur, plan) d'un utilisateur.
	 *
	 * @param int $user_id Utilisateur.
	 * @return array<int, array{0:int, 1:int}>
	 */
	public function pairs_for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT DISTINCT user_id, plan_id FROM ' . self::table() . ' WHERE user_id = %d', $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return array_map(
			static fn( array $row ): array => array( (int) $row['user_id'], (int) $row['plan_id'] ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Sources actives ou suspendues pour un utilisateur et un plan.
	 *
	 * @param int $user_id Utilisateur.
	 * @param int $plan_id Plan.
	 * @return array<int, array{seat_id:int, subscription_id:int, state:AccessState}>
	 */
	public function for_user_plan( int $user_id, int $plan_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT seat_id, subscription_id, state, end_at FROM ' . self::table() . ' WHERE user_id = %d AND plan_id = %d ORDER BY seat_id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user_id,
				$plan_id
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'seat_id'         => (int) $row['seat_id'],
				'subscription_id' => (int) $row['subscription_id'],
				'state'           => AccessState::from( (string) $row['state'], Dates::from_mysql( $row['end_at'] ?? null ) ),
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Plans d'une place (pour l'affichage).
	 *
	 * @param int $seat_id Place.
	 * @return array<int, array{plan_id:int, state:string}>
	 */
	public function for_seat( int $seat_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT plan_id, state FROM ' . self::table() . ' WHERE seat_id = %d ORDER BY plan_id ASC', $seat_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return array_map(
			static fn( array $row ): array => array(
				'plan_id' => (int) $row['plan_id'],
				'state'   => (string) $row['state'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Supprime toutes les sources d'un utilisateur (compte supprimé).
	 *
	 * @param int $user_id Utilisateur.
	 */
	public function delete_for_user( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
