<?php
/**
 * Calcule le quota d'invités d'un abonnement à partir de ses lignes.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess\Domain;

/**
 * Règle : somme, pour chaque ligne, du quota du produit (ou de la variation) multiplié
 * par la quantité. Une variation sans valeur propre hérite de la valeur du produit parent.
 */
final class QuotaCalculator {

	/**
	 * Quota total.
	 *
	 * @param array<int, array{quantity:int, own:?int, parent:?int}> $lines Lignes : quota propre (variation ou produit simple), quota du parent, quantité.
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
	 * Convertit une méta brute en quota (null = non renseigné).
	 *
	 * @param mixed $raw Valeur brute.
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
