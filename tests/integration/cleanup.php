<?php
/**
 * Supprime toutes les données de la suite d'intégration.
 *
 * Usage : wp eval-file tests/integration/cleanup.php <fichier-fixtures.json>
 *
 * @package WCSubscriptionTeamAccess
 */


defined( 'WP_CLI' ) && WP_CLI || exit( 1 );

require_once __DIR__ . '/lib.php';

$file     = (string) ( $args[0] ?? '' );
$fixtures = is_readable( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();

global $wpdb;

$t                 = new WCSTA_IT();
$t->created_users  = array_map( 'intval', (array) ( $fixtures['created_users'] ?? array() ) );
$t->created_orders = array_map( 'intval', (array) ( $fixtures['created_orders'] ?? array() ) );

// Comptes de test créés hors suivi (ex. acceptation avec création de compte, concurrence).
$extra = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE %s", $wpdb->esc_like( WCSTA_IT::PREFIX ) . '%' ) ); // phpcs:ignore
$t->created_users = array_merge( $t->created_users, array_map( 'intval', $extra ) );

// Abonnements et commandes de ces comptes.
foreach ( array_unique( $t->created_users ) as $user_id ) {
	foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
		$t->created_orders[] = $subscription->get_id();
		foreach ( $subscription->get_related_orders() as $order_id ) {
			$t->created_orders[] = (int) $order_id;
		}
	}
	foreach ( wc_get_orders( array( 'customer_id' => $user_id, 'limit' => -1, 'return' => 'ids' ) ) as $order_id ) {
		$t->created_orders[] = (int) $order_id;
	}
}

$t->cleanup_created();

foreach ( array( 'vb', 'va', 'pv', 'p5', 'p0' ) as $key ) {
	if ( ! empty( $fixtures[ $key ] ) && ( $product = wc_get_product( (int) $fixtures[ $key ] ) ) ) {
		$product->delete( true );
	}
}
if ( ! empty( $fixtures['plan_id'] ) ) {
	wp_delete_post( (int) $fixtures['plan_id'], true );
}

// Journal de la suite.
$wpdb->query( "DELETE l FROM {$wpdb->prefix}wcsta_log l LEFT JOIN {$wpdb->prefix}wcsta_seats s ON s.subscription_id = l.subscription_id WHERE l.subscription_id > 0 AND s.id IS NULL" ); // phpcs:ignore

if ( is_file( $file ) ) {
	unlink( $file );
}
WP_CLI::success( sprintf( 'Nettoyage : %d compte(s), %d commande(s)/abonnement(s).', count( array_unique( $t->created_users ) ), count( array_unique( $t->created_orders ) ) ) );
