<?php
/**
 * Translates a WooCommerce Subscriptions subscription status into an effective access entitlement.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Rules (documented in the README):
 *
 * - active          : active access, no end date;
 * - pending-cancel  : active access until the end of the paid period, none afterwards;
 * - on-hold         : suspended access;
 * - cancelled       : active access until the actual end date is reached, none afterwards;
 * - others (expired, pending, failed, switched, trash…) : no access.
 */
final class SubscriptionAccessResolver {

	/**
	 * Computes the effective access.
	 *
	 * @param string $status Subscription status, without the "wc-" prefix.
	 * @param int    $end_ts End date (UTC timestamp), 0 if none.
	 * @param int    $now    Reference time (UTC timestamp).
	 */
	public static function resolve( string $status, int $end_ts, int $now ): AccessState {
		$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		switch ( $status ) {
			case 'active':
				return AccessState::active();

			case 'pending-cancel':
				// Paid period end passed: access stops, without waiting for the switch to "cancelled".
				// With no known end date (abnormal case), access follows the owner's, which is still active.
				if ( $end_ts > 0 && $end_ts <= $now ) {
					return AccessState::none();
				}
				return AccessState::active( $end_ts > 0 ? $end_ts : null );

			case 'on-hold':
				return AccessState::paused();

			case 'cancelled':
				return $end_ts > $now ? AccessState::active( $end_ts ) : AccessState::none();

			default:
				return AccessState::none();
		}
	}
}
