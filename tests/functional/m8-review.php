<?php
/**
 * Functional suite for the Mac review of 1.0.1 (tasks/todo.md, "Edit Orders 1.0.1: Mac
 * review"): R1 cash on delivery balance orders, R2 balance orders made by 1.0.0, R3 a
 * cheaper swap after a dearer one, R4 more units after a dearer swap, R6 a refund that
 * fails part way, R7 tax rounded at subtotal and R8 a slow run marked stuck.
 *
 * Run with: bash tests/functional/run.sh m8-review (or all suites without an argument).
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

$eo_error = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_message() : '';
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

// Lines are array( SKU or product ID, quantity ). Cash on delivery orders are left as the
// gateway leaves them at checkout: Processing, no paid date.
$eo_make_order = static function ( array $lines, $gateway = 'edit_orders_test' ) use ( &$eo_t ) {
	$address = array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '1 Yonge St',
		'city'       => 'Toronto',
		'state'      => 'ON',
		'postcode'   => 'M5E 1E5',
		'country'    => 'CA',
	);
	$order   = wc_create_order();
	$order->set_address( array_merge( $address, array( 'email' => 'customer@example.com' ) ), 'billing' );
	$order->set_address( $address, 'shipping' );
	foreach ( $lines as $line ) {
		$product = is_numeric( $line[0] ) ? wc_get_product( $line[0] ) : wc_get_product( $eo_t['product'][ $line[0] ] );
		$order->add_product( $product, $line[1] );
	}
	$order->calculate_totals();
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()[ $gateway ] );
	$order->save();
	if ( 'cod' === $gateway ) {
		$order->update_status( 'processing', 'Payment to be made upon delivery.' );
	} else {
		$order->payment_complete( 'TEST-' . $order->get_id() );
	}
	$eo_t['orders'][] = $order->get_id();
	return wc_get_order( $order->get_id() );
};

$eo_item_id = static function ( WC_Order $order, $product ) use ( &$eo_t ) {
	$product = is_numeric( $product ) ? (int) $product : (int) $eo_t['product'][ $product ];
	foreach ( $order->get_items() as $item_id => $item ) {
		$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		if ( (int) $id === $product ) {
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

$eo_apply = static function ( WC_Order $order, array $changes ) {
	return Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $order->get_id() ), $changes );
};

$eo_preview = static function ( WC_Order $order, array $changes ) {
	return Edit_Orders_For_WooCommerce_Settlement_Executor::preview( wc_get_order( $order->get_id() ), $changes );
};

$eo_refunded = static function ( $order_id ) {
	wp_cache_flush();
	return (float) wc_get_order( $order_id )->get_total_refunded();
};

$eo_effective = static function ( WC_Order $order, $item_id ) {
	wp_cache_flush();
	$order = wc_get_order( $order->get_id() );
	return Edit_Orders_For_WooCommerce_Balance_Orders::effective_quantity( $order, $order->get_item( $item_id ) );
};

$eo_notes = static function ( $order_id ) {
	return implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order_id ) ), 'content' ) );
};

// A variable product made for one case: size => price. Returns size => variation ID.
$eo_variable = static function ( $name, array $prices ) use ( &$eo_t ) {
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( array_keys( $prices ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$parent = new WC_Product_Variable();
	$parent->set_name( $name );
	$parent->set_attributes( array( $attribute ) );
	$parent->set_status( 'publish' );
	$parent_id      = $parent->save();
	$eo_t['temp'][] = $parent_id;
	$ids            = array();
	foreach ( $prices as $size => $price ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( array( 'size' => $size ) );
		$variation->set_regular_price( (string) $price );
		$variation->set_status( 'publish' );
		$ids[ $size ]   = $variation->save();
		$eo_t['temp'][] = $ids[ $size ];
	}
	WC_Product_Variable::sync( $parent_id );
	return $ids;
};

// ---------------------------------------------------------------------------
WP_CLI::log( 'R1: units on an unpaid cash on delivery balance order count, and removing the line refunds them' );
$eo_start  = $eo_stock( 'EO-TSHIRT' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ), 'cod' );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_result = $eo_apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_tshirt,
			'quantity' => 3,
		),
	)
);
$eo_bal    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
wp_cache_flush();
$eo_check( 'the linked order is cash on delivery, Processing, with no paid date', $eo_bal && 'processing' === wc_get_order( $eo_bal )->get_status() && ! wc_get_order( $eo_bal )->get_date_paid(), $eo_error( $eo_result ) );
$eo_check( 'the line counts 3 units', 3 === $eo_effective( $eo_order, $eo_tshirt ), (string) $eo_effective( $eo_order, $eo_tshirt ) );
$eo_remove = array(
	array(
		'type'    => 'remove',
		'item_id' => $eo_tshirt,
	),
);
$eo_plan   = $eo_preview( $eo_order, $eo_remove );
$eo_check( 'removing it takes all 3 off what is collected: 67.80', ! is_wp_error( $eo_plan ) && '67.80' === $eo_money( $eo_plan->get_refund_amount() ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) );
$eo_result = $eo_apply( $eo_order, $eo_remove );
$eo_check( 'applied: 22.60 off the order and 45.20 off the linked order', is_array( $eo_result ) && '22.60' === $eo_money( $eo_refunded( $eo_order->get_id() ) ) && '45.20' === $eo_money( $eo_refunded( $eo_bal ) ), $eo_error( $eo_result ) . ' ' . $eo_money( $eo_refunded( $eo_order->get_id() ) ) . '/' . $eo_money( $eo_refunded( $eo_bal ) ) );
$eo_check( 'nothing left to collect on the linked order', '0.00' === $eo_money( wc_get_order( $eo_bal )->get_total() - $eo_refunded( $eo_bal ) ) );
$eo_check( 'all 3 T-Shirts back in stock', $eo_start === $eo_stock( 'EO-TSHIRT' ), $eo_stock( 'EO-TSHIRT' ) . ' of ' . $eo_start );
$eo_check( 'no money moved through a gateway', '' === (string) wc_get_order( $eo_bal )->get_meta( '_edit_orders_test_refunded' ) );

WP_CLI::log( 'R1: a paid balance order the store cancelled no longer counts' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_result = $eo_apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_tshirt,
			'quantity' => 2,
		),
	)
);
$eo_bal    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_pay( $eo_bal );
wc_get_order( $eo_bal )->update_status( 'cancelled', 'Cancelled by the store.' );
$eo_check( 'the line counts 1 unit', 1 === $eo_effective( $eo_order, $eo_tshirt ), (string) $eo_effective( $eo_order, $eo_tshirt ) );
$eo_plan = $eo_preview(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_tshirt,
		),
	)
);
$eo_check( 'removing it refunds only the original unit: 22.60, nothing on the cancelled order', ! is_wp_error( $eo_plan ) && '22.60' === $eo_money( $eo_plan->get_refund_amount() ) && ! $eo_plan->get_balance_refunds(), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'R2: a balance order made by 1.0.0 (no line meta) is matched to the right lines' );
$eo_legacy = static function () use ( $eo_make_order, $eo_item_id, $eo_apply, $eo_pay, &$eo_t ) {
	$order  = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ), array( 'EO-TSHIRT', 1 ) ) );
	$hoodie = $eo_item_id( $order, 'EO-HOODIE-S' );
	$tshirt = $eo_item_id( $order, 'EO-TSHIRT' );
	// The swap comes first: the plan lists the fee before the product line, while the
	// balance order returns its items grouped by type, products first.
	$result = $eo_apply(
		$order,
		array(
			array(
				'type'         => 'swap',
				'item_id'      => $hoodie,
				'variation_id' => $eo_t['product']['EO-HOODIE-L'],
			),
			array(
				'type'     => 'quantity',
				'item_id'  => $tshirt,
				'quantity' => 2,
			),
		)
	);
	$bal    = is_array( $result ) ? (int) $result['balance_order_id'] : 0;
	$eo_pay( $bal );
	$balance = wc_get_order( $bal );
	foreach ( $balance->get_items( array( 'line_item', 'fee' ) ) as $part ) {
		$part->delete_meta_data( Edit_Orders_For_WooCommerce_Balance_Orders::FOR_ITEM_META );
		$part->save();
	}
	return array( $order, $hoodie, $tshirt, $bal );
};
list( $eo_order, $eo_hoodie, $eo_tshirt, $eo_bal ) = $eo_legacy();
$eo_plan_items                                     = wp_list_pluck( (array) wc_get_order( $eo_bal )->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::PLAN_META )['balance_items'], 'type' );
$eo_check( 'set up: the plan lists the fee first', array( 'fee', 'product' ) === $eo_plan_items, wp_json_encode( $eo_plan_items ) );
$eo_check( 'the hoodie counts 1 unit', 1 === $eo_effective( $eo_order, $eo_hoodie ), (string) $eo_effective( $eo_order, $eo_hoodie ) );
$eo_check( 'the T-Shirt counts 2 units', 2 === $eo_effective( $eo_order, $eo_tshirt ), (string) $eo_effective( $eo_order, $eo_tshirt ) );
$eo_plan = $eo_preview(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_hoodie,
		),
	)
);
$eo_check( 'removing the hoodie refunds it and its price difference: 50.85', ! is_wp_error( $eo_plan ) && '50.85' === $eo_money( $eo_plan->get_refund_amount() ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) );

WP_CLI::log( 'R2: when the 1.0.0 items don\'t match the plan, the lines stay refused as in 1.0.0' );
list( $eo_order, $eo_hoodie, $eo_tshirt, $eo_bal ) = $eo_legacy();
$eo_balance                                        = wc_get_order( $eo_bal );
$eo_data = $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::PLAN_META );
foreach ( $eo_data['balance_items'] as $eo_index => $eo_line ) {
	if ( 'product' === $eo_line['type'] ) {
		$eo_data['balance_items'][ $eo_index ]['product_id'] = $eo_t['product']['EO-MUG'];
	}
}
$eo_balance->update_meta_data( Edit_Orders_For_WooCommerce_Balance_Orders::PLAN_META, $eo_data );
$eo_balance->save();
wp_cache_flush();
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'the T-Shirt is refused', is_wp_error( Edit_Orders_For_WooCommerce_Eligibility::check_item( $eo_order, $eo_order->get_item( $eo_tshirt ) ) ) );
$eo_check( 'the hoodie is refused', is_wp_error( Edit_Orders_For_WooCommerce_Eligibility::check_item( $eo_order, $eo_order->get_item( $eo_hoodie ) ) ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'R3: a cheaper swap after a dearer one refunds the price difference paid' );
$eo_tee    = $eo_variable(
	'EO R3 tee',
	array(
		'S'  => 10,
		'XL' => 30,
		'XS' => 5,
	)
);
$eo_order  = $eo_make_order( array( array( $eo_tee['S'], 1 ) ) );
$eo_line   = $eo_item_id( $eo_order, $eo_tee['S'] );
$eo_result = $eo_apply(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_line,
			'variation_id' => $eo_tee['XL'],
		),
	)
);
$eo_bal    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_pay( $eo_bal );
$eo_check( 'set up: S to XL paid 22.60 more', '22.60' === $eo_money( wc_get_order( $eo_bal )->get_total() ), $eo_error( $eo_result ) );
$eo_swap = array(
	array(
		'type'         => 'swap',
		'item_id'      => $eo_line,
		'variation_id' => $eo_tee['XS'],
	),
);
$eo_plan = $eo_preview( $eo_order, $eo_swap );
$eo_check( 'XL to XS refunds 25.00 + tax: 28.25 (the customer paid 30 and keeps a 5 item)', ! is_wp_error( $eo_plan ) && '28.25' === $eo_money( $eo_plan->get_refund_amount() ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) );
$eo_result = $eo_apply( $eo_order, $eo_swap );
$eo_check( 'applied: the price difference back on the balance order (22.60), the rest on the order (5.65)', is_array( $eo_result ) && '22.60' === $eo_money( $eo_refunded( $eo_bal ) ) && '5.65' === $eo_money( $eo_refunded( $eo_order->get_id() ) ), $eo_error( $eo_result ) . ' ' . $eo_money( $eo_refunded( $eo_bal ) ) . '/' . $eo_money( $eo_refunded( $eo_order->get_id() ) ) );

// ---------------------------------------------------------------------------
foreach ( array( 'no', 'yes' ) as $eo_setting ) {
	update_option( 'woocommerce_tax_round_at_subtotal', $eo_setting );
	WP_CLI::log( "R4: more units after a dearer swap pay the price difference too (round tax at subtotal: {$eo_setting})" );
	$eo_l_start = $eo_stock( 'EO-HOODIE-L' );
	$eo_order   = $eo_make_order( array( array( 'EO-HOODIE-S', 2 ) ) );
	$eo_hoodie  = $eo_item_id( $eo_order, 'EO-HOODIE-S' );
	$eo_result  = $eo_apply(
		$eo_order,
		array(
			array(
				'type'         => 'swap',
				'item_id'      => $eo_hoodie,
				'variation_id' => $eo_t['product']['EO-HOODIE-L'],
			),
		)
	);
	$eo_bal1    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
	$eo_pay( $eo_bal1 );
	$eo_up   = array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_hoodie,
			'quantity' => 3,
		),
	);
	$eo_plan = $eo_preview( $eo_order, $eo_up );
	$eo_check( 'the third L costs 40.00 + 5.00 + tax: 50.85', ! is_wp_error( $eo_plan ) && '50.85' === $eo_money( $eo_plan->get_balance_estimate( wc_get_order( $eo_order->get_id() ) ) ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_balance_estimate( wc_get_order( $eo_order->get_id() ) ) ) );
	$eo_result = $eo_apply( $eo_order, $eo_up );
	$eo_bal2   = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
	$eo_check( 'the balance order charges 50.85', $eo_bal2 && '50.85' === $eo_money( wc_get_order( $eo_bal2 )->get_total() ), $eo_bal2 ? $eo_money( wc_get_order( $eo_bal2 )->get_total() ) : $eo_error( $eo_result ) );
	$eo_pay( $eo_bal2 );
	$eo_down   = array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_hoodie,
			'quantity' => 2,
		),
	);
	$eo_plan   = $eo_preview( $eo_order, $eo_down );
	$eo_result = $eo_apply( $eo_order, $eo_down );
	$eo_check( '3 to 2 refunds what the third L cost: 50.85', ! is_wp_error( $eo_plan ) && '50.85' === $eo_money( $eo_plan->get_refund_amount() ) && is_array( $eo_result ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) . ' ' . $eo_error( $eo_result ) );
	$eo_remove = array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_hoodie,
		),
	);
	$eo_plan   = $eo_preview( $eo_order, $eo_remove );
	$eo_result = $eo_apply( $eo_order, $eo_remove );
	$eo_check( 'removing the rest is allowed', ! is_wp_error( $eo_plan ) && is_array( $eo_result ), $eo_error( $eo_plan ) . ' ' . $eo_error( $eo_result ) );
	$eo_full = true;
	$eo_seen = array();
	foreach ( array( $eo_order->get_id(), $eo_bal1, $eo_bal2 ) as $eo_id ) {
		$eo_seen[] = $eo_id . ': ' . $eo_money( $eo_refunded( $eo_id ) ) . ' of ' . $eo_money( wc_get_order( $eo_id )->get_total() );
		$eo_full   = $eo_full && $eo_money( $eo_refunded( $eo_id ) ) === $eo_money( wc_get_order( $eo_id )->get_total() );
	}
	$eo_check( 'every order refunded exactly what it charged, not a cent more', $eo_full, implode( ', ', $eo_seen ) );
	// Tax as stored on the refunds (unrounded when rounded at subtotal): never more than charged.
	$eo_tax_ok = true;
	$eo_seen   = array();
	foreach ( array( $eo_order->get_id(), $eo_bal1, $eo_bal2 ) as $eo_id ) {
		$eo_tax = 0.0;
		foreach ( wc_get_order( $eo_id )->get_refunds() as $eo_refund ) {
			foreach ( $eo_refund->get_items( array( 'line_item', 'fee' ) ) as $eo_refund_item ) {
				$eo_tax -= array_sum( $eo_refund_item->get_taxes()['total'] );
			}
		}
		$eo_seen[] = $eo_id . ': ' . wc_format_decimal( $eo_tax, 6 ) . ' of ' . wc_get_order( $eo_id )->get_total_tax();
		$eo_tax_ok = $eo_tax_ok && $eo_tax <= (float) wc_get_order( $eo_id )->get_total_tax() + 0.000001;
	}
	$eo_check( 'no order refunded more tax than it charged', $eo_tax_ok, implode( ', ', $eo_seen ) );
	$eo_check( 'all 3 L back in stock', $eo_l_start === $eo_stock( 'EO-HOODIE-L' ), $eo_stock( 'EO-HOODIE-L' ) . ' of ' . $eo_l_start );
}
update_option( 'woocommerce_tax_round_at_subtotal', 'no' );

// ---------------------------------------------------------------------------
WP_CLI::log( 'R6: a refund that fails after another one was made says which refunds stand' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_result = $eo_apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_tshirt,
			'quantity' => 2,
		),
	)
);
$eo_bal    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_pay( $eo_bal );
$eo_parent = $eo_order->get_id();
$eo_fail   = static function ( $refund, $args ) use ( $eo_parent ) {
	if ( (int) $args['order_id'] === $eo_parent ) {
		throw new Exception( 'Test: the refund could not be saved.' );
	}
};
add_action( 'woocommerce_create_refund', $eo_fail, 10, 2 );
$eo_result = $eo_apply(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_tshirt,
		),
	)
);
remove_action( 'woocommerce_create_refund', $eo_fail, 10 );
$eo_note = current( wc_get_order_notes( array( 'order_id' => $eo_parent ) ) );
$eo_note = $eo_note ? $eo_note->content : '';
$eo_check( 'set up: the edit failed after the balance order was refunded', is_wp_error( $eo_result ) && '22.60' === $eo_money( $eo_refunded( $eo_bal ) ) );
$eo_check( 'the order note names the refund already made: 22.60 on the balance order', false !== strpos( $eo_note, '#' . wc_get_order( $eo_bal )->get_order_number() ) && false !== strpos( $eo_note, '22.60' ), $eo_note );

// ---------------------------------------------------------------------------
WP_CLI::log( 'R7: with tax rounded at subtotal, the last line can still be removed' );
update_option( 'woocommerce_tax_round_at_subtotal', 'yes' );
$eo_cheap = new WC_Product_Simple();
$eo_cheap->set_name( 'EO R7 sticker' );
$eo_cheap->set_regular_price( '0.50' );
$eo_cheap->set_status( 'publish' );
$eo_cheap_id    = $eo_cheap->save();
$eo_t['temp'][] = $eo_cheap_id;
$eo_order       = $eo_make_order( array( array( $eo_cheap_id, 1 ), array( $eo_cheap_id, 1 ) ) );
$eo_check( 'set up: 1.00 + 0.13 tax = 1.13', '1.13' === $eo_money( $eo_order->get_total() ), $eo_money( $eo_order->get_total() ) );
foreach ( array_keys( $eo_order->get_items() ) as $eo_index => $eo_line ) {
	$eo_result = $eo_apply(
		$eo_order,
		array(
			array(
				'type'    => 'remove',
				'item_id' => $eo_line,
			),
		)
	);
	$eo_check( 'line ' . ( $eo_index + 1 ) . ' removed', is_array( $eo_result ), $eo_error( $eo_result ) );
}
$eo_check( 'refunded exactly 1.13 in all', '1.13' === $eo_money( $eo_refunded( $eo_order->get_id() ) ), $eo_money( $eo_refunded( $eo_order->get_id() ) ) );
update_option( 'woocommerce_tax_round_at_subtotal', 'no' );

// ---------------------------------------------------------------------------
WP_CLI::log( 'R8: a slow run is never marked stuck, and one marked stuck anyway is put right when it finishes' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ), array( 'EO-MUG', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_mug    = $eo_item_id( $eo_order, 'EO-MUG' );
$eo_result = $eo_apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_tshirt,
			'quantity' => 2,
		),
	)
);
$eo_bal1   = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_pay( $eo_bal1 );
// Removing the T-Shirt refunds the balance order and the order; the extra mug needs a balance
// order, so both refunds wait for its payment and run in one go.
$eo_result = $eo_apply(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_tshirt,
		),
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_mug,
			'quantity' => 2,
		),
	)
);
$eo_bal2   = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_seen   = array();
$eo_slow   = static function () use ( $eo_bal2, $eo_order, &$eo_seen ) {
	$balance = wc_get_order( $eo_bal2 );
	if ( ! $balance || ! $balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLYING_META ) ) {
		return;
	}
	$eo_seen[] = Edit_Orders_For_WooCommerce_Balance_Orders::is_stuck( $balance ) ? 'stuck' : 'running';
	// Ten minutes pass during this refund (a slow gateway), and another request looks at
	// the order: only the second refund lets it decide.
	$balance->update_meta_data( Edit_Orders_For_WooCommerce_Balance_Orders::APPLYING_META, time() - 600 );
	$balance->save();
	if ( 2 === count( $eo_seen ) ) {
		Edit_Orders_For_WooCommerce_Balance_Orders::is_applying( wc_get_order( $eo_order->get_id() ) );
	}
};
add_action( 'woocommerce_create_refund', $eo_slow );
$eo_pay( $eo_bal2 );
remove_action( 'woocommerce_create_refund', $eo_slow );
wp_cache_flush();
$eo_balance = wc_get_order( $eo_bal2 );
$eo_check( 'set up: two refunds ran while applying', 2 === count( $eo_seen ), wp_json_encode( $eo_seen ) );
$eo_check( 'after ten slow minutes on the first refund, the run still counts as running', array( 'running', 'running' ) === $eo_seen, wp_json_encode( $eo_seen ) );
$eo_check( 'the changes applied: the T-Shirt refunded on both orders', '22.60' === $eo_money( $eo_refunded( $eo_order->get_id() ) ) && '22.60' === $eo_money( $eo_refunded( $eo_bal1 ) ) );
$eo_check( 'no stuck mark or manual refund left on the balance order', ! $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::STUCK_META ) && 0.0 === (float) $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ), wp_json_encode( array( $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::STUCK_META ), $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) ) ) );
$eo_note = $eo_notes( $eo_order->get_id() );
$eo_check( 'the order says the changes did finish after all', false !== strpos( $eo_note, 'did finish' ), $eo_note );

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
foreach ( array_reverse( $eo_t['temp'] ) as $eo_product_id ) {
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
	WP_CLI::error( 'Mac review suite failed.' );
}
WP_CLI::success( 'Mac review suite passed.' );
