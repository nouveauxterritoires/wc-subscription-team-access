<?php
/**
 * A single invitation attempt (launched in parallel by run.sh to test concurrency).
 *
 * Usage: wp eval-file tests/integration/concurrency.php <fixtures-file.json> <n>
 *
 * @package WCSubscriptionTeamAccess
 */


defined( 'WP_CLI' ) && WP_CLI || exit( 1 );

add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

$fixtures = (array) json_decode( (string) file_get_contents( (string) $args[0] ), true );
$n        = (int) ( $args[1] ?? 0 );
$sub_id   = (int) $fixtures['concurrency_sub'];

$result = \WCSubscriptionTeamAccess\Plugin::instance()->team()->invite( $sub_id, "race{$n}-{$sub_id}@example.test", (int) $fixtures['concurrency_owner'] );
WP_CLI::log( is_wp_error( $result ) ? 'REFUSED ' . $result->get_error_code() : 'INVITED #' . $result['seat']->id );
