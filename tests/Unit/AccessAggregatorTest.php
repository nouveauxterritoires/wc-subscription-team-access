<?php
declare( strict_types=1 );

namespace Exprime\TeamAccess\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Exprime\TeamAccess\Domain\AccessAggregator;
use Exprime\TeamAccess\Domain\AccessState;

/**
 * Combination of several access sources (guest invited by several subscriptions).
 */
final class AccessAggregatorTest extends TestCase {

	public function test_no_source_means_no_access(): void {
		$this->assertTrue( AccessAggregator::aggregate( array() )->is_none() );
	}

	public function test_active_wins_over_paused(): void {
		$state = AccessAggregator::aggregate( array( AccessState::paused(), AccessState::active( 2000 ) ) );
		$this->assertTrue( $state->is_active() );
		$this->assertSame( 2000, $state->end_ts );
	}

	public function test_paused_wins_over_none(): void {
		$this->assertTrue( AccessAggregator::aggregate( array( AccessState::none(), AccessState::paused() ) )->is_paused() );
	}

	public function test_latest_end_date_wins_between_active_sources(): void {
		$state = AccessAggregator::aggregate( array( AccessState::active( 1000 ), AccessState::active( 3000 ), AccessState::active( 2000 ) ) );
		$this->assertSame( 3000, $state->end_ts );
	}

	public function test_unlimited_source_makes_access_unlimited(): void {
		$state = AccessAggregator::aggregate( array( AccessState::active( 1000 ), AccessState::active() ) );
		$this->assertTrue( $state->is_active() );
		$this->assertNull( $state->end_ts );
	}

	public function test_revoking_one_source_keeps_the_other(): void {
		$both      = AccessAggregator::aggregate( array( AccessState::active(), AccessState::active() ) );
		$remaining = AccessAggregator::aggregate( array( AccessState::active() ) );
		$this->assertTrue( $both->is_active() );
		$this->assertTrue( $remaining->is_active() );
	}
}
