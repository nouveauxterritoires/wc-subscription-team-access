<?php
/**
 * Creates the durable fixtures of the integration suite: Memberships plan and test products.
 * (Run in a separate process: Memberships caches the plan list.)
 *
 * Usage: wp eval-file tests/integration/setup.php <fixtures-file.json>
 *
 * @package WCSubscriptionTeamAccess
 */


defined( 'WP_CLI' ) && WP_CLI || exit( 1 );

$file = $args[0] ?? '';
if ( '' === $file ) {
	WP_CLI::error( 'Missing fixtures file.' );
}

/**
 * Simple subscription product.
 *
 * @param string      $name   Name.
 * @param string|null $guests Quota.
 */
$make_simple = static function ( string $name, ?string $guests ): int {
	$product = new WC_Product_Subscription();
	$product->set_name( 'WCSTA IT ' . $name );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->update_meta_data( '_subscription_price', '10' );
	$product->update_meta_data( '_subscription_period', 'month' );
	$product->update_meta_data( '_subscription_period_interval', '1' );
	$product->update_meta_data( '_subscription_length', '0' );
	if ( null !== $guests ) {
		$product->update_meta_data( '_wcsta_max_guests', $guests );
	}
	return $product->save();
};

$p0 = $make_simple( 'Individual (0 guests)', '0' );
$p5 = $make_simple( 'Team (5 guests)', '5' );

// Variable subscription: parent = 10 guests; variation A = 2; variation B inherits from the parent.
$parent    = new WC_Product_Variable_Subscription();
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Plan' );
$attribute->set_options( array( 'Small', 'Large' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$parent->set_name( 'WCSTA IT Variable' );
$parent->set_status( 'publish' );
$parent->set_catalog_visibility( 'hidden' );
$parent->set_attributes( array( $attribute ) );
$parent->update_meta_data( '_wcsta_max_guests', '10' );
$pv = $parent->save();

$make_variation = static function ( int $parent_id, string $option, ?string $guests ): int {
	$variation = new WC_Product_Subscription_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_attributes( array( 'plan' => $option ) );
	$variation->set_regular_price( '20' );
	$variation->set_virtual( true );
	$variation->set_status( 'publish' );
	$variation->update_meta_data( '_subscription_price', '20' );
	$variation->update_meta_data( '_subscription_period', 'month' );
	$variation->update_meta_data( '_subscription_period_interval', '1' );
	$variation->update_meta_data( '_subscription_length', '0' );
	if ( null !== $guests ) {
		$variation->update_meta_data( '_wcsta_max_guests', $guests );
	}
	return $variation->save();
};
$va = $make_variation( $pv, 'Small', '2' );
$vb = $make_variation( $pv, 'Large', null );
WC_Product_Variable::sync( $pv );

$plan_id = wp_insert_post(
	array(
		'post_type'   => 'wc_membership_plan',
		'post_status' => 'publish',
		'post_title'  => 'WCSTA IT Plan',
		'post_name'   => 'wcsta-it-plan',
	)
);
update_post_meta( $plan_id, '_access_method', 'purchase' );
update_post_meta( $plan_id, '_product_ids', array( $p0, $p5, $pv, $va, $vb ) );
update_post_meta( $plan_id, '_access_length', '' );

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

$fixtures = array(
	'plan_id'   => (int) $plan_id,
	'p0'        => (int) $p0,
	'p5'        => (int) $p5,
	'pv'        => (int) $pv,
	'va'        => (int) $va,
	'vb'        => (int) $vb,
	'admin_id'  => (int) ( $admins[0] ?? 0 ),
	'created_at' => time(),
);

file_put_contents( $file, (string) wp_json_encode( $fixtures, JSON_PRETTY_PRINT ) );
WP_CLI::success( 'Fixtures: ' . wp_json_encode( $fixtures ) );
