<?php
/**
 * Translated status labels (customer area and admin).
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess;

use Exprime\TeamAccess\Domain\SeatStatus;
use Exprime\TeamAccess\Repository\Seat;

/**
 * Displayed seat status: one of the four statuses shown to users
 * (pending, accepted, suspended, revoked), plus the history (cancelled, expired).
 */
final class Labels {

	/**
	 * Displayed status key.
	 *
	 * @param Seat $seat Seat.
	 */
	public static function display_status( Seat $seat ): string {
		if ( $seat->is_accepted() ) {
			return SeatStatus::ACCESS_ACTIVE === $seat->access ? 'accepted' : 'suspended';
		}
		return $seat->status;
	}

	/**
	 * Label of the displayed status.
	 *
	 * @param Seat $seat Seat.
	 */
	public static function status( Seat $seat ): string {
		$labels = array(
			'pending'   => __( 'Invitation pending', 'exprime-team-access' ),
			'accepted'  => __( 'Invitation accepted', 'exprime-team-access' ),
			'suspended' => __( 'Access suspended', 'exprime-team-access' ),
			'revoked'   => __( 'Access revoked', 'exprime-team-access' ),
			'cancelled' => __( 'Invitation cancelled', 'exprime-team-access' ),
			'expired'   => __( 'Invitation expired', 'exprime-team-access' ),
		);
		$key = self::display_status( $seat );
		return $labels[ $key ] ?? $key;
	}

	/**
	 * Explanation of a suspension.
	 *
	 * @param Seat $seat Seat.
	 */
	public static function reason( Seat $seat ): string {
		if ( ! $seat->is_accepted() || SeatStatus::ACCESS_ACTIVE === $seat->access ) {
			return '';
		}
		return match ( $seat->access_reason ) {
			SeatStatus::REASON_OVER_QUOTA            => __( 'The number of guests exceeds the subscription quota.', 'exprime-team-access' ),
			SeatStatus::REASON_SUBSCRIPTION_PAUSED   => __( 'The subscription is on hold.', 'exprime-team-access' ),
			SeatStatus::REASON_SUBSCRIPTION_INACTIVE => __( 'The subscription is no longer active.', 'exprime-team-access' ),
			SeatStatus::REASON_NO_PLAN               => __( 'The subscription does not grant any membership plan.', 'exprime-team-access' ),
			default                                  => '',
		};
	}
}
