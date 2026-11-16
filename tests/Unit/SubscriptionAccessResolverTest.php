<?php
declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCSubscriptionTeamAccess\Domain\AccessState;
use WCSubscriptionTeamAccess\Domain\SubscriptionAccessResolver;

/**
 * Traduction des statuts WooCommerce Subscriptions en droits.
 */
final class SubscriptionAccessResolverTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function test_active_subscription_grants_unlimited_access(): void {
		$state = SubscriptionAccessResolver::resolve( 'active', 0, self::NOW );
		$this->assertTrue( $state->is_active() );
		$this->assertNull( $state->end_ts );
	}

	public function test_prefixed_status_is_understood(): void {
		$this->assertTrue( SubscriptionAccessResolver::resolve( 'wc-active', 0, self::NOW )->is_active() );
	}

	public function test_on_hold_suspends_access(): void {
		$this->assertTrue( SubscriptionAccessResolver::resolve( 'on-hold', 0, self::NOW )->is_paused() );
	}

	public function test_pending_cancel_keeps_access_until_end_of_paid_period(): void {
		$end   = self::NOW + 10 * 86400;
		$state = SubscriptionAccessResolver::resolve( 'pending-cancel', $end, self::NOW );
		$this->assertTrue( $state->is_active() );
		$this->assertSame( $end, $state->end_ts );
	}

	public function test_pending_cancel_after_end_of_paid_period_removes_access(): void {
		$this->assertTrue( SubscriptionAccessResolver::resolve( 'pending-cancel', self::NOW - 1, self::NOW )->is_none() );
	}

	public function test_pending_cancel_without_end_date_keeps_access(): void {
		$state = SubscriptionAccessResolver::resolve( 'pending-cancel', 0, self::NOW );
		$this->assertTrue( $state->is_active() );
		$this->assertNull( $state->end_ts );
	}

	public function test_cancelled_with_future_end_date_keeps_access_until_that_date(): void {
		$end   = self::NOW + 3600;
		$state = SubscriptionAccessResolver::resolve( 'cancelled', $end, self::NOW );
		$this->assertTrue( $state->is_active() );
		$this->assertSame( $end, $state->end_ts );
	}

	public function test_cancelled_with_past_end_date_removes_access(): void {
		$this->assertTrue( SubscriptionAccessResolver::resolve( 'cancelled', self::NOW - 1, self::NOW )->is_none() );
		$this->assertTrue( SubscriptionAccessResolver::resolve( 'cancelled', 0, self::NOW )->is_none() );
	}

	/**
	 * @dataProvider no_access_statuses
	 */
	public function test_other_statuses_grant_no_access( string $status ): void {
		$this->assertTrue( SubscriptionAccessResolver::resolve( $status, self::NOW + 86400, self::NOW )->is_none() );
	}

	/** @return array<string, array{string}> */
	public static function no_access_statuses(): array {
		return array(
			'expired'  => array( 'expired' ),
			'pending'  => array( 'pending' ),
			'switched' => array( 'switched' ),
			'trash'    => array( 'trash' ),
			'unknown'  => array( 'whatever' ),
		);
	}

	public function test_same_input_gives_equal_states(): void {
		$a = SubscriptionAccessResolver::resolve( 'pending-cancel', self::NOW + 60, self::NOW );
		$b = SubscriptionAccessResolver::resolve( 'pending-cancel', self::NOW + 60, self::NOW );
		$this->assertTrue( $a->equals( $b ) );
		$this->assertFalse( $a->equals( AccessState::active() ) );
	}
}
