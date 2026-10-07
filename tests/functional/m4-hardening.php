<?php
/**
 * Functional suite for the pre-release review fixes (2026-10-07).
 *
 * Each block reproduces a problem found by reading the code, then checks the fix:
 * money caps, unavailable variations, stale balance orders, double submits,
 * preview and apply mismatches, cancellation with linked orders, guest access,
 * the activity log and settings.
 *
 * Run with: bash tests/functional/run.sh m4-hardening (or all suites without an argument).
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

$eo_product = static function ( $sku ) use ( &$eo_t ) {
	return wc_get_product( $eo_t['product'][ $sku ] );
};

WP_CLI::log( 'HPOS ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'A swap refund is never more than was paid on the line' );
$eo_order = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_s     = $eo_product( 'EO-HOODIE-S' );
$eo_s->set_regular_price( '100' );
$eo_s->save();
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
$eo_s->set_regular_price( '40' );
$eo_s->save();
$eo_line = is_wp_error( $eo_plan ) ? array() : current( $eo_plan->get_refund_lines() );
$eo_item = $eo_order->get_item( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) );
$eo_check( 'S bought at 40, now listed at 100, swapped to XS at 35: refund capped at the 40 paid', $eo_line && '40.00' === $eo_money( $eo_line['refund_total'] ), $eo_line ? $eo_money( $eo_line['refund_total'] ) : 'no plan' );
$eo_check( 'and its tax capped at the line tax', $eo_line && $eo_money( array_sum( $eo_line['refund_tax'] ) ) === $eo_money( $eo_item->get_total_tax() ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Disabled or unpriced variations can\'t be swapped to' );
$eo_m = $eo_product( 'EO-HOODIE-M' );
$eo_m->set_status( 'private' );
$eo_m->save();
$eo_order = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ), $eo_customer->ID );
$eo_plan  = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item_id( $eo_order, 'EO-HOODIE-S' ),
			'variation_id' => $eo_t['product']['EO-HOODIE-M'],
		),
	),
	'customer'
);
$eo_check( 'swap to a disabled variation refused', is_wp_error( $eo_plan ) && false !== strpos( $eo_plan->get_error_message(), 'not available' ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : 'allowed' );
$eo_args = Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_order, '' );
$eo_check( 'and not offered in the customer panel', $eo_args && ! isset( $eo_args['swap_options'][ $eo_item_id( $eo_order, 'EO-HOODIE-S' ) ]['options'][ $eo_t['product']['EO-HOODIE-M'] ] ) && isset( $eo_args['swap_options'][ $eo_item_id( $eo_order, 'EO-HOODIE-S' ) ]['options'][ $eo_t['product']['EO-HOODIE-L'] ] ) );
$eo_m->set_status( 'publish' );
$eo_m->save();

// ---------------------------------------------------------------------------
WP_CLI::log( 'More units of a product marked out of stock are refused' );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_mug   = $eo_product( 'EO-MUG' );
$eo_was   = array( $eo_mug->get_manage_stock(), $eo_mug->get_stock_status() );
$eo_mug->set_manage_stock( false );
$eo_mug->set_stock_status( 'outofstock' );
$eo_mug->save();
$eo_plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_id( $eo_order, 'EO-MUG' ),
			'quantity' => 2,
		),
	)
);
$eo_check( 'quantity up on an out-of-stock product without stock management: refused', is_wp_error( $eo_plan ) && false !== strpos( $eo_plan->get_error_message(), 'not enough stock' ) );
$eo_mug->set_manage_stock( $eo_was[0] );
$eo_mug->set_stock_status( $eo_was[1] );
$eo_mug->save();

// ---------------------------------------------------------------------------
WP_CLI::log( 'Balance orders follow VAT exemption and the product\'s tax status' );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_order->update_meta_data( 'is_vat_exempt', 'yes' );
$eo_order->save();
$eo_changes = array(
	array(
		'type'     => 'quantity',
		'item_id'  => $eo_item_id( $eo_order, 'EO-TSHIRT' ),
		'quantity' => 2,
	),
);
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
$eo_result  = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes );
$eo_balance = is_array( $eo_result ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_check( 'VAT-exempt customer: no tax on the balance order, and it stays exempt', $eo_balance && 0.0 === (float) $eo_balance->get_total_tax() && 'yes' === $eo_balance->get_meta( 'is_vat_exempt' ) );
$eo_check( 'the preview showed that same amount', $eo_balance && ! is_wp_error( $eo_plan ) && $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ) === $eo_money( $eo_balance->get_total() ) );

// An address change re-taxes every line for the new location: an exempt order stays untaxed.
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_order->update_meta_data( 'is_vat_exempt', 'yes' );
$eo_order->calculate_totals();
$eo_order->save();
$eo_changes = array(
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
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
$eo_result  = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes );
$eo_balance = is_array( $eo_result ) && ! empty( $eo_result['balance_order_id'] ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_check( 'VAT-exempt order moved Ontario to Alberta: no tax before the change', 0.0 === (float) $eo_order->get_total_tax() );
$eo_check( 'it owes only the $10.00 shipping difference, with no tax added', ! is_wp_error( $eo_plan ) && '10.00' === $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ) && 0.0 === (float) $eo_plan->get_refund_amount(), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ) );
$eo_check( 'the balance order charges no tax', $eo_balance && '10.00' === $eo_money( $eo_balance->get_total() ) && 0.0 === (float) $eo_balance->get_total_tax(), $eo_balance ? $eo_money( $eo_balance->get_total() ) : 'no balance order' );

// Variations take their tax status from the parent product.
// WooCommerce caches product objects within a request; a new page load reads the parent fresh.
$eo_l = wc_get_product( $eo_product( 'EO-HOODIE-L' )->get_parent_id() );
$eo_l->set_tax_status( 'none' );
$eo_l->save();
wp_cache_flush();
$eo_order   = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_changes = array(
	array(
		'type'         => 'swap',
		'item_id'      => $eo_item_id( $eo_order, 'EO-HOODIE-S' ),
		'variation_id' => $eo_t['product']['EO-HOODIE-L'],
	),
);
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
$eo_result  = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes );
$eo_balance = is_array( $eo_result ) && ! empty( $eo_result['balance_order_id'] ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_check( 'dearer swap to a variation that isn\'t taxed: the $5.00 difference carries no tax', $eo_balance && '5.00' === $eo_money( $eo_balance->get_total() ) && 0.0 === (float) $eo_balance->get_total_tax(), $eo_balance ? $eo_money( $eo_balance->get_total() ) : 'no balance' );
$eo_check( 'and the preview matched', $eo_balance && ! is_wp_error( $eo_plan ) && '5.00' === $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ) );
$eo_l->set_tax_status( 'taxable' );
$eo_l->save();
wp_cache_flush();

// ---------------------------------------------------------------------------
WP_CLI::log( 'Swapping from a variation without stock management takes stock from the new one' );
$eo_s = $eo_product( 'EO-HOODIE-S' );
$eo_s->set_manage_stock( false );
$eo_s->save();
$eo_order    = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_xs_stock = $eo_product( 'EO-HOODIE-XS' )->get_stock_quantity();
$eo_result   = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item_id( $eo_order, 'EO-HOODIE-S' ),
			'variation_id' => $eo_t['product']['EO-HOODIE-XS'],
		),
	)
);
$eo_item     = wc_get_order( $eo_order->get_id() )->get_item( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) );
$eo_check( 'XS stock down by 1', is_array( $eo_result ) && $eo_xs_stock - 1 === $eo_product( 'EO-HOODIE-XS' )->get_stock_quantity(), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_check( 'the line records it, so a later refund restocks XS', $eo_item && 1 === (int) $eo_item->get_meta( '_reduced_stock', true ) );
$eo_s->set_manage_stock( true );
$eo_s->save();

// ---------------------------------------------------------------------------
WP_CLI::log( 'Cancelling the original from WooCommerce cancels its unpaid balance order' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
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
wc_get_order( $eo_order->get_id() )->update_status( 'cancelled' );
$eo_balance = is_array( $eo_result ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_check( 'balance order cancelled, so it can\'t be paid', $eo_balance && 'cancelled' === $eo_balance->get_status() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'A balance paid after the original was refunded by hand applies nothing and says so' );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_id( $eo_order, 'EO-TSHIRT' ),
			'quantity' => 1,
		),
		array(
			'type'       => 'add',
			'product_id' => $eo_t['product']['EO-POSTER'],
			'quantity'   => 4,
		),
	)
);
wc_create_refund(
	array(
		'order_id' => $eo_order->get_id(),
		'amount'   => '1.00',
		'reason'   => 'Goodwill, by hand',
	)
);
if ( is_array( $eo_result ) && ! empty( $eo_result['balance_order_id'] ) ) {
	$eo_pay( $eo_result['balance_order_id'] );
}
$eo_order   = wc_get_order( $eo_order->get_id() );
$eo_balance = is_array( $eo_result ) && ! empty( $eo_result['balance_order_id'] ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_notes   = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $eo_order->get_id() ) ), 'content' ) );
$eo_rows    = Edit_Orders_For_WooCommerce_Audit_Log::for_order( $eo_order->get_id() );
$eo_check( 'the plan had a refund and a balance', $eo_balance && 'balance_due' === $eo_result['status'] );
$eo_check( 'nothing more refunded on the original: only the 1.00 by hand', '1.00' === $eo_money( $eo_order->get_total_refunded() ) && 3 === (int) $eo_order->get_item( $eo_item_id( $eo_order, 'EO-TSHIRT' ) )->get_quantity() );
$eo_check( 'the order note says the changes were not applied (not "applied")', false !== strpos( $eo_notes, 'changes were not applied' ) && false === strpos( $eo_notes, 'paid; changes applied' ) );
$eo_check( 'the balance order is flagged for a manual refund of what was paid', $eo_balance && $eo_money( $eo_balance->get_total() ) === $eo_money( $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) ) );
$eo_check( 'the activity log says "changes failed"', $eo_rows && 'balance_paid_failed' === $eo_rows[0]['action'] );
$eo_mail = $eo_find_mail( 'manual refund' );
$eo_check( 'the store owner is emailed, with the reason and no claim the refund is recorded', $eo_mail && false !== strpos( $eo_mail['message'], 'could not be applied' ) && false === strpos( $eo_mail['message'], 'already recorded' ), $eo_mail ? '' : 'no email' );
$eo_check( 'the customer is not told the order was updated', null === $eo_find_mail( 'was updated' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'One apply at a time per order' );
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ) );
$eo_changes = array(
	array(
		'type'     => 'quantity',
		'item_id'  => $eo_item_id( $eo_order, 'EO-TSHIRT' ),
		'quantity' => 2,
	),
);
Edit_Orders_For_WooCommerce_Lock::claim( 'apply_' . $eo_order->get_id() );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes );
$eo_check( 'a second apply while one is running is refused', is_wp_error( $eo_result ) && 'edit_orders_for_woocommerce_busy' === $eo_result->get_error_code() );
Edit_Orders_For_WooCommerce_Lock::unclaim( 'apply_' . $eo_order->get_id() );
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->options,
	array(
		'option_name'  => 'edit_orders_for_woocommerce_guard_apply_' . $eo_order->get_id(),
		'option_value' => (string) ( time() - 300 ),
		'autoload'     => 'no',
	)
);
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes );
$eo_check( 'a guard left by a request that died is ignored after a minute', is_array( $eo_result ) && 'applied' === $eo_result['status'] );
$eo_check( 'and is gone afterwards', null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'edit_orders_for_woocommerce_guard_apply_' . $eo_order->get_id() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$eo_again = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $eo_order->get_id() ), $eo_changes );
$eo_check( 'the same change sent twice in a row is refused the second time', is_wp_error( $eo_again ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'What is applied must be what was previewed' );
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ) );
$eo_changes = array(
	array(
		'type'     => 'quantity',
		'item_id'  => $eo_item_id( $eo_order, 'EO-TSHIRT' ),
		'quantity' => 2,
	),
);
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
$eo_result  = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes, 'staff', array( 'expect' => 'abcdef123456' ) );
$eo_check( 'a different plan than the preview is refused', is_wp_error( $eo_result ) && 'edit_orders_for_woocommerce_changed' === $eo_result->get_error_code() );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes, 'staff', array( 'expect' => $eo_plan->fingerprint() ) );
$eo_check( 'the previewed plan applies', is_array( $eo_result ) && 'applied' === $eo_result['status'] );
$eo_order   = $eo_make_order( array( array( 'EO-MUG', 2 ) ) );
$eo_preview = Edit_Orders_For_WooCommerce_Admin::preview(
	$eo_order,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_id( $eo_order, 'EO-MUG' ),
			'quantity' => 1,
		),
	)
);
$eo_check( 'the editor preview carries the fingerprint for Apply', $eo_preview['ok'] && false !== strpos( $eo_preview['html'], 'name="expect"' ) );

$eo_order = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ), $eo_customer->ID );
$eo_post  = array(
	'edit_orders_for_woocommerce_action' => 'swap',
	'swap'                               => array( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) => (string) $eo_t['product']['EO-HOODIE-XS'] ),
);
$eo_step  = Edit_Orders_For_WooCommerce_Frontend::process( $eo_order, $eo_post, 'customer' );
$eo_check( 'customer preview carries the fingerprint', 'preview' === $eo_step['type'] && ! empty( $eo_step['preview']['fields']['expect'] ) );
$eo_bad = Edit_Orders_For_WooCommerce_Frontend::process(
	$eo_order,
	array_merge(
		$eo_post,
		array(
			'step'   => 'confirm',
			'expect' => 'abcdef123456',
		)
	),
	'customer'
);
$eo_check( 'a confirm that doesn\'t match the preview is refused', 'error' === $eo_bad['type'] && false !== strpos( $eo_bad['message'], 'review the change again' ) );
$eo_good = Edit_Orders_For_WooCommerce_Frontend::process( $eo_order, array_merge( $eo_step['preview']['fields'], array( 'step' => 'confirm' ) ), 'customer' );
$eo_check( 'the confirm of what was shown applies', 'success' === $eo_good['type'], $eo_good['message'] );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Cancelling refunds a paid balance order too, once' );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ), $eo_customer->ID );
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
$eo_pay( $eo_result['balance_order_id'] );
$eo_balance = wc_get_order( $eo_result['balance_order_id'] );
$eo_total   = (float) wc_get_order( $eo_order->get_id() )->get_total() + (float) $eo_balance->get_total();
$eo_cancel  = Edit_Orders_For_WooCommerce_Cancellation::execute( wc_get_order( $eo_order->get_id() ), 'customer' );
$eo_balance = wc_get_order( $eo_balance->get_id() );
$eo_check( 'the paid balance order is cancelled and fully refunded', 'cancelled' === $eo_balance->get_status() && '0.00' === $eo_money( $eo_balance->get_remaining_refund_amount() ) );
$eo_check( 'the amount refunded covers both orders', is_array( $eo_cancel ) && $eo_money( $eo_total ) === $eo_money( $eo_cancel['amount'] ), is_array( $eo_cancel ) ? $eo_money( $eo_cancel['amount'] ) . ' vs ' . $eo_money( $eo_total ) : $eo_cancel->get_error_message() );
$eo_twice = Edit_Orders_For_WooCommerce_Cancellation::execute( wc_get_order( $eo_order->get_id() ), 'customer' );
$eo_check( 'cancelling again is refused, with no second refund', is_wp_error( $eo_twice ) && $eo_money( wc_get_order( $eo_order->get_id() )->get_total() ) === $eo_money( wc_get_order( $eo_order->get_id() )->get_total_refunded() ) );

WP_CLI::log( 'A linked cash-on-delivery order is cancelled with the original' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ), $eo_customer->ID, 'cod' );
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
$eo_rows   = Edit_Orders_For_WooCommerce_Audit_Log::for_order( $eo_order->get_id() );
$eo_check( 'a staff COD edit logs one row, not a "balance paid" by the customer', is_array( $eo_result ) && 1 === count( $eo_rows ) && 'balance_created' === $eo_rows[0]['action'], wp_json_encode( wp_list_pluck( $eo_rows, 'action' ) ) );
Edit_Orders_For_WooCommerce_Cancellation::execute( wc_get_order( $eo_order->get_id() ), 'customer' );
$eo_check( 'the linked order (Processing, to collect) is cancelled', is_array( $eo_result ) && 'cancelled' === wc_get_order( $eo_result['balance_order_id'] )->get_status() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Approving a request re-checks the order first' );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ), $eo_customer->ID );
Edit_Orders_For_WooCommerce_Cancellation::request( $eo_order, 'Too slow', 'customer' );
wc_get_order( $eo_order->get_id() )->update_status( 'completed' );
$eo_result = Edit_Orders_For_WooCommerce_Cancellation::approve( wc_get_order( $eo_order->get_id() ) );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'request on an order completed since: not cancelled or refunded', is_wp_error( $eo_result ) && 'completed' === $eo_order->get_status() && 0.0 === (float) $eo_order->get_total_refunded() );
$eo_check( 'and the request leaves the list', 'expired' === $eo_order->get_meta( Edit_Orders_For_WooCommerce_Cancellation::STATUS_META ) && ! in_array( $eo_order->get_id(), array_map( static fn( $o ) => $o->get_id(), Edit_Orders_For_WooCommerce_Cancellation::pending_requests() ), true ) );

$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ), $eo_customer->ID );
Edit_Orders_For_WooCommerce_Cancellation::forget_pending_count();
$eo_before = Edit_Orders_For_WooCommerce_Cancellation::pending_count();
Edit_Orders_For_WooCommerce_Cancellation::request( $eo_order, 'Too slow', 'customer' );
$eo_after = Edit_Orders_For_WooCommerce_Cancellation::pending_count();
$eo_check( 'the menu count is cached and updated when a request arrives', $eo_after === $eo_before + 1 );
Edit_Orders_For_WooCommerce_Cancellation::decline( wc_get_order( $eo_order->get_id() ) );
$eo_after = Edit_Orders_For_WooCommerce_Cancellation::pending_count();
$eo_check( 'and when it is answered', $eo_after === $eo_before );

// ---------------------------------------------------------------------------
WP_CLI::log( 'An account order needs its account, not just the order key' );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ), $eo_customer->ID );
wp_set_current_user( 0 );
$eo_result = Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_order, $eo_order->get_order_key() );
$eo_check( 'logged out with the right key: asked to log in', is_wp_error( $eo_result ) && 'edit_orders_for_woocommerce_login' === $eo_result->get_error_code() );
wp_set_current_user( $eo_customer->ID );
$eo_check( 'logged in as the customer: allowed', 'customer' === Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_order, '' ) );
$eo_guest = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
wp_set_current_user( 0 );
$eo_check( 'a guest order still opens with its key', 'guest' === Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_guest, $eo_guest->get_order_key() ) );
wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Customers pick a shipping option when theirs isn\'t offered at the new address' );
$eo_zone = WC_Shipping_Zones::get_zone_matching_package(
	array(
		'destination' => array(
			'country'  => 'CA',
			'state'    => 'AB',
			'postcode' => 'T2P 1B3',
		),
	)
);
$eo_flat = current( $eo_zone->get_shipping_methods( true ) );
$wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => 0 ), array( 'instance_id' => $eo_flat->get_instance_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$eo_free = $eo_zone->add_shipping_method( 'free_shipping' );
WC_Cache_Helper::get_transient_version( 'shipping', true );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_post  = array(
	'edit_orders_for_woocommerce_action' => 'address',
	'shipping'                           => array_merge(
		$eo_order->get_address( 'shipping' ),
		array(
			'address_1' => '100 8 Ave SW',
			'city'      => 'Calgary',
			'state'     => 'AB',
			'postcode'  => 'T2P 1B3',
		)
	),
);
$eo_step  = Edit_Orders_For_WooCommerce_Frontend::process( $eo_order, $eo_post, 'guest' );
$eo_check( 'the error offers the shipping options for the new address', 'error' === $eo_step['type'] && ! empty( $eo_step['rates'] ) && isset( $eo_step['rates'][ 'free_shipping:' . $eo_free ] ), wp_json_encode( $eo_step ) );
$eo_args          = Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_order, $eo_order->get_order_key() );
$eo_args['state'] = $eo_step;
$eo_html          = wc_get_template_html( 'myaccount/edit-order.php', $eo_args, 'edit-orders-for-woocommerce/', EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/' );
$eo_check( 'the panel shows them as choices, with the address kept', false !== strpos( $eo_html, 'name="shipping_method" value="free_shipping:' . $eo_free . '"' ) && false !== strpos( $eo_html, 'value="Calgary"' ) );
$eo_step = Edit_Orders_For_WooCommerce_Frontend::process( $eo_order, array_merge( $eo_post, array( 'shipping_method' => 'free_shipping:' . $eo_free ) ), 'guest' );
$eo_check( 'choosing one gives the preview', 'preview' === $eo_step['type'], isset( $eo_step['message'] ) ? $eo_step['message'] : '' );
$eo_zone->delete_shipping_method( $eo_free );
$wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => 1 ), array( 'instance_id' => $eo_flat->get_instance_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
WC_Cache_Helper::get_transient_version( 'shipping', true );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Cash on delivery customers are told what they pay at the door' );
$eo_cod_panel              = static function ( WC_Order $order, array $form ) {
	$step = Edit_Orders_For_WooCommerce_Frontend::process( $order, $form, 'guest' );
	$args = Edit_Orders_For_WooCommerce_Frontend::panel_args( $order, $order->get_order_key() );

	$args['state'] = $step;
	return array( $step, wc_get_template_html( 'myaccount/edit-order.php', $args, 'edit-orders-for-woocommerce/', EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/' ) );
};
$eo_order                  = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ), 0, 'cod' );
$eo_form                   = array(
	'edit_orders_for_woocommerce_action' => 'swap',
	'swap'                               => array( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) => $eo_t['product']['EO-HOODIE-L'] ),
);
list( $eo_step, $eo_html ) = $eo_cod_panel( $eo_order, $eo_form );
$eo_check( 'dearer size: "$5.65 more on delivery", no pay page', 'preview' === $eo_step['type'] && false !== strpos( wp_strip_all_tags( $eo_html ), '5.65 more on delivery' ) && false === strpos( $eo_html, 'next page' ), wp_strip_all_tags( $eo_html ) );
$eo_check( 'and the button says "Confirm the change"', false !== strpos( $eo_html, 'Confirm the change' ) && false === strpos( $eo_html, 'Confirm and pay the difference' ) );
$eo_step = Edit_Orders_For_WooCommerce_Frontend::process( $eo_order, array_merge( $eo_form, array( 'step' => 'confirm' ) ), 'guest' );
$eo_check( 'confirming applies it straight away, without sending them to pay', 'success' === $eo_step['type'] && '' === $eo_step['redirect'], wp_json_encode( $eo_step ) );

$eo_order                  = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ), 0, 'cod' );
list( $eo_step, $eo_html ) = $eo_cod_panel(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'swap',
		'swap'                               => array( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) => $eo_t['product']['EO-HOODIE-XS'] ),
	)
);
$eo_check( 'cheaper size: "$5.65 less on delivery", no refund promised', 'preview' === $eo_step['type'] && false !== strpos( wp_strip_all_tags( $eo_html ), '5.65 less on delivery' ) && false === strpos( $eo_html, 'refund' ), wp_strip_all_tags( $eo_html ) );

// A paid order keeps the pay-page wording.
$eo_order                  = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
list( $eo_step, $eo_html ) = $eo_cod_panel(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'swap',
		'swap'                               => array( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) => $eo_t['product']['EO-HOODIE-L'] ),
	)
);
$eo_check( 'paid order: still "pay the difference on the next page"', false !== strpos( $eo_html, 'next page' ) && false !== strpos( $eo_html, 'Confirm and pay the difference' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Addresses keep backslashes' );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_build = Edit_Orders_For_WooCommerce_Address_Change::build(
	$eo_order,
	array(
		'type'     => 'address',
		'shipping' => array( 'address_2' => 'Unit 3\B' ),
	)
);
$eo_check( 'a backslash typed in the address survives', ! is_wp_error( $eo_build ) && 'Unit 3\B' === $eo_build['address_update']['shipping']['address_2'] );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Activity filter dates are the site\'s days' );
$eo_tz_before = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Asia/Kolkata' );
$eo_day = wp_date( 'Y-m-d' );
// 00:30 today in Kolkata is 19:00 yesterday in UTC.
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	Edit_Orders_For_WooCommerce_Audit_Log::table(),
	array(
		'order_id'   => 987654321,
		'actor_type' => 'staff',
		'action'     => 'edit',
		'created_at' => get_gmt_from_date( $eo_day . ' 00:30:00' ),
	)
);
$eo_found = Edit_Orders_For_WooCommerce_Audit_Log::query(
	array(
		'from' => $eo_day,
		'to'   => $eo_day,
	)
);
$eo_check( 'a change at 00:30 local time is listed under today', in_array( '987654321', wp_list_pluck( $eo_found['rows'], 'order_id' ), true ) );
$wpdb->delete( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'order_id' => 987654321 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
update_option( 'timezone_string', $eo_tz_before );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Settings keep only the statuses offered' );
$GLOBALS['current_section'] = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- what WooCommerce does on its settings screen.
$eo_pages                   = Edit_Orders_For_WooCommerce_Admin::settings_page( array() );
$eo_page                    = end( $eo_pages );
$_POST                      = array(
	Edit_Orders_For_WooCommerce_Settings::OPTION => array(
		'admin_enabled'     => '1',
		'customer_enabled'  => '1',
		'editable_statuses' => array( 'completed', 'processing' ),
		'window_minutes'    => '60',
		'cancel_mode'       => 'approval',
		'cancel_reasons'    => 'Too slow',
		'cancel_policy'     => '',
		'note_max_length'   => '500',
	),
);
$eo_page->save();
$_POST = array();
$eo_check( 'a posted "Completed" is dropped', array( 'processing' ) === array_values( (array) Edit_Orders_For_WooCommerce_Settings::get( 'editable_statuses' ) ), wp_json_encode( Edit_Orders_For_WooCommerce_Settings::get( 'editable_statuses' ) ) );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );

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
	WP_CLI::error( 'Hardening suite failed.' );
}
WP_CLI::success( 'Hardening suite passed.' );
