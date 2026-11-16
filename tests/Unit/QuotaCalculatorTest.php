<?php
declare( strict_types=1 );

namespace Exprime\TeamAccess\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Exprime\TeamAccess\Domain\QuotaCalculator;

/**
 * Quota of a subscription computed from its line items.
 */
final class QuotaCalculatorTest extends TestCase {

	public function test_simple_subscription_without_value_has_no_guest(): void {
		$this->assertSame( 0, QuotaCalculator::total( array( array( 'quantity' => 1, 'own' => null, 'parent' => null ) ) ) );
	}

	public function test_quantity_multiplies_the_quota(): void {
		$this->assertSame( 10, QuotaCalculator::total( array( array( 'quantity' => 2, 'own' => 5, 'parent' => null ) ) ) );
	}

	public function test_variation_value_overrides_parent(): void {
		$this->assertSame( 20, QuotaCalculator::total( array( array( 'quantity' => 1, 'own' => 20, 'parent' => 5 ) ) ) );
	}

	public function test_variation_without_value_inherits_parent(): void {
		$this->assertSame( 5, QuotaCalculator::total( array( array( 'quantity' => 1, 'own' => null, 'parent' => 5 ) ) ) );
	}

	public function test_explicit_zero_on_variation_disables_feature(): void {
		$this->assertSame( 0, QuotaCalculator::total( array( array( 'quantity' => 1, 'own' => 0, 'parent' => 5 ) ) ) );
	}

	public function test_several_lines_are_added(): void {
		$lines = array(
			array( 'quantity' => 1, 'own' => 5, 'parent' => null ),
			array( 'quantity' => 1, 'own' => 0, 'parent' => null ),
			array( 'quantity' => 3, 'own' => 1, 'parent' => null ),
		);
		$this->assertSame( 8, QuotaCalculator::total( $lines ) );
	}

	public function test_parse_handles_empty_and_invalid_values(): void {
		$this->assertNull( QuotaCalculator::parse( '' ) );
		$this->assertNull( QuotaCalculator::parse( null ) );
		$this->assertNull( QuotaCalculator::parse( 'abc' ) );
		$this->assertSame( 0, QuotaCalculator::parse( '-3' ) );
		$this->assertSame( 0, QuotaCalculator::parse( '0' ) );
		$this->assertSame( 12, QuotaCalculator::parse( '12' ) );
	}
}
