<?php
/**
 * Functional suite for M3: the settings screen (spec section 6).
 *
 * Saves go through WC_Admin_Settings::save_fields() with a posted form, the same path
 * as pressing "Save changes" in WooCommerce > Settings > Edit Orders.
 *
 * Run with: bash tests/functional/run.sh m3-settings (or all suites without an argument).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

$eo_t = array(
	'pass'   => 0,
	'fail'   => 0,
	'orders' => array(),
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

if ( ! class_exists( 'Edit_Orders_For_WooCommerce_Admin' ) ) {
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/admin/class-edit-orders-for-woocommerce-admin.php';
}
if ( ! class_exists( 'WC_Admin_Settings', false ) ) {
	require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
}
if ( ! class_exists( 'WC_Settings_Page', false ) ) {
	require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
}

$eo_before_settings = get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null );
$eo_before_delete   = get_option( 'edit_orders_for_woocommerce_delete_data', null );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
wp_set_current_user( 1 );

$eo_pages = Edit_Orders_For_WooCommerce_Admin::settings_page( array() );
$eo_page  = end( $eo_pages );
$eo_check( 'the Edit Orders settings tab is registered', $eo_page instanceof Edit_Orders_For_WooCommerce_Settings_Page && 'edit_orders' === $eo_page->get_id() );

$eo_fields  = $eo_page->get_settings();
$eo_ids     = wp_list_pluck(
	array_filter(
		$eo_fields,
		static function ( $f ) {
			return isset( $f['id'] ) && ! in_array( $f['type'], array( 'title', 'sectionend' ), true );
		} ), 'id' ); // phpcs:ignore Generic.Functions.FunctionCallArgumentSpacing,PEAR.Functions.FunctionCallSignature -- compact test helper.
$eo_want    = array( 'admin_enabled', 'editable_statuses', 'customer_enabled', 'window_minutes', 'action_cancel', 'action_address', 'action_swap', 'action_note', 'cancel_mode', 'cancel_reasons', 'cancel_reason_required', 'cancel_policy', 'note_max_length' );
$eo_missing = array();
foreach ( $eo_want as $eo_key ) {
	if ( ! in_array( Edit_Orders_For_WooCommerce_Settings::OPTION . '[' . $eo_key . ']', $eo_ids, true ) ) {
		$eo_missing[] = $eo_key;
	}
}
$eo_check( 'every setting has a field, saved into the one settings option', array() === $eo_missing, implode( ', ', $eo_missing ) );
$eo_check( 'delete-data uses the option uninstall.php reads', in_array( 'edit_orders_for_woocommerce_delete_data', $eo_ids, true ) && false !== strpos( (string) file_get_contents( EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'uninstall.php' ), "'edit_orders_for_woocommerce_delete_data'" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$eo_statuses = array();
foreach ( $eo_fields as $eo_field ) {
	if ( isset( $eo_field['id'] ) && false !== strpos( $eo_field['id'], 'editable_statuses' ) ) {
		$eo_statuses = array_keys( $eo_field['options'] );
	}
}
$eo_check( 'status choices leave out final and unpaid statuses', in_array( 'processing', $eo_statuses, true ) && in_array( 'on-hold', $eo_statuses, true ) && ! array_intersect( array( 'completed', 'cancelled', 'refunded', 'failed', 'pending' ), $eo_statuses ) );

// The settings screen sets the section; WooCommerce before 9.6 prints nothing without it.
$GLOBALS['current_section'] = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- what WooCommerce's settings screen does.
ob_start();
$eo_page->output();
$eo_html = ob_get_clean();
$eo_check( 'the screen renders the fields', false !== strpos( $eo_html, 'edit_orders_for_woocommerce_settings[window_minutes]' ) && false !== strpos( $eo_html, 'edit_orders_for_woocommerce_settings[cancel_mode]' ) );
$eo_check( 'with defaults: approval and 60 minutes', false !== strpos( $eo_html, 'value="60"' ) && preg_match( '/<option value="approval"[^>]*selected/', $eo_html ) );

WP_CLI::log( 'Saving' );
// WooCommerce sets this from ?section= on the settings screen; '' is the default section.
$GLOBALS['current_section'] = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- what WooCommerce does on its settings screen.
$eo_save                    = static function ( array $post ) use ( $eo_page ) {
	// The browser always posts textareas; WooCommerce trims them and warns on a missing one.
	$key          = Edit_Orders_For_WooCommerce_Settings::OPTION;
	$post[ $key ] = array_merge(
		array(
			'cancel_reasons' => '',
			'cancel_policy'  => '',
		),
		$post[ $key ]
	);
	$_POST        = $post;
	$eo_page->save();
	$_POST = array();
};
$eo_option                  = Edit_Orders_For_WooCommerce_Settings::OPTION;
$eo_save(
	array(
		$eo_option => array(
			'admin_enabled'     => '1',
			'editable_statuses' => array( 'processing' ),
			'customer_enabled'  => '1',
			'window_minutes'    => '2',
			'action_cancel'     => '1',
			'cancel_mode'       => 'instant',
			'cancel_reasons'    => "Too slow\nWrong item",
			'note_max_length'   => '99999',
		),
	)
);
$eo_check( 'saved values are read back by the plugin', 'instant' === Edit_Orders_For_WooCommerce_Settings::get( 'cancel_mode' ) && array( 'Too slow', 'Wrong item' ) === Edit_Orders_For_WooCommerce_Settings::cancel_reasons() );
$eo_check( 'unticked boxes save as off (address, swap, note)', ! Edit_Orders_For_WooCommerce_Settings::is_on( 'action_address' ) && ! Edit_Orders_For_WooCommerce_Settings::is_on( 'action_swap' ) && ! Edit_Orders_For_WooCommerce_Settings::is_on( 'action_note' ) && array( 'cancel' ) === Edit_Orders_For_WooCommerce_Customer_Rules::enabled_actions() );
$eo_check( 'window clamped up to 5 minutes', 5 === (int) Edit_Orders_For_WooCommerce_Settings::get( 'window_minutes' ) && 5 * MINUTE_IN_SECONDS === Edit_Orders_For_WooCommerce_Settings::window_seconds() );
$eo_check( 'note length clamped down to 2000', 2000 === (int) Edit_Orders_For_WooCommerce_Settings::get( 'note_max_length' ) );
$eo_check( 'editable statuses: only Processing', array( 'processing' ) === Edit_Orders_For_WooCommerce_Eligibility::editable_statuses() );

WP_CLI::log( 'Settings take effect' );
$eo_order = wc_create_order();
$eo_order->add_product( wc_get_product( wc_get_product_id_by_sku( 'EO-MUG' ) ), 1 );
$eo_order->set_billing_email( 'customer@example.com' );
$eo_order->calculate_totals();
$eo_order->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
$eo_order->save();
$eo_order->payment_complete( 'TEST-SET' );
$eo_t['orders'][] = $eo_order->get_id();
$eo_order->update_status( 'on-hold' );
$eo_order  = wc_get_order( $eo_order->get_id() );
$eo_result = Edit_Orders_For_WooCommerce_Eligibility::check_order( $eo_order );
$eo_check( 'On hold is refused once only Processing is allowed, naming the allowed status', is_wp_error( $eo_result ) && false !== strpos( $eo_result->get_error_message(), 'Processing' ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : 'allowed' );
$eo_order->update_status( 'processing' );
$eo_check( 'Processing is allowed', true === Edit_Orders_For_WooCommerce_Eligibility::check_order( wc_get_order( $eo_order->get_id() ) ) );

$eo_save(
	array(
		$eo_option => array(
			'editable_statuses' => array( 'processing' ),
			'window_minutes'    => '60',
		),
	)
);
$eo_result = Edit_Orders_For_WooCommerce_Eligibility::check_order( wc_get_order( $eo_order->get_id() ) );
$eo_check( 'store-owner editing off: staff edits refused with a pointer to the setting', is_wp_error( $eo_result ) && 'edit_orders_for_woocommerce_admin_off' === $eo_result->get_error_code() );
$eo_check( 'customer changes off: customers refused', is_wp_error( Edit_Orders_For_WooCommerce_Customer_Rules::can( wc_get_order( $eo_order->get_id() ), 'cancel' ) ) );

$eo_save(
	array(
		$eo_option => array(
			'admin_enabled'    => '1',
			'customer_enabled' => '1',
			'window_minutes'   => '60',
		),
	)
);
$eo_check( 'clearing the status list falls back to Processing and On hold', array( 'processing', 'on-hold' ) === Edit_Orders_For_WooCommerce_Eligibility::editable_statuses() );

update_option( $eo_option, array( 'editable_statuses' => array( 'completed', 'refunded' ) ) );
$eo_check( 'a stored final status is ignored', array( 'processing', 'on-hold' ) === Edit_Orders_For_WooCommerce_Eligibility::editable_statuses() );

$eo_links = Edit_Orders_For_WooCommerce_Admin::plugin_links( array( 'deactivate' => 'x' ) );
$eo_check( 'Plugins screen gets a Settings link first', false !== strpos( reset( $eo_links ), 'tab=edit_orders' ) );

WP_CLI::log( 'Cleanup' );
foreach ( $eo_t['orders'] as $eo_order_id ) {
	$eo_order = wc_get_order( $eo_order_id );
	if ( $eo_order ) {
		$eo_order->delete( true );
	}
}
wc_update_product_stock( wc_get_product_id_by_sku( 'EO-MUG' ), 50, 'set' );
if ( null === $eo_before_settings ) {
	delete_option( $eo_option );
} else {
	update_option( $eo_option, $eo_before_settings );
}
if ( null === $eo_before_delete ) {
	delete_option( 'edit_orders_for_woocommerce_delete_data' );
} else {
	update_option( 'edit_orders_for_woocommerce_delete_data', $eo_before_delete );
}

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'M3 suite failed.' );
}
WP_CLI::success( 'M3 suite passed.' );
