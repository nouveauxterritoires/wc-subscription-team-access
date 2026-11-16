<?php
declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCSubscriptionTeamAccess\Domain\Token;

/**
 * Invitation tokens.
 */
final class TokenTest extends TestCase {

	public function test_generated_tokens_are_well_formed_and_unique(): void {
		$tokens = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$token = Token::generate();
			$this->assertTrue( Token::is_well_formed( $token ) );
			$tokens[ $token ] = true;
		}
		$this->assertCount( 200, $tokens );
	}

	public function test_hash_is_sha256_and_differs_from_token(): void {
		$token = Token::generate();
		$hash  = Token::hash( $token );
		$this->assertSame( 64, strlen( $hash ) );
		$this->assertNotSame( $token, $hash );
		$this->assertSame( $hash, Token::hash( $token ) );
	}

	/**
	 * @dataProvider malformed
	 */
	public function test_malformed_tokens_are_rejected( string $token ): void {
		$this->assertFalse( Token::is_well_formed( $token ) );
	}

	/** @return array<string, array{string}> */
	public static function malformed(): array {
		return array(
			'empty'         => array( '' ),
			'too short'     => array( 'abc' ),
			'too long'      => array( str_repeat( 'a', 44 ) ),
			'invalid chars' => array( str_repeat( 'a', 42 ) . '/' ),
			'sql'           => array( str_pad( "' OR 1=1 --", 43, 'a' ) ),
		);
	}
}
