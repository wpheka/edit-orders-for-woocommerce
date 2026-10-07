<?php
/**
 * Functional suite for M1: store-owner editing (spec section 9, cases 1 to 14 for the
 * parts the engine suites don't cover, and 28 to 31).
 *
 * - audit log rows (case 1), emails (cases 2, 4, 5, 28), the edit lock (case 13);
 * - the preview shows the money that actually moves (case 14);
 * - the editor's form parsing, page and order-screen box render without notices;
 * - uninstall keeps data unless asked (case 30); no plugin errors in debug.log (case 31).
 *
 * Run with: bash tests/functional/run.sh m1-admin (or all suites without an argument).
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

// debug.log size now, to read only what this run adds (case 31).
$eo_debug_log  = WP_CONTENT_DIR . '/debug.log';
$eo_debug_from = file_exists( $eo_debug_log ) ? filesize( $eo_debug_log ) : 0;

// The admin class loads only on admin requests; WP-CLI isn't one.
if ( ! class_exists( 'Edit_Orders_For_WooCommerce_Admin' ) ) {
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/admin/class-edit-orders-for-woocommerce-admin.php';
}

Edit_Orders_For_WooCommerce_Audit_Log::maybe_install();
wp_set_current_user( 1 );
delete_option( Edit_Orders_Mail_Log::OPTION );

foreach ( array( 'EO-TSHIRT', 'EO-MUG', 'EO-POSTER', 'EO-HOODIE-S', 'EO-HOODIE-L' ) as $eo_sku ) {
	$eo_t['product'][ $eo_sku ]                   = wc_get_product_id_by_sku( $eo_sku );
	$eo_t['stock'][ $eo_t['product'][ $eo_sku ] ] = wc_get_product( $eo_t['product'][ $eo_sku ] )->get_stock_quantity();
}

$eo_addresses = array(
	'ON' => array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '1 Yonge St',
		'city'       => 'Toronto',
		'state'      => 'ON',
		'postcode'   => 'M5E 1E5',
		'country'    => 'CA',
	),
	'AB' => array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '100 8 Ave SW',
		'city'       => 'Calgary',
		'state'      => 'AB',
		'postcode'   => 'T2P 1B3',
		'country'    => 'CA',
	),
);

$eo_make_order = static function ( array $lines, $place = 'ON', $gateway = 'edit_orders_test', $coupon = '' ) use ( &$eo_t, $eo_addresses ) {
	$order = wc_create_order();
	$order->set_address( array_merge( $eo_addresses[ $place ], array( 'email' => 'customer@example.com' ) ), 'billing' );
	$order->set_address( $eo_addresses[ $place ], 'shipping' );
	foreach ( $lines as $line ) {
		$order->add_product( wc_get_product( $eo_t['product'][ $line[0] ] ), $line[1] );
	}
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
	if ( '' !== $coupon ) {
		$order->apply_coupon( $coupon );
	}
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

$eo_mail = static function () {
	return get_option( Edit_Orders_Mail_Log::OPTION, array() );
};

$eo_find_mail = static function ( $subject_part ) use ( $eo_mail ) {
	foreach ( array_reverse( $eo_mail() ) as $mail ) {
		if ( false !== stripos( $mail['subject'], $subject_part ) ) {
			return $mail;
		}
	}
	return null;
};

$eo_log_rows = static function ( $order_id ) {
	return Edit_Orders_For_WooCommerce_Audit_Log::for_order( $order_id );
};

$eo_pay = static function ( $balance_id ) {
	$balance = wc_get_order( $balance_id );
	$balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$balance->save();
	$balance->payment_complete( 'TEST-BAL-' . $balance_id );
};

WP_CLI::log( 'HPOS ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Form parsing: the editor form becomes the right change set' );
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 3 ), array( 'EO-MUG', 2 ), array( 'EO-HOODIE-S', 1 ) ) );
$eo_tshirt  = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_mug     = $eo_item_id( $eo_order, 'EO-MUG' );
$eo_hoodie  = $eo_item_id( $eo_order, 'EO-HOODIE-S' );
$eo_changes = Edit_Orders_For_WooCommerce_Admin::changes_from_request(
	$eo_order,
	array(
		'mode'  => 'items',
		'items' => array(
			$eo_tshirt => array( 'qty' => '1' ),
			$eo_mug    => array( 'qty' => '0' ),
			$eo_hoodie => array(
				'qty'       => '1',
				'variation' => (string) $eo_t['product']['EO-HOODIE-L'],
			),
		),
		'add'   => array(
			array(
				'product_id' => (string) $eo_t['product']['EO-POSTER'],
				'qty'        => '2',
				'price'      => '5',
			),
		),
	)
);
$eo_types   = wp_list_pluck( $eo_changes, 'type' );
$eo_check( 'quantity, remove, swap and add parsed', array( 'quantity', 'remove', 'swap', 'add' ) === $eo_types, wp_json_encode( $eo_types ) );
$eo_unchanged = Edit_Orders_For_WooCommerce_Admin::changes_from_request(
	$eo_order,
	array(
		'mode'  => 'items',
		'items' => array( $eo_tshirt => array( 'qty' => '3' ) ),
	)
);
$eo_check( 'unchanged rows produce no change', array() === $eo_unchanged );
$eo_address = Edit_Orders_For_WooCommerce_Admin::changes_from_request(
	$eo_order,
	array(
		'mode'     => 'address',
		'shipping' => array_merge( $eo_order->get_address( 'shipping' ), array( 'city' => 'Ottawa' ) ),
		'billing'  => $eo_order->get_address( 'billing' ),
	)
);
$eo_check( 'address mode sends only changed fields', 1 === count( $eo_address ) && array( 'city' => 'Ottawa' ) === $eo_address[0]['shipping'] && ! isset( $eo_address[0]['billing'] ), wp_json_encode( $eo_address ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 14: the preview shows the money that actually moves' );
$eo_scenarios = array(
	'quantity down'           => array(
		array( array( 'EO-TSHIRT', 3 ) ),
		'ON',
		'',
		static function ( $o ) use ( $eo_item_id ) {
			return array( 'mode' => 'items', 'items' => array( $eo_item_id( $o, 'EO-TSHIRT' ) => array( 'qty' => '1' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		},
	),
	'quantity up'             => array(
		array( array( 'EO-TSHIRT', 1 ) ),
		'ON',
		'',
		static function ( $o ) use ( $eo_item_id ) {
			return array( 'mode' => 'items', 'items' => array( $eo_item_id( $o, 'EO-TSHIRT' ) => array( 'qty' => '4' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		},
	),
	'add with a price'        => array(
		array( array( 'EO-MUG', 1 ) ),
		'ON',
		'',
		static function () use ( &$eo_t ) {
			return array( 'mode' => 'items', 'add' => array( array( 'product_id' => $eo_t['product']['EO-POSTER'], 'qty' => 3, 'price' => '6.10' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		},
	),
	'dearer swap with coupon' => array(
		array( array( 'EO-HOODIE-S', 1 ) ),
		'ON',
		'save20',
		static function ( $o ) use ( $eo_item_id, &$eo_t ) {
			return array( 'mode' => 'items', 'items' => array( $eo_item_id( $o, 'EO-HOODIE-S' ) => array( 'qty' => '1', 'variation' => $eo_t['product']['EO-HOODIE-L'] ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		},
	),
	'address ON to AB'        => array(
		array( array( 'EO-TSHIRT', 2 ) ),
		'ON',
		'',
		static function () use ( $eo_addresses ) {
			return array( 'mode' => 'address', 'shipping' => $eo_addresses['AB'], 'billing' => $eo_addresses['AB'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		},
	),
	'address AB to ON'        => array(
		array( array( 'EO-TSHIRT', 2 ) ),
		'AB',
		'',
		static function () use ( $eo_addresses ) {
			return array( 'mode' => 'address', 'shipping' => $eo_addresses['ON'], 'billing' => $eo_addresses['ON'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		},
	),
);
foreach ( $eo_scenarios as $eo_name => $eo_scenario ) {
	$eo_order   = $eo_make_order( $eo_scenario[0], $eo_scenario[1], 'edit_orders_test', $eo_scenario[2] );
	$eo_form    = call_user_func( $eo_scenario[3], $eo_order );
	$eo_preview = Edit_Orders_For_WooCommerce_Admin::preview( $eo_order, Edit_Orders_For_WooCommerce_Admin::changes_from_request( $eo_order, $eo_form ) );
	preg_match( '/edit-orders-refund" data-amount="([0-9.]+)"/', $eo_preview['html'], $eo_shown_refund );
	preg_match( '/edit-orders-balance" data-amount="([0-9.]+)"/', $eo_preview['html'], $eo_shown_balance );
	$eo_shown_refund  = $eo_shown_refund ? $eo_shown_refund[1] : '0.00';
	$eo_shown_balance = $eo_shown_balance ? $eo_shown_balance[1] : '0.00';

	$eo_result         = Edit_Orders_For_WooCommerce_Admin::apply( $eo_order, $eo_form, array() );
	$eo_actual_balance = '0.00';
	if ( is_array( $eo_result ) && ! empty( $eo_result['balance_order_id'] ) ) {
		$eo_t['orders'][]  = $eo_result['balance_order_id'];
		$eo_actual_balance = $eo_money( wc_get_order( $eo_result['balance_order_id'] )->get_total() );
		$eo_pay( $eo_result['balance_order_id'] );
	}
	$eo_actual_refund = $eo_money( wc_get_order( $eo_order->get_id() )->get_total_refunded() );

	$eo_check(
		"{$eo_name}: shown refund {$eo_shown_refund} = refunded {$eo_actual_refund}; shown balance {$eo_shown_balance} = balance order {$eo_actual_balance}",
		$eo_preview['ok'] && $eo_shown_refund === $eo_actual_refund && $eo_shown_balance === $eo_actual_balance,
		$eo_preview['ok'] ? '' : wp_strip_all_tags( $eo_preview['html'] )
	);
}
delete_option( Edit_Orders_Mail_Log::OPTION );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 1 and 28: an applied edit is logged and the customer is emailed' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Admin::apply(
	$eo_order,
	array(
		'mode'  => 'items',
		'items' => array( $eo_item_id( $eo_order, 'EO-TSHIRT' ) => array( 'qty' => '1' ) ),
	),
	array( 'notify_customer' => true )
);
$eo_rows   = $eo_log_rows( $eo_order->get_id() );
$eo_row    = $eo_rows ? $eo_rows[0] : array();
$eo_before = $eo_row ? json_decode( $eo_row['before_data'], true ) : array();
$eo_check( 'log row: action edit, staff user 1, refund linked, amount 45.20', $eo_row && 'edit' === $eo_row['action'] && 'staff' === $eo_row['actor_type'] && 1 === (int) $eo_row['actor_id'] && (int) $eo_row['refund_id'] > 0 && '45.20' === $eo_money( $eo_row['amount'] ), wp_json_encode( $eo_row ) );
$eo_check( 'log row keeps the order as it was (3 T-Shirts)', isset( $eo_before['items'][0]['quantity'] ) && 3 === (int) $eo_before['items'][0]['quantity'] );
$eo_mail_row = $eo_find_mail( 'was updated' );
$eo_check( 'customer emailed "order was updated" with the refund', $eo_mail_row && 'customer@example.com' === $eo_mail_row['to'] && false !== strpos( $eo_mail_row['message'], 'refunded' ) && false !== strpos( $eo_mail_row['message'], '45.20' ), $eo_mail_row ? $eo_mail_row['subject'] : 'no mail' );

WP_CLI::log( 'Unticked "email the customer": no email' );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_order = $eo_make_order( array( array( 'EO-MUG', 2 ) ) );
delete_option( Edit_Orders_Mail_Log::OPTION );
Edit_Orders_For_WooCommerce_Admin::apply(
	$eo_order,
	array(
		'mode'  => 'items',
		'items' => array( $eo_item_id( $eo_order, 'EO-MUG' ) => array( 'qty' => '1' ) ),
	),
	array( 'notify_customer' => false )
);
$eo_check( 'no "order was updated" email', null === $eo_find_mail( 'was updated' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 4 and 28: a balance order emails the pay link' );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_order         = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_result        = Edit_Orders_For_WooCommerce_Admin::apply(
	$eo_order,
	array(
		'mode'  => 'items',
		'items' => array( $eo_item_id( $eo_order, 'EO-TSHIRT' ) => array( 'qty' => '3' ) ),
	),
	array( 'send_pay_link' => true )
);
$eo_balance       = is_array( $eo_result ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
$eo_t['orders'][] = $eo_balance ? $eo_balance->get_id() : 0;
$eo_mail_row      = $eo_find_mail( 'Pay the difference' );
$eo_check( 'balance-due email sent to the customer', $eo_mail_row && 'customer@example.com' === $eo_mail_row['to'], $eo_mail_row ? $eo_mail_row['to'] : 'no mail' );
// The link may be written with &, &#038; or &amp; depending on the email styler.
$eo_body = $eo_mail_row ? str_replace( array( '&#038;', '&amp;' ), '&', $eo_mail_row['message'] ) : '';
$eo_check( 'it contains the pay link', $eo_balance && false !== strpos( $eo_body, $eo_balance->get_checkout_payment_url() ), $eo_balance ? $eo_balance->get_checkout_payment_url() : '' );
$eo_check( 'it contains the amount due (45.20)', false !== strpos( wp_strip_all_tags( $eo_body ), '45.20' ), substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $eo_body ) ), 0, 300 ) );
$eo_rows = $eo_log_rows( $eo_order->get_id() );
$eo_check( 'log row: balance_created with the balance order linked', $eo_rows && 'balance_created' === $eo_rows[0]['action'] && $eo_balance && (int) $eo_rows[0]['balance_order_id'] === $eo_balance->get_id() );

WP_CLI::log( 'Order screen box: pay link, resend and the balance order\'s link back' );
ob_start();
Edit_Orders_For_WooCommerce_Admin::render_meta_box( wc_get_order( $eo_order->get_id() ) );
$eo_box = ob_get_clean();
$eo_check( 'parent box lists the balance order with its pay link', $eo_balance && false !== strpos( $eo_box, 'data-balance="' . $eo_balance->get_id() . '"' ) && false !== strpos( $eo_box, esc_attr( $eo_balance->get_checkout_payment_url() ) ) && false !== strpos( $eo_box, 'edit-orders-resend' ) );
$eo_check( 'parent box shows no edit button while a balance is open', false === strpos( $eo_box, 'Edit items or address' ) && false !== strpos( $eo_box, 'unpaid balance order' ) );
ob_start();
Edit_Orders_For_WooCommerce_Admin::render_meta_box( $eo_balance );
$eo_box = ob_get_clean();
$eo_check( 'balance order box links back to the original', false !== strpos( $eo_box, 'balance order for changes to' ) && false !== strpos( $eo_box, '#' . $eo_order->get_order_number() ) );

WP_CLI::log( 'Case 5 and 28: paying the balance logs it and emails the customer' );
delete_option( Edit_Orders_Mail_Log::OPTION );
if ( $eo_balance ) {
	$eo_pay( $eo_balance->get_id() );
}
$eo_rows     = $eo_log_rows( $eo_order->get_id() );
$eo_mail_row = $eo_find_mail( 'was updated' );
$eo_check( 'log row: balance_paid', $eo_rows && 'balance_paid' === $eo_rows[0]['action'] );
$eo_check( 'customer emailed "order was updated" thanking for the payment', $eo_mail_row && false !== strpos( $eo_mail_row['message'], 'paying the difference' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 2 and 28: a manual refund emails the store owner' );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ), 'ON', 'bacs' );
Edit_Orders_For_WooCommerce_Admin::apply(
	$eo_order,
	array(
		'mode'  => 'items',
		'items' => array( $eo_item_id( $eo_order, 'EO-TSHIRT' ) => array( 'qty' => '1' ) ),
	),
	array( 'notify_customer' => true )
);
$eo_mail_row = $eo_find_mail( 'Manual refund needed' );
$eo_check( 'store owner emailed with the amount', $eo_mail_row && get_option( 'admin_email' ) === $eo_mail_row['to'] && false !== strpos( $eo_mail_row['message'], '45.20' ), $eo_mail_row ? $eo_mail_row['to'] : 'no mail' );
$eo_mail_row = $eo_find_mail( 'was updated' );
$eo_check( 'customer told the refund will follow', $eo_mail_row && false !== strpos( $eo_mail_row['message'], 'We will refund' ) );
ob_start();
Edit_Orders_For_WooCommerce_Admin::render_meta_box( wc_get_order( $eo_order->get_id() ) );
$eo_box = ob_get_clean();
$eo_check( 'order screen box shows "Manual refund needed"', false !== strpos( $eo_box, 'Manual refund needed' ) && false !== strpos( $eo_box, '45.20' ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 13: a second admin sees the lock' );
$eo_order  = $eo_make_order( array( array( 'EO-MUG', 2 ) ) );
$eo_second = wp_insert_user(
	array(
		'user_login'   => 'eo_second_admin_' . wp_rand( 1000, 9999 ),
		'user_pass'    => wp_generate_password(),
		'role'         => 'administrator',
		'display_name' => 'Second Admin',
	)
);
Edit_Orders_For_WooCommerce_Lock::acquire( $eo_order, 1 );
wp_set_current_user( $eo_second );
$eo_result = Edit_Orders_For_WooCommerce_Admin::apply(
	wc_get_order( $eo_order->get_id() ),
	array(
		'mode'  => 'items',
		'items' => array( $eo_item_id( $eo_order, 'EO-MUG' ) => array( 'qty' => '1' ) ),
	),
	array()
);
$eo_holder = get_userdata( 1 );
$eo_check( 'refused, naming who is editing', is_wp_error( $eo_result ) && 'edit_orders_for_woocommerce_locked' === $eo_result->get_error_code() && false !== strpos( $eo_result->get_error_message(), $eo_holder->display_name ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : 'applied' );
$eo_check( 'lock still with the first admin', 1 === Edit_Orders_For_WooCommerce_Lock::holder( wc_get_order( $eo_order->get_id() ) ) );
wp_set_current_user( 1 );
Edit_Orders_For_WooCommerce_Lock::release( wc_get_order( $eo_order->get_id() ), 1 );
wp_set_current_user( $eo_second );
$eo_result = Edit_Orders_For_WooCommerce_Admin::apply(
	wc_get_order( $eo_order->get_id() ),
	array(
		'mode'  => 'items',
		'items' => array( $eo_item_id( $eo_order, 'EO-MUG' ) => array( 'qty' => '1' ) ),
	),
	array()
);
$eo_check( 'after release the second admin can apply, and the lock is freed', is_array( $eo_result ) && 0 === Edit_Orders_For_WooCommerce_Lock::holder( wc_get_order( $eo_order->get_id() ) ) );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $eo_second );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Editor page renders' );
$eo_order         = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ), array( 'EO-MUG', 1 ) ) );
$_GET['order_id'] = (string) $eo_order->get_id();
set_current_screen( 'woocommerce_page_' . Edit_Orders_For_WooCommerce_Admin::PAGE );
ob_start();
Edit_Orders_For_WooCommerce_Admin::render_editor();
$eo_page = ob_get_clean();
unset( $_GET['order_id'] );
$eo_check( 'items table, variation choices, add-product search and address fields', false !== strpos( $eo_page, 'edit-orders-items' ) && false !== strpos( $eo_page, 'name="items[' ) && false !== strpos( $eo_page, '[variation]' ) && false !== strpos( $eo_page, 'wc-product-search' ) && false !== strpos( $eo_page, 'name="shipping[city]"' ) );
$eo_check( 'billing email is not editable', false === strpos( $eo_page, 'name="billing[email]"' ) );
$eo_check( 'opening the editor takes the lock', 1 === Edit_Orders_For_WooCommerce_Lock::holder( wc_get_order( $eo_order->get_id() ) ) );
Edit_Orders_For_WooCommerce_Lock::release( wc_get_order( $eo_order->get_id() ), 1 );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Cleanup' );
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
delete_option( Edit_Orders_Mail_Log::OPTION );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 30: uninstall keeps data unless asked' );
$eo_table     = Edit_Orders_For_WooCommerce_Audit_Log::table();
$eo_has_table = static function () use ( $wpdb, $eo_table ) {
	return $eo_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $eo_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
};
$eo_uninstall = 'eval-file ' . WP_PLUGIN_DIR . '/edit-orders-for-woocommerce/tests/functional/helpers/run-uninstall.php';
delete_option( 'edit_orders_for_woocommerce_delete_data' );
WP_CLI::runcommand( $eo_uninstall, array( 'launch' => true ) );
$eo_check( 'box unchecked: the log table is kept', $eo_has_table() );
update_option( 'edit_orders_for_woocommerce_delete_data', 'yes' );
WP_CLI::runcommand( $eo_uninstall, array( 'launch' => true ) );
wp_cache_flush();
$eo_check( 'box checked: table and options removed', ! $eo_has_table() && false === get_option( 'edit_orders_for_woocommerce_delete_data' ) && false === get_option( Edit_Orders_For_WooCommerce_Audit_Log::DB_VERSION_OPTION ) );
Edit_Orders_For_WooCommerce_Audit_Log::maybe_install();
$eo_check( 'table recreated for the next run', $eo_has_table() );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 31: no plugin errors in debug.log' );
clearstatcache();
$eo_new_log = file_exists( $eo_debug_log ) ? (string) file_get_contents( $eo_debug_log, false, null, $eo_debug_from ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$eo_ours    = preg_grep( '/edit-orders-for-woocommerce|edit_orders_for_woocommerce/i', explode( "\n", $eo_new_log ) );
$eo_check( 'nothing from this plugin logged during the run', empty( $eo_ours ), implode( ' | ', array_slice( $eo_ours, 0, 3 ) ) );

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'M1 suite failed.' );
}
WP_CLI::success( 'M1 suite passed.' );
