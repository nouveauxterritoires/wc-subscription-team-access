<?php
/**
 * Checks that the quota is not exceeded after the concurrent invitations.
 *
 * @package ExprimeTeamAccess
 */


defined( 'WP_CLI' ) && WP_CLI || exit( 1 );

$fixtures = (array) json_decode( (string) file_get_contents( (string) $args[0] ), true );
$live     = \Exprime\TeamAccess\Plugin::instance()->seats()->live_for_subscription( (int) $fixtures['concurrency_sub'] );

if ( 5 !== count( $live ) ) {
	WP_CLI::error( sprintf( 'Concurrent invitations: %d live seats for a quota of 5.', count( $live ) ) );
}
WP_CLI::success( 'Concurrent invitations: quota respected (5 live seats out of 5, only one invitation accepted).' );
