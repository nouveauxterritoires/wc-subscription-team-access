<?php
/**
 * Jetons d'invitation : aléatoires, opaques, stockés uniquement sous forme de hachage.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * 32 octets issus de random_bytes(), encodés en base64url (43 caractères).
 * Seul le SHA-256 du jeton est conservé en base : une fuite de la table ne permet
 * pas d'accepter une invitation.
 */
final class Token {

	private const BYTES  = 32;
	private const LENGTH = 43;

	/** Génère un jeton. */
	public static function generate(): string {
		return rtrim( strtr( base64_encode( random_bytes( self::BYTES ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Hachage stocké.
	 *
	 * @param string $token Jeton en clair.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Vérifie la forme d'un jeton reçu (avant toute requête en base).
	 *
	 * @param string $token Jeton reçu.
	 */
	public static function is_well_formed( string $token ): bool {
		return self::LENGTH === strlen( $token ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $token );
	}
}
