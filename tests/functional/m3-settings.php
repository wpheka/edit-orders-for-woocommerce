<?php
/**
 * Functional suite for M3: the settings screen (spec section 6), on the plugin's own
 * page since 1.2.0 (WPHEKA > Edit Orders > Settings).
 *
 * Saves go through Edit_Orders_For_WooCommerce_Settings_Form::save() with a posted form,
 * the same path as pressing "Save changes" on the page.
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
foreach ( array( 'wpheka-ui/load.php', 'admin/class-edit-orders-for-woocommerce-settings-form.php', 'admin/class-edit-orders-for-woocommerce-admin-page.php' ) as $eo_file ) {
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/' . $eo_file;
}

$eo_before_settings = get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null );
$eo_before_delete   = get_option( 'edit_orders_for_woocommerce_delete_data', null );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
wp_set_current_user( 1 );

$eo_pages = Edit_Orders_For_WooCommerce_Admin::settings_page( array() );
$eo_page  = end( $eo_pages );
$eo_check( 'the old WooCommerce tab is still registered', $eo_page instanceof Edit_Orders_For_WooCommerce_Settings_Page && 'edit_orders' === $eo_page->get_id() );
$GLOBALS['current_section'] = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- what WooCommerce's settings screen does.
ob_start();
$eo_page->output();
$eo_html = ob_get_clean();
$eo_check( 'the old tab says the settings moved and links to the new page', false !== strpos( $eo_html, 'have moved' ) && false !== strpos( $eo_html, 'page=edit-orders-for-woocommerce' ) && false !== strpos( $eo_html, 'tab=settings' ) && array() === $eo_page->get_settings() );

ob_start();
Edit_Orders_For_WooCommerce_Settings_Form::render();
$eo_html    = ob_get_clean();
$eo_want    = array( 'admin_enabled', 'editable_statuses', 'customer_enabled', 'window_minutes', 'action_cancel', 'action_address', 'action_swap', 'action_note', 'cancel_mode', 'cancel_reasons', 'cancel_reason_required', 'cancel_policy', 'note_max_length', 'debug_log' );
$eo_missing = array();
foreach ( $eo_want as $eo_key ) {
	if ( false === strpos( $eo_html, 'name="' . Edit_Orders_For_WooCommerce_Settings::OPTION . '[' . $eo_key . ']' ) ) {
		$eo_missing[] = $eo_key;
	}
}
$eo_check( 'every setting has a field on the new page, saved into the one settings option', array() === $eo_missing, implode( ', ', $eo_missing ) );
$eo_check( 'every setting key is one the form saves', array() === array_diff( $eo_want, Edit_Orders_For_WooCommerce_Settings_Form::keys() ) );
$eo_check( 'delete-data uses the option uninstall.php reads', false !== strpos( $eo_html, 'name="edit_orders_for_woocommerce_delete_data"' ) && false !== strpos( (string) file_get_contents( EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'uninstall.php' ), "'edit_orders_for_woocommerce_delete_data'" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$eo_statuses = array_keys( Edit_Orders_For_WooCommerce_Settings_Form::status_options() );
$eo_check( 'status choices leave out final and unpaid statuses', in_array( 'processing', $eo_statuses, true ) && in_array( 'on-hold', $eo_statuses, true ) && ! array_intersect( array( 'completed', 'cancelled', 'refunded', 'failed', 'pending' ), $eo_statuses ) );
$eo_check( 'with defaults: approval and 60 minutes', false !== strpos( $eo_html, 'value="60"' ) && preg_match( '/value="approval" checked/', $eo_html ) );
$eo_check( 'every Edit Orders email has an on/off switch and an Edit wording link', 7 === count( Edit_Orders_For_WooCommerce_Settings_Form::emails() ) && 14 === substr_count( $eo_html, 'name="emails[edit_orders_for_woocommerce_' ) && 7 === substr_count( $eo_html, 'section=edit_orders_for_woocommerce_email_' ), (string) count( Edit_Orders_For_WooCommerce_Settings_Form::emails() ) );
$eo_check( 'customer rows sit under the customer switch', false !== strpos( $eo_html, 'data-w-controls="edit-orders-customer-rows"' ) && false !== strpos( $eo_html, 'id="edit-orders-customer-rows"' ) );

WP_CLI::log( 'Saving' );
$eo_save = static function ( array $post ) {
	// The browser always posts textareas.
	$key          = Edit_Orders_For_WooCommerce_Settings::OPTION;
	$post[ $key ] = array_merge(
		array(
			'cancel_reasons' => '',
			'cancel_policy'  => '',
		),
		$post[ $key ]
	);
	Edit_Orders_For_WooCommerce_Settings_Form::save( $post );
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
$eo_check( 'Plugins screen gets a Settings link first, to the new page', false !== strpos( reset( $eo_links ), 'page=edit-orders-for-woocommerce' ) && false !== strpos( reset( $eo_links ), 'tab=settings' ) );

WP_CLI::log( 'Emails, permissions and stats' );
$eo_emails = Edit_Orders_For_WooCommerce_Settings_Form::emails();
$eo_email  = $eo_emails['edit_orders_for_woocommerce_order_updated'];
$eo_was    = $eo_email->get_option( 'enabled', $eo_email->enabled );
$eo_save(
	array(
		$eo_option => array( 'window_minutes' => '60' ),
		'emails'   => array( 'edit_orders_for_woocommerce_order_updated' => 'no' ),
	)
);
$eo_fresh = new Edit_Orders_For_WooCommerce_Email_Order_Updated();
$eo_check( 'an email switch turned off saves WooCommerce\'s own email setting', 'no' === $eo_fresh->get_option( 'enabled' ) && ! $eo_fresh->is_enabled() );
$eo_save(
	array(
		$eo_option => array( 'window_minutes' => '60' ),
		'emails'   => array( 'edit_orders_for_woocommerce_order_updated' => 'yes' ),
	)
);
$eo_fresh = new Edit_Orders_For_WooCommerce_Email_Order_Updated();
$eo_check( 'and turned on again', 'yes' === $eo_fresh->get_option( 'enabled' ) );
$eo_fresh->update_option( 'enabled', $eo_was );

$eo_manager = wp_insert_user(
	array(
		'user_login' => 'eo_m3_clerk_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'eo-m3-clerk-' . wp_generate_password( 6, false ) . '@example.invalid',
		'role'       => 'shop_manager',
	)
);
$eo_role   = get_role( 'shop_manager' );
$eo_had    = $eo_role && $eo_role->has_cap( 'manage_woocommerce' );
if ( $eo_role ) {
	$eo_role->remove_cap( 'manage_woocommerce' );
}
wp_set_current_user( $eo_manager );
try {
	$_GET['tab'] = 'settings';
	$eo_check( 'a user without manage_woocommerce is sent to Activity, not Settings', 'activity' === Edit_Orders_For_WooCommerce_Admin_Page::current_tab() );
} finally {
	// Put the role back even if the check throws, so later suites see the shop manager as before.
	unset( $_GET['tab'] );
	if ( $eo_role && $eo_had ) {
		$eo_role->add_cap( 'manage_woocommerce' );
	}
	wp_set_current_user( 1 );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $eo_manager );
global $submenu;
Edit_Orders_For_WooCommerce_Admin_Page::register_page();
$eo_under = array_column( isset( $submenu['wpheka_plugin_panel'] ) ? (array) $submenu['wpheka_plugin_panel'] : array(), 2 );
$eo_check( 'the page is registered under the shared WPHEKA menu', in_array( Edit_Orders_For_WooCommerce_Admin_Page::PAGE, $eo_under, true ), wp_json_encode( $eo_under ) );
$_GET['tab'] = 'help';
$eo_check( 'an admin can open any tab', 'help' === Edit_Orders_For_WooCommerce_Admin_Page::current_tab() );
unset( $_GET['tab'] );

$eo_stats_before = Edit_Orders_For_WooCommerce_Settings_Form::stats();
$eo_rows         = array(
	array( 'order_id' => 999901, 'actor_type' => 'customer', 'action' => 'edit', 'refund_id' => 777, 'amount' => 12.5 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test rows.
	array( 'order_id' => 999902, 'actor_type' => 'staff', 'action' => 'balance_created', 'balance_order_id' => 999903, 'amount' => 20 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test rows.
	array( 'order_id' => 999902, 'actor_type' => 'customer', 'action' => 'balance_paid', 'balance_order_id' => 999903, 'amount' => 20 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test rows.
);
foreach ( $eo_rows as $eo_row ) {
	Edit_Orders_For_WooCommerce_Audit_Log::add( $eo_row );
}
$eo_stats = Edit_Orders_For_WooCommerce_Settings_Form::stats();
$eo_check(
	'stats count the activity log: 2 orders edited, 1 refund of 12.50, 1 pay link paid for 20, 1 customer change',
	2 === $eo_stats['edited'] - $eo_stats_before['edited'] && 1 === $eo_stats['refunds'] - $eo_stats_before['refunds'] && abs( 12.5 - ( $eo_stats['refunded'] - $eo_stats_before['refunded'] ) ) < 0.001 && 1 === $eo_stats['links'] - $eo_stats_before['links'] && 1 === $eo_stats['links_paid'] - $eo_stats_before['links_paid'] && abs( 20 - ( $eo_stats['collected'] - $eo_stats_before['collected'] ) ) < 0.001 && 1 === $eo_stats['customer'] - $eo_stats_before['customer'],
	wp_json_encode( array( $eo_stats_before, $eo_stats ) )
);
global $wpdb;
$wpdb->query( 'DELETE FROM ' . Edit_Orders_For_WooCommerce_Audit_Log::table() . ' WHERE order_id IN ( 999901, 999902 )' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup, fixed ids.

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
