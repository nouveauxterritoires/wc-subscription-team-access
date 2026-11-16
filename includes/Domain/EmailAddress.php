<?php
/**
 * Normalization and masking of email addresses.
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess\Domain;

/**
 * Pure functions for email addresses.
 */
final class EmailAddress {

	public const MAX_LENGTH = 100;

	/**
	 * Canonical form used for comparisons (whitespace trimmed, lowercase).
	 *
	 * @param string $email Raw address.
	 */
	public static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Compares two addresses case-insensitively.
	 *
	 * @param string $a Address A.
	 * @param string $b Address B.
	 */
	public static function same( string $a, string $b ): bool {
		return '' !== self::normalize( $a ) && self::normalize( $a ) === self::normalize( $b );
	}

	/**
	 * Masks an address so it can be shown to someone who is not necessarily its owner.
	 * Example: jean.dupont@example.org → j•••••••••@example.org
	 *
	 * @param string $email Address.
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
