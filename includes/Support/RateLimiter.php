<?php
/**
 * Limitation de débit simple à fenêtre fixe, stockée en transients.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Support;

/**
 * Compte les tentatives par clé sur une fenêtre de temps.
 */
final class RateLimiter {

	/**
	 * Enregistre une tentative et indique si elle est autorisée.
	 *
	 * @param string $key    Clé (action + acteur).
	 * @param int    $limit  Nombre maximal de tentatives par fenêtre.
	 * @param int    $window Durée de la fenêtre en secondes.
	 */
	public static function hit( string $key, int $limit, int $window ): bool {
		$transient = self::transient( $key );
		$data      = get_transient( $transient );
		$now       = time();

		if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) || ( $now - (int) $data['start'] ) >= $window ) {
			$data = array(
				'start' => $now,
				'count' => 0,
			);
		}

		if ( (int) $data['count'] >= $limit ) {
			return false;
		}

		++$data['count'];
		set_transient( $transient, $data, $window );

		return true;
	}

	/**
	 * Indique si la limite est atteinte, sans consommer de tentative.
	 *
	 * @param string $key    Clé.
	 * @param int    $limit  Limite.
	 * @param int    $window Fenêtre.
	 */
	public static function exceeded( string $key, int $limit, int $window ): bool {
		$data = get_transient( self::transient( $key ) );
		if ( ! is_array( $data ) || ( time() - (int) ( $data['start'] ?? 0 ) ) >= $window ) {
			return false;
		}
		return (int) ( $data['count'] ?? 0 ) >= $limit;
	}

	/**
	 * Remet un compteur à zéro.
	 *
	 * @param string $key Clé.
	 */
	public static function reset( string $key ): void {
		delete_transient( self::transient( $key ) );
	}

	/**
	 * Clé de transient (longueur bornée).
	 *
	 * @param string $key Clé.
	 */
	private static function transient( string $key ): string {
		return 'wcsta_rl_' . md5( $key );
	}

	/**
	 * Identifiant anonymisé du client (adresse IP hachée, jamais stockée en clair).
	 *
	 * Derrière un proxy inverse ou un CDN, REMOTE_ADDR est celle du proxy : le filtre
	 * « wcsta_client_ip » permet de fournir l'adresse réelle issue d'un en-tête de confiance.
	 */
	public static function client_fingerprint(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		/**
		 * Filtre l'adresse IP du client utilisée pour la limitation de débit.
		 *
		 * @param string $ip Adresse (REMOTE_ADDR par défaut).
		 */
		$ip = (string) apply_filters( 'wcsta_client_ip', $ip );
		return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}
}
