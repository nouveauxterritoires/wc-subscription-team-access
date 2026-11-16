<?php
/**
 * Unit test bootstrap: only the pure logic (includes/Domain) is loaded.
 *
 * The end-to-end scenarios (real WooCommerce, Subscriptions, Memberships) live in
 * tests/integration and run on a WordPress site through WP-CLI.
 *
 * @package ExprimeTeamAccess
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
