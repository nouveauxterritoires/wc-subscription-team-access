<?php
/**
 * Vérifie qu'après les invitations concurrentes le quota n'est pas dépassé.
 *
 * @package WCSubscriptionTeamAccess
 */


defined( 'WP_CLI' ) && WP_CLI || exit( 1 );

$fixtures = (array) json_decode( (string) file_get_contents( (string) $args[0] ), true );
$live     = \WCSubscriptionTeamAccess\Plugin::instance()->seats()->live_for_subscription( (int) $fixtures['concurrency_sub'] );

if ( 5 !== count( $live ) ) {
	WP_CLI::error( sprintf( 'Invitations concurrentes : %d places vivantes pour un quota de 5.', count( $live ) ) );
}
WP_CLI::success( 'Invitations concurrentes : quota respecté (5 places vivantes sur 5, une seule invitation acceptée).' );
