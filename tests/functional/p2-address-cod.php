<?php
/**
 * Functional suite for prototype P2: address changes (spec section 9, cases 10, 11 and
 * the money side of 22) and pay-on-delivery orders (decision of 2026-10-06: edits to an
 * unpaid Cash on delivery order change the amount to collect).
 *
 * Run with: bash tests/functional/run.sh (all suites, HPOS on, then off).
 *
 * Seeded store: Ontario 13% HST (zone Ontario, $10 flat rate); Alberta 5% GST and
 * British Columbia with no tax rate (zone Rest of Canada, $20 flat rate).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', '__return_false' );

$eo_t = array(
	'pass'    => 0,
	'fail'    => 0,
	'orders'  => array(),
	'stock'   => array(),
	'product' => array(),
);

$eo_check = static function ( $label, $condition, $detail = '' ) use ( &$eo_t ) {
	if ( $condition ) {
		++$eo_t['pass'];
		WP_CLI::log( "  PASS  {$label}" );
	} else {
		++$eo_t['fail'];
		WP_CLI::log( "  FAIL  {$label}" . ( '' !== $detail ? " ({$detail})" : '' ) );
	}
};

$eo_money = static function ( $amount ) {
	return wc_format_decimal( $amount, 2 );
};

foreach ( array( 'EO-TSHIRT', 'EO-MUG' ) as $eo_sku ) {
	$eo_id = wc_get_product_id_by_sku( $eo_sku );
	if ( ! $eo_id ) {
		WP_CLI::error( "Product {$eo_sku} missing. Run tests/env/seed.php first." );
	}
	$eo_t['product'][ $eo_sku ] = $eo_id;
	$eo_t['stock'][ $eo_id ]    = wc_get_product( $eo_id )->get_stock_quantity();
}

$eo_stock = static function ( $sku ) use ( &$eo_t ) {
	return (int) wc_get_product( $eo_t['product'][ $sku ] )->get_stock_quantity();
};

$eo_addresses = array(
	'ON'  => array(
		'address_1' => '1 Yonge St',
		'city'      => 'Toronto',
		'state'     => 'ON',
		'postcode'  => 'M5E 1E5',
		'country'   => 'CA',
	),
	'ON2' => array(
		'address_1' => '50 King St',
		'city'      => 'Ottawa',
		'state'     => 'ON',
		'postcode'  => 'K1P 5N2',
		'country'   => 'CA',
	),
	'AB'  => array(
		'address_1' => '100 8 Ave SW',
		'city'      => 'Calgary',
		'state'     => 'AB',
		'postcode'  => 'T2P 1B3',
		'country'   => 'CA',
	),
	'BC'  => array(
		'address_1' => '800 Robson St',
		'city'      => 'Vancouver',
		'state'     => 'BC',
		'postcode'  => 'V6Z 3B7',
		'country'   => 'CA',
	),
);

// Tax rate IDs from the seed.
$eo_rate = static function ( $state ) {
	$rates = WC_Tax::find_rates(
		array(
			'country'   => 'CA',
			'state'     => $state,
			'postcode'  => '',
			'city'      => '',
			'tax_class' => '',
		)
	);
	return (int) key( $rates );
};
$eo_hst  = $eo_rate( 'ON' );
$eo_gst  = $eo_rate( 'AB' );

/**
 * Create an order at an address with a flat-rate line priced for its zone.
 * Paid through the test gateway, or left unpaid Processing for Cash on delivery.
 */
$eo_make_order = static function ( array $lines, $place, $gateway = 'edit_orders_test' ) use ( &$eo_t, $eo_addresses ) {
	$order   = wc_create_order( array( 'customer_id' => 0 ) );
	$address = array_merge(
		array(
			'first_name' => 'Casey',
			'last_name'  => 'Customer',
			'email'      => 'customer@example.com',
		),
		$eo_addresses[ $place ]
	);
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );

	foreach ( $lines as $line ) {
		$order->add_product( wc_get_product( $eo_t['product'][ $line[0] ] ), $line[1] );
	}

	// The flat rate of the zone the address falls in, as checkout would have charged.
	$zone     = WC_Shipping_Zones::get_zone_matching_package(
		array(
			'destination' => array(
				'country'  => 'CA',
				'state'    => $eo_addresses[ $place ]['state'],
				'postcode' => $eo_addresses[ $place ]['postcode'],
			),
		)
	);
	$method   = current( $zone->get_shipping_methods( true ) );
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Flat rate' );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_instance_id( $method->get_instance_id() );
	$shipping->set_total( $method->get_option( 'cost' ) );
	$order->add_item( $shipping );

	$order->calculate_totals();

	$gateways = WC()->payment_gateways()->payment_gateways();
	$order->set_payment_method( $gateways[ $gateway ] );
	$order->save();

	if ( 'cod' === $gateway ) {
		// What the COD gateway does at checkout: Processing, unpaid, stock reduced.
		$order->update_status( 'processing', 'Payment to be made upon delivery.' );
	} else {
		$order->payment_complete( 'TEST-' . $order->get_id() );
	}

	$eo_t['orders'][] = $order->get_id();

	return wc_get_order( $order->get_id() );
};

$eo_item_for = static function ( WC_Order $order, $sku ) use ( &$eo_t ) {
	foreach ( $order->get_items() as $item ) {
		if ( (int) $item->get_product_id() === $eo_t['product'][ $sku ] ) {
			return $item;
		}
	}
	return null;
};

$eo_pay = static function ( $balance_id ) {
	$balance = wc_get_order( $balance_id );
	$balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$balance->save();
	$balance->payment_complete( 'TEST-BAL-' . $balance_id );
	return wc_get_order( $balance_id );
};

$eo_address_change = static function ( WC_Order $order, $place ) use ( $eo_addresses ) {
	return Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
		$order,
		array(
			array(
				'type'     => 'address',
				'shipping' => $eo_addresses[ $place ],
				'billing'  => $eo_addresses[ $place ],
			),
		)
	);
};

$eo_error = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_message() : '';
};

WP_CLI::log( 'HPOS ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . ", HST rate {$eo_hst}, GST rate {$eo_gst}" );

// ---------------------------------------------------------------------------
WP_CLI::log( 'COD 1: quantity down on an unpaid Cash on delivery order lowers the amount to collect' );
$eo_before = $eo_stock( 'EO-TSHIRT' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ), 'ON', 'cod' );
$eo_check( 'order is Processing and unpaid', 'processing' === $eo_order->get_status() && ! $eo_order->get_date_paid() );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_for( $eo_order, 'EO-TSHIRT' )->get_id(),
			'quantity' => 1,
		),
	)
);
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_refund = current( $eo_order->get_refunds() );
$eo_check( 'applied; refund recorded 45.20 without payment', is_array( $eo_result ) && $eo_refund && '45.20' === $eo_money( $eo_refund->get_amount() ) && ! $eo_refund->get_refunded_payment(), $eo_error( $eo_result ) );
$eo_check( 'no manual refund flag', '' === $eo_order->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) && ! empty( $eo_result['refund']['on_delivery'] ) );
$eo_notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $eo_order->get_id() ) ), 'content' );
$eo_check( 'note: collect 33.90 on delivery', (bool) preg_grep( '/collect on delivery is now.*33\.90/', $eo_notes ) );
$eo_check( 'stock restored by 2', $eo_stock( 'EO-TSHIRT' ) === $eo_before - 1 );

WP_CLI::log( 'COD 2: quantity up on an unpaid Cash on delivery order adds a linked COD order, applied now' );
$eo_before = $eo_stock( 'EO-TSHIRT' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ), 'ON', 'cod' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_for( $eo_order, 'EO-TSHIRT' )->get_id(),
			'quantity' => 3,
		),
	)
);
$eo_linked = is_array( $eo_result ) && ! empty( $eo_result['balance_order_id'] ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
if ( $eo_linked ) {
	$eo_t['orders'][] = $eo_linked->get_id();
}
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'status collect_on_delivery, no pay link', is_array( $eo_result ) && 'collect_on_delivery' === $eo_result['status'] && empty( $eo_result['pay_url'] ), $eo_error( $eo_result ) );
$eo_check( 'linked order: Cash on delivery, Processing, 45.20, applied', $eo_linked && 'cod' === $eo_linked->get_payment_method() && 'processing' === $eo_linked->get_status() && '45.20' === $eo_money( $eo_linked->get_total() ) && $eo_linked->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_META ) );
$eo_check( 'stock for the 2 extra units taken', $eo_stock( 'EO-TSHIRT' ) === $eo_before - 3 );
$eo_check( 'original order free for new edits', null === Edit_Orders_For_WooCommerce_Balance_Orders::get_open_balance_order( $eo_order ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 10: Ontario to Alberta: dearer shipping, tax moves from HST to GST, balance order' );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ), 'ON' );
$eo_check( 'order paid: 33.90', '33.90' === $eo_money( $eo_order->get_total() ), $eo_order->get_total() );
$eo_result  = $eo_address_change( $eo_order, 'AB' );
$eo_balance = is_array( $eo_result ) && ! empty( $eo_result['balance_order_id'] ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
if ( $eo_balance ) {
	$eo_t['orders'][] = $eo_balance->get_id();
}
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'balance due: 12.00 (shipping +10, GST 1.00 + 1.00)', $eo_balance && '12.00' === $eo_money( $eo_balance->get_total() ), $eo_balance ? $eo_balance->get_total() : $eo_error( $eo_result ) );
$eo_check( 'balance taxes are GST only', $eo_balance && '2.00' === $eo_money( $eo_balance->get_total_tax() ) && 1 === count( $eo_balance->get_taxes() ) && (int) current( $eo_balance->get_taxes() )->get_rate_id() === $eo_gst );
$eo_check( 'balance order is addressed to Alberta', $eo_balance && 'AB' === $eo_balance->get_shipping_state() );
$eo_check( 'original unchanged until paid: Ontario, no refunds', 'ON' === $eo_order->get_shipping_state() && 0.0 === (float) $eo_order->get_total_refunded() );
if ( $eo_balance ) {
	$eo_pay( $eo_balance->get_id() );
}
$eo_order    = wc_get_order( $eo_order->get_id() );
$eo_shipping = current( $eo_order->get_shipping_methods() );
$eo_check( 'after payment: HST 3.90 refunded through the gateway', '3.90' === $eo_money( $eo_order->get_total_refunded() ) && '3.90' === $eo_money( $eo_order->get_meta( '_edit_orders_test_refunded' ) ), $eo_order->get_total_refunded() );
$eo_check( 'HST refunded by rate: 3.90', '3.90' === $eo_money( $eo_order->get_total_tax_refunded_by_rate_id( $eo_hst ) ) );
$eo_check( 'address now Alberta (shipping and billing)', 'AB' === $eo_order->get_shipping_state() && 'AB' === $eo_order->get_billing_state() );
$eo_check( 'shipping line points at the Alberta zone method, totals unchanged', $eo_shipping && '10.00' === $eo_money( $eo_shipping->get_total() ) && (int) $eo_shipping->get_instance_id() !== 0 );
$eo_check( 'net paid equals the Alberta price: 33.90 + 12.00 - 3.90 = 42.00', '42.00' === $eo_money( (float) $eo_order->get_total() + (float) $eo_balance->get_total() - (float) $eo_order->get_total_refunded() ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 11: Alberta to British Columbia: same shipping, no tax there, tax-only refund now' );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ), 'AB' );
$eo_check( 'order paid: 42.00', '42.00' === $eo_money( $eo_order->get_total() ), $eo_order->get_total() );
$eo_result = $eo_address_change( $eo_order, 'BC' );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'applied at once, no balance', is_array( $eo_result ) && 'applied' === $eo_result['status'], $eo_error( $eo_result ) );
$eo_check( 'refunded 2.00, all of it tax (GST)', '2.00' === $eo_money( $eo_order->get_total_refunded() ) && '2.00' === $eo_money( $eo_order->get_total_tax_refunded() ) && '2.00' === $eo_money( $eo_order->get_total_tax_refunded_by_rate_id( $eo_gst ) ) );
$eo_check( 'gateway refunded 2.00', '2.00' === $eo_money( $eo_order->get_meta( '_edit_orders_test_refunded' ) ) );
$eo_check( 'no units refunded', 0 === (int) $eo_order->get_qty_refunded_for_item( $eo_item_for( $eo_order, 'EO-TSHIRT' )->get_id() ) );
$eo_check( 'address now British Columbia', 'BC' === $eo_order->get_shipping_state() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 10b: Alberta to Ontario: cheaper shipping, GST out, HST in, netted into one refund' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ), 'AB' );
$eo_result = $eo_address_change( $eo_order, 'ON' );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'applied at once', is_array( $eo_result ) && 'applied' === $eo_result['status'], $eo_error( $eo_result ) );
$eo_check( 'one refund of 8.10 (shipping 10 + GST 2.00 - HST 3.90) through the gateway', 1 === count( $eo_order->get_refunds() ) && '8.10' === $eo_money( $eo_order->get_total_refunded() ) && '8.10' === $eo_money( $eo_order->get_meta( '_edit_orders_test_refunded' ) ), $eo_order->get_total_refunded() );
// Read the refund's own tax lines: WC_Order::get_total_tax_refunded_by_rate_id() wraps
// each amount in abs(), so it reports the HST charged here as refunded.
$eo_refund  = current( $eo_order->get_refunds() );
$eo_by_rate = array();
foreach ( $eo_refund ? $eo_refund->get_items( 'tax' ) : array() as $eo_tax_line ) {
	$eo_by_rate[ (int) $eo_tax_line->get_rate_id() ] = (float) $eo_tax_line->get_tax_total() + (float) $eo_tax_line->get_shipping_tax_total();
}
$eo_check(
	'refund tax lines: GST -2.00 (refunded), HST +3.90 (charged)',
	isset( $eo_by_rate[ $eo_gst ], $eo_by_rate[ $eo_hst ] ) && '-2.00' === $eo_money( $eo_by_rate[ $eo_gst ] ) && '3.90' === $eo_money( $eo_by_rate[ $eo_hst ] ),
	wp_json_encode( $eo_by_rate )
);
// WooCommerce Analytics (the tax report) books the same signed amounts.
if ( $eo_refund && class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Taxes\DataStore' ) ) {
	global $wpdb;
	\Automattic\WooCommerce\Admin\API\Reports\Taxes\DataStore::sync_order_taxes( $eo_order->get_id() );
	\Automattic\WooCommerce\Admin\API\Reports\Taxes\DataStore::sync_order_taxes( $eo_refund->get_id() );
	$eo_net = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT tax_rate_id, SUM(total_tax) AS total FROM {$wpdb->prefix}wc_order_tax_lookup WHERE order_id IN (%d, %d) GROUP BY tax_rate_id",
			$eo_order->get_id(),
			$eo_refund->get_id()
		),
		OBJECT_K
	);
	$eo_check(
		'tax report nets to GST 0.00 and HST 3.90 (an Ontario delivery)',
		isset( $eo_net[ $eo_gst ], $eo_net[ $eo_hst ] ) && '0.00' === $eo_money( $eo_net[ $eo_gst ]->total ) && '3.90' === $eo_money( $eo_net[ $eo_hst ]->total ),
		wp_json_encode( $eo_net )
	);
}
$eo_check( 'net paid equals the Ontario price: 42.00 - 8.10 = 33.90', '33.90' === $eo_money( (float) $eo_order->get_total() - (float) $eo_order->get_total_refunded() ) );
$eo_check( 'address now Ontario', 'ON' === $eo_order->get_shipping_state() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Same zone, same tax (Toronto to Ottawa): address changes, no money moves' );
$eo_order  = $eo_make_order( array( array( 'EO-MUG', 1 ) ), 'ON' );
$eo_result = $eo_address_change( $eo_order, 'ON2' );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'applied, no refund, no balance', is_array( $eo_result ) && 'applied' === $eo_result['status'] && 0.0 === (float) $eo_order->get_total_refunded(), $eo_error( $eo_result ) );
$eo_check( 'address now Ottawa', 'Ottawa' === $eo_order->get_shipping_city() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Refusals' );
$eo_order  = $eo_make_order( array( array( 'EO-MUG', 2 ) ), 'ON' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'     => 'address',
			'shipping' => array( 'state' => 'ZZ' ),
		),
	)
);
$eo_check( 'invalid province refused', is_wp_error( $eo_result ) && false !== strpos( $eo_result->get_error_message(), 'state or province' ), $eo_error( $eo_result ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'     => 'address',
			'shipping' => $eo_addresses['AB'],
		),
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_for( $eo_order, 'EO-MUG' )->get_id(),
			'quantity' => 1,
		),
	)
);
$eo_check( 'address mixed with other changes refused', is_wp_error( $eo_result ) && false !== strpos( $eo_result->get_error_message(), 'on its own' ), $eo_error( $eo_result ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Cleanup' );
foreach ( array_unique( $eo_t['orders'] ) as $eo_order_id ) {
	$eo_order = wc_get_order( $eo_order_id );
	if ( ! $eo_order ) {
		continue;
	}
	foreach ( $eo_order->get_refunds() as $eo_refund ) {
		$eo_refund->delete( true );
	}
	$eo_order->delete( true );
}
foreach ( $eo_t['stock'] as $eo_product_id => $eo_quantity ) {
	wc_update_product_stock( $eo_product_id, $eo_quantity, 'set' );
}
$eo_left = wc_get_orders(
	array(
		'limit'  => -1,
		'return' => 'ids',
		'status' => 'any',
	)
);
$eo_check( 'no test orders left behind', empty( array_intersect( $eo_left, $eo_t['orders'] ) ) );

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'P2 suite failed.' );
}
WP_CLI::success( 'P2 suite passed.' );
