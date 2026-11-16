<?php
/**
 * Translated status labels (customer area and admin).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\SeatStatus;
use WCSubscriptionTeamAccess\Repository\Seat;

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
			'pending'   => __( 'Invitation pending', 'wc-subscription-team-access' ),
			'accepted'  => __( 'Invitation accepted', 'wc-subscription-team-access' ),
			'suspended' => __( 'Access suspended', 'wc-subscription-team-access' ),
			'revoked'   => __( 'Access revoked', 'wc-subscription-team-access' ),
			'cancelled' => __( 'Invitation cancelled', 'wc-subscription-team-access' ),
			'expired'   => __( 'Invitation expired', 'wc-subscription-team-access' ),
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
			SeatStatus::REASON_OVER_QUOTA            => __( 'The number of guests exceeds the subscription quota.', 'wc-subscription-team-access' ),
			SeatStatus::REASON_SUBSCRIPTION_PAUSED   => __( 'The subscription is on hold.', 'wc-subscription-team-access' ),
			SeatStatus::REASON_SUBSCRIPTION_INACTIVE => __( 'The subscription is no longer active.', 'wc-subscription-team-access' ),
			SeatStatus::REASON_NO_PLAN               => __( 'The subscription does not grant any membership plan.', 'wc-subscription-team-access' ),
			default                                  => '',
		};
	}
}
