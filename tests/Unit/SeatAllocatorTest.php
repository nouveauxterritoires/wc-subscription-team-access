<?php
declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCSubscriptionTeamAccess\Domain\SeatAllocator;
use WCSubscriptionTeamAccess\Domain\SeatStatus;

/**
 * Répartition des places et règle de dépassement de quota.
 */
final class SeatAllocatorTest extends TestCase {

	/**
	 * @param int      $id          Place.
	 * @param string   $status      Statut.
	 * @param int      $created_at  Création.
	 * @param int|null $accepted_at Acceptation.
	 * @return array{id:int, status:string, created_at:int, accepted_at:int|null}
	 */
	private static function seat( int $id, string $status, int $created_at, ?int $accepted_at = null ): array {
		return compact( 'id', 'status', 'created_at', 'accepted_at' );
	}

	public function test_zero_quota_allows_no_invitation(): void {
		$allocation = SeatAllocator::allocate( array(), 0 );
		$this->assertFalse( $allocation->can_invite() );
		$this->assertSame( 0, $allocation->available() );
	}

	public function test_five_seats_quota_counts_pending_and_accepted(): void {
		$seats = array(
			self::seat( 1, SeatStatus::ACCEPTED, 10, 20 ),
			self::seat( 2, SeatStatus::PENDING, 11 ),
			self::seat( 3, SeatStatus::REVOKED, 12 ),
			self::seat( 4, SeatStatus::CANCELLED, 13 ),
			self::seat( 5, SeatStatus::EXPIRED, 14 ),
		);
		$allocation = SeatAllocator::allocate( $seats, 5 );
		$this->assertSame( 2, $allocation->used );
		$this->assertSame( 3, $allocation->available() );
		$this->assertTrue( $allocation->can_invite() );
	}

	public function test_full_quota_refuses_a_sixth_invitation(): void {
		$seats = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$seats[] = self::seat( $i, SeatStatus::ACCEPTED, $i, 100 + $i );
		}
		$allocation = SeatAllocator::allocate( $seats, 5 );
		$this->assertFalse( $allocation->can_invite() );
		$this->assertFalse( $allocation->is_over_quota() );
	}

	public function test_decrease_suspends_most_recent_acceptances_first(): void {
		$seats = array(
			self::seat( 1, SeatStatus::ACCEPTED, 1, 300 ),
			self::seat( 2, SeatStatus::ACCEPTED, 2, 100 ),
			self::seat( 3, SeatStatus::PENDING, 3 ),
			self::seat( 4, SeatStatus::ACCEPTED, 4, 200 ),
		);
		$allocation = SeatAllocator::allocate( $seats, 2 );
		$this->assertSame( array( 2, 4 ), $allocation->within );
		$this->assertSame( array( 1, 3 ), $allocation->over );
		$this->assertSame( 2, $allocation->overage() );
		$this->assertTrue( $allocation->is_over_quota() );
		$this->assertFalse( $allocation->can_invite() );
	}

	public function test_pending_invitations_are_ranked_after_accepted_guests(): void {
		$seats = array(
			self::seat( 1, SeatStatus::PENDING, 1 ),
			self::seat( 2, SeatStatus::ACCEPTED, 50, 60 ),
		);
		$allocation = SeatAllocator::allocate( $seats, 1 );
		$this->assertSame( array( 2 ), $allocation->within );
		$this->assertTrue( $allocation->is_within( 2 ) );
		$this->assertFalse( $allocation->is_within( 1 ) );
	}

	public function test_ties_are_broken_by_identifier(): void {
		$seats = array(
			self::seat( 9, SeatStatus::ACCEPTED, 1, 100 ),
			self::seat( 3, SeatStatus::ACCEPTED, 1, 100 ),
		);
		$this->assertSame( array( 3 ), SeatAllocator::allocate( $seats, 1 )->within );
	}

	public function test_allocation_is_deterministic_whatever_the_input_order(): void {
		$seats = array(
			self::seat( 1, SeatStatus::ACCEPTED, 1, 30 ),
			self::seat( 2, SeatStatus::ACCEPTED, 2, 10 ),
			self::seat( 3, SeatStatus::ACCEPTED, 3, 20 ),
		);
		$first  = SeatAllocator::allocate( $seats, 2 );
		$second = SeatAllocator::allocate( array_reverse( $seats ), 2 );
		$this->assertSame( $first->within, $second->within );
	}

	public function test_keep_all_policy_suspends_nobody_but_blocks_invitations(): void {
		$seats = array(
			self::seat( 1, SeatStatus::ACCEPTED, 1, 10 ),
			self::seat( 2, SeatStatus::ACCEPTED, 2, 20 ),
			self::seat( 3, SeatStatus::ACCEPTED, 3, 30 ),
		);
		$allocation = SeatAllocator::allocate( $seats, 1, SeatAllocator::POLICY_KEEP_ALL );
		$this->assertSame( array( 1, 2, 3 ), $allocation->within );
		$this->assertSame( array(), $allocation->over );
		$this->assertTrue( $allocation->is_over_quota() );
		$this->assertFalse( $allocation->can_invite() );
	}

	public function test_removing_a_guest_frees_a_seat(): void {
		$full  = array( self::seat( 1, SeatStatus::ACCEPTED, 1, 1 ), self::seat( 2, SeatStatus::ACCEPTED, 2, 2 ) );
		$freed = array( self::seat( 1, SeatStatus::ACCEPTED, 1, 1 ), self::seat( 2, SeatStatus::REVOKED, 2, 2 ) );
		$this->assertFalse( SeatAllocator::allocate( $full, 2 )->can_invite() );
		$this->assertTrue( SeatAllocator::allocate( $freed, 2 )->can_invite() );
	}
}
