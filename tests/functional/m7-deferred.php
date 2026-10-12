<?php
/**
 * Functional suite for the four items deferred from the independent review to 1.0.1
 * (tasks/edit-orders-for-woocommerce-review-2026-10-08.md: H1, L2, L4 and L8).
 *
 * Run with: bash tests/functional/run.sh m7-deferred (or all suites without an argument).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$eo_t = array(
	'pass'    => 0,
	'fail'    => 0,
	'orders'  => array(),
	'stock'   => array(),
	'product' => array(),
	'temp'    => array(),
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

if ( ! class_exists( 'Edit_Orders_Mail_Log' ) ) {
	WP_CLI::error( 'The mail log mu-plugin is not loaded (tests/env/mu-plugins).' );
}

Edit_Orders_For_WooCommerce_Audit_Log::maybe_install();
wp_set_current_user( 1 );
$eo_settings_before = get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
$eo_round_before = get_option( 'woocommerce_tax_round_at_subtotal', 'no' );
update_option( 'woocommerce_tax_round_at_subtotal', 'no' );

foreach ( array( 'EO-TSHIRT', 'EO-MUG', 'EO-HOODIE-XS', 'EO-HOODIE-S', 'EO-HOODIE-L' ) as $eo_sku ) {
	$eo_t['product'][ $eo_sku ]                   = wc_get_product_id_by_sku( $eo_sku );
	$eo_t['stock'][ $eo_t['product'][ $eo_sku ] ] = wc_get_product( $eo_t['product'][ $eo_sku ] )->get_stock_quantity();
}
$eo_stock = static function ( $sku ) use ( &$eo_t ) {
	wp_cache_flush();
	return (int) wc_get_product( $eo_t['product'][ $sku ] )->get_stock_quantity();
};

$eo_make_order = static function ( array $lines, $customer_id = 0, $gateway = 'edit_orders_test' ) use ( &$eo_t ) {
	$address = array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '1 Yonge St',
		'city'       => 'Toronto',
		'state'      => 'ON',
		'postcode'   => 'M5E 1E5',
		'country'    => 'CA',
	);
	$order   = wc_create_order( array( 'customer_id' => $customer_id ) );
	$order->set_address( array_merge( $address, array( 'email' => 'customer@example.com' ) ), 'billing' );
	$order->set_address( $address, 'shipping' );
	foreach ( $lines as $line ) {
		$product = is_numeric( $line[0] ) ? wc_get_product( $line[0] ) : wc_get_product( $eo_t['product'][ $line[0] ] );
		$order->add_product( $product, $line[1] );
	}
	$order->calculate_totals();
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()[ $gateway ] );
	$order->save();
	$order->payment_complete( 'TEST-' . $order->get_id() );
	$eo_t['orders'][] = $order->get_id();
	return wc_get_order( $order->get_id() );
};

$eo_item_id = static function ( WC_Order $order, $sku ) use ( &$eo_t ) {
	foreach ( $order->get_items() as $item_id => $item ) {
		$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		if ( (int) $id === (int) $eo_t['product'][ $sku ] ) {
			return $item_id;
		}
	}
	return 0;
};

$eo_pay = static function ( $balance_id ) {
	$balance = wc_get_order( $balance_id );
	$balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$balance->save();
	$balance->payment_complete( 'TEST-BAL-' . $balance_id );
};

$eo_change = static function ( WC_Order $order, array $change ) {
	return Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $order->get_id() ), array( $change ) );
};

$eo_preview = static function ( WC_Order $order, array $change ) {
	return Edit_Orders_For_WooCommerce_Settlement_Executor::preview( wc_get_order( $order->get_id() ), array( $change ) );
};

$eo_refunded = static function ( $order_id ) {
	wp_cache_flush();
	return (float) wc_get_order( $order_id )->get_total_refunded();
};

// ---------------------------------------------------------------------------
WP_CLI::log( 'H1: quantity 1 to 3 paid on a balance order, then the line is removed' );
$eo_start  = $eo_stock( 'EO-TSHIRT' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_result = $eo_change(
	$eo_order,
	array(
		'type'     => 'quantity',
		'item_id'  => $eo_tshirt,
		'quantity' => 3,
	)
);
$eo_bal    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_pay( $eo_bal );
$eo_check( 'after paying, 3 T-Shirts are taken from stock', $eo_start - 3 === $eo_stock( 'EO-TSHIRT' ), $eo_stock( 'EO-TSHIRT' ) . ' of ' . $eo_start );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'the editors count 3 units (1 on the order, 2 on the balance order)', 3 === Edit_Orders_For_WooCommerce_Balance_Orders::effective_quantity( $eo_order, $eo_order->get_item( $eo_tshirt ) ) );
$eo_remove = array(
	'type'    => 'remove',
	'item_id' => $eo_tshirt,
);
$eo_plan   = $eo_preview( $eo_order, $eo_remove );
$eo_check( 'removing it is allowed now', ! is_wp_error( $eo_plan ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : '' );
$eo_check( 'the preview refunds all 3 units with tax: 22.60 + 45.20 = 67.80', ! is_wp_error( $eo_plan ) && '67.80' === $eo_money( $eo_plan->get_refund_amount() ), is_wp_error( $eo_plan ) ? '' : $eo_money( $eo_plan->get_refund_amount() ) );
$eo_rows = is_wp_error( $eo_plan ) ? array() : $eo_plan->get_preview_rows( $eo_order );
$eo_check( 'the preview shows 3 to 0', $eo_rows && 3 === $eo_rows[0]['before'] && 0 === $eo_rows[0]['after'] && 'removed' === $eo_rows[0]['state'], wp_json_encode( $eo_rows ) );
$eo_result = $eo_change( $eo_order, $eo_remove );
$eo_check( 'applied', is_array( $eo_result ) && 'applied' === $eo_result['status'], is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_check( 'the order refunded its unit: 22.60', '22.60' === $eo_money( $eo_refunded( $eo_order->get_id() ) ), $eo_money( $eo_refunded( $eo_order->get_id() ) ) );
$eo_check( 'the balance order refunded its 2 units: 45.20', '45.20' === $eo_money( $eo_refunded( $eo_bal ) ), $eo_money( $eo_refunded( $eo_bal ) ) );
$eo_check( 'what was refunded equals the preview (m5 rule)', '67.80' === $eo_money( $eo_refunded( $eo_order->get_id() ) + $eo_refunded( $eo_bal ) ) );
$eo_check( 'all 3 T-Shirts are back in stock', $eo_start === $eo_stock( 'EO-TSHIRT' ), $eo_stock( 'EO-TSHIRT' ) . ' of ' . $eo_start );

// ---------------------------------------------------------------------------
WP_CLI::log( 'H1: a dearer swap paid on a balance order, then the line is removed' );
$eo_order  = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ), array( 'EO-MUG', 1 ) ) );
$eo_hoodie = $eo_item_id( $eo_order, 'EO-HOODIE-S' );
$eo_result = $eo_change(
	$eo_order,
	array(
		'type'         => 'swap',
		'item_id'      => $eo_hoodie,
		'variation_id' => $eo_t['product']['EO-HOODIE-L'],
	)
);
$eo_bal    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_pay( $eo_bal );
$eo_check( 'the swap\'s price difference was paid: 5.65', '5.65' === $eo_money( wc_get_order( $eo_bal )->get_total() ), $eo_money( wc_get_order( $eo_bal )->get_total() ) );
$eo_remove = array(
	'type'    => 'remove',
	'item_id' => $eo_hoodie,
);
$eo_plan   = $eo_preview( $eo_order, $eo_remove );
$eo_check( 'the preview refunds the line and the price difference: 45.20 + 5.65 = 50.85', ! is_wp_error( $eo_plan ) && '50.85' === $eo_money( $eo_plan->get_refund_amount() ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) );
$eo_result = $eo_change( $eo_order, $eo_remove );
$eo_check( 'applied', is_array( $eo_result ) && 'applied' === $eo_result['status'], is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_check( 'the order refunded the hoodie: 45.20', '45.20' === $eo_money( $eo_refunded( $eo_order->get_id() ) ), $eo_money( $eo_refunded( $eo_order->get_id() ) ) );
$eo_check( 'the balance order refunded the price difference: 5.65', '5.65' === $eo_money( $eo_refunded( $eo_bal ) ), $eo_money( $eo_refunded( $eo_bal ) ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'H1: a partial decrease takes the units on the balance order first' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_result = $eo_change(
	$eo_order,
	array(
		'type'     => 'quantity',
		'item_id'  => $eo_tshirt,
		'quantity' => 3,
	)
);
$eo_bal    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_pay( $eo_bal );
$eo_down   = array(
	'type'     => 'quantity',
	'item_id'  => $eo_tshirt,
	'quantity' => 2,
);
$eo_plan   = $eo_preview( $eo_order, $eo_down );
$eo_check( 'the preview for 3 to 2 refunds one unit: 22.60', ! is_wp_error( $eo_plan ) && '22.60' === $eo_money( $eo_plan->get_refund_amount() ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) );
$eo_check( 'from the balance order, not the original order', ! is_wp_error( $eo_plan ) && ! $eo_plan->get_refund_lines() && isset( $eo_plan->get_balance_refunds()[ $eo_bal ] ) );
$eo_result = $eo_change( $eo_order, $eo_down );
wp_cache_flush();
$eo_balance = wc_get_order( $eo_bal );
$eo_bal_item = current( $eo_balance->get_items() );
$eo_check( 'applied: the balance order refunded 1 unit, the original order nothing', is_array( $eo_result ) && '22.60' === $eo_money( $eo_refunded( $eo_bal ) ) && 0.0 === $eo_refunded( $eo_order->get_id() ) && -1 === (int) $eo_balance->get_qty_refunded_for_item( $eo_bal_item->get_id() ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : $eo_money( $eo_refunded( $eo_bal ) ) . ' / ' . $eo_money( $eo_refunded( $eo_order->get_id() ) ) );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'the line now counts 2 units and can still be changed', 2 === Edit_Orders_For_WooCommerce_Balance_Orders::effective_quantity( $eo_order, $eo_order->get_item( $eo_tshirt ) ) && true === Edit_Orders_For_WooCommerce_Eligibility::check_item( $eo_order, $eo_order->get_item( $eo_tshirt ) ) );
$eo_down['quantity'] = 0;
$eo_plan             = $eo_preview( $eo_order, $eo_down );
$eo_result           = $eo_change( $eo_order, $eo_down );
$eo_check( 'removing the rest refunds the last balance unit and the original unit: 45.20, matching the preview', ! is_wp_error( $eo_plan ) && '45.20' === $eo_money( $eo_plan->get_refund_amount() ) && is_array( $eo_result ) && '45.20' === $eo_money( $eo_refunded( $eo_bal ) ) && '22.60' === $eo_money( $eo_refunded( $eo_order->get_id() ) ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'H1: a cheaper swap after a paid quantity increase swaps and refunds both orders' );
$eo_s_start  = $eo_stock( 'EO-HOODIE-S' );
$eo_xs_start = $eo_stock( 'EO-HOODIE-XS' );
$eo_order    = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_hoodie   = $eo_item_id( $eo_order, 'EO-HOODIE-S' );
$eo_result   = $eo_change(
	$eo_order,
	array(
		'type'     => 'quantity',
		'item_id'  => $eo_hoodie,
		'quantity' => 2,
	)
);
$eo_bal      = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_pay( $eo_bal );
$eo_address = $eo_preview(
	$eo_order,
	array(
		'type'     => 'address',
		'shipping' => array(
			'address_1' => '100 8 Ave SW',
			'city'      => 'Calgary',
			'state'     => 'AB',
			'postcode'  => 'T2P 1B3',
		),
	)
);
$eo_check( 'the address of an order with a paid balance order is still refused (kept in 1.0.1)', is_wp_error( $eo_address ) && false !== strpos( $eo_address->get_error_message(), 'balance order' ), is_wp_error( $eo_address ) ? $eo_address->get_error_message() : 'allowed' );
$eo_options = ( new ReflectionMethod( 'Edit_Orders_For_WooCommerce_Frontend', 'swap_options' ) );
$eo_options->setAccessible( true );
$eo_options = $eo_options->invoke( null, wc_get_order( $eo_order->get_id() ) );
$eo_check( 'the customer\'s swap list shows the line with both units', isset( $eo_options[ $eo_hoodie ] ) && 2 === $eo_options[ $eo_hoodie ]['quantity'], wp_json_encode( $eo_options ) );
$eo_swap = array(
	'type'         => 'swap',
	'item_id'      => $eo_hoodie,
	'variation_id' => $eo_t['product']['EO-HOODIE-XS'],
);
$eo_plan = $eo_preview( $eo_order, $eo_swap );
$eo_check( 'the preview refunds 5.00 + tax per unit on each order: 11.30', ! is_wp_error( $eo_plan ) && '11.30' === $eo_money( $eo_plan->get_refund_amount() ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) );
$eo_result = $eo_change( $eo_order, $eo_swap );
wp_cache_flush();
$eo_balance  = wc_get_order( $eo_bal );
$eo_bal_item = current( $eo_balance->get_items() );
$eo_check( 'applied: 5.65 refunded on each order', is_array( $eo_result ) && '5.65' === $eo_money( $eo_refunded( $eo_order->get_id() ) ) && '5.65' === $eo_money( $eo_refunded( $eo_bal ) ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_check( 'both units are XS now: the original line and the balance order line', (int) wc_get_order( $eo_order->get_id() )->get_item( $eo_hoodie )->get_variation_id() === (int) $eo_t['product']['EO-HOODIE-XS'] && (int) $eo_bal_item->get_variation_id() === (int) $eo_t['product']['EO-HOODIE-XS'] );
$eo_check( 'stock: 2 S back, 2 XS taken', $eo_s_start === $eo_stock( 'EO-HOODIE-S' ) && $eo_xs_start - 2 === $eo_stock( 'EO-HOODIE-XS' ), $eo_stock( 'EO-HOODIE-S' ) . '/' . $eo_s_start . ' S, ' . $eo_stock( 'EO-HOODIE-XS' ) . '/' . $eo_xs_start . ' XS' );

// ---------------------------------------------------------------------------
WP_CLI::log( 'L2: a balance order whose plan never finished applying' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_result = $eo_change(
	$eo_order,
	array(
		'type'     => 'quantity',
		'item_id'  => $eo_tshirt,
		'quantity' => 2,
	)
);
$eo_bal     = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_balance = wc_get_order( $eo_bal );
// A request that started applying and is still running (or died a moment ago).
$eo_balance->update_meta_data( Edit_Orders_For_WooCommerce_Balance_Orders::APPLYING_META, time() );
$eo_balance->save();
$eo_pay( $eo_bal );
wp_cache_flush();
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'while applying: the payment hooks don\'t apply it a second time', ! wc_get_order( $eo_bal )->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_META ) && ! in_array( $eo_bal, (array) $eo_order->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_BALANCES_META ), true ) );
$eo_check( 'and the order waits for it', Edit_Orders_For_WooCommerce_Balance_Orders::is_applying( $eo_order ) );
$eo_balance = wc_get_order( $eo_bal );
$eo_balance->update_meta_data( Edit_Orders_For_WooCommerce_Balance_Orders::APPLYING_META, time() - 600 );
$eo_balance->save();
$eo_check( 'stuck for ten minutes: the next look at the order settles it', ! Edit_Orders_For_WooCommerce_Balance_Orders::is_applying( wc_get_order( $eo_order->get_id() ) ) );
wp_cache_flush();
$eo_balance = wc_get_order( $eo_bal );
$eo_order   = wc_get_order( $eo_order->get_id() );
$eo_notes   = implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $eo_order->get_id() ) ), 'content' ) );
$eo_check( 'marked done and stuck, never applied', $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_META ) && $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::STUCK_META ) && ! $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLYING_META ) );
$eo_check( 'the original order gets a note', false !== strpos( $eo_notes, 'did not finish' ), $eo_notes );
$eo_check( 'the balance order is flagged for a manual refund', '' !== (string) $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) );
$eo_check( 'the order can be edited again', ! $eo_order->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::OPEN_BALANCE_META ) && ! is_wp_error( Edit_Orders_For_WooCommerce_Eligibility::check_order( $eo_order, 'edit', 'staff' ) ) );
Edit_Orders_For_WooCommerce_Balance_Orders::maybe_apply( $eo_bal );
wp_cache_flush();
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'a later payment hook still doesn\'t apply it', ! in_array( $eo_bal, array_map( 'intval', (array) $eo_order->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_BALANCES_META ) ), true ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'L4: refund tax with "round tax at subtotal" on and off (three lines of 2.66 at 13%)' );
$eo_cheap = new WC_Product_Simple();
$eo_cheap->set_name( 'EO L4 sticker' );
$eo_cheap->set_regular_price( '2.66' );
$eo_cheap->set_status( 'publish' );
$eo_cheap_id     = $eo_cheap->save();
$eo_t['temp'][]  = $eo_cheap_id;
foreach ( array(
	'no'  => '9.03',
	'yes' => '9.02',
) as $eo_setting => $eo_expected ) {
	update_option( 'woocommerce_tax_round_at_subtotal', $eo_setting );
	$eo_order   = $eo_make_order( array( array( $eo_cheap_id, 1 ), array( $eo_cheap_id, 1 ), array( $eo_cheap_id, 1 ), array( 'EO-MUG', 1 ) ) );
	$eo_changes = array();
	foreach ( $eo_order->get_items() as $eo_line_id => $eo_line ) {
		if ( (int) $eo_line->get_product_id() === (int) $eo_cheap_id ) {
			$eo_changes[] = array(
				'type'    => 'remove',
				'item_id' => $eo_line_id,
			);
		}
	}
	$eo_plan   = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
	$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes );
	$eo_check( "setting {$eo_setting}: three lines refund {$eo_expected}, as charged", ! is_wp_error( $eo_plan ) && $eo_expected === $eo_money( $eo_plan->get_refund_amount() ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) );
	$eo_check( "setting {$eo_setting}: the refund recorded equals the preview", is_array( $eo_result ) && $eo_expected === $eo_money( $eo_refunded( $eo_order->get_id() ) ), $eo_money( $eo_refunded( $eo_order->get_id() ) ) );
}
update_option( 'woocommerce_tax_round_at_subtotal', 'no' );

// ---------------------------------------------------------------------------
WP_CLI::log( 'L8: the wrong-key limit never locks out the right key, and counts per order' );
wp_set_current_user( 0 );
$eo_clear = static function ( array $orders ) {
	delete_transient( 'eofw_badkey_' . md5( WC_Geolocation::get_ip_address() ) );
	foreach ( $orders as $order ) {
		delete_transient( Edit_Orders_For_WooCommerce_Customer_Rules::failed_key_order_transient( $order->get_id() ) );
	}
};
$eo_guest = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_other = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_clear( array( $eo_guest, $eo_other ) );
for ( $eo_i = 0; $eo_i < Edit_Orders_For_WooCommerce_Customer_Rules::MAX_FAILED_KEYS; $eo_i++ ) {
	Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_other, 'wc_order_guess' . $eo_i );
}
$eo_check( 'ten wrong keys from one IP: that IP is limited', Edit_Orders_For_WooCommerce_Customer_Rules::is_rate_limited() );
$eo_check( 'the right key from the same IP still works', 'guest' === Edit_Orders_For_WooCommerce_Customer_Rules::authorize( wc_get_order( $eo_guest->get_id() ), $eo_guest->get_order_key() ) );

// The real request handler: the limit is checked only after authorize() refuses.
$eo_die = static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new RuntimeException( 'wp_die ' . ( is_array( $args ) && isset( $args['response'] ) ? $args['response'] : '' ) );
	};
};
add_filter( 'wp_die_handler', $eo_die, PHP_INT_MAX );
$eo_post = static function ( WC_Order $order, $key ) {
	$_POST = array(
		'edit_orders_for_woocommerce_action' => 'unknown',
		'_eofw_nonce'                        => wp_create_nonce( Edit_Orders_For_WooCommerce_Frontend::NONCE ),
		'order_id'                           => $order->get_id(),
		'order_key'                          => $key,
	);
	try {
		Edit_Orders_For_WooCommerce_Frontend::handle_post();
		return 'handled';
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	} finally {
		$_POST = array();
	}
};
$eo_check( 'a request with the right key from a limited IP is handled, not refused', 'handled' === $eo_post( $eo_guest, $eo_guest->get_order_key() ), $eo_post( $eo_guest, $eo_guest->get_order_key() ) );
$eo_check( 'a wrong key from that IP gets 429', 'wp_die 429' === $eo_post( $eo_guest, 'wc_order_nope' ) );
$eo_clear( array( $eo_guest, $eo_other ) );

// Per order: wrong keys from many IPs.
$eo_ip_before = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
for ( $eo_i = 0; $eo_i < Edit_Orders_For_WooCommerce_Customer_Rules::MAX_FAILED_KEYS_PER_ORDER; $eo_i++ ) {
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 10 + $eo_i );
	Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_other, 'wc_order_spread' . $eo_i );
}
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$eo_check( 'ten wrong keys on one order from ten IPs: a new IP is not limited in general', ! Edit_Orders_For_WooCommerce_Customer_Rules::is_rate_limited() );
$eo_check( 'but that order is limited', Edit_Orders_For_WooCommerce_Customer_Rules::is_rate_limited( $eo_other ) );
$eo_check( 'another order is not', ! Edit_Orders_For_WooCommerce_Customer_Rules::is_rate_limited( $eo_guest ) );
$eo_check( 'a wrong key on that order gets 429', 'wp_die 429' === $eo_post( $eo_other, 'wc_order_more' ) );
$eo_check( 'its right key is still handled', 'handled' === $eo_post( $eo_other, $eo_other->get_order_key() ) );
remove_filter( 'wp_die_handler', $eo_die, PHP_INT_MAX );
$eo_clear( array( $eo_guest, $eo_other ) );
for ( $eo_i = 0; $eo_i < 12; $eo_i++ ) {
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 10 + $eo_i );
	delete_transient( 'eofw_badkey_' . md5( WC_Geolocation::get_ip_address() ) );
}
if ( null === $eo_ip_before ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $eo_ip_before;
}
$eo_clear( array( $eo_guest, $eo_other ) );
wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Cleanup' );
foreach ( $eo_t['orders'] as $eo_order_id ) {
	$eo_order = wc_get_order( $eo_order_id );
	if ( ! $eo_order ) {
		continue;
	}
	$eo_children = wc_get_orders(
		array(
			'parent' => $eo_order_id,
			'type'   => 'shop_order',
			'limit'  => -1,
		)
	);
	foreach ( array_merge( $eo_children, array( $eo_order ) ) as $eo_delete ) {
		foreach ( $eo_delete->get_refunds() as $eo_refund ) {
			$eo_refund->delete( true );
		}
		$wpdb->delete( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'order_id' => $eo_delete->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$eo_delete->delete( true );
	}
}
foreach ( $eo_t['temp'] as $eo_product_id ) {
	wp_delete_post( $eo_product_id, true );
}
foreach ( $eo_t['stock'] as $eo_product_id => $eo_quantity ) {
	if ( null !== $eo_quantity ) {
		wc_update_product_stock( $eo_product_id, $eo_quantity, 'set' );
	}
}
update_option( 'woocommerce_tax_round_at_subtotal', $eo_round_before );
if ( null === $eo_settings_before ) {
	delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
} else {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $eo_settings_before );
}
delete_option( Edit_Orders_Mail_Log::OPTION );

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'Deferred items suite failed.' );
}
WP_CLI::success( 'Deferred items suite passed.' );
