<?php
/**
 * Libellés traduits des statuts (espace client et administration).
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Domain\SeatStatus;
use WCSubscriptionTeamAccess\Repository\Seat;

/**
 * Statut affiché d'une place : l'un des quatre statuts du cahier des charges
 * (en attente, acceptée, suspendue, révoquée), plus l'historique (annulée, expirée).
 */
final class Labels {

	/**
	 * Clé de statut affiché.
	 *
	 * @param Seat $seat Place.
	 */
	public static function display_status( Seat $seat ): string {
		if ( $seat->is_accepted() ) {
			return SeatStatus::ACCESS_ACTIVE === $seat->access ? 'accepted' : 'suspended';
		}
		return $seat->status;
	}

	/**
	 * Libellé du statut affiché.
	 *
	 * @param Seat $seat Place.
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
	 * Explication d'une suspension.
	 *
	 * @param Seat $seat Place.
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
