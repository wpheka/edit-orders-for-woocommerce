<?php
/**
 * Functional suite for the fixes from the independent review (2026-10-08,
 * tasks/edit-orders-for-woocommerce-review-2026-10-08.md).
 *
 * Each block reproduces a finding, then checks the fix.
 *
 * Run with: bash tests/functional/run.sh m6-review (or all suites without an argument).
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
foreach ( array( 'admin/class-edit-orders-for-woocommerce-admin.php', 'admin/class-edit-orders-for-woocommerce-activity.php' ) as $eo_file ) {
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/' . $eo_file;
}
if ( ! class_exists( 'WC_Settings_Page', false ) ) {
	require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
}
if ( ! class_exists( 'WC_Admin_Settings', false ) ) {
	require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
}

Edit_Orders_For_WooCommerce_Audit_Log::maybe_install();
wp_set_current_user( 1 );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_settings_before = get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );

foreach ( array( 'EO-TSHIRT', 'EO-MUG', 'EO-POSTER', 'EO-HOODIE-XS', 'EO-HOODIE-S', 'EO-HOODIE-M', 'EO-HOODIE-L' ) as $eo_sku ) {
	$eo_t['product'][ $eo_sku ]                   = wc_get_product_id_by_sku( $eo_sku );
	$eo_t['stock'][ $eo_t['product'][ $eo_sku ] ] = wc_get_product( $eo_t['product'][ $eo_sku ] )->get_stock_quantity();
}
$eo_customer = get_user_by( 'login', 'customer' );

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
		$order->add_product( wc_get_product( $eo_t['product'][ $line[0] ] ), $line[1] );
	}
	$zone     = WC_Shipping_Zones::get_zone_matching_package(
		array(
			'destination' => array(
				'country'  => 'CA',
				'state'    => 'ON',
				'postcode' => 'M5E 1E5',
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
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()[ $gateway ] );
	$order->save();
	if ( 'cod' === $gateway ) {
		$order->update_status( 'processing' );
	} else {
		$order->payment_complete( 'TEST-' . $order->get_id() );
	}
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

$eo_find_mail = static function ( $subject_part ) {
	foreach ( array_reverse( get_option( Edit_Orders_Mail_Log::OPTION, array() ) ) as $mail ) {
		if ( false !== stripos( $mail['subject'], $subject_part ) ) {
			return $mail;
		}
	}
	return null;
};


// ---------------------------------------------------------------------------
WP_CLI::log( 'H1: a line with units or a price difference paid on a balance order is locked' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_tshirt,
			'quantity' => 3,
		),
	)
);
$eo_check( 'quantity 1 to 3 asks for a balance', is_array( $eo_result ) && 'balance_due' === $eo_result['status'] );
$eo_pay( $eo_result['balance_order_id'] );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_remove = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_tshirt,
		),
	)
);
$eo_check( 'once paid, removing the T-Shirt is refused (2 of its units are on the balance order)', is_wp_error( $eo_remove ) && false !== strpos( $eo_remove->get_error_message(), 'balance order' ), is_wp_error( $eo_remove ) ? $eo_remove->get_error_message() : 'allowed: refund ' . $eo_money( $eo_remove->get_refund_amount() ) );
$eo_address = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'     => 'address',
			'shipping' => array(
				'address_1' => '100 8 Ave SW',
				'city'      => 'Calgary',
				'state'     => 'AB',
				'postcode'  => 'T2P 1B3',
			),
		),
	)
);
$eo_check( 'and the address can no longer be changed here', is_wp_error( $eo_address ) && false !== strpos( $eo_address->get_error_message(), 'balance order' ), is_wp_error( $eo_address ) ? $eo_address->get_error_message() : 'allowed' );

$eo_order  = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ), array( 'EO-MUG', 1 ) ) );
$eo_hoodie = $eo_item_id( $eo_order, 'EO-HOODIE-S' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_hoodie,
			'variation_id' => $eo_t['product']['EO-HOODIE-L'],
		),
	)
);
$eo_pay( $eo_result['balance_order_id'] );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_remove = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_hoodie,
		),
	)
);
$eo_check( 'after a paid dearer swap, removing the hoodie is refused (its price difference is on the balance order)', is_wp_error( $eo_remove ) && false !== strpos( $eo_remove->get_error_message(), 'balance order' ), is_wp_error( $eo_remove ) ? $eo_remove->get_error_message() : 'allowed' );
$eo_mug = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_item_id( $eo_order, 'EO-MUG' ),
		),
	)
);
$eo_check( 'other lines on the order can still be changed', ! is_wp_error( $eo_mug ), is_wp_error( $eo_mug ) ? $eo_mug->get_error_message() : '' );

// ---------------------------------------------------------------------------
WP_CLI::log( 'H2: free shipping on an address change is decided from the order, not the visitor\'s cart' );
$eo_zone_for = static function ( $state, $postcode ) {
	return WC_Shipping_Zones::get_zone_matching_package(
		array(
			'destination' => array(
				'country'  => 'CA',
				'state'    => $state,
				'postcode' => $postcode,
			),
		)
	);
};
$eo_free     = array();
foreach ( array( array( 'ON', 'M5E 1E5' ), array( 'AB', 'T2P 1B3' ) ) as $eo_place ) {
	$eo_zone     = $eo_zone_for( $eo_place[0], $eo_place[1] );
	$eo_instance = $eo_zone->add_shipping_method( 'free_shipping' );
	update_option(
		'woocommerce_free_shipping_' . $eo_instance . '_settings',
		array(
			'title'            => 'Free shipping',
			'requires'         => 'min_amount',
			'min_amount'       => '50',
			'ignore_discounts' => 'no',
		)
	);
	$eo_free[ $eo_place[0] ] = array( $eo_zone, $eo_instance );
}
WC_Cache_Helper::get_transient_version( 'shipping', true );

// A $60 order that got free shipping in Ontario moves to Alberta, where free shipping over $50 is offered too.
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ) );
foreach ( $eo_order->get_shipping_methods() as $eo_ship ) {
	$eo_ship->set_method_id( 'free_shipping' );
	$eo_ship->set_instance_id( $eo_free['ON'][1] );
	$eo_ship->set_method_title( 'Free shipping' );
	$eo_ship->set_total( 0 );
	$eo_ship->set_taxes( array() );
	$eo_ship->save();
}
$eo_order->calculate_totals();
$eo_order->save();
$eo_plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'     => 'address',
			'shipping' => array(
				'address_1' => '100 8 Ave SW',
				'city'      => 'Calgary',
				'state'     => 'AB',
				'postcode'  => 'T2P 1B3',
			),
		),
	)
);
$eo_check( 'a $60 order keeps free shipping in the new zone: nothing to pay, only the tax difference (HST 7.80 to GST 3.00) refunded', ! is_wp_error( $eo_plan ) && '0.00' === $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ) && '4.80' === $eo_money( $eo_plan->get_refund_amount() ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : 'balance ' . $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ) . ', refund ' . $eo_money( $eo_plan->get_refund_amount() ) );

// A $20 order can't pick free shipping, even with $100 of T-Shirts in the visitor's cart.
if ( ! WC()->cart ) {
	wc_load_cart();
}
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $eo_t['product']['EO-TSHIRT'], 5 );
WC()->cart->calculate_totals();
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_plan  = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'            => 'address',
			'shipping'        => array(
				'address_1' => '100 8 Ave SW',
				'city'      => 'Calgary',
				'state'     => 'AB',
				'postcode'  => 'T2P 1B3',
			),
			'shipping_method' => 'free_shipping:' . $eo_free['AB'][1],
		),
	)
);
$eo_check( 'a $20 order choosing free shipping is refused', is_wp_error( $eo_plan ), is_wp_error( $eo_plan ) ? '' : implode( ' ', $eo_plan->get_descriptions() ) );
WC()->cart->empty_cart();

foreach ( $eo_free as $eo_pair ) {
	$eo_pair[0]->delete_shipping_method( $eo_pair[1] );
	delete_option( 'woocommerce_free_shipping_' . $eo_pair[1] . '_settings' );
}
WC_Cache_Helper::get_transient_version( 'shipping', true );

// ---------------------------------------------------------------------------
WP_CLI::log( 'M8: no "your order has been refunded" email from WooCommerce when no money moved' );
$eo_refund_mails = static function ( WC_Order $order ) {
	$found = 0;
	foreach ( get_option( Edit_Orders_Mail_Log::OPTION, array() ) as $mail ) {
		if ( false !== stripos( $mail['subject'], 'refunded' ) && false !== strpos( $mail['subject'], '#' . $order->get_order_number() ) ) {
			++$found;
		}
	}
	return $found;
};
$eo_decrease     = static function ( WC_Order $order ) use ( $eo_item_id ) {
	return Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
		$order,
		array(
			array(
				'type'     => 'quantity',
				'item_id'  => $eo_item_id( $order, 'EO-TSHIRT' ),
				'quantity' => 1,
			),
		)
	);
};
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ), 0, 'cod' );
$eo_decrease( $eo_order );
$eo_check( 'unpaid cash on delivery made cheaper: no refunded email', 0 === $eo_refund_mails( $eo_order ), $eo_refund_mails( $eo_order ) . ' sent' );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ), 0, 'bacs' );
$eo_decrease( $eo_order );
$eo_check( 'bank transfer (no automatic refunds): no refunded email while the refund waits to be made by hand', 0 === $eo_refund_mails( $eo_order ), $eo_refund_mails( $eo_order ) . ' sent' );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_decrease( $eo_order );
$eo_check( 'a real refund through the gateway still sends WooCommerce\'s refunded email', 1 === $eo_refund_mails( $eo_order ), $eo_refund_mails( $eo_order ) . ' sent' );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
Edit_Orders_For_WooCommerce_Cancellation::execute( $eo_order, 'customer' );
$eo_check( 'cancelling: the plugin\'s cancellation email says what was refunded, so no second refunded email', 0 === $eo_refund_mails( $eo_order ), $eo_refund_mails( $eo_order ) . ' sent' );

// ---------------------------------------------------------------------------
WP_CLI::log( 'M1: a change that costs nothing more never waits for a payment that can\'t happen' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'       => 'add',
			'product_id' => $eo_t['product']['EO-POSTER'],
			'quantity'   => 1,
			'price'      => '0',
		),
	)
);
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_zero   = is_array( $eo_result ) && ! empty( $eo_result['balance_order_id'] ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_check( 'adding a product at 0.00: applied at once, nothing left waiting', $eo_zero && '0.00' === $eo_money( $eo_zero->get_total() ) && $eo_zero->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_META ) && ! $eo_order->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::OPEN_BALANCE_META ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : ( $eo_zero ? $eo_zero->get_status() . ', applied ' . ( $eo_zero->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_META ) ? 'yes' : 'no' ) : 'no balance order' ) );
$eo_check( 'and the order can be edited again', true === Edit_Orders_For_WooCommerce_Eligibility::check_order( $eo_order ), is_wp_error( Edit_Orders_For_WooCommerce_Eligibility::check_order( $eo_order ) ) ? Edit_Orders_For_WooCommerce_Eligibility::check_order( $eo_order )->get_error_message() : '' );

// ---------------------------------------------------------------------------
WP_CLI::log( 'M2: a late payment on a cancelled balance order doesn\'t apply it or unblock a newer one' );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ), array( 'EO-MUG', 1 ) ) );
$eo_first = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_id( $eo_order, 'EO-TSHIRT' ),
			'quantity' => 2,
		),
	)
);
wc_get_order( $eo_first['balance_order_id'] )->update_status( 'cancelled' );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_second = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_id( $eo_order, 'EO-MUG' ),
			'quantity' => 2,
		),
	)
);
$eo_pay( $eo_first['balance_order_id'] );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_late  = wc_get_order( $eo_first['balance_order_id'] );
$eo_check( 'the newer balance order still blocks other edits', (int) $eo_order->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::OPEN_BALANCE_META ) === (int) $eo_second['balance_order_id'] );
$eo_check( 'the late payment\'s change is not applied', ! in_array( (int) $eo_first['balance_order_id'], array_map( 'intval', (array) $eo_order->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_BALANCES_META ) ), true ) && ! $eo_order->get_item( $eo_item_id( $eo_order, 'EO-TSHIRT' ) )->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::LINE_META ) );
$eo_check( 'and the money taken on it is flagged for a manual refund', (float) $eo_late->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) > 0 );

// ---------------------------------------------------------------------------
WP_CLI::log( 'M3: a balance order paid after the store shipped or changed the order is not applied' );
$eo_order  = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item_id( $eo_order, 'EO-HOODIE-S' ),
			'variation_id' => $eo_t['product']['EO-HOODIE-L'],
		),
	)
);
wc_get_order( $eo_order->get_id() )->update_status( 'completed' );
$eo_late = wc_get_order( $eo_result['balance_order_id'] );
$eo_check( 'completing (shipping) the order cancels its unpaid balance order', $eo_late->has_status( 'cancelled' ), $eo_late->get_status() );
$eo_pay( $eo_result['balance_order_id'] );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'if it is paid anyway, the shipped hoodie is not swapped', $eo_item_id( $eo_order, 'EO-HOODIE-S' ) > 0 && 0 === $eo_item_id( $eo_order, 'EO-HOODIE-L' ) );

$eo_order  = $eo_make_order( array( array( 'EO-HOODIE-S', 2 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item_id( $eo_order, 'EO-HOODIE-S' ),
			'variation_id' => $eo_t['product']['EO-HOODIE-L'],
		),
	)
);
// The store changes the line in WooCommerce's own editor while the customer is still paying.
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_line  = $eo_order->get_item( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) );
$eo_line->set_quantity( 1 );
$eo_line->set_subtotal( 40 );
$eo_line->set_total( 40 );
$eo_line->save();
$eo_order->calculate_totals();
$eo_pay( $eo_result['balance_order_id'] );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_paid  = wc_get_order( $eo_result['balance_order_id'] );
$eo_check( 'a line changed by the store since: the swap is not applied', $eo_item_id( $eo_order, 'EO-HOODIE-S' ) > 0 && 0 === $eo_item_id( $eo_order, 'EO-HOODIE-L' ) );
$eo_check( 'and the payment is flagged for a manual refund', (float) $eo_paid->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) > 0 );

// ---------------------------------------------------------------------------
WP_CLI::log( 'M9: replacing every item keeps the order open while the new items are on their way' );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
foreach ( $eo_order->get_shipping_methods() as $eo_ship_id => $eo_ship ) {
	$eo_order->remove_item( $eo_ship_id );
}
$eo_order->calculate_totals();
$eo_order->save();
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_item_id( $eo_order, 'EO-TSHIRT' ),
		),
		array(
			'type'       => 'add',
			'product_id' => $eo_t['product']['EO-MUG'],
			'quantity'   => 1,
		),
	)
);
$eo_pay( $eo_result['balance_order_id'] );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'T-Shirt (the whole order) swapped for a Mug: refunded in full, but still Processing, not Refunded', $eo_order->has_status( 'processing' ) && $eo_money( $eo_order->get_total() ) === $eo_money( $eo_order->get_total_refunded() ), $eo_order->get_status() . ', refunded ' . $eo_money( $eo_order->get_total_refunded() ) . ' of ' . $eo_money( $eo_order->get_total() ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'M4 to M6: taxes on an address change follow WooCommerce' );
$eo_to_alberta   = array(
	array(
		'type'     => 'address',
		'shipping' => array(
			'address_1' => '100 8 Ave SW',
			'city'      => 'Calgary',
			'state'     => 'AB',
			'postcode'  => 'T2P 1B3',
		),
	),
);
$eo_temp_product = static function ( $tax_status ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Review temp ' . $tax_status );
	$product->set_regular_price( '12' );
	$product->set_tax_status( $tax_status );
	$product->save();
	return $product;
};
$eo_make_with    = static function ( WC_Product $product ) use ( $eo_make_order, &$eo_t ) {
	$eo_t['product']['TEMP'] = $product->get_id();
	return $eo_make_order( array( array( 'TEMP', 1 ) ) );
};

// M4: goods that aren't taxed, a taxable flat rate. WooCommerce charges no shipping tax ("inherit" finds no class).
$eo_untaxed = $eo_temp_product( 'none' );
$eo_order   = $eo_make_with( $eo_untaxed );
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_to_alberta );
$eo_check( 'untaxed goods: shipping 10 to 20 costs exactly 10.00, no shipping tax added', ! is_wp_error( $eo_plan ) && 0.0 === (float) $eo_order->get_shipping_tax() && '10.00' === $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : 'shipping tax was ' . $eo_money( $eo_order->get_shipping_tax() ) . ', balance ' . $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ) );

// M6: the same change with the product deleted settles like it does with the product.
$eo_taxed = $eo_temp_product( 'taxable' );
$eo_a     = $eo_make_with( $eo_taxed );
$eo_b     = $eo_make_with( $eo_taxed );
$eo_plan  = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_a, $eo_to_alberta );
$eo_with  = is_wp_error( $eo_plan ) ? 'error' : $eo_money( $eo_plan->get_refund_amount() ) . '/' . $eo_money( $eo_plan->get_balance_estimate( $eo_a ) );
$eo_taxed->delete( true );
$eo_b    = wc_get_order( $eo_b->get_id() );
$eo_plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_b, $eo_to_alberta );
$eo_gone = is_wp_error( $eo_plan ) ? 'error: ' . $eo_plan->get_error_message() : $eo_money( $eo_plan->get_refund_amount() ) . '/' . $eo_money( $eo_plan->get_balance_estimate( $eo_b ) );
$eo_check( 'a deleted product is still taxed like the order: same refund and balance as with the product', $eo_with === $eo_gone, "with product {$eo_with}, deleted {$eo_gone}" );
$eo_untaxed->delete( true );
unset( $eo_t['product']['TEMP'] );

// M5: local pickup is taxed at the shop (Ontario), whatever the customer's address.
$eo_rest   = WC_Shipping_Zones::get_zone_matching_package(
	array(
		'destination' => array(
			'country'  => 'CA',
			'state'    => 'AB',
			'postcode' => 'T2P 1B3',
		),
	)
);
$eo_pickup = $eo_rest->add_shipping_method( 'local_pickup' );
WC_Cache_Helper::get_transient_version( 'shipping', true );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_order->set_address(
	array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '100 8 Ave SW',
		'city'       => 'Calgary',
		'state'      => 'AB',
		'postcode'   => 'T2P 1B3',
		'country'    => 'CA',
	),
	'shipping'
);
foreach ( $eo_order->get_shipping_methods() as $eo_ship ) {
	$eo_ship->set_method_id( 'local_pickup' );
	$eo_ship->set_instance_id( $eo_pickup );
	$eo_ship->set_method_title( 'Local pickup' );
	$eo_ship->set_total( 0 );
	$eo_ship->save();
}
$eo_order->calculate_totals();
$eo_order->save();
$eo_plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'     => 'address',
			'shipping' => array(
				'address_1' => '800 Robson St',
				'city'      => 'Vancouver',
				'state'     => 'BC',
				'postcode'  => 'V6Z 3B7',
			),
		),
	)
);
$eo_check( 'local pickup taxed at the shop: a new customer address moves no tax (nothing refunded or charged)', ! is_wp_error( $eo_plan ) && 0.0 === (float) $eo_plan->get_refund_amount() && 0.0 === (float) $eo_plan->get_balance_estimate( $eo_order ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : 'refund ' . $eo_money( $eo_plan->get_refund_amount() ) . ', balance ' . $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ) );
$eo_rest->delete_shipping_method( $eo_pickup );
WC_Cache_Helper::get_transient_version( 'shipping', true );

// ---------------------------------------------------------------------------
WP_CLI::log( 'M7: cancelling after an address change that moved tax between rates leaves no tax booked' );
$eo_alberta = array(
	'first_name' => 'Casey',
	'last_name'  => 'Customer',
	'address_1'  => '100 8 Ave SW',
	'city'       => 'Calgary',
	'state'      => 'AB',
	'postcode'   => 'T2P 1B3',
	'country'    => 'CA',
);
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_order->set_address( array_merge( $eo_alberta, array( 'email' => 'customer@example.com' ) ), 'billing' );
$eo_order->set_address( $eo_alberta, 'shipping' );
$eo_ab_zone = WC_Shipping_Zones::get_zone_matching_package(
	array(
		'destination' => array(
			'country'  => 'CA',
			'state'    => 'AB',
			'postcode' => 'T2P 1B3',
		),
	)
);
$eo_ab_flat = current( $eo_ab_zone->get_shipping_methods( true ) );
foreach ( $eo_order->get_shipping_methods() as $eo_ship ) {
	$eo_ship->set_instance_id( $eo_ab_flat->get_instance_id() );
	$eo_ship->set_total( $eo_ab_flat->get_option( 'cost' ) );
	$eo_ship->save();
}
$eo_order->calculate_totals();
$eo_order->save();
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'address',
			'shipping' => array(
				'address_1' => '1 Yonge St',
				'city'      => 'Toronto',
				'state'     => 'ON',
				'postcode'  => 'M5E 1E5',
			),
		),
	)
);
$eo_check( 'Alberta to Ontario applied as one netted refund (GST back, HST charged)', is_array( $eo_result ) && ! empty( $eo_result['plan'] ) && $eo_result['plan']->get_refund_amount() > 0, is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_order = wc_get_order( $eo_order->get_id() );
Edit_Orders_For_WooCommerce_Cancellation::execute( $eo_order, 'customer' );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_left  = array();
foreach ( $eo_order->get_items( array( 'line_item', 'shipping' ) ) as $eo_line_id => $eo_line ) {
	$eo_rates = array_keys( (array) ( $eo_line->get_taxes()['total'] ?? array() ) );
	foreach ( $eo_order->get_refunds() as $eo_refund ) {
		foreach ( $eo_refund->get_items( $eo_line->get_type() ) as $eo_refunded ) {
			if ( (int) $eo_refunded->get_meta( '_refunded_item_id' ) === (int) $eo_line_id ) {
				$eo_rates = array_merge( $eo_rates, array_keys( (array) ( $eo_refunded->get_taxes()['total'] ?? array() ) ) );
			}
		}
	}
	foreach ( array_unique( $eo_rates ) as $eo_rate ) {
		$eo_paid = (float) ( $eo_line->get_taxes()['total'][ $eo_rate ] ?? 0 );
		$eo_rest = $eo_paid - (float) $eo_order->get_tax_refunded_for_item( $eo_line_id, $eo_rate, $eo_line->get_type() );
		if ( abs( $eo_rest ) > 0.001 ) {
			$eo_left[] = $eo_line->get_name() . ' rate ' . $eo_rate . ': ' . $eo_money( $eo_rest );
		}
	}
}
$eo_check( 'after cancelling, no tax is left booked on any line, for any rate', array() === $eo_left && $eo_money( $eo_order->get_total() ) === $eo_money( $eo_order->get_total_refunded() ), implode( '; ', $eo_left ) . ' | refunded ' . $eo_money( $eo_order->get_total_refunded() ) . ' of ' . $eo_money( $eo_order->get_total() ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Low findings' );

// L3: a cheaper swap on a line that cost nothing makes no empty refund.
$eo_free_coupon = new WC_Coupon();
$eo_free_coupon->set_code( 'eo-review-100' );
$eo_free_coupon->set_discount_type( 'percent' );
$eo_free_coupon->set_amount( 100 );
$eo_free_coupon->save();
$eo_order = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_order->apply_coupon( 'eo-review-100' );
$eo_order->save();
$eo_plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item_id( $eo_order, 'EO-HOODIE-S' ),
			'variation_id' => $eo_t['product']['EO-HOODIE-XS'],
		),
	)
);
$eo_check( 'L3: cheaper swap on a free line: no 0.00 refund planned', ! is_wp_error( $eo_plan ) && array() === $eo_plan->get_refund_lines(), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : wp_json_encode( $eo_plan->get_refund_lines() ) );
$eo_free_coupon->delete( true );

// L9: a page shown without a key (order tracking) is not counted as a wrong key.
wp_set_current_user( 0 );
delete_transient( 'eofw_badkey_' . md5( WC_Geolocation::get_ip_address() ) );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
for ( $eo_i = 0; $eo_i < 12; $eo_i++ ) {
	Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_order, '' );
}
$eo_check( 'L9: twelve views without a key don\'t lock the visitor out', ! Edit_Orders_For_WooCommerce_Customer_Rules::is_rate_limited() );
delete_transient( 'eofw_badkey_' . md5( WC_Geolocation::get_ip_address() ) );

// L11: only the store's own cancel reasons are accepted from the form.
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
Edit_Orders_For_WooCommerce_Frontend::process(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'cancel',
		'reason'                             => 'Not a reason from the list',
	),
	'guest'
);
$eo_request = wc_get_order( $eo_order->get_id() )->get_meta( Edit_Orders_For_WooCommerce_Cancellation::REQUEST_META );
$eo_check( 'L11: a reason that isn\'t in the store\'s list is not stored', is_array( $eo_request ) && '' === $eo_request['reason'], wp_json_encode( $eo_request ) );
wp_set_current_user( 1 );

// L1: while a paid balance waits to be applied, the order can't be edited again.
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ), array( 'EO-MUG', 1 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_id( $eo_order, 'EO-TSHIRT' ),
			'quantity' => 2,
		),
	)
);
remove_action( 'woocommerce_payment_complete', array( 'Edit_Orders_For_WooCommerce_Balance_Orders', 'maybe_apply' ) );
remove_action( 'woocommerce_order_status_processing', array( 'Edit_Orders_For_WooCommerce_Balance_Orders', 'maybe_apply' ) );
$eo_pay( $eo_result['balance_order_id'] );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'L1: paid, not applied yet: further edits wait', is_wp_error( Edit_Orders_For_WooCommerce_Eligibility::check_order( $eo_order ) ) && 'edit_orders_for_woocommerce_applying' === Edit_Orders_For_WooCommerce_Eligibility::check_order( $eo_order )->get_error_code() );
add_action( 'woocommerce_payment_complete', array( 'Edit_Orders_For_WooCommerce_Balance_Orders', 'maybe_apply' ) );
add_action( 'woocommerce_order_status_processing', array( 'Edit_Orders_For_WooCommerce_Balance_Orders', 'maybe_apply' ) );
Edit_Orders_For_WooCommerce_Balance_Orders::maybe_apply( $eo_result['balance_order_id'] );

// L6: cancelling from WooCommerce's own screen names the paid balance order to refund.
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_order->update_status( 'cancelled' );
$eo_notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $eo_order->get_id() ) ), 'content' ) );
$eo_check( 'L6: cancelled from WooCommerce: a note names the paid balance order to refund', false !== strpos( $eo_notes, 'still holds' ), $eo_notes );

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
foreach ( $eo_t['stock'] as $eo_product_id => $eo_quantity ) {
	if ( null !== $eo_quantity ) {
		wc_update_product_stock( $eo_product_id, $eo_quantity, 'set' );
	}
}
if ( null === $eo_settings_before ) {
	delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
} else {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $eo_settings_before );
}
Edit_Orders_For_WooCommerce_Cancellation::forget_pending_count();
delete_option( Edit_Orders_Mail_Log::OPTION );

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'Review suite failed.' );
}
WP_CLI::success( 'Review suite passed.' );
