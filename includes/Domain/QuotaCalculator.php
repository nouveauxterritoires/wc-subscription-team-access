<?php
/**
 * Computes a subscription's guest quota from its line items.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Rule: sum, for each line, of the product (or variation) quota multiplied
 * by the quantity. A variation without its own value inherits the parent product's value.
 */
final class QuotaCalculator {

	/**
	 * Total quota.
	 *
	 * @param array<int, array{quantity:int, own:?int, parent:?int}> $lines Lines: own quota (variation or simple product), parent quota, quantity.
	 */
	public static function total( array $lines ): int {
		$total = 0;
		foreach ( $lines as $line ) {
			$per_unit = $line['own'] ?? $line['parent'] ?? 0;
			$total   += max( 0, (int) $per_unit ) * max( 0, (int) $line['quantity'] );
		}
		return $total;
	}

	/**
	 * Converts a raw meta value into a quota (null = not set).
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function parse( mixed $raw ): ?int {
		if ( null === $raw || '' === $raw || false === $raw ) {
			return null;
		}
		if ( is_numeric( $raw ) ) {
			return max( 0, (int) $raw );
		}
		return null;
	}
}
