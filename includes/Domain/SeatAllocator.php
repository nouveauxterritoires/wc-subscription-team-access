<?php
/**
 * Deterministic split of live seats between "within quota" and "over quota".
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess\Domain;

/**
 * Priority rule (documented in the README):
 *
 * 1. accepted seats, from the oldest acceptance to the most recent;
 * 2. then pending invitations, from the oldest to the most recent;
 * 3. on equal dates, the lowest ID wins.
 *
 * The first `quota` seats are within the quota; the rest are over quota.
 * With the "keep_all" policy, no seat is suspended: the overage is
 * only reported and blocks new invitations.
 */
final class SeatAllocator {

	public const POLICY_SUSPEND_NEWEST = 'suspend_newest';
	public const POLICY_KEEP_ALL       = 'keep_all';

	/**
	 * Computes the allocation.
	 *
	 * @param array<int, array{id:int, status:string, created_at:int, accepted_at:int|null}> $seats  Live seats.
	 * @param int                                                                          $quota  Guest quota.
	 * @param string                                                                       $policy Over-quota policy.
	 */
	public static function allocate( array $seats, int $quota, string $policy = self::POLICY_SUSPEND_NEWEST ): Allocation {
		$quota = max( 0, $quota );
		$live  = array_values(
			array_filter(
				$seats,
				static fn( array $seat ): bool => SeatStatus::is_live( (string) $seat['status'] )
			)
		);

		usort( $live, array( self::class, 'compare' ) );

		$ordered = array_map( static fn( array $seat ): int => (int) $seat['id'], $live );
		$used    = count( $ordered );

		if ( self::POLICY_KEEP_ALL === $policy ) {
			$within = $ordered;
			$over   = array();
		} else {
			$within = array_slice( $ordered, 0, $quota );
			$over   = array_slice( $ordered, $quota );
		}

		return new Allocation( $quota, $used, $within, $over );
	}

	/**
	 * Priority comparator.
	 *
	 * @param array{id:int, status:string, created_at:int, accepted_at:int|null} $a Seat A.
	 * @param array{id:int, status:string, created_at:int, accepted_at:int|null} $b Seat B.
	 */
	private static function compare( array $a, array $b ): int {
		$a_accepted = SeatStatus::ACCEPTED === $a['status'];
		$b_accepted = SeatStatus::ACCEPTED === $b['status'];

		if ( $a_accepted !== $b_accepted ) {
			return $a_accepted ? -1 : 1;
		}

		$a_time = $a_accepted ? (int) ( $a['accepted_at'] ?? 0 ) : (int) $a['created_at'];
		$b_time = $b_accepted ? (int) ( $b['accepted_at'] ?? 0 ) : (int) $b['created_at'];

		return ( $a_time <=> $b_time ) ?: ( (int) $a['id'] <=> (int) $b['id'] );
	}
}
