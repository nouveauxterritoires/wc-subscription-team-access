<?php
/**
 * Conversion des dates stockées (UTC, format MySQL).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Support;

/**
 * Toutes les dates de l'extension sont stockées en UTC.
 */
final class Dates {

	/**
	 * Horodatage → chaîne MySQL UTC.
	 *
	 * @param int|null $timestamp Horodatage ou null.
	 */
	public static function to_mysql( ?int $timestamp ): ?string {
		return null === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Chaîne MySQL UTC → horodatage.
	 *
	 * @param string|null $value Valeur stockée.
	 */
	public static function from_mysql( ?string $value ): ?int {
		if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		$ts = strtotime( $value . ' UTC' );
		return false === $ts ? null : $ts;
	}

	/** Maintenant, au format MySQL UTC. */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Affichage localisé d'un horodatage (fuseau du site).
	 *
	 * @param int|null $timestamp Horodatage.
	 * @param bool     $with_time Afficher l'heure.
	 */
	public static function display( ?int $timestamp, bool $with_time = false ): string {
		if ( null === $timestamp ) {
			return '—';
		}
		$format = (string) get_option( 'date_format' );
		if ( $with_time ) {
			$format .= ' ' . (string) get_option( 'time_format' );
		}
		return wp_date( $format, $timestamp );
	}
}
