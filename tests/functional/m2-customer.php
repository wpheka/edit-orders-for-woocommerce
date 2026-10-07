<?php
/**
 * Functional suite for M2: customer self-service (spec section 9, cases 15 to 26).
 * Case 27 (block theme thank-you page) is in the browser test, tests/e2e/customer.cjs.
 *
 * Requests go through Edit_Orders_For_WooCommerce_Frontend::process(), the same function
 * the storefront forms call after their nonce and key checks.
 *
 * Run with: bash tests/functional/run.sh m2-customer (or all suites without an argument).
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

if ( ! class_exists( 'Edit_Orders_For_WooCommerce_Admin' ) ) {
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/admin/class-edit-orders-for-woocommerce-admin.php';
}
if ( ! class_exists( 'Edit_Orders_For_WooCommerce_Activity' ) ) {
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/admin/class-edit-orders-for-woocommerce-activity.php';
}

Edit_Orders_For_WooCommerce_Audit_Log::maybe_install();
delete_option( Edit_Orders_Mail_Log::OPTION );

$eo_settings_before = get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null );
$eo_set             = static function ( array $settings ) {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array_merge( (array) get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array() ), $settings ) );
};
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );

foreach ( array( 'EO-TSHIRT', 'EO-MUG', 'EO-HOODIE-XS', 'EO-HOODIE-S', 'EO-HOODIE-M', 'EO-HOODIE-L' ) as $eo_sku ) {
	$eo_t['product'][ $eo_sku ]                   = wc_get_product_id_by_sku( $eo_sku );
	$eo_t['stock'][ $eo_t['product'][ $eo_sku ] ] = wc_get_product( $eo_t['product'][ $eo_sku ] )->get_stock_quantity();
}

$eo_customer = get_user_by( 'login', 'customer' );
$eo_other    = wp_insert_user(
	array(
		'user_login' => 'eo_other_customer_' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);

$eo_places = array(
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
);

/**
 * A paid Ontario order, for the logged-in customer (default) or a guest (customer 0).
 */
$eo_make_order = static function ( array $lines, $customer_id = null, $gateway = 'edit_orders_test' ) use ( &$eo_t, $eo_customer, $eo_places ) {
	$order = wc_create_order( array( 'customer_id' => null === $customer_id ? $eo_customer->ID : $customer_id ) );
	$order->set_address(
		array_merge(
			$eo_places['ON'],
			array(
				'first_name' => 'Casey',
				'last_name'  => 'Customer',
				'email'      => 'customer@example.com',
			)
		),
		'billing'
	);
	$order->set_address(
		array_merge(
			$eo_places['ON'],
			array(
				'first_name' => 'Casey',
				'last_name'  => 'Customer',
			)
		),
		'shipping'
	);
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
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Flat rate' );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_instance_id( current( $zone->get_shipping_methods( true ) )->get_instance_id() );
	$shipping->set_total( 10 );
	$order->add_item( $shipping );
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

$eo_item_id = static function ( WC_Order $order, $sku ) use ( &$eo_t ) {
	foreach ( $order->get_items() as $item_id => $item ) {
		$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		if ( (int) $id === (int) $eo_t['product'][ $sku ] ) {
			return $item_id;
		}
	}
	return 0;
};

$eo_stock = static function ( $sku ) use ( &$eo_t ) {
	return (int) wc_get_product( $eo_t['product'][ $sku ] )->get_stock_quantity();
};

$eo_process = static function ( WC_Order $order, array $data, $actor = 'customer' ) {
	return Edit_Orders_For_WooCommerce_Frontend::process( wc_get_order( $order->get_id() ), $data, $actor );
};

$eo_find_mail = static function ( $subject_part, $to = '' ) {
	foreach ( array_reverse( get_option( Edit_Orders_Mail_Log::OPTION, array() ) ) as $mail ) {
		if ( false !== stripos( $mail['subject'], $subject_part ) && ( '' === $to || $to === $mail['to'] ) ) {
			return $mail;
		}
	}
	return null;
};

$eo_last_log = static function ( $order_id ) {
	$rows = Edit_Orders_For_WooCommerce_Audit_Log::for_order( $order_id );
	return $rows ? $rows[0] : array();
};

$eo_pay = static function ( $balance_id ) {
	$balance = wc_get_order( $balance_id );
	$balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$balance->save();
	$balance->payment_complete( 'TEST-BAL-' . $balance_id );
};

$eo_balance_of = static function ( WC_Order $order ) use ( &$eo_t ) {
	$balance = Edit_Orders_For_WooCommerce_Balance_Orders::get_open_balance_order( wc_get_order( $order->get_id() ) );
	if ( $balance ) {
		$eo_t['orders'][] = $balance->get_id();
	}
	return $balance;
};

wp_set_current_user( $eo_customer->ID );
WP_CLI::log( 'HPOS ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Defaults are conservative' );
$eo_check( 'cancellations wait for approval; one-hour window', 'approval' === Edit_Orders_For_WooCommerce_Settings::get( 'cancel_mode' ) && HOUR_IN_SECONDS === Edit_Orders_For_WooCommerce_Settings::window_seconds() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 15: instant cancel refunds, restocks, cancels and emails' );
$eo_set( array( 'cancel_mode' => 'instant' ) );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_before = $eo_stock( 'EO-TSHIRT' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_check( 'order paid: 56.50, stock down 2', '56.50' === $eo_money( $eo_order->get_total() ) && $eo_stock( 'EO-TSHIRT' ) === $eo_before - 2 );
$eo_result = $eo_process(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'cancel',
		'reason'                             => 'Ordered by mistake',
	)
);
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'success message', 'success' === $eo_result['type'] && false !== strpos( $eo_result['message'], 'cancelled' ), $eo_result['message'] );
$eo_check( 'status Cancelled (not Refunded)', 'cancelled' === $eo_order->get_status(), $eo_order->get_status() );
$eo_check( 'refunded 56.50 through the gateway', '56.50' === $eo_money( $eo_order->get_total_refunded() ) && '56.50' === $eo_money( $eo_order->get_meta( '_edit_orders_test_refunded' ) ), $eo_order->get_total_refunded() );
$eo_check( 'stock back exactly once', $eo_stock( 'EO-TSHIRT' ) === $eo_before, $eo_stock( 'EO-TSHIRT' ) . ' vs ' . $eo_before );
$eo_check( 'store owner got WooCommerce\'s "Cancelled order" email', null !== $eo_find_mail( 'cancelled', get_option( 'admin_email' ) ) );
$eo_mail = $eo_find_mail( 'is cancelled', 'customer@example.com' );
$eo_check( 'customer got "your order is cancelled" with the refund', $eo_mail && false !== strpos( $eo_mail['message'], '56.50' ) && false !== strpos( $eo_mail['message'], 'refunded' ) );
$eo_log = $eo_last_log( $eo_order->get_id() );
$eo_check( 'log row: cancelled by the customer, reason kept', $eo_log && 'cancelled' === $eo_log['action'] && 'customer' === $eo_log['actor_type'] && false !== strpos( $eo_log['after_data'], 'Ordered by mistake' ) );

WP_CLI::log( 'Case 15b: instant cancel when the gateway refuses the refund' );
update_option( 'edit_orders_test_gateway_fail_refunds', 'yes' );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_process( $eo_order, array( 'edit_orders_for_woocommerce_action' => 'cancel' ) );
delete_option( 'edit_orders_test_gateway_fail_refunds' );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'still cancelled, flagged for a manual refund of the full total', 'cancelled' === $eo_order->get_status() && $eo_money( $eo_order->get_total() ) === $eo_money( $eo_order->get_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) ) );

WP_CLI::log( 'Case 15c: instant cancel of an unpaid Cash on delivery order' );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_before = $eo_stock( 'EO-MUG' );
$eo_order  = $eo_make_order( array( array( 'EO-MUG', 2 ) ), null, 'cod' );
$eo_process( $eo_order, array( 'edit_orders_for_woocommerce_action' => 'cancel' ) );
$eo_order = wc_get_order( $eo_order->get_id() );
$eo_check( 'cancelled with no refund and stock back', 'cancelled' === $eo_order->get_status() && 0.0 === (float) $eo_order->get_total_refunded() && $eo_stock( 'EO-MUG' ) === $eo_before );
$eo_mail = $eo_find_mail( 'is cancelled', 'customer@example.com' );
$eo_check( 'customer told nothing was charged', $eo_mail && false !== strpos( $eo_mail['message'], 'Nothing was charged' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 16: approval mode queues the request; approve refunds; decline changes nothing' );
$eo_set( array( 'cancel_mode' => 'approval' ) );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_result = $eo_process(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'cancel',
		'reason'                             => '__other',
		'reason_other'                       => 'Bought it in store',
	)
);
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'request sent, status unchanged, request pending', 'success' === $eo_result['type'] && 'processing' === $eo_order->get_status() && 'pending' === $eo_order->get_meta( Edit_Orders_For_WooCommerce_Cancellation::STATUS_META ) );
$eo_mail = $eo_find_mail( 'Cancellation requested', get_option( 'admin_email' ) );
$eo_check( 'store owner emailed with the "Other" reason', $eo_mail && false !== strpos( $eo_mail['message'], 'Bought it in store' ) );
$eo_again = $eo_process( $eo_order, array( 'edit_orders_for_woocommerce_action' => 'cancel' ) );
$eo_check( 'a second request is refused while one is waiting', 'error' === $eo_again['type'] );
$eo_not_asked = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_pending   = array_map(
	static function ( $o ) {
		return $o->get_id();
	},
	Edit_Orders_For_WooCommerce_Cancellation::pending_requests()
);
$eo_check( 'pending list holds only orders with a request (both order storages)', in_array( $eo_order->get_id(), $eo_pending, true ) && ! in_array( $eo_not_asked->get_id(), $eo_pending, true ), wp_json_encode( $eo_pending ) );
$eo_check(
	'listed as a pending request',
	in_array(
		$eo_order->get_id(),
		array_map(
			static function ( $o ) {
				return $o->get_id();
			},
			Edit_Orders_For_WooCommerce_Cancellation::pending_requests()
		),
		true
	)
);
wp_set_current_user( 1 );
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_page = ob_get_clean();
$eo_check( 'activity page shows the request with approve and decline', false !== strpos( $eo_page, 'data-order="' . $eo_order->get_id() . '"' ) && false !== strpos( $eo_page, 'Approve and refund' ) && false !== strpos( $eo_page, 'value="decline"' ) );
ob_start();
Edit_Orders_For_WooCommerce_Admin::render_meta_box( $eo_order );
$eo_box = ob_get_clean();
$eo_check( 'order screen box shows the request and an approve link', false !== strpos( $eo_box, 'asked to cancel' ) && false !== strpos( $eo_box, Edit_Orders_For_WooCommerce_Activity::DECISION_ACTION ) );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_result = Edit_Orders_For_WooCommerce_Activity::decide( $eo_order, 'approve' );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'approved: cancelled and refunded 33.90', is_array( $eo_result ) && 'cancelled' === $eo_order->get_status() && '33.90' === $eo_money( $eo_order->get_total_refunded() ) && 'approved' === $eo_order->get_meta( Edit_Orders_For_WooCommerce_Cancellation::STATUS_META ) );
$eo_check( 'customer told the order is cancelled', null !== $eo_find_mail( 'is cancelled', 'customer@example.com' ) );

$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
wp_set_current_user( $eo_customer->ID );
$eo_process( $eo_order, array( 'edit_orders_for_woocommerce_action' => 'cancel' ) );
wp_set_current_user( 1 );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_result = Edit_Orders_For_WooCommerce_Activity::decide( wc_get_order( $eo_order->get_id() ), 'decline', 'It has already been packed.' );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'declined: order unchanged, no refund', true === $eo_result && 'processing' === $eo_order->get_status() && 0.0 === (float) $eo_order->get_total_refunded() && 'declined' === $eo_order->get_meta( Edit_Orders_For_WooCommerce_Cancellation::STATUS_META ) );
$eo_mail = $eo_find_mail( 'request to cancel', 'customer@example.com' );
$eo_check( 'customer emailed the decline with the message', $eo_mail && false !== strpos( $eo_mail['message'], 'already been packed' ) );
wp_set_current_user( $eo_customer->ID );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 17: a required reason blocks an empty cancellation' );
$eo_set( array( 'cancel_reason_required' => 'yes' ) );
$eo_order  = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_result = $eo_process(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'cancel',
		'reason'                             => '',
	)
);
$eo_check( 'refused with a message, nothing changed', 'error' === $eo_result['type'] && false !== strpos( $eo_result['message'], 'why' ) && '' === wc_get_order( $eo_order->get_id() )->get_meta( Edit_Orders_For_WooCommerce_Cancellation::STATUS_META ), $eo_result['message'] );
$eo_set( array( 'cancel_reason_required' => 'no' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 18: after the window, actions are hidden and refused' );
$eo_set( array( 'window_minutes' => 5 ) );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_order->set_date_paid( time() - 10 * MINUTE_IN_SECONDS );
$eo_order->save();
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_result = $eo_process(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'note',
		'customer_note'                      => 'Too late',
	)
);
$eo_check( 'a save after the window is refused', 'error' === $eo_result['type'] && false !== strpos( $eo_result['message'], 'passed' ), $eo_result['message'] );
$eo_check( 'no panel, no "Change or cancel" in My Account', null === Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_order, '' ) && ! isset( Edit_Orders_For_WooCommerce_Frontend::order_action( array(), $eo_order )['edit_order'] ) );
$eo_set( array( 'window_minutes' => 60 ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 19: "All good" closes the window early' );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_check( 'window open before (about an hour left)', Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $eo_order ) > 50 * MINUTE_IN_SECONDS );
$eo_result = $eo_process( $eo_order, array( 'edit_orders_for_woocommerce_action' => 'close' ) );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'closed: no seconds left, actions refused', 'success' === $eo_result['type'] && 0 === Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $eo_order ) && is_wp_error( Edit_Orders_For_WooCommerce_Customer_Rules::can( $eo_order, 'cancel' ) ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 20 and 25: who may act on an order' );
wp_set_current_user( 0 );
$eo_guest = $eo_make_order( array( array( 'EO-MUG', 1 ) ), 0 );
$eo_check( 'guest with the right key: allowed as guest', 'guest' === Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_guest, $eo_guest->get_order_key() ) );
$eo_bad = Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_guest, 'wc_order_wrongkey' );
$eo_check( 'wrong key: 404', is_wp_error( $eo_bad ) && 404 === $eo_bad->get_error_data()['status'] );
wp_set_current_user( $eo_other );
$eo_check( 'another customer\'s order without its key: 404', is_wp_error( Edit_Orders_For_WooCommerce_Customer_Rules::authorize( wc_get_order( $eo_guest->get_id() ), '' ) ) );
$eo_mine = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_check( 'logged in as someone else: the customer\'s order is a 404', is_wp_error( Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_mine, '' ) ) );
wp_set_current_user( $eo_customer->ID );
$eo_check( 'the order\'s own customer: allowed', 'customer' === Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_mine, '' ) );
$eo_check( 'a balance order is never a customer target', is_wp_error( Edit_Orders_For_WooCommerce_Customer_Rules::authorize( false, '' ) ) );
$eo_ip_key = 'eofw_badkey_' . md5( WC_Geolocation::get_ip_address() );
delete_transient( $eo_ip_key );
for ( $eo_i = 0; $eo_i < Edit_Orders_For_WooCommerce_Customer_Rules::MAX_FAILED_KEYS; $eo_i++ ) {
	Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $eo_guest, 'wc_order_guess' . $eo_i );
}
$eo_check( 'ten wrong keys from one IP: rate limited', Edit_Orders_For_WooCommerce_Customer_Rules::is_rate_limited() );
delete_transient( $eo_ip_key );
set_transient( 'eofw_actions_' . $eo_mine->get_id(), Edit_Orders_For_WooCommerce_Customer_Rules::MAX_ACTIONS, HOUR_IN_SECONDS );
$eo_result = $eo_process(
	$eo_mine,
	array(
		'edit_orders_for_woocommerce_action' => 'note',
		'customer_note'                      => 'x',
	)
);
$eo_check( 'too many actions on one order: refused', 'error' === $eo_result['type'] && false !== strpos( $eo_result['message'], 'Too many' ) );
delete_transient( 'eofw_actions_' . $eo_mine->get_id() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 21: an address change at the same cost applies at once' );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_form    = array(
	'edit_orders_for_woocommerce_action' => 'address',
	'shipping'                           => array_merge( $eo_order->get_address( 'shipping' ), $eo_places['ON2'] ),
);
$eo_preview = $eo_process( $eo_order, $eo_form );
$eo_check( 'preview first: no money moves', 'preview' === $eo_preview['type'] && 0.0 === (float) $eo_preview['preview']['refund'] && 0.0 === (float) $eo_preview['preview']['balance'], wp_json_encode( $eo_preview['preview'] ) );
$eo_check( 'nothing changed by the preview', 'Toronto' === wc_get_order( $eo_order->get_id() )->get_shipping_city() );
$eo_result = $eo_process( $eo_order, array_merge( $eo_form, array( 'step' => 'confirm' ) ) );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'confirmed: address is Ottawa', 'success' === $eo_result['type'] && 'Ottawa' === $eo_order->get_shipping_city(), $eo_result['message'] );
$eo_log = $eo_last_log( $eo_order->get_id() );
$eo_check( 'log row by the customer, with the new address', $eo_log && 'customer' === $eo_log['actor_type'] && false !== strpos( $eo_log['after_data'], 'Ottawa' ) );
$eo_check( 'store owner emailed "changed by the customer"', null !== $eo_find_mail( 'changed by the customer', get_option( 'admin_email' ) ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 22: an address change that costs more creates a balance and applies after payment' );
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_form    = array(
	'edit_orders_for_woocommerce_action' => 'address',
	'shipping'                           => array_merge( $eo_order->get_address( 'shipping' ), $eo_places['AB'] ),
	'billing'                            => array_merge( $eo_order->get_address( 'billing' ), $eo_places['AB'] ),
);
$eo_preview = $eo_process( $eo_order, $eo_form );
$eo_check( 'preview: 12.00 to pay, 3.90 back after', 'preview' === $eo_preview['type'] && '12.00' === $eo_money( $eo_preview['preview']['balance'] ) && '3.90' === $eo_money( $eo_preview['preview']['refund'] ), wp_json_encode( $eo_preview['preview'] ) );
$eo_result  = $eo_process( $eo_order, array_merge( $eo_form, array( 'step' => 'confirm' ) ) );
$eo_balance = $eo_balance_of( $eo_order );
$eo_check( 'confirmed: sent straight to the pay page', $eo_balance && $eo_result['redirect'] === $eo_balance->get_checkout_payment_url() );
$eo_check( 'order unchanged until paid', 'ON' === wc_get_order( $eo_order->get_id() )->get_shipping_state() );
if ( $eo_balance ) {
	$eo_pay( $eo_balance->get_id() );
}
$eo_check( 'after payment: Alberta, HST 3.90 refunded', 'AB' === wc_get_order( $eo_order->get_id() )->get_shipping_state() && '3.90' === $eo_money( wc_get_order( $eo_order->get_id() )->get_total_refunded() ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 23: the customer swaps a variation, in each price case' );
$eo_s = $eo_stock( 'EO-HOODIE-S' );
$eo_m = $eo_stock( 'EO-HOODIE-M' );

$eo_order = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_check( 'the panel offers the sizes', isset( Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_order, '' )['swap_options'][ $eo_item_id( $eo_order, 'EO-HOODIE-S' ) ] ) );
$eo_form   = array(
	'edit_orders_for_woocommerce_action' => 'swap',
	'swap'                               => array( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) => $eo_t['product']['EO-HOODIE-M'] ),
	'step'                               => 'confirm',
);
$eo_result = $eo_process( $eo_order, $eo_form );
$eo_check( 'same price (S to M): applied, no money, stock moved', 'success' === $eo_result['type'] && 0.0 === (float) wc_get_order( $eo_order->get_id() )->get_total_refunded() && $eo_stock( 'EO-HOODIE-S' ) === $eo_s && $eo_stock( 'EO-HOODIE-M' ) === $eo_m - 1, $eo_result['message'] );

$eo_order   = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_form    = array(
	'edit_orders_for_woocommerce_action' => 'swap',
	'swap'                               => array( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) => $eo_t['product']['EO-HOODIE-L'] ),
);
$eo_preview = $eo_process( $eo_order, $eo_form );
$eo_check( 'dearer (S to L): preview 5.65 to pay', 'preview' === $eo_preview['type'] && '5.65' === $eo_money( $eo_preview['preview']['balance'] ), wp_json_encode( $eo_preview['preview'] ) );
$eo_result  = $eo_process( $eo_order, array_merge( $eo_form, array( 'step' => 'confirm' ) ) );
$eo_balance = $eo_balance_of( $eo_order );
$eo_check( 'dearer: sent to pay 5.65', $eo_balance && '5.65' === $eo_money( $eo_balance->get_total() ) && $eo_result['redirect'] === $eo_balance->get_checkout_payment_url() );

$eo_order  = $eo_make_order( array( array( 'EO-HOODIE-M', 1 ) ) );
$eo_result = $eo_process(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'swap',
		'swap'                               => array( $eo_item_id( $eo_order, 'EO-HOODIE-M' ) => $eo_t['product']['EO-HOODIE-XS'] ),
		'step'                               => 'confirm',
	)
);
$eo_check( 'cheaper (M to XS): 5.65 refunded', 'success' === $eo_result['type'] && '5.65' === $eo_money( wc_get_order( $eo_order->get_id() )->get_total_refunded() ), $eo_result['message'] );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 24: the customer edits the order note' );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_order  = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_result = $eo_process(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'note',
		'customer_note'                      => "Leave it at the side door.\n<script>x</script>",
	)
);
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_check( 'note saved, tags stripped', 'success' === $eo_result['type'] && false !== strpos( $eo_order->get_customer_note(), 'side door' ) && false === strpos( $eo_order->get_customer_note(), '<script>' ), $eo_order->get_customer_note() );
$eo_check( 'logged with old and new note', 'note_changed' === $eo_last_log( $eo_order->get_id() )['action'] );
$eo_check( 'store owner emailed', null !== $eo_find_mail( 'changed by the customer', get_option( 'admin_email' ) ) );
$eo_set( array( 'note_max_length' => 10 ) );
$eo_process(
	$eo_order,
	array(
		'edit_orders_for_woocommerce_action' => 'note',
		'customer_note'                      => 'A very long note indeed',
	)
);
$eo_check( 'length limit applied', 10 === strlen( wc_get_order( $eo_order->get_id() )->get_customer_note() ) );
$eo_set( array( 'note_max_length' => 500 ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 26: a shipped order refuses changes' );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_order->update_meta_data( '_wc_shipment_tracking_items', array( array( 'tracking_number' => '1Z999' ) ) );
$eo_order->save();
$eo_result = $eo_process( wc_get_order( $eo_order->get_id() ), array( 'edit_orders_for_woocommerce_action' => 'cancel' ) );
$eo_check( 'tracking number: refused as shipped', 'error' === $eo_result['type'] && false !== strpos( $eo_result['message'], 'shipped' ), $eo_result['message'] );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_order->update_status( 'completed' );
$eo_check( 'Completed: refused', is_wp_error( Edit_Orders_For_WooCommerce_Customer_Rules::can( wc_get_order( $eo_order->get_id() ), 'note' ) ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Panel, email link and My Account action' );
wp_set_current_user( 0 );
$eo_guest = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ), 0 );
$eo_args  = Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_guest, $eo_guest->get_order_key() );
ob_start();
wc_get_template( 'myaccount/edit-order.php', $eo_args, 'edit-orders-for-woocommerce/', EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/' );
$eo_panel = ob_get_clean();
$eo_check( 'guest panel: all four actions, the countdown and the key in every form', false !== strpos( $eo_panel, 'id="edit-order"' ) && false !== strpos( $eo_panel, 'data-eofw-seconds' ) && 5 === substr_count( $eo_panel, 'value="' . $eo_guest->get_order_key() . '"' ) && false !== strpos( $eo_panel, 'name="swap[' ) && false !== strpos( $eo_panel, 'name="shipping[city]"' ) && false !== strpos( $eo_panel, 'name="customer_note"' ) && false !== strpos( $eo_panel, 'Ask to cancel' ) );
$eo_email = WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order'];
ob_start();
Edit_Orders_For_WooCommerce_Frontend::email_link( $eo_guest, false, false, $eo_email );
$eo_link = ob_get_clean();
$eo_check( 'processing email links guests to the thank-you page with the key', false !== strpos( $eo_link, 'order-received' ) && false !== strpos( $eo_link, $eo_guest->get_order_key() ) && false !== strpos( $eo_link, '#edit-order' ) );
wp_set_current_user( $eo_customer->ID );
$eo_mine = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
ob_start();
Edit_Orders_For_WooCommerce_Frontend::email_link( $eo_mine, false, false, $eo_email );
$eo_link = ob_get_clean();
$eo_check( 'and account holders to My Account', false !== strpos( $eo_link, 'view-order' ) );
$eo_actions = Edit_Orders_For_WooCommerce_Frontend::order_action( array(), $eo_mine );
$eo_check( 'My Account orders list offers "Change or cancel"', isset( $eo_actions['edit_order'] ) && false !== strpos( $eo_actions['edit_order']['url'], '#edit-order' ) );
ob_start();
Edit_Orders_For_WooCommerce_Frontend::email_link( $eo_mine, true, false, $eo_email );
$eo_check( 'no link in emails to the store owner', '' === ob_get_clean() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Cleanup' );
wp_set_current_user( 1 );
foreach ( array_unique( array_filter( $eo_t['orders'] ) ) as $eo_order_id ) {
	$eo_order = wc_get_order( $eo_order_id );
	if ( ! $eo_order ) {
		continue;
	}
	foreach ( $eo_order->get_refunds() as $eo_refund ) {
		$eo_refund->delete( true );
	}
	$eo_order->delete( true );
	$wpdb->delete( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'order_id' => $eo_order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
foreach ( $eo_t['stock'] as $eo_product_id => $eo_quantity ) {
	wc_update_product_stock( $eo_product_id, $eo_quantity, 'set' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $eo_other );
if ( null === $eo_settings_before ) {
	delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
} else {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $eo_settings_before );
}
delete_option( Edit_Orders_Mail_Log::OPTION );

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'M2 suite failed.' );
}
WP_CLI::success( 'M2 suite passed.' );
