<?php
/**
 * Invitation tokens: random, opaque, stored only as a hash.
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess\Domain;

/**
 * 32 bytes from random_bytes(), base64url-encoded (43 characters).
 * Only the token's SHA-256 is kept in the database: a leak of the table does not
 * allow anyone to accept an invitation.
 */
final class Token {

	private const BYTES  = 32;
	private const LENGTH = 43;

	public static function generate(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes.
		return rtrim( strtr( base64_encode( random_bytes( self::BYTES ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Stored hash.
	 *
	 * @param string $token Plain-text token.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Checks the format of a received token (before any database query).
	 *
	 * @param string $token Received token.
	 */
	public static function is_well_formed( string $token ): bool {
		return self::LENGTH === strlen( $token ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $token );
	}
}
