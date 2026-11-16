<?php
/**
 * Combine plusieurs sources de droits pour un même utilisateur et un même plan.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Règle : l'état le plus favorable l'emporte (actif > en pause > nul).
 * Entre plusieurs sources actives, la fin la plus tardive l'emporte, et une source
 * sans date de fin rend l'accès illimité.
 */
final class AccessAggregator {

	/**
	 * Agrège une liste d'états.
	 *
	 * @param AccessState[] $states États issus de chaque source.
	 */
	public static function aggregate( array $states ): AccessState {
		$has_paused = false;
		$has_active = false;
		$unlimited  = false;
		$max_end    = 0;

		foreach ( $states as $state ) {
			if ( $state->is_active() ) {
				$has_active = true;
				if ( null === $state->end_ts ) {
					$unlimited = true;
				} else {
					$max_end = max( $max_end, $state->end_ts );
				}
			} elseif ( $state->is_paused() ) {
				$has_paused = true;
			}
		}

		if ( $has_active ) {
			return AccessState::active( $unlimited ? null : $max_end );
		}

		return $has_paused ? AccessState::paused() : AccessState::none();
	}
}
