<?php
/**
 * Combines several entitlement sources for the same user and the same plan.
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

namespace Exprime\TeamAccess\Domain;

/**
 * Rule: the most favourable state wins (active > paused > none).
 * Among several active sources, the latest end wins, and a source
 * with no end date makes access unlimited.
 */
final class AccessAggregator {

	/**
	 * Aggregates a list of states.
	 *
	 * @param AccessState[] $states States from each source.
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
