<?php
/**
 * Seed the wp-env development store.
 *
 * Run with: npm run env:seed
 * (wp-env run cli wp eval-file wp-content/plugins/edit-orders-for-woocommerce/tests/env/seed.php)
 *
 * Safe to run more than once: everything is looked up before it is created.
 * Not shipped: tests/ is excluded from the release build.
 *
 * The store is set up for the spec's functional cases:
 * - two shipping zones with different rates and two tax rates (address change, cases 10, 11, 22);
 * - a variable product with same, dearer and cheaper variations (cases 7 to 9, 23);
 * - a 20% coupon (case 8);
 * - Cash on delivery, a gateway without refunds (case 2);
 * - a customer account (customer self-service cases).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

/**
 * Print a progress line.
 *
 * @param string $message Message.
 */
$edit_orders_seed_log = static function ( $message ) {
	WP_CLI::log( '  ' . $message );
};

// Site and theme.
switch_theme( 'storefront' );
// WP-CLI's hard flush writes .htaccess; flush_rewrite_rules() can't from the CLI,
// which leaves /shop/ and every product page on 404.
WP_CLI::runcommand( "rewrite structure '/%postname%/' --hard" );
update_option( 'blogname', 'Edit Orders Dev Store' );
$edit_orders_seed_log( 'Storefront active, pretty permalinks on.' );

// WooCommerce basics: Canadian store, taxes on, no onboarding or coming-soon mode.
$edit_orders_seed_options = array(
	'woocommerce_store_address'                  => '100 King St W',
	'woocommerce_store_city'                     => 'Toronto',
	'woocommerce_default_country'                => 'CA:ON',
	'woocommerce_store_postcode'                 => 'M5X 1A9',
	'woocommerce_currency'                       => 'CAD',
	'woocommerce_calc_taxes'                     => 'yes',
	'woocommerce_prices_include_tax'             => 'no',
	'woocommerce_tax_based_on'                   => 'shipping',
	'woocommerce_shipping_tax_class'             => 'inherit',
	'woocommerce_manage_stock'                   => 'yes',
	'woocommerce_coming_soon'                    => 'no',
	'woocommerce_store_pages_only'               => 'no',
	'woocommerce_enable_guest_checkout'          => 'yes',
	'woocommerce_enable_checkout_login_reminder' => 'yes',
	'woocommerce_onboarding_profile'             => array( 'skipped' => true ),
	'woocommerce_task_list_hidden'               => 'yes',
	'woocommerce_show_marketplace_suggestions'   => 'no',
	'woocommerce_allow_tracking'                 => 'no',
);
foreach ( $edit_orders_seed_options as $edit_orders_seed_key => $edit_orders_seed_value ) {
	update_option( $edit_orders_seed_key, $edit_orders_seed_value );
}
$edit_orders_seed_log( 'Store set to Toronto, CAD, taxes on (based on shipping address).' );

// Tax rates: Ontario HST 13%, Alberta GST 5%. Looked up first so a re-run adds nothing.
global $wpdb;
$edit_orders_seed_rates = array(
	array( 'ON', '13.0000', 'HST' ),
	array( 'AB', '5.0000', 'GST' ),
);
foreach ( $edit_orders_seed_rates as $edit_orders_seed_rate ) {
	// Dev seed only: WooCommerce has no API to look up a rate by country and state.
	$edit_orders_seed_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'CA' AND tax_rate_state = %s AND tax_rate_class = ''",
			$edit_orders_seed_rate[0]
		)
	);
	if ( ! $edit_orders_seed_exists ) {
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'CA',
				'tax_rate_state'    => $edit_orders_seed_rate[0],
				'tax_rate'          => $edit_orders_seed_rate[1],
				'tax_rate_name'     => $edit_orders_seed_rate[2],
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
	}
}
$edit_orders_seed_log( 'Tax rates: ON 13% HST, AB 5% GST.' );

// Shipping zones: Ontario flat rate $10, rest of Canada flat rate $20.
$edit_orders_seed_zones          = array(
	array(
		'Ontario',
		array(
			array(
				'code' => 'CA:ON',
				'type' => 'state',
			),
		),
		'10',
	),
	array(
		'Rest of Canada',
		array(
			array(
				'code' => 'CA',
				'type' => 'country',
			),
		),
		'20',
	),
);
$edit_orders_seed_existing_zones = wp_list_pluck( WC_Shipping_Zones::get_zones(), 'zone_name' );
foreach ( $edit_orders_seed_zones as $edit_orders_seed_order => $edit_orders_seed_zone_data ) {
	if ( in_array( $edit_orders_seed_zone_data[0], $edit_orders_seed_existing_zones, true ) ) {
		continue;
	}
	$edit_orders_seed_zone = new WC_Shipping_Zone();
	$edit_orders_seed_zone->set_zone_name( $edit_orders_seed_zone_data[0] );
	$edit_orders_seed_zone->set_zone_order( $edit_orders_seed_order );
	foreach ( $edit_orders_seed_zone_data[1] as $edit_orders_seed_location ) {
		$edit_orders_seed_zone->add_location( $edit_orders_seed_location['code'], $edit_orders_seed_location['type'] );
	}
	$edit_orders_seed_zone->save();
	$edit_orders_seed_instance = $edit_orders_seed_zone->add_shipping_method( 'flat_rate' );
	update_option(
		'woocommerce_flat_rate_' . $edit_orders_seed_instance . '_settings',
		array(
			'title'      => 'Flat rate',
			'tax_status' => 'taxable',
			'cost'       => $edit_orders_seed_zone_data[2],
		)
	);
}
$edit_orders_seed_log( 'Shipping zones: Ontario $10, Rest of Canada $20.' );

// Gateways: Cash on delivery (no refunds) and Direct bank transfer. Stripe stays off until test keys are added.
foreach ( array( 'cod', 'bacs' ) as $edit_orders_seed_gateway ) {
	$edit_orders_seed_settings            = get_option( 'woocommerce_' . $edit_orders_seed_gateway . '_settings', array() );
	$edit_orders_seed_settings['enabled'] = 'yes';
	update_option( 'woocommerce_' . $edit_orders_seed_gateway . '_settings', $edit_orders_seed_settings );
}
$edit_orders_seed_log( 'Gateways: Cash on delivery and Direct bank transfer enabled.' );

/**
 * Create a simple product unless one with this SKU exists.
 *
 * @param string $name  Name.
 * @param string $sku   SKU.
 * @param string $price Regular price.
 * @param int    $stock Stock quantity.
 * @return int Product ID.
 */
$edit_orders_seed_simple = static function ( $name, $sku, $price, $stock ) {
	$id = wc_get_product_id_by_sku( $sku );
	if ( $id ) {
		return $id;
	}
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_sku( $sku );
	$product->set_regular_price( $price );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$product->set_status( 'publish' );
	return $product->save();
};

$edit_orders_seed_simple( 'T-Shirt', 'EO-TSHIRT', '20', 50 );
$edit_orders_seed_simple( 'Mug', 'EO-MUG', '12', 50 );
$edit_orders_seed_simple( 'Poster', 'EO-POSTER', '8', 50 );

// Variable product: S and M cost the same (same-price swap), L costs more, XS costs less.
if ( ! wc_get_product_id_by_sku( 'EO-HOODIE' ) ) {
	$edit_orders_seed_attribute = new WC_Product_Attribute();
	$edit_orders_seed_attribute->set_name( 'Size' );
	$edit_orders_seed_attribute->set_options( array( 'XS', 'S', 'M', 'L' ) );
	$edit_orders_seed_attribute->set_visible( true );
	$edit_orders_seed_attribute->set_variation( true );

	$edit_orders_seed_hoodie = new WC_Product_Variable();
	$edit_orders_seed_hoodie->set_name( 'Hoodie' );
	$edit_orders_seed_hoodie->set_sku( 'EO-HOODIE' );
	$edit_orders_seed_hoodie->set_attributes( array( $edit_orders_seed_attribute ) );
	$edit_orders_seed_hoodie->set_status( 'publish' );
	$edit_orders_seed_hoodie_id = $edit_orders_seed_hoodie->save();

	foreach ( array(
		'XS' => '35',
		'S'  => '40',
		'M'  => '40',
		'L'  => '45',
	) as $edit_orders_seed_size => $edit_orders_seed_price ) {
		$edit_orders_seed_variation = new WC_Product_Variation();
		$edit_orders_seed_variation->set_parent_id( $edit_orders_seed_hoodie_id );
		$edit_orders_seed_variation->set_attributes( array( 'size' => $edit_orders_seed_size ) );
		$edit_orders_seed_variation->set_sku( 'EO-HOODIE-' . $edit_orders_seed_size );
		$edit_orders_seed_variation->set_regular_price( $edit_orders_seed_price );
		$edit_orders_seed_variation->set_manage_stock( true );
		$edit_orders_seed_variation->set_stock_quantity( 20 );
		$edit_orders_seed_variation->save();
	}
	WC_Product_Variable::sync( $edit_orders_seed_hoodie_id );
}
$edit_orders_seed_log( 'Products: T-Shirt $20, Mug $12, Poster $8, Hoodie XS $35 / S $40 / M $40 / L $45 (stock managed).' );

// A 20% coupon for the coupon-ratio case.
if ( ! wc_get_coupon_id_by_code( 'save20' ) ) {
	$edit_orders_seed_coupon = new WC_Coupon();
	$edit_orders_seed_coupon->set_code( 'save20' );
	$edit_orders_seed_coupon->set_discount_type( 'percent' );
	$edit_orders_seed_coupon->set_amount( 20 );
	$edit_orders_seed_coupon->save();
}
$edit_orders_seed_log( 'Coupon: save20 (20% off).' );

// A customer account with an Ontario address.
if ( ! get_user_by( 'login', 'customer' ) ) {
	$edit_orders_seed_customer = new WC_Customer();
	$edit_orders_seed_customer->set_username( 'customer' );
	$edit_orders_seed_customer->set_password( 'password' );
	$edit_orders_seed_customer->set_email( 'customer@example.com' );
	$edit_orders_seed_customer->set_first_name( 'Casey' );
	$edit_orders_seed_customer->set_last_name( 'Customer' );
	$edit_orders_seed_address = array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '1 Yonge St',
		'city'       => 'Toronto',
		'state'      => 'ON',
		'postcode'   => 'M5E 1E5',
		'country'    => 'CA',
	);
	foreach ( $edit_orders_seed_address as $edit_orders_seed_field => $edit_orders_seed_field_value ) {
		$edit_orders_seed_customer->{"set_billing_{$edit_orders_seed_field}"}( $edit_orders_seed_field_value );
		$edit_orders_seed_customer->{"set_shipping_{$edit_orders_seed_field}"}( $edit_orders_seed_field_value );
	}
	$edit_orders_seed_customer->set_billing_email( 'customer@example.com' );
	$edit_orders_seed_customer->save();
}
$edit_orders_seed_log( 'Customer: customer / password (Toronto, ON).' );

// HPOS on by default, as for new WooCommerce stores. Only switched while the store has
// no orders, so a re-run never forces a data sync. Test with HPOS off by running
// `wp wc hpos disable` (spec section 9).
$edit_orders_seed_order_ids = wc_get_orders(
	array(
		'limit'  => 1,
		'return' => 'ids',
	)
);
if ( ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() && empty( $edit_orders_seed_order_ids ) ) {
	WP_CLI::runcommand( 'wc hpos enable' );
}

// Read the stored option: OrderUtil caches the earlier answer for this request.
WP_CLI::success( 'Dev store seeded. HPOS: ' . ( 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) ? 'on' : 'off' ) . '.' );
