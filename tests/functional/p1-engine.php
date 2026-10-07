<?php
/**
 * Functional suite for prototype P1: the settlement engine (spec section 9, cases 1 to 9,
 * plus the refusal and fallback checks the engine already covers).
 *
 * Run with: bash tests/functional/run-p1.sh (HPOS on, then off).
 * Or once: wp-env run cli wp eval-file wp-content/plugins/edit-orders-for-woocommerce/tests/functional/p1-engine.php
 *
 * Needs the seeded wp-env store (tests/env/seed.php) and the test gateway mu-plugin.
 * Every order it creates is deleted and every stock level restored at the end.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

// No mail server in wp-env; keep WooCommerce's emails from erroring.
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

// Products from the seed, by SKU.
foreach ( array( 'EO-TSHIRT', 'EO-MUG', 'EO-POSTER', 'EO-HOODIE-XS', 'EO-HOODIE-S', 'EO-HOODIE-M', 'EO-HOODIE-L' ) as $eo_sku ) {
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

$eo_customer = get_user_by( 'login', 'customer' );

/**
 * Create a paid order: lines of array( sku, qty ), Ontario address, $10 flat rate.
 */
$eo_make_order = static function ( array $lines, $gateway = 'edit_orders_test', $coupon = '' ) use ( &$eo_t, $eo_customer ) {
	$order   = wc_create_order( array( 'customer_id' => $eo_customer ? $eo_customer->ID : 0 ) );
	$address = array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '1 Yonge St',
		'city'       => 'Toronto',
		'state'      => 'ON',
		'postcode'   => 'M5E 1E5',
		'country'    => 'CA',
		'email'      => 'customer@example.com',
	);
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );

	foreach ( $lines as $line ) {
		$order->add_product( wc_get_product( $eo_t['product'][ $line[0] ] ), $line[1] );
	}

	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Flat rate' );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_total( 10 );
	$order->add_item( $shipping );

	$order->calculate_totals();
	if ( '' !== $coupon ) {
		$order->apply_coupon( $coupon );
	}

	$gateways = WC()->payment_gateways()->payment_gateways();
	$order->set_payment_method( $gateways[ $gateway ] );
	$order->save();
	$order->payment_complete( 'TEST-' . $order->get_id() );

	$eo_t['orders'][] = $order->get_id();

	return wc_get_order( $order->get_id() );
};

$eo_item_for = static function ( WC_Order $order, $sku ) use ( &$eo_t ) {
	$id = $eo_t['product'][ $sku ];
	foreach ( $order->get_items() as $item ) {
		if ( (int) $item->get_variation_id() === $id || ( ! $item->get_variation_id() && (int) $item->get_product_id() === $id ) ) {
			return $item;
		}
	}
	return null;
};

$eo_paid_count = 0;
add_action(
	'edit_orders_for_woocommerce_balance_paid',
	static function () use ( &$eo_paid_count ) {
		++$eo_paid_count;
	}
);

$eo_track_balance = static function ( $result ) use ( &$eo_t ) {
	if ( is_array( $result ) && ! empty( $result['balance_order_id'] ) ) {
		$eo_t['orders'][] = $result['balance_order_id'];
	}
};

WP_CLI::log( 'HPOS ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 1: quantity down refunds through the gateway' );
$eo_stock_before = $eo_stock( 'EO-TSHIRT' );
$eo_order        = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ) );
$eo_check( 'order paid: total 79.10, stock reduced by 3', '79.10' === $eo_money( $eo_order->get_total() ) && $eo_stock( 'EO-TSHIRT' ) === $eo_stock_before - 3, $eo_order->get_total() );
$eo_item   = $eo_item_for( $eo_order, 'EO-TSHIRT' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item->get_id(),
			'quantity' => 1,
		),
	)
);
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'applied at once', is_array( $eo_result ) && 'applied' === $eo_result['status'], is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_check( 'refunded 45.20 (2 x 20 + 13% tax)', '45.20' === $eo_money( $eo_order->get_total_refunded() ), $eo_order->get_total_refunded() );
$eo_check( 'gateway received the refund', '45.20' === $eo_money( $eo_order->get_meta( '_edit_orders_test_refunded' ) ) );
$eo_check( 'refund line: 2 units of the T-Shirt', -2 === (int) $eo_order->get_qty_refunded_for_item( $eo_item->get_id() ) );
$eo_check( 'order total unchanged (79.10)', '79.10' === $eo_money( $eo_order->get_total() ) );
$eo_check( 'stock restored by 2', $eo_stock( 'EO-TSHIRT' ) === $eo_stock_before - 1 );
$eo_check( 'still Processing, no manual flag', 'processing' === $eo_order->get_status() && '' === $eo_order->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) );
$eo_notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $eo_order->get_id() ) ), 'content' );
$eo_check( 'order note records the change', (bool) preg_grep( '/Order edited:.*quantity 3 to 1/', $eo_notes ) );

WP_CLI::log( 'Case 12a: an item that already has a refund is refused' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item->get_id(),
			'quantity' => 0,
		),
	)
);
$eo_check( 'refused with a plain message', is_wp_error( $eo_result ) && false !== strpos( $eo_result->get_error_message(), 'already has a refund' ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : 'applied' );

// ---------------------------------------------------------------------------
// Direct bank transfer: paid, but the gateway can't refund. (A Cash on delivery order is
// Processing while unpaid, so it is refused as unpaid; see the spec's open questions.)
WP_CLI::log( 'Case 2: quantity down on a paid bank-transfer order (no gateway refunds) is flagged for a manual refund' );
$eo_stock_before = $eo_stock( 'EO-TSHIRT' );
$eo_order        = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ), 'bacs' );
$eo_item         = $eo_item_for( $eo_order, 'EO-TSHIRT' );
$eo_result       = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item->get_id(),
			'quantity' => 1,
		),
	)
);
$eo_order        = wc_get_order( $eo_order->get_id() );
$eo_refunds      = $eo_order->get_refunds();
$eo_check( 'refund recorded (45.20) but not sent to a gateway', 1 === count( $eo_refunds ) && '45.20' === $eo_money( $eo_refunds[0]->get_amount() ) && ! $eo_refunds[0]->get_refunded_payment() );
$eo_check( 'manual refund flag 45.20', '45.20' === $eo_money( $eo_order->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) ) );
$eo_check( 'result says manual', is_array( $eo_result ) && ! empty( $eo_result['refund']['manual'] ) );
$eo_check( 'stock restored by 2', $eo_stock( 'EO-TSHIRT' ) === $eo_stock_before - 1 );

WP_CLI::log( 'Case 2b: the gateway refuses the refund: recorded without payment and flagged' );
update_option( 'edit_orders_test_gateway_fail_refunds', 'yes' );
$eo_order  = $eo_make_order( array( array( 'EO-MUG', 2 ) ) );
$eo_item   = $eo_item_for( $eo_order, 'EO-MUG' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_item->get_id(),
		),
	)
);
delete_option( 'edit_orders_test_gateway_fail_refunds' );
$eo_order   = wc_get_order( $eo_order->get_id() );
$eo_refunds = $eo_order->get_refunds();
$eo_check( 'refund recorded without payment', 1 === count( $eo_refunds ) && ! $eo_refunds[0]->get_refunded_payment() );
$eo_check( 'manual flag 27.12 and the gateway error kept', '27.12' === $eo_money( $eo_order->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) ) && is_array( $eo_result ) && false !== strpos( $eo_result['refund']['gateway_error'], 'declined on request' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 3: removing a line refunds it in full, tax included' );
$eo_mug_before = $eo_stock( 'EO-MUG' );
$eo_order      = $eo_make_order( array( array( 'EO-TSHIRT', 1 ), array( 'EO-MUG', 2 ) ) );
$eo_item       = $eo_item_for( $eo_order, 'EO-MUG' );
$eo_result     = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_item->get_id(),
		),
	)
);
$eo_order      = wc_get_order( $eo_order->get_id() );
$eo_check( 'refunded 27.12 (24 + 3.12 tax)', '27.12' === $eo_money( $eo_order->get_total_refunded() ), $eo_order->get_total_refunded() );
$eo_check( 'tax refunded 3.12', '3.12' === $eo_money( $eo_order->get_total_tax_refunded() ), $eo_order->get_total_tax_refunded() );
$eo_check( 'Mug stock back where it started', $eo_stock( 'EO-MUG' ) === $eo_mug_before );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 4: quantity up creates a balance order at the paid unit price' );
$eo_stock_before = $eo_stock( 'EO-TSHIRT' );
$eo_order        = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_item         = $eo_item_for( $eo_order, 'EO-TSHIRT' );
$eo_result       = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item->get_id(),
			'quantity' => 3,
		),
	)
);
$eo_track_balance( $eo_result );
$eo_balance = is_array( $eo_result ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_order   = wc_get_order( $eo_order->get_id() );
$eo_check( 'balance due', is_array( $eo_result ) && 'balance_due' === $eo_result['status'], is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_check( 'balance order: pending, linked, 45.20', $eo_balance && 'pending' === $eo_balance->get_status() && (int) $eo_balance->get_parent_id() === $eo_order->get_id() && '45.20' === $eo_money( $eo_balance->get_total() ), $eo_balance ? $eo_balance->get_total() : '' );
$eo_check( 'pay link present', is_array( $eo_result ) && false !== strpos( $eo_result['pay_url'], 'pay_for_order=true' ) );
$eo_check( 'original order unchanged until paid', 1 === (int) $eo_item_for( $eo_order, 'EO-TSHIRT' )->get_quantity() && '33.90' === $eo_money( $eo_order->get_total() ) );
$eo_check( 'stock not taken yet', $eo_stock( 'EO-TSHIRT' ) === $eo_stock_before - 1 );

WP_CLI::log( 'Case 12b: a second edit while a balance is unpaid is refused' );
$eo_second = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_item->get_id(),
		),
	)
);
$eo_check( 'refused: open balance order', is_wp_error( $eo_second ) && 'edit_orders_for_woocommerce_open_balance' === $eo_second->get_error_code() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 5: paying the balance applies the change once' );
if ( $eo_balance ) {
	$eo_paid_count = 0;
	$eo_balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$eo_balance->save();
	$eo_balance->payment_complete( 'TEST-BAL-' . $eo_balance->get_id() );
	$eo_balance = wc_get_order( $eo_balance->get_id() );
	$eo_order   = wc_get_order( $eo_order->get_id() );
	$eo_check( 'balance order Processing and marked applied', 'processing' === $eo_balance->get_status() && $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_META ) );
	$eo_check( 'stock for the 2 extra units taken', $eo_stock( 'EO-TSHIRT' ) === $eo_stock_before - 3 );
	$eo_check( 'balance_paid fired once', 1 === $eo_paid_count, (string) $eo_paid_count );
	$eo_check( 'original order free for new edits', null === Edit_Orders_For_WooCommerce_Balance_Orders::get_open_balance_order( $eo_order ) );
	$eo_balance->update_status( 'completed' );
	$eo_check( 'a later status change does not apply it again', 1 === $eo_paid_count, (string) $eo_paid_count );
} else {
	$eo_check( 'balance order exists', false );
}

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 6: adding a product with a price override' );
$eo_order  = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'       => 'add',
			'product_id' => $eo_t['product']['EO-POSTER'],
			'quantity'   => 2,
			'price'      => '5',
		),
	)
);
$eo_track_balance( $eo_result );
$eo_balance = is_array( $eo_result ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_line    = $eo_balance ? current( $eo_balance->get_items() ) : null;
$eo_check( 'balance order 11.30 (2 x 5 + 13% tax)', $eo_balance && '11.30' === $eo_money( $eo_balance->get_total() ), $eo_balance ? $eo_balance->get_total() : '' );
$eo_check( 'Poster line: 2 units at 10.00, tax 1.30', $eo_line && 2 === (int) $eo_line->get_quantity() && '10.00' === $eo_money( $eo_line->get_total() ) && '1.30' === $eo_money( $eo_line->get_total_tax() ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 7: same-price variation swap moves stock, no money' );
$eo_s_before = $eo_stock( 'EO-HOODIE-S' );
$eo_m_before = $eo_stock( 'EO-HOODIE-M' );
$eo_order    = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_item     = $eo_item_for( $eo_order, 'EO-HOODIE-S' );
$eo_result   = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item->get_id(),
			'variation_id' => $eo_t['product']['EO-HOODIE-M'],
		),
	)
);
$eo_order    = wc_get_order( $eo_order->get_id() );
$eo_item     = $eo_order->get_item( $eo_item->get_id() );
$eo_check( 'applied with no refund and no balance', is_array( $eo_result ) && 'applied' === $eo_result['status'] && 0.0 === (float) $eo_order->get_total_refunded() );
$eo_check( 'line now the M variation, attribute updated', (int) $eo_item->get_variation_id() === $eo_t['product']['EO-HOODIE-M'] && 'M' === $eo_item->get_meta( 'size' ) );
$eo_check( 'stock: S back to where it was, M down 1', $eo_stock( 'EO-HOODIE-S' ) === $eo_s_before && $eo_stock( 'EO-HOODIE-M' ) === $eo_m_before - 1 );
$eo_check( 'order total unchanged', '56.50' === $eo_money( $eo_order->get_total() ), $eo_order->get_total() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 8: dearer swap with a 20% coupon charges the gap times 0.8' );
$eo_s_before = $eo_stock( 'EO-HOODIE-S' );
$eo_l_before = $eo_stock( 'EO-HOODIE-L' );
$eo_order    = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ), 'edit_orders_test', 'save20' );
$eo_item     = $eo_item_for( $eo_order, 'EO-HOODIE-S' );
$eo_check( 'order paid with the coupon: line total 32.00', '32.00' === $eo_money( $eo_item->get_total() ), $eo_item->get_total() );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item->get_id(),
			'variation_id' => $eo_t['product']['EO-HOODIE-L'],
		),
	)
);
$eo_track_balance( $eo_result );
$eo_balance = is_array( $eo_result ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_fees    = $eo_balance ? $eo_balance->get_fees() : array();
$eo_check( 'balance: one fee of 4.00 ((45 - 40) x 0.8), total 4.52', $eo_balance && 1 === count( $eo_fees ) && '4.00' === $eo_money( current( $eo_fees )->get_total() ) && '4.52' === $eo_money( $eo_balance->get_total() ), $eo_balance ? $eo_balance->get_total() : ( is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' ) );
$eo_check( 'line still S until paid', (int) wc_get_order( $eo_order->get_id() )->get_item( $eo_item->get_id() )->get_variation_id() === $eo_t['product']['EO-HOODIE-S'] );
if ( $eo_balance ) {
	$eo_balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$eo_balance->save();
	$eo_balance->payment_complete( 'TEST-BAL-' . $eo_balance->get_id() );
}
$eo_item = wc_get_order( $eo_order->get_id() )->get_item( $eo_item->get_id() );
$eo_check( 'after payment the line is L', (int) $eo_item->get_variation_id() === $eo_t['product']['EO-HOODIE-L'] && 'L' === $eo_item->get_meta( 'size' ) );
$eo_check( 'stock: S back, L down 1', $eo_stock( 'EO-HOODIE-S' ) === $eo_s_before && $eo_stock( 'EO-HOODIE-L' ) === $eo_l_before - 1 );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 9: cheaper swap refunds the difference' );
$eo_m_before  = $eo_stock( 'EO-HOODIE-M' );
$eo_xs_before = $eo_stock( 'EO-HOODIE-XS' );
$eo_order     = $eo_make_order( array( array( 'EO-HOODIE-M', 1 ) ) );
$eo_item      = $eo_item_for( $eo_order, 'EO-HOODIE-M' );
$eo_result    = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item->get_id(),
			'variation_id' => $eo_t['product']['EO-HOODIE-XS'],
		),
	)
);
$eo_order     = wc_get_order( $eo_order->get_id() );
$eo_item      = $eo_order->get_item( $eo_item->get_id() );
$eo_check( 'refunded 5.65 (5 + 0.65 tax) through the gateway', '5.65' === $eo_money( $eo_order->get_total_refunded() ) && '5.65' === $eo_money( $eo_order->get_meta( '_edit_orders_test_refunded' ) ), $eo_order->get_total_refunded() );
$eo_check( 'no units refunded, only money', 0 === (int) $eo_order->get_qty_refunded_for_item( $eo_item->get_id() ) );
$eo_check( 'line now XS', (int) $eo_item->get_variation_id() === $eo_t['product']['EO-HOODIE-XS'] && 'XS' === $eo_item->get_meta( 'size' ) );
$eo_check( 'stock: M back, XS down 1', $eo_stock( 'EO-HOODIE-M' ) === $eo_m_before && $eo_stock( 'EO-HOODIE-XS' ) === $eo_xs_before - 1 );

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
	WP_CLI::error( 'P1 engine suite failed.' );
}
WP_CLI::success( 'P1 engine suite passed.' );
