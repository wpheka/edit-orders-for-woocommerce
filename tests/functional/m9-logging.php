<?php
/**
 * Functional suite for 1.1.0 debug logging (tasks/edit-orders-for-woocommerce-logging-spec.md,
 * section 10): every step of a quantity decrease, a dearer swap paid later, an address change
 * and a cancellation, under one run ID per change; only notice and above with debug off;
 * failures; redaction; the settings save; the System Status section.
 *
 * Entries are collected in memory by a test log handler (woocommerce_register_log_handlers).
 *
 * Run with: bash tests/functional/run.sh m9-logging (or all suites without an argument).
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

$eo_error = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_message() : '';
};

if ( ! class_exists( 'Edit_Orders_Mail_Log' ) ) {
	WP_CLI::error( 'The mail log mu-plugin is not loaded (tests/env/mu-plugins).' );
}

foreach ( array( 'admin/class-edit-orders-for-woocommerce-admin.php', 'admin/class-edit-orders-for-woocommerce-settings-page.php', 'admin/class-edit-orders-for-woocommerce-system-status.php', 'wpheka-ui/load.php', 'admin/class-edit-orders-for-woocommerce-settings-form.php', 'admin/class-edit-orders-for-woocommerce-admin-page.php' ) as $eo_file ) {
	if ( ! class_exists( 'WC_Settings_Page', false ) ) {
		require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
	}
	if ( ! class_exists( 'WC_Admin_Settings', false ) ) {
		require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
	}
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/' . $eo_file;
}

// ---------------------------------------------------------------------------
// The test log handler: WooCommerce builds its logger once, so a logger object of our own
// makes it use a new one, built with the handlers from woocommerce_register_log_handlers.
$GLOBALS['eo_m9_entries'] = array();
$eo_logging_before        = get_option( 'woocommerce_logs_logging_enabled', null );
update_option( 'woocommerce_logs_logging_enabled', 'yes' );
$eo_handlers = static function ( $handlers ) {
	$handlers[] = new class() implements WC_Log_Handler_Interface {
		/**
		 * Keep an entry from this plugin's source.
		 *
		 * @param int    $timestamp Time.
		 * @param string $level     Level.
		 * @param string $message   Message.
		 * @param array  $context   Context.
		 * @return bool
		 */
		public function handle( $timestamp, $level, $message, $context ) {
			if ( isset( $context['source'] ) && Edit_Orders_For_WooCommerce_Log::SOURCE === $context['source'] ) {
				$GLOBALS['eo_m9_entries'][] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
			return true;
		}
	};
	return $handlers;
};
add_filter( 'woocommerce_register_log_handlers', $eo_handlers );
// Every level, whatever the store's log settings say.
$eo_logger_object = new class( null, 'debug' ) extends WC_Logger {};
$eo_logger        = static function () use ( $eo_logger_object ) {
	return $eo_logger_object;
};
add_filter( 'woocommerce_logging_class', $eo_logger );

$eo_debug_on  = static function () {
	return true;
};
$eo_debug_off = static function () {
	return false;
};
$eo_debug     = static function ( $on ) use ( $eo_debug_on, $eo_debug_off ) {
	remove_filter( 'edit_orders_for_woocommerce_debug_log', $eo_debug_on );
	remove_filter( 'edit_orders_for_woocommerce_debug_log', $eo_debug_off );
	add_filter( 'edit_orders_for_woocommerce_debug_log', $on ? $eo_debug_on : $eo_debug_off );
};

$eo_reset   = static function () {
	$GLOBALS['eo_m9_entries'] = array();
	Edit_Orders_For_WooCommerce_Log::end_run();
};
$eo_entries = static function () {
	return $GLOBALS['eo_m9_entries'];
};
// The first needle that is missing, in order; empty when all are found in this order.
$eo_sequence = static function ( array $needles ) {
	$entries = $GLOBALS['eo_m9_entries'];
	$from    = 0;
	foreach ( $needles as $needle ) {
		$found = false;
		for ( $i = $from, $n = count( $entries ); $i < $n; $i++ ) {
			if ( false !== strpos( $entries[ $i ]['message'], $needle ) ) {
				$found = true;
				$from  = $i + 1;
				break;
			}
		}
		if ( ! $found ) {
			return $needle;
		}
	}
	return '';
};
$eo_runs     = static function () {
	$runs = array();
	foreach ( $GLOBALS['eo_m9_entries'] as $entry ) {
		$runs[] = isset( $entry['context']['run'] ) ? $entry['context']['run'] : '(none)';
	}
	return array_values( array_unique( $runs ) );
};
$eo_levels   = static function () {
	return array_values( array_unique( wp_list_pluck( $GLOBALS['eo_m9_entries'], 'level' ) ) );
};
$eo_find     = static function ( $needle, $level = '' ) {
	foreach ( $GLOBALS['eo_m9_entries'] as $entry ) {
		if ( false !== strpos( $entry['message'], $needle ) && ( '' === $level || $level === $entry['level'] ) ) {
			return $entry;
		}
	}
	return null;
};
$eo_dump     = static function () {
	return implode( ' || ', wp_list_pluck( $GLOBALS['eo_m9_entries'], 'message' ) );
};

Edit_Orders_For_WooCommerce_Audit_Log::maybe_install();
wp_set_current_user( 1 );
$eo_settings_before = get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null );
$eo_delete_before   = get_option( 'edit_orders_for_woocommerce_delete_data', null );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
delete_option( 'edit_orders_test_gateway_fail_refunds' );

foreach ( array( 'EO-TSHIRT', 'EO-MUG', 'EO-HOODIE-S', 'EO-HOODIE-L' ) as $eo_sku ) {
	$eo_t['product'][ $eo_sku ]                   = wc_get_product_id_by_sku( $eo_sku );
	$eo_t['stock'][ $eo_t['product'][ $eo_sku ] ] = wc_get_product( $eo_t['product'][ $eo_sku ] )->get_stock_quantity();
}

$eo_person     = array(
	'first_name' => 'Zebulon',
	'last_name'  => 'Quixotic',
	'company'    => 'Pemberton Widgets',
	'address_1'  => '742 Evergreen Terrace',
	'address_2'  => 'Suite Marigold',
	'city'       => 'Scarborough',
	'state'      => 'ON',
	'postcode'   => 'M4C 1B5',
	'country'    => 'CA',
	'phone'      => '4165550199',
);
$eo_email      = 'zeb.secret@example.org';
$eo_ip         = '203.0.113.77';
$eo_make_order = static function ( array $lines ) use ( &$eo_t, $eo_person, $eo_email, $eo_ip ) {
	$order = wc_create_order();
	$order->set_address( array_merge( $eo_person, array( 'email' => $eo_email ) ), 'billing' );
	$order->set_address( $eo_person, 'shipping' );
	$order->set_customer_ip_address( $eo_ip );
	foreach ( $lines as $line ) {
		$order->add_product( wc_get_product( $eo_t['product'][ $line[0] ] ), $line[1] );
	}
	$order->calculate_totals();
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$order->save();
	$order->payment_complete( 'TEST-' . $order->get_id() );
	$eo_t['orders'][] = $order->get_id();
	return wc_get_order( $order->get_id() );
};
$eo_item_id    = static function ( WC_Order $order, $sku ) use ( &$eo_t ) {
	foreach ( $order->get_items() as $item_id => $item ) {
		$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		if ( (int) $id === (int) $eo_t['product'][ $sku ] ) {
			return $item_id;
		}
	}
	return 0;
};
$eo_pay        = static function ( $balance_id ) {
	$balance = wc_get_order( $balance_id );
	$balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$balance->save();
	$balance->payment_complete( 'TEST-BAL-' . $balance_id );
};
$eo_decrease   = static function ( WC_Order $order ) use ( $eo_item_id ) {
	return array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_id( $order, 'EO-TSHIRT' ),
			'quantity' => 1,
		),
	);
};
$eo_money_text = static function ( $amount ) {
	return wc_format_decimal( $amount, wc_get_price_decimals() ) . ' ' . get_woocommerce_currency();
};

// ===========================================================================
WP_CLI::log( '1a. Debug on: a quantity decrease logs every step under the preview\'s run ID' );
$eo_debug( true );
$eo_reset();
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_changes = $eo_decrease( $eo_order );
// The editor's preview, as the admin screen builds it, then Apply with what it sent back.
$eo_preview = Edit_Orders_For_WooCommerce_Admin::preview( wc_get_order( $eo_order->get_id() ), $eo_changes );
$eo_run     = Edit_Orders_For_WooCommerce_Log::run_id();
preg_match( '/name="expect" value="([a-f0-9]+)"/', $eo_preview['html'], $eo_expect );
$eo_check( 'the editor preview carries its run ID for Apply', Edit_Orders_For_WooCommerce_Log::is_run_id( $eo_run ) && false !== strpos( $eo_preview['html'], 'name="run" value="' . $eo_run . '"' ) );
Edit_Orders_For_WooCommerce_Log::end_run(); // Apply is another request.
$eo_result = Edit_Orders_For_WooCommerce_Admin::apply(
	wc_get_order( $eo_order->get_id() ),
	array( 'items' => array( $eo_item_id( $eo_order, 'EO-TSHIRT' ) => array( 'qty' => '1' ) ) ),
	array(
		'notify_customer' => true,
		'expect'          => isset( $eo_expect[1] ) ? $eo_expect[1] : '',
		'run'             => $eo_run,
	)
);
$eo_check( 'set up: the decrease applied', is_array( $eo_result ) && 'applied' === $eo_result['status'], $eo_error( $eo_result ) );
$eo_missing = $eo_sequence( array( 'preview for staff #1', 'change started by staff #1', 'refund requested: ' . $eo_money_text( 22.60 ) . ' via edit_orders_test (can refund: yes)', 'recorded: ' . $eo_money_text( 22.60 ) . ', money sent through edit_orders_test: yes', 'applied', 'change finished: applied' ) );
$eo_check( 'every step is logged, in order', '' === $eo_missing, 'missing "' . $eo_missing . '" in: ' . $eo_dump() );
$eo_check( 'the order updated email is logged', null !== $eo_find( 'email edit_orders_for_woocommerce_order_updated' ), $eo_dump() );
$eo_check( 'one run ID for the whole change, the preview\'s', array( $eo_run ) === $eo_runs(), wp_json_encode( $eo_runs() ) );
$eo_entry = $eo_find( 'preview for staff #1' );
$eo_check( 'the preview entry has the plan fingerprint, refund lines and totals', $eo_entry && ! empty( $eo_entry['context']['fingerprint'] ) && ! empty( $eo_entry['context']['refund_lines'] ) && isset( $eo_entry['context']['balance_total'] ) && false !== strpos( $eo_entry['message'], 'plan ' . $eo_entry['context']['fingerprint'] ) );
$eo_entry = $eo_find( 'recorded' );
$eo_check( 'the refund entry names the gateway and the refund record', $eo_entry && 'edit_orders_test' === $eo_entry['context']['gateway'] && $eo_entry['context']['refund_id'] > 0 && array_key_exists( 'transaction_id', $eo_entry['context'] ) );
$eo_check( 'entries start with "[run <id>] order <id>:"', 0 === strpos( $GLOBALS['eo_m9_entries'][0]['message'], '[run ' . $eo_run . '] order ' . $eo_order->get_id() . ':' ), $GLOBALS['eo_m9_entries'] ? $GLOBALS['eo_m9_entries'][0]['message'] : 'none' );

// ===========================================================================
WP_CLI::log( '1b. Debug on: a dearer swap paid later keeps the run ID across the payment' );
$eo_reset();
$eo_order = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_post  = array(
	'edit_orders_for_woocommerce_action' => 'swap',
	'swap'                               => array( $eo_item_id( $eo_order, 'EO-HOODIE-S' ) => (string) $eo_t['product']['EO-HOODIE-L'] ),
);
$eo_step  = Edit_Orders_For_WooCommerce_Frontend::process( wc_get_order( $eo_order->get_id() ), $eo_post, 'customer' );
$eo_run   = Edit_Orders_For_WooCommerce_Log::run_id();
$eo_check( 'the customer preview carries its run ID', 'preview' === $eo_step['type'] && isset( $eo_step['preview']['fields']['run'] ) && $eo_run === $eo_step['preview']['fields']['run'] );
Edit_Orders_For_WooCommerce_Log::end_run(); // The confirm is another request.
$eo_step = Edit_Orders_For_WooCommerce_Frontend::process( wc_get_order( $eo_order->get_id() ), array_merge( $eo_step['preview']['fields'], array( 'step' => 'confirm' ) ), 'customer' );
$eo_bal  = (int) wc_get_order( $eo_order->get_id() )->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::OPEN_BALANCE_META );
$eo_check( 'set up: a balance order waits for payment', 'success' === $eo_step['type'] && $eo_bal > 0, $eo_step['message'] );
$eo_stored = (string) wc_get_order( $eo_bal )->get_meta( Edit_Orders_For_WooCommerce_Log::RUN_META );
$eo_check( 'the balance order stores the run ID', $eo_run === $eo_stored, $eo_stored );
Edit_Orders_For_WooCommerce_Log::end_run(); // Paid later, in another request.
$eo_pay( $eo_bal );
$eo_missing = $eo_sequence( array( 'preview for customer', 'change started by customer', 'balance order ' . $eo_bal . ' created', 'change finished: balance_due', 'balance order ' . $eo_bal . ' paid', 'switched from product ' . $eo_t['product']['EO-HOODIE-S'] . ' to ' . $eo_t['product']['EO-HOODIE-L'], 'applied (paid through balance order ' . $eo_bal . ')', 'balance order ' . $eo_bal . ': finished for order' ) );
$eo_check( 'every step is logged, in order, through the payment', '' === $eo_missing, 'missing "' . $eo_missing . '" in: ' . $eo_dump() );
$eo_check( 'one run ID from preview to payment', array( $eo_run ) === $eo_runs(), wp_json_encode( $eo_runs() ) );
ob_start();
Edit_Orders_For_WooCommerce_Frontend::render_for_order( wc_get_order( $eo_bal ) );
ob_end_clean();
$eo_check( 'the thank-you page of the balance order writes nothing', null === $eo_find( 'edit panel not shown' ), $eo_dump() );
$eo_entry = $eo_find( 'switched from product' );
$eo_check( 'the stock move names the products and quantities', $eo_entry && 1 === (int) $eo_entry['context']['back'] && 1 === (int) $eo_entry['context']['taken'], $eo_entry ? wp_json_encode( $eo_entry['context'] ) : 'none' );

// ===========================================================================
WP_CLI::log( '1c. Debug on: an address change logs country and state only' );
$eo_reset();
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_changes = array(
	array(
		'type'     => 'address',
		'shipping' => array(
			'address_1' => '99 Hidden Lane',
			'city'      => 'Calgary',
			'state'     => 'AB',
			'postcode'  => 'T2P 1J9',
			'country'   => 'CA',
		),
	),
);
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( wc_get_order( $eo_order->get_id() ), $eo_changes, 'staff' );
$eo_run     = Edit_Orders_For_WooCommerce_Log::run_id();
$eo_result  = is_wp_error( $eo_plan ) ? $eo_plan : Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
	$eo_changes,
	'staff',
	array(
		'expect' => $eo_plan->fingerprint(),
		'run'    => $eo_run,
	)
);
$eo_check( 'set up: the address change applied', is_array( $eo_result ), $eo_error( $eo_result ) );
$eo_missing = $eo_sequence( array( 'address change: shipping CA/ON to CA/AB', 'preview for staff #1', 'change started by staff #1', 'change finished' ) );
$eo_check( 'the address change and every step are logged', '' === $eo_missing, 'missing "' . $eo_missing . '" in: ' . $eo_dump() );
$eo_entry = $eo_find( 'address change:' );
$eo_check( 'it gives the shipping and tax difference', $eo_entry && isset( $eo_entry['context']['tax_delta'], $eo_entry['context']['shipping_delta'], $eo_entry['context']['net'] ) && false !== strpos( $eo_entry['message'], 'tax difference' ) );
$eo_check( 'one run ID for the change', array( $eo_run ) === $eo_runs(), wp_json_encode( $eo_runs() ) );

// ===========================================================================
WP_CLI::log( '1d. Debug on: an instant cancellation logs every step under one run ID' );
update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array( 'cancel_mode' => 'instant' ) );
$eo_reset();
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Cancellation::request( wc_get_order( $eo_order->get_id() ), 'Xylophone grievance', 'customer' );
$eo_check( 'set up: cancelled', is_array( $eo_result ) && 'cancelled' === $eo_result['status'], $eo_error( $eo_result ) );
$eo_missing = $eo_sequence( array( 'cancellation requested by customer (instant mode, reason given: yes)', 'refund requested: ' . $eo_money_text( 22.60 ), 'refund #', 'cancelled for customer; refund ' . $eo_money_text( 22.60 ), 'email edit_orders_for_woocommerce_cancelled' ) );
$eo_check( 'every step is logged, in order', '' === $eo_missing, 'missing "' . $eo_missing . '" in: ' . $eo_dump() );
$eo_check( 'one run ID for the cancellation', 1 === count( $eo_runs() ) && Edit_Orders_For_WooCommerce_Log::is_run_id( $eo_runs()[0] ), wp_json_encode( $eo_runs() ) );

WP_CLI::log( '1e. Debug on: a request and its approval later share the run ID' );
update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array( 'cancel_mode' => 'approval' ) );
$eo_reset();
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Cancellation::request( wc_get_order( $eo_order->get_id() ), '', 'customer' );
$eo_run    = Edit_Orders_For_WooCommerce_Log::run_id();
Edit_Orders_For_WooCommerce_Log::end_run(); // The store answers in another request.
$eo_result = Edit_Orders_For_WooCommerce_Cancellation::approve( wc_get_order( $eo_order->get_id() ) );
$eo_check( 'set up: approved and cancelled', is_array( $eo_result ) && 'cancelled' === $eo_result['status'], $eo_error( $eo_result ) );
$eo_missing = $eo_sequence( array( 'cancellation requested by customer (approval mode, reason given: no)', 'cancellation approved by staff #1', 'refund requested', 'cancelled for staff #1' ) );
$eo_check( 'request, approval and refund are logged, in order', '' === $eo_missing, 'missing "' . $eo_missing . '" in: ' . $eo_dump() );
$eo_check( 'one run ID from the request to the approval', array( $eo_run ) === $eo_runs(), wp_json_encode( $eo_runs() ) );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );

WP_CLI::log( '1f. Debug on: an email the editor chose not to send is logged as not sent' );
$eo_reset();
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $eo_order->get_id() ), $eo_decrease( $eo_order ), 'staff', array( 'notify_customer' => false ) );
$eo_check( 'logged: order updated email not sent, by choice', null !== $eo_find( 'email edit_orders_for_woocommerce_order_updated not sent: the person making the change chose not to send it', 'debug' ) && null === $eo_find( 'email edit_orders_for_woocommerce_order_updated sent' ), $eo_dump() );

// ===========================================================================
WP_CLI::log( '2. Debug off: the same flows log only notice and above' );
$eo_debug( false );
$eo_reset();
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $eo_order->get_id() ), $eo_decrease( $eo_order ) );
$eo_order  = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item_id( $eo_order, 'EO-HOODIE-S' ),
			'variation_id' => $eo_t['product']['EO-HOODIE-L'],
		),
	)
);
$eo_pay( is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0 );
update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array( 'cancel_mode' => 'instant' ) );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
Edit_Orders_For_WooCommerce_Cancellation::request( wc_get_order( $eo_order->get_id() ), 'Xylophone grievance', 'customer' );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
$eo_check( 'successful changes write nothing', array() === $eo_entries(), $eo_dump() );
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	wc_get_order( $eo_order->get_id() ),
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => 999999,
			'quantity' => 1,
		),
	)
);
$eo_check( 'a refused plan is still logged, as a notice', null !== $eo_find( 'plan refused', 'notice' ) && array( 'notice' ) === $eo_levels(), $eo_dump() );

// ===========================================================================
WP_CLI::log( '3a. Failures (debug off): a gateway refusal logs a warning' );
$eo_reset();
update_option( 'edit_orders_test_gateway_fail_refunds', 'yes' );
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $eo_order->get_id() ), $eo_decrease( $eo_order ) );
delete_option( 'edit_orders_test_gateway_fail_refunds' );
$eo_entry = $eo_find( 'refused the refund', 'warning' );
$eo_check( 'warning: the gateway refused, with its error', $eo_entry && false !== strpos( $eo_entry['message'], 'Test gateway: refund declined on request' ) && 'edit_orders_test' === $eo_entry['context']['gateway'], $eo_dump() );
$eo_check( 'the gateway error reads as one sentence (no double full stop)', $eo_entry && false === strpos( $eo_entry['message'], '..' ), $eo_entry ? $eo_entry['message'] : 'none' );
$eo_entry = $eo_find( 'manual refund flagged', 'warning' );
$eo_check( 'warning: manual refund flagged, with the reason code', $eo_entry && 'gateway_refused' === $eo_entry['context']['code'] && false !== strpos( $eo_entry['message'], $eo_money_text( 22.60 ) ), $eo_dump() );
$eo_manual_order = $eo_order->get_id();

WP_CLI::log( '3b. Failures (debug off): a refund that can\'t be recorded logs an error naming the refunds made' );
$eo_reset();
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
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
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_tshirt,
		),
	)
);
remove_action( 'woocommerce_create_refund', $eo_fail, 10 );
$eo_entry = $eo_find( 'could not be recorded', 'error' );
$eo_check( 'set up: the edit failed', is_wp_error( $eo_result ) );
$eo_check( 'error: names WooCommerce\'s error and the refund already made on the balance order', $eo_entry && false !== strpos( $eo_entry['message'], 'Test: the refund could not be saved.' ) && false !== strpos( $eo_entry['message'], 'Refunds already made in this run: ' . $eo_money_text( 22.60 ) . ' on balance order ' . $eo_bal ), $eo_dump() );

WP_CLI::log( '3b2. Failures (debug off): the refund on the balance order can not be recorded' );
$eo_reset();
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
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
$eo_fail = static function ( $refund, $args ) use ( $eo_bal ) {
	if ( (int) $args['order_id'] === $eo_bal ) {
		throw new Exception( 'Test: the balance refund could not be saved.' );
	}
};
add_action( 'woocommerce_create_refund', $eo_fail, 10, 2 );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_tshirt,
		),
	)
);
remove_action( 'woocommerce_create_refund', $eo_fail, 10 );
$eo_entry = $eo_find( 'refund on order ' . $eo_bal . ' could not be recorded', 'error' );
$eo_check( 'set up: the edit failed on the refund of the balance order', is_wp_error( $eo_result ) );
$eo_check( 'error: names the balance order, the WooCommerce error and no refunds made', $eo_entry && false !== strpos( $eo_entry['message'], 'Test: the balance refund could not be saved.' ) && false !== strpos( $eo_entry['message'], 'Refunds already made in this run: none' ), $eo_dump() );

WP_CLI::log( '3c. Failures (debug off): a run marked stuck logs an error, then a warning when put right' );
$eo_reset();
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ), array( 'EO-MUG', 1 ) ) );
$eo_tshirt = $eo_item_id( $eo_order, 'EO-TSHIRT' );
$eo_mug    = $eo_item_id( $eo_order, 'EO-MUG' );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_tshirt,
			'quantity' => 2,
		),
	)
);
$eo_pay( is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0 );
$eo_result  = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
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
$eo_bal2    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_bal_run = (string) wc_get_order( $eo_bal2 )->get_meta( Edit_Orders_For_WooCommerce_Log::RUN_META );
$eo_once    = false;
// While the first refund runs, the run looks ten minutes old and another request marks it stuck.
$eo_slow = static function () use ( $eo_bal2, $eo_order, &$eo_once ) {
	$balance = wc_get_order( $eo_bal2 );
	if ( $eo_once || ! $balance || ! $balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLYING_META ) ) {
		return;
	}
	$eo_once = true;
	$balance->update_meta_data( Edit_Orders_For_WooCommerce_Balance_Orders::APPLYING_META, time() - 600 );
	$balance->save();
	Edit_Orders_For_WooCommerce_Balance_Orders::is_applying( wc_get_order( $eo_order->get_id() ) );
};
add_action( 'woocommerce_create_refund', $eo_slow );
Edit_Orders_For_WooCommerce_Log::end_run();
$eo_pay( $eo_bal2 );
remove_action( 'woocommerce_create_refund', $eo_slow );
$eo_entry = $eo_find( 'marked stuck', 'error' );
$eo_check( 'error: marked stuck, with when it started', $eo_entry && $eo_bal2 === (int) $eo_entry['context']['balance'] && $eo_entry['context']['started_at'] > 0, $eo_dump() );
$eo_check( 'warning: put right when it finished', null !== $eo_find( 'did finish after all', 'warning' ), $eo_dump() );
$eo_check( 'warning: the false manual refund flag is taken back', null !== $eo_find( 'manual refund flag', 'warning' ) && null !== $eo_find( 'taken back', 'warning' ), $eo_dump() );
$eo_check( 'the stuck and put-right entries carry the change\'s run ID', $eo_entry && $eo_bal_run === $eo_entry['context']['run'] && $eo_bal_run === $eo_find( 'did finish after all' )['context']['run'] );

WP_CLI::log( '3d. An unexpected error is logged with where it happened, and still thrown' );
$eo_reset();
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_boom  = static function () {
	throw new RuntimeException( 'Boom from a listener' );
};
add_action( 'edit_orders_for_woocommerce_before_apply', $eo_boom );
$eo_thrown = '';
try {
	Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $eo_order->get_id() ), $eo_decrease( $eo_order ) );
} catch ( RuntimeException $e ) {
	$eo_thrown = $e->getMessage();
}
remove_action( 'edit_orders_for_woocommerce_before_apply', $eo_boom );
$eo_entry = $eo_find( 'unexpected error while applying a change to order ' . $eo_order->get_id(), 'error' );
$eo_check( 'the error is still thrown', 'Boom from a listener' === $eo_thrown );
$eo_check( 'error: where and what, with the file and line', $eo_entry && false !== strpos( $eo_entry['message'], 'Boom from a listener' ) && 1 === preg_match( '/:\d+$/', (string) $eo_entry['context']['file'] ) && 'RuntimeException' === $eo_entry['context']['type'], $eo_dump() );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $eo_order->get_id() ), $eo_decrease( $eo_order ) );
$eo_check( 'the guard stays after a crash, as before: a second apply right away is refused', is_wp_error( $eo_result ) && 'edit_orders_for_woocommerce_busy' === $eo_result->get_error_code(), $eo_error( $eo_result ) );
$eo_check( 'notice: the busy refusal is logged', null !== $eo_find( 'another change to this order is running', 'notice' ), $eo_dump() );
Edit_Orders_For_WooCommerce_Lock::unclaim( 'apply_' . $eo_order->get_id() );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $eo_order->get_id() ), $eo_decrease( $eo_order ) );
$eo_check( 'set up: once the guard is gone the change applies', is_array( $eo_result ), $eo_error( $eo_result ) );

WP_CLI::log( '3e. The wrong-key limit logs a warning with the order and no IP' );
$eo_reset();
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
delete_transient( Edit_Orders_For_WooCommerce_Customer_Rules::failed_key_order_transient( $eo_order->get_id() ) );
delete_transient( 'eofw_badkey_' . md5( WC_Geolocation::get_ip_address() ) );
for ( $eo_i = 0; $eo_i < Edit_Orders_For_WooCommerce_Customer_Rules::MAX_FAILED_KEYS_PER_ORDER; $eo_i++ ) {
	Edit_Orders_For_WooCommerce_Customer_Rules::authorize( wc_get_order( $eo_order->get_id() ), 'wc_order_wrong' . $eo_i );
}
$eo_entry = $eo_find( 'wrong-key limit reached (for this order', 'warning' );
$eo_check( 'warning once the order\'s limit is reached', $eo_entry && $eo_order->get_id() === (int) $eo_entry['context']['order'], $eo_dump() );
$eo_check( 'no key and no IP in it', $eo_entry && false === strpos( wp_json_encode( $eo_entry ), 'wc_order_wrong' ) && false === strpos( wp_json_encode( $eo_entry ), $eo_ip ) );
delete_transient( Edit_Orders_For_WooCommerce_Customer_Rules::failed_key_order_transient( $eo_order->get_id() ) );
foreach ( array( 'eofw_badkey_' . md5( WC_Geolocation::get_ip_address() ) ) as $eo_transient ) {
	delete_transient( $eo_transient );
}

WP_CLI::log( '3f. A customer request with a wrong key logs the refusal code, never the key' );
$eo_reset();
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_die   = static function () {
	return static function ( $message ) {
		throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? esc_html( $message ) : '' ) );
	};
};
add_filter( 'wp_die_handler', $eo_die, PHP_INT_MAX );
$_POST   = array(
	'edit_orders_for_woocommerce_action' => 'cancel',
	'_eofw_nonce'                        => wp_create_nonce( Edit_Orders_For_WooCommerce_Frontend::NONCE ),
	'order_id'                           => (string) $eo_order->get_id(),
	'order_key'                          => 'wc_order_notTheKey42',
);
$eo_died = '';
try {
	Edit_Orders_For_WooCommerce_Frontend::handle_post();
} catch ( RuntimeException $e ) {
	$eo_died = $e->getMessage();
}
$_POST = array();
remove_filter( 'wp_die_handler', $eo_die, PHP_INT_MAX );
$eo_entry = $eo_find( 'customer access refused', 'notice' );
$eo_check( 'set up: the request was refused', 0 === strpos( $eo_died, 'wp_die: ' ), $eo_died );
$eo_check( 'notice: the refusal code, with the order', $eo_entry && 'edit_orders_for_woocommerce_not_found' === $eo_entry['context']['code'] && $eo_order->get_id() === (int) $eo_entry['context']['order'], $eo_dump() );
$eo_check( 'the key sent is not in the log', false === strpos( wp_json_encode( $eo_entries() ), 'notTheKey42' ) );
delete_transient( Edit_Orders_For_WooCommerce_Customer_Rules::failed_key_order_transient( $eo_order->get_id() ) );
delete_transient( 'eofw_badkey_' . md5( WC_Geolocation::get_ip_address() ) );

WP_CLI::log( '3g. A cancellation whose refund fails names the balance order refunds already made' );
$eo_reset();
$eo_order  = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_id( $eo_order, 'EO-TSHIRT' ),
			'quantity' => 2,
		),
	)
);
$eo_bal    = is_array( $eo_result ) ? (int) $eo_result['balance_order_id'] : 0;
$eo_pay( $eo_bal );
$eo_parent = $eo_order->get_id();
$eo_fail   = static function ( $refund, $args ) use ( $eo_parent ) {
	if ( (int) $args['order_id'] === $eo_parent ) {
		throw new Exception( 'Test: the cancel refund could not be saved.' );
	}
};
add_action( 'woocommerce_create_refund', $eo_fail, 10, 2 );
Edit_Orders_For_WooCommerce_Cancellation::execute( wc_get_order( $eo_order->get_id() ), 'staff' );
remove_action( 'woocommerce_create_refund', $eo_fail, 10 );
$eo_entry = $eo_find( 'cancelled, but the refund of', 'error' );
$eo_check( 'error: names the refund already made on the balance order', $eo_entry && false !== strpos( $eo_entry['message'], 'Refunds already made in this run: ' . $eo_money_text( 22.60 ) . ' on balance order ' . $eo_bal ), $eo_dump() );

WP_CLI::log( '3h. A refusal because someone else is editing never names them' );
$eo_reason = Edit_Orders_For_WooCommerce_Log::reason( new WP_Error( 'edit_orders_for_woocommerce_locked', 'Priscilla Staffperson is editing this order.' ) );
$eo_check( 'the staff name is replaced', false === strpos( $eo_reason, 'Priscilla' ) && 'another staff member is editing this order' === $eo_reason, $eo_reason );

// ===========================================================================
WP_CLI::log( '4. Redaction: no name, email, street, postcode, IP, order key or cancel reason in any entry' );
$eo_debug( true );
$eo_reset();
$eo_order = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_key   = $eo_order->get_order_key();
Edit_Orders_For_WooCommerce_Settlement_Executor::apply( wc_get_order( $eo_order->get_id() ), $eo_decrease( $eo_order ) );
$eo_order2 = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_key2   = $eo_order2->get_order_key();
Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order2->get_id() ),
	array(
		array(
			'type'     => 'address',
			'shipping' => array(
				'address_1' => '99 Hidden Lane',
				'city'      => 'Calgary',
				'state'     => 'AB',
				'postcode'  => 'T2P 1J9',
				'country'   => 'CA',
			),
		),
	)
);
update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array( 'cancel_mode' => 'instant' ) );
Edit_Orders_For_WooCommerce_Cancellation::request( wc_get_order( $eo_order2->get_id() ), 'Xylophone grievance', 'customer' );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
// The safety net: a careless call with personal data in its keys, values and message.
Edit_Orders_For_WooCommerce_Log::warning(
	'order ' . $eo_order->get_id() . ': careless call with key ' . $eo_key . ' for Zebulon Quixotic at 742 Evergreen Terrace; it will not be applied',
	array(
		'order'         => $eo_order->get_id(),
		'billing_email' => $eo_email,
		'order_key'     => $eo_key,
		'customer_note' => 'Leave it with the neighbour',
		'details'       => array(
			'first_name' => 'Zebulon',
			'city'       => 'Scarborough',
			'postcode'   => 'M4C 1B5',
			'country'    => 'CA',
		),
		'free_text'     => 'Call 416 555 0199 or Zebulon Quixotic at ' . $eo_email,
	)
);
$eo_all    = wp_json_encode( $eo_entries() );
$eo_leaked = array();
foreach ( array( 'Zebulon', 'Quixotic', 'Pemberton', $eo_email, '742 Evergreen', 'Marigold', 'Scarborough', 'M4C 1B5', '4165550199', $eo_ip, $eo_key, $eo_key2, 'Xylophone', '99 Hidden Lane', 'Calgary', 'T2P 1J9', 'neighbour' ) as $eo_secret ) {
	if ( false !== stripos( $eo_all, $eo_secret ) ) {
		$eo_leaked[] = $eo_secret;
	}
}
$eo_check( 'set up: the flows and the careless call were logged', count( $eo_entries() ) > 10 && null !== $eo_find( 'careless call' ), (string) count( $eo_entries() ) );
$eo_check( 'none of them appears in any entry or context', array() === $eo_leaked, implode( ', ', $eo_leaked ) );
$eo_entry = $eo_find( 'careless call' );
$eo_check( 'personal keys are dropped, at any depth; the rest stays', $eo_entry && ! isset( $eo_entry['context']['billing_email'] ) && ! isset( $eo_entry['context']['order_key'] ) && ! isset( $eo_entry['context']['customer_note'] ) && ! isset( $eo_entry['context']['details']['first_name'] ) && ! isset( $eo_entry['context']['details']['city'] ) && ! isset( $eo_entry['context']['details']['postcode'] ) && 'CA' === $eo_entry['context']['details']['country'], $eo_entry ? wp_json_encode( $eo_entry['context'] ) : 'none' );
$eo_check( 'the key and details in the text are replaced, ordinary words are not', $eo_entry && false !== strpos( $eo_entry['message'], 'key [redacted] for [redacted] at [redacted]; it will not be applied' ) && false !== strpos( $eo_entry['context']['free_text'], '[redacted]' ), $eo_entry ? $eo_entry['message'] . ' / ' . $eo_entry['context']['free_text'] : 'none' );

// ===========================================================================
WP_CLI::log( '5. Saving the settings logs only the changed settings' );
remove_filter( 'edit_orders_for_woocommerce_debug_log', $eo_debug_on );
remove_filter( 'edit_orders_for_woocommerce_debug_log', $eo_debug_off );
$eo_reset();
$GLOBALS['current_section'] = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- what WooCommerce does on its settings screen.
$eo_save                    = static function ( array $changes ) {
	$key      = Edit_Orders_For_WooCommerce_Settings::OPTION;
	$settings = array_merge( wp_parse_args( (array) get_option( $key, array() ), Edit_Orders_For_WooCommerce_Settings::defaults() ), $changes );
	$post     = array();
	foreach ( $settings as $name => $value ) {
		if ( 'no' === $value ) {
			continue; // An unticked box isn't posted.
		}
		$post[ $name ] = $value;
	}
	$form = array( $key => $post );
	if ( 'yes' === get_option( 'edit_orders_for_woocommerce_delete_data', 'no' ) ) {
		$form['edit_orders_for_woocommerce_delete_data'] = 'yes';
	}
	Edit_Orders_For_WooCommerce_Settings_Form::save( $form );
};
$eo_save(
	array(
		'debug_log'      => 'yes',
		'window_minutes' => 90,
	)
);
$eo_entry = $eo_find( 'settings saved by staff #1' );
$eo_check( 'the Debug log setting saves', Edit_Orders_For_WooCommerce_Settings::is_on( 'debug_log' ) && Edit_Orders_For_WooCommerce_Log::debug_enabled() );
$eo_check( 'logged: the changed settings and their new values (a notice, as the debug log was switched)', $eo_entry && 'notice' === $eo_entry['level'] && false !== strpos( $eo_entry['message'], 'debug_log = yes' ) && false !== strpos( $eo_entry['message'], 'window_minutes = 90' ), $eo_dump() );
$eo_check( 'not logged: settings that did not change', $eo_entry && 2 === count( $eo_entry['context']['changed'] ) && false === strpos( $eo_entry['message'], 'cancel_mode' ), $eo_entry ? $eo_entry['message'] : 'none' );
$eo_reset();
$eo_save( array() );
$eo_check( 'saving again without changes logs nothing', null === $eo_find( 'settings saved' ), $eo_dump() );
$eo_reset();
$eo_save( array( 'debug_log' => 'no' ) );
$eo_entry = $eo_find( 'settings saved by staff #1' );
$eo_check( 'turning the debug log off is still logged, as a notice', $eo_entry && 'notice' === $eo_entry['level'] && false !== strpos( $eo_entry['message'], 'debug_log = no' ), $eo_dump() );
ob_start();
Edit_Orders_For_WooCommerce_Settings_Form::render();
$eo_form = ob_get_clean();
$eo_check( 'the Debug log field is on the settings page', false !== strpos( $eo_form, 'name="' . Edit_Orders_For_WooCommerce_Settings::OPTION . '[debug_log]"' ) );
$eo_check( 'it is off by default and links to the logs filtered to this source', 'no' === Edit_Orders_For_WooCommerce_Settings::defaults()['debug_log'] && false !== strpos( $eo_form, 'page=wc-status' ) && false !== strpos( $eo_form, 'tab=logs' ) && false !== strpos( $eo_form, 'source=wpheka-edit-orders-for-woocommerce' ) && false !== strpos( $eo_form, 'Errors, warnings and refusals are always recorded' ) );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
$eo_check( 'off by default: info is not written without the setting or the filter', ! Edit_Orders_For_WooCommerce_Log::debug_enabled() );

// ===========================================================================
WP_CLI::log( '6. The Edit Orders section in System Status' );
$eo_rows = array();
foreach ( Edit_Orders_For_WooCommerce_System_Status::rows() as $eo_row ) {
	$eo_rows[ $eo_row['label'] ] = $eo_row['value'];
}
$eo_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
$eo_check( 'plugin version and Debug log', EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION === $eo_rows['Version'] && 'No' === $eo_rows['Debug log'], wp_json_encode( $eo_rows ) );
$eo_check( 'every plugin setting', isset( $eo_rows['Setting: window_minutes'], $eo_rows['Setting: cancel_mode'], $eo_rows['Setting: debug_log'], $eo_rows['Setting: delete_data'] ) && '60' === $eo_rows['Setting: window_minutes'] );
$eo_check( 'tax rows', isset( $eo_rows['Tax enabled'], $eo_rows['Prices entered with tax'], $eo_rows['Round tax at subtotal'], $eo_rows['Tax based on'] ) );
$eo_check( 'HPOS on or off, and compatibility mode', ( $eo_hpos ? 'Yes' : 'No' ) === $eo_rows['HPOS'] && isset( $eo_rows['HPOS compatibility mode'] ) );
$eo_check( 'enabled gateways and whether each refunds automatically', false !== strpos( $eo_rows['Payment gateways'], 'edit_orders_test (refunds automatically)' ), $eo_rows['Payment gateways'] );
$eo_check( 'shipment tracking plugins', isset( $eo_rows['Shipment tracking'] ) );
$eo_check( 'counts: open balance orders, stuck, manual refunds', isset( $eo_rows['Open balance orders'], $eo_rows['Balance orders marked stuck'] ) && (int) $eo_rows['Orders ever flagged for a manual refund'] >= 1, wp_json_encode( $eo_rows ) );
$eo_open_before = (int) $eo_rows['Open balance orders'];
$eo_order       = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	wc_get_order( $eo_order->get_id() ),
	array(
		array(
			'type'         => 'swap',
			'item_id'      => $eo_item_id( $eo_order, 'EO-HOODIE-S' ),
			'variation_id' => $eo_t['product']['EO-HOODIE-L'],
		),
	)
);
$eo_rows2 = wp_list_pluck( Edit_Orders_For_WooCommerce_System_Status::rows(), 'value', 'label' );
$eo_check( 'an unpaid balance order counts as open', $eo_open_before + 1 === (int) $eo_rows2['Open balance orders'], $eo_open_before . ' then ' . $eo_rows2['Open balance orders'] );
ob_start();
Edit_Orders_For_WooCommerce_System_Status::render();
$eo_html = ob_get_clean();
$eo_check( 'the section prints as a status table WooCommerce copies into the report', false !== strpos( $eo_html, 'data-export-label="Edit Orders"' ) && false !== strpos( $eo_html, 'data-export-label="Version"' ) );
$eo_check( 'the section shows no customer data', false === strpos( $eo_html, $eo_email ) && false === strpos( $eo_html, 'Zebulon' ) );

// ===========================================================================
WP_CLI::log( 'Cleanup' );
remove_filter( 'woocommerce_register_log_handlers', $eo_handlers );
remove_filter( 'woocommerce_logging_class', $eo_logger );
remove_filter( 'edit_orders_for_woocommerce_debug_log', $eo_debug_on );
remove_filter( 'edit_orders_for_woocommerce_debug_log', $eo_debug_off );
Edit_Orders_For_WooCommerce_Log::end_run();
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
if ( null === $eo_delete_before ) {
	delete_option( 'edit_orders_for_woocommerce_delete_data' );
} else {
	update_option( 'edit_orders_for_woocommerce_delete_data', $eo_delete_before );
}
if ( null === $eo_logging_before ) {
	delete_option( 'woocommerce_logs_logging_enabled' );
} else {
	update_option( 'woocommerce_logs_logging_enabled', $eo_logging_before );
}
delete_option( Edit_Orders_Mail_Log::OPTION );
Edit_Orders_For_WooCommerce_Cancellation::forget_pending_count();

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'Logging suite failed.' );
}
WP_CLI::success( 'Logging suite passed.' );
