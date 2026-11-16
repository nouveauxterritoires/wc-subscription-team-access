<?php
/**
 * Normalisation et masquage des adresses e-mail.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Fonctions pures autour des adresses e-mail.
 */
final class EmailAddress {

	public const MAX_LENGTH = 100;

	/**
	 * Forme canonique utilisée pour les comparaisons (espaces retirés, minuscules).
	 *
	 * @param string $email Adresse brute.
	 */
	public static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Compare deux adresses sans tenir compte de la casse.
	 *
	 * @param string $a Adresse A.
	 * @param string $b Adresse B.
	 */
	public static function same( string $a, string $b ): bool {
		return '' !== self::normalize( $a ) && self::normalize( $a ) === self::normalize( $b );
	}

	/**
	 * Masque une adresse pour l'afficher à une personne qui n'en est pas forcément titulaire.
	 * Exemple : jean.dupont@example.org → j•••••••••@example.org
	 *
	 * @param string $email Adresse.
	 */
	public static function mask( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '•••';
		}
		$local = substr( $email, 0, $at );
		return mb_substr( $local, 0, 1 ) . str_repeat( '•', max( 2, mb_strlen( $local ) - 1 ) ) . substr( $email, $at );
	}
}
