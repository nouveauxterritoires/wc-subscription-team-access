<?php
declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCSubscriptionTeamAccess\Domain\EmailAddress;

/**
 * Comparaison et masquage des adresses.
 */
final class EmailAddressTest extends TestCase {

	public function test_comparison_ignores_case_and_spaces(): void {
		$this->assertTrue( EmailAddress::same( ' Jeanne.Dupont@Example.org ', 'jeanne.dupont@example.org' ) );
		$this->assertFalse( EmailAddress::same( 'a@example.org', 'b@example.org' ) );
		$this->assertFalse( EmailAddress::same( '', '' ) );
	}

	public function test_mask_hides_the_local_part(): void {
		$masked = EmailAddress::mask( 'jeanne@example.org' );
		$this->assertStringStartsWith( 'j', $masked );
		$this->assertStringEndsWith( '@example.org', $masked );
		$this->assertStringNotContainsString( 'jeanne', $masked );
	}

	public function test_mask_of_invalid_value(): void {
		$this->assertSame( '•••', EmailAddress::mask( 'not-an-email' ) );
	}
}
