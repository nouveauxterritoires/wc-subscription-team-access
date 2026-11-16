<?php
/**
 * Amorçage des tests unitaires : seule la logique pure (includes/Domain) est chargée.
 *
 * Les scénarios bout en bout (WooCommerce, Subscriptions, Memberships réels) sont dans
 * tests/integration et s'exécutent sur un site WordPress via WP-CLI.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
