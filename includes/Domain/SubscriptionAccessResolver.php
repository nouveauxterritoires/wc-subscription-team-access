<?php
/**
 * Traduit le statut d'un abonnement WooCommerce Subscriptions en droit d'accès effectif.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Règles (documentées dans le README) :
 *
 * - active          : accès actif, sans date de fin ;
 * - pending-cancel  : accès actif jusqu'à la date de fin de la période payée ;
 * - on-hold         : accès suspendu ;
 * - cancelled       : accès actif tant que la date de fin réelle n'est pas atteinte, nul ensuite ;
 * - autres (expired, pending, failed, switched, trash…) : aucun accès.
 */
final class SubscriptionAccessResolver {

	/**
	 * Calcule l'accès effectif.
	 *
	 * @param string $status Statut de l'abonnement, sans préfixe « wc- ».
	 * @param int    $end_ts Date de fin (horodatage UTC), 0 si aucune.
	 * @param int    $now    Instant de référence (horodatage UTC).
	 */
	public static function resolve( string $status, int $end_ts, int $now ): AccessState {
		$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		switch ( $status ) {
			case 'active':
				return AccessState::active();

			case 'pending-cancel':
				return AccessState::active( $end_ts > $now ? $end_ts : null );

			case 'on-hold':
				return AccessState::paused();

			case 'cancelled':
				return $end_ts > $now ? AccessState::active( $end_ts ) : AccessState::none();

			default:
				return AccessState::none();
		}
	}
}
