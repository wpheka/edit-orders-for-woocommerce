<?php
/**
 * Functional suite for the 1.2.0 settings page (WPHEKA > Edit Orders): every field's
 * save rules, security on saving, the email switches, the menu, stats, getting
 * started, the Activity tab and the "More from WPHEKA" buttons.
 *
 * Run with: bash tests/functional/run.sh m10-settings-page
 *
 * Settings, emails, users and orders it changes are put back or deleted.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

$eo_t = array(
	'pass'   => 0,
	'fail'   => 0,
	'orders' => array(),
	'users'  => array(),
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

foreach ( array( 'admin/class-edit-orders-for-woocommerce-admin.php', 'admin/class-edit-orders-for-woocommerce-activity.php', 'wpheka-ui/load.php', 'admin/class-edit-orders-for-woocommerce-settings-form.php', 'admin/class-edit-orders-for-woocommerce-admin-page.php' ) as $eo_file ) {
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/' . $eo_file;
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$eo_option          = Edit_Orders_For_WooCommerce_Settings::OPTION;
$eo_before_settings = get_option( $eo_option, null );
$eo_before_delete   = get_option( Edit_Orders_For_WooCommerce_Settings_Form::DELETE_OPTION, null );
$eo_emails_before   = array();
foreach ( Edit_Orders_For_WooCommerce_Settings_Form::emails() as $eo_id => $eo_email ) {
	$eo_emails_before[ $eo_id ] = $eo_email->get_option( 'enabled', $eo_email->enabled );
}
wp_set_current_user( 1 );

// Cleanup runs even if a check throws part way (the try/finally below), so a failed run
// never leaves changed settings for the next suite to inherit. A shutdown function alone
// is not enough: WP-CLI's own fatal handler exits before later shutdown functions run.
$eo_cleaned = false;
$eo_cleanup = static function () use ( &$eo_t, &$eo_cleaned, $eo_emails_before, $eo_option, $eo_before_settings, $eo_before_delete ) {
	if ( $eo_cleaned ) {
		return;
	}
	$eo_cleaned = true;
	foreach ( $eo_t['orders'] as $eo_order_id ) {
		$eo_o = wc_get_order( $eo_order_id );
		if ( $eo_o ) {
			$eo_o->delete( true );
		}
	}
	foreach ( $eo_t['users'] as $eo_user ) {
		wp_delete_user( $eo_user );
	}
	foreach ( Edit_Orders_For_WooCommerce_Settings_Form::emails() as $eo_id => $eo_email ) {
		if ( isset( $eo_emails_before[ $eo_id ] ) ) {
			$eo_email->update_option( 'enabled', $eo_emails_before[ $eo_id ] );
		}
	}
	wc_update_product_stock( wc_get_product_id_by_sku( 'EO-MUG' ), 50, 'set' );
	if ( null === $eo_before_settings ) {
		delete_option( $eo_option );
	} else {
		update_option( $eo_option, $eo_before_settings );
	}
	if ( null === $eo_before_delete ) {
		delete_option( Edit_Orders_For_WooCommerce_Settings_Form::DELETE_OPTION );
	} else {
		update_option( Edit_Orders_For_WooCommerce_Settings_Form::DELETE_OPTION, $eo_before_delete );
	}
};
register_shutdown_function( $eo_cleanup );
try {

// Saves as the browser posts them: every field present, unticked switches as "no".
$eo_post = static function ( array $changes = array(), array $extra = array() ) use ( $eo_option ) {
	$base = array(
		'admin_enabled'          => 'yes',
		'editable_statuses'      => array( '', 'processing', 'on-hold' ),
		'customer_enabled'       => 'yes',
		'window_minutes'         => '60',
		'action_cancel'          => 'yes',
		'action_address'         => 'yes',
		'action_swap'            => 'yes',
		'action_note'            => 'yes',
		'note_max_length'        => '500',
		'cancel_mode'            => 'approval',
		'cancel_reasons'         => "Ordered by mistake\nFound a better price",
		'cancel_reason_required' => 'no',
		'cancel_policy'          => '',
		'debug_log'              => 'no',
	);
	Edit_Orders_For_WooCommerce_Settings_Form::save(
		array_merge(
			array(
				$eo_option => array_merge( $base, $changes ),
				Edit_Orders_For_WooCommerce_Settings_Form::DELETE_OPTION => 'no',
			),
			$extra
		)
	);

	return wp_parse_args( (array) get_option( $eo_option, array() ), Edit_Orders_For_WooCommerce_Settings::defaults() );
};

// ===========================================================================
WP_CLI::log( '1. Every on/off setting saves both ways' );
foreach ( array( 'admin_enabled', 'customer_enabled', 'action_cancel', 'action_address', 'action_swap', 'action_note', 'cancel_reason_required', 'debug_log' ) as $eo_key ) {
	$eo_off = $eo_post( array( $eo_key => 'no' ) );
	$eo_on  = $eo_post( array( $eo_key => 'yes' ) );
	$eo_check( "{$eo_key}: off then on", 'no' === $eo_off[ $eo_key ] && 'yes' === $eo_on[ $eo_key ], $eo_off[ $eo_key ] . '/' . $eo_on[ $eo_key ] );
}
$eo_s = $eo_post( array( 'debug_log' => 'banana' ) );
$eo_check( 'an unexpected value for a switch saves as off', 'no' === $eo_s['debug_log'] );
Edit_Orders_For_WooCommerce_Settings_Form::save( array( $eo_option => array() ) );
$eo_s = wp_parse_args( (array) get_option( $eo_option, array() ), Edit_Orders_For_WooCommerce_Settings::defaults() );
$eo_check( 'a switch missing from the post saves as off (a cleared box is never lost)', 'no' === $eo_s['admin_enabled'] && 'no' === $eo_s['customer_enabled'] );

// ===========================================================================
WP_CLI::log( '2. Numbers are clamped and whole' );
$eo_cases = array(
	array( 'window_minutes', '0', 5 ),
	array( 'window_minutes', '4', 5 ),
	array( 'window_minutes', '5', 5 ),
	array( 'window_minutes', '10080', 10080 ),
	array( 'window_minutes', '99999', 10080 ),
	array( 'window_minutes', '-30', 30 ),
	array( 'window_minutes', '90.7', 90 ),
	array( 'window_minutes', 'abc', 5 ),
	array( 'note_max_length', '1', 20 ),
	array( 'note_max_length', '2000', 2000 ),
	array( 'note_max_length', '5000', 2000 ),
);
foreach ( $eo_cases as $eo_case ) {
	$eo_s = $eo_post( array( $eo_case[0] => $eo_case[1] ) );
	$eo_check( "{$eo_case[0]} '{$eo_case[1]}' saves as {$eo_case[2]}", (int) $eo_case[2] === (int) $eo_s[ $eo_case[0] ], (string) $eo_s[ $eo_case[0] ] );
}
Edit_Orders_For_WooCommerce_Settings_Form::save( array( $eo_option => array( 'admin_enabled' => 'yes' ) ) );
$eo_s = wp_parse_args( (array) get_option( $eo_option, array() ), Edit_Orders_For_WooCommerce_Settings::defaults() );
$eo_check( 'a number missing from the post keeps its current value', 60 === (int) $eo_s['window_minutes'] || 5 <= (int) $eo_s['window_minutes'] );

// ===========================================================================
WP_CLI::log( '3. Choices, statuses and text' );
$eo_s = $eo_post( array( 'cancel_mode' => 'instant' ) );
$eo_check( 'cancel mode instant', 'instant' === $eo_s['cancel_mode'] );
$eo_s = $eo_post( array( 'cancel_mode' => 'refund-everything' ) );
$eo_check( 'an unknown cancel mode falls back to approval (the safe default)', 'approval' === $eo_s['cancel_mode'] );
$eo_s = $eo_post( array( 'editable_statuses' => array( '', 'on-hold' ) ) );
$eo_check( 'statuses: only On hold', array( 'on-hold' ) === $eo_s['editable_statuses'] );
$eo_s = $eo_post( array( 'editable_statuses' => array( '', 'completed', 'refunded', 'pending', 'processing', '<script>' ) ) );
$eo_check( 'statuses: final, unpaid and junk values are dropped', array( 'processing' ) === $eo_s['editable_statuses'], wp_json_encode( $eo_s['editable_statuses'] ) );
$eo_s = $eo_post( array( 'editable_statuses' => array( '' ) ) );
$eo_check( 'statuses: an empty list falls back to Processing and On hold', array( 'processing', 'on-hold' ) === $eo_s['editable_statuses'] );
register_post_status(
	'wc-eo-packing',
	array(
		'label'  => 'Packing',
		'public' => true,
	)
);
$eo_add_status = static function ( $statuses ) {
	$statuses['wc-eo-packing'] = 'Packing';

	return $statuses;
};
add_filter( 'wc_order_statuses', $eo_add_status );
$eo_s = $eo_post( array( 'editable_statuses' => array( '', 'eo-packing' ) ) );
$eo_check( 'a custom paid status from another plugin can be chosen', array( 'eo-packing' ) === $eo_s['editable_statuses'] && isset( Edit_Orders_For_WooCommerce_Settings_Form::status_options()['eo-packing'] ) );
remove_filter( 'wc_order_statuses', $eo_add_status );
$eo_s = $eo_post( array( 'cancel_reasons' => "  Too slow  \n\nWrong size\n<b>Bold</b>\n" ) );
$eo_check( 'reasons: one per line, blanks and tags removed', array( 'Too slow', 'Wrong size', 'Bold' ) === Edit_Orders_For_WooCommerce_Settings::cancel_reasons(), wp_json_encode( Edit_Orders_For_WooCommerce_Settings::cancel_reasons() ) );
$eo_s = $eo_post( array( 'cancel_policy' => '<p>Full refund within <strong>1 hour</strong>.</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>' ) );
$eo_check( 'policy: simple HTML kept, scripts and javascript links removed', false !== strpos( $eo_s['cancel_policy'], '<strong>1 hour</strong>' ) && false === strpos( $eo_s['cancel_policy'], '<script' ) && false === stripos( $eo_s['cancel_policy'], 'javascript:' ), $eo_s['cancel_policy'] );
Edit_Orders_For_WooCommerce_Settings_Form::save(
	array(
		$eo_option => array( 'admin_enabled' => 'yes' ),
		Edit_Orders_For_WooCommerce_Settings_Form::DELETE_OPTION => 'yes',
	)
);
$eo_check( 'delete data on uninstall: on', 'yes' === get_option( Edit_Orders_For_WooCommerce_Settings_Form::DELETE_OPTION ) );
$eo_post();
$eo_check( 'delete data on uninstall: off', 'no' === get_option( Edit_Orders_For_WooCommerce_Settings_Form::DELETE_OPTION ) );

// ===========================================================================
WP_CLI::log( '4. Customer rows keep their values while customer changes are off' );
$eo_s = $eo_post(
	array(
		'customer_enabled' => 'no',
		'window_minutes'   => '120',
		'action_note'      => 'no',
	)
);
$eo_check( 'with customer changes off, the time and actions still save', 'no' === $eo_s['customer_enabled'] && 120 === (int) $eo_s['window_minutes'] && 'no' === $eo_s['action_note'] );
$eo_s = $eo_post( array( 'window_minutes' => '120', 'action_note' => 'no' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short test array.
$eo_check( 'turning customer changes back on keeps them', 'yes' === $eo_s['customer_enabled'] && 120 === (int) $eo_s['window_minutes'] && 'no' === $eo_s['action_note'] );

// ===========================================================================
WP_CLI::log( '5. Rendering: every value appears, escaped' );
$eo_post(
	array(
		'cancel_reasons' => "A </textarea><script>alert(1)</script>\nB \"quoted\"",
		'cancel_policy'  => 'Policy "with" quotes',
		'window_minutes' => '1440',
		'cancel_mode'    => 'instant',
	)
);
ob_start();
Edit_Orders_For_WooCommerce_Settings_Form::render();
$eo_html = ob_get_clean();
$eo_check( 'a textarea value cannot close the textarea or run a script', false === strpos( $eo_html, '<script>alert(1)</script>' ) && false !== strpos( $eo_html, '&lt;/textarea&gt;' ) || false === strpos( $eo_html, '</textarea><script>' ) );
$eo_check( 'saved values are shown: 1440 minutes and instant', false !== strpos( $eo_html, 'value="1440"' ) && preg_match( '/value="instant" checked/', $eo_html ) );
$eo_check( 'every section has its anchor for the side navigation', 5 === preg_match_all( '/<section class="w-section" id="(editing|customers|cancellations|emails|advanced)"/', $eo_html ) );
$eo_check( 'every switch has a label tied to it', preg_match_all( '/role="switch" id="(w-f\d+)"/', $eo_html, $eo_ids ) && count( $eo_ids[1] ) === count( array_filter( $eo_ids[1], static function ( $id ) use ( $eo_html ) { return false !== strpos( $eo_html, 'for="' . $id . '"' ); } ) ) ); // phpcs:ignore Generic.Functions.FunctionCallArgumentSpacing,PEAR.Functions.FunctionCallSignature,Squiz.Functions.MultiLineFunctionDeclaration -- compact test helper.
$eo_check( 'the search box has its empty-result card', false !== strpos( $eo_html, 'data-w-find-empty' ) );

// ===========================================================================
WP_CLI::log( '6. Every email switch' );
foreach ( Edit_Orders_For_WooCommerce_Settings_Form::emails() as $eo_id => $eo_email ) {
	$eo_class = get_class( $eo_email );
	$eo_post( array(), array( 'emails' => array( $eo_id => 'no' ) ) );
	$eo_fresh = new $eo_class();
	$eo_off   = 'no' === $eo_fresh->get_option( 'enabled' );
	$eo_post( array(), array( 'emails' => array( $eo_id => 'yes' ) ) );
	$eo_fresh = new $eo_class();
	$eo_check( "{$eo_id}: off then on in WooCommerce's own setting", $eo_off && 'yes' === $eo_fresh->get_option( 'enabled' ) );
}
$eo_post( array(), array( 'emails' => array( 'woocommerce_new_order' => 'no' ) ) );
$eo_core = WC()->mailer()->get_emails()['WC_Email_New_Order'];
$eo_check( 'a posted id that is not one of ours changes nothing', 'no' !== $eo_core->get_option( 'enabled', 'yes' ) );

// ===========================================================================
WP_CLI::log( '7. Saving: nonce and permission' );
$eo_die = static function () {
	return static function ( $message ) {
		throw new RuntimeException( is_string( $message ) ? $message : 'died' );
	};
};
add_filter( 'wp_die_handler', $eo_die );
add_filter( 'wp_die_ajax_handler', $eo_die );
// The handlers redirect and exit. WP-CLI ends the command on a redirect, so the test
// turns every redirect into an exception it catches, and records where it went.
$eo_redirect_filters = $GLOBALS['wp_filter']['wp_redirect'] ?? null;
remove_all_filters( 'wp_redirect' );
$eo_went  = '';
$eo_throw = static function ( $location ) use ( &$eo_went ) {
	$eo_went = $location;
	throw new LogicException( 'redirect' );
};
add_filter( 'wp_redirect', $eo_throw, 0 );
$eo_before = get_option( $eo_option );
$_POST     = array(
	'action'   => Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION,
	'_wpnonce' => 'not-a-nonce',
	$eo_option => array( 'window_minutes' => '777' ),
);
$_REQUEST  = $_POST;
$eo_threw  = false;
try {
	Edit_Orders_For_WooCommerce_Admin_Page::handle_save();
} catch ( RuntimeException $e ) {
	$eo_threw = true; // Refused through wp_die().
} catch ( LogicException $e ) {
	$eo_threw = false; // It saved and redirected: not refused.
}
$eo_check( 'a save without a valid nonce is refused and changes nothing', $eo_threw && get_option( $eo_option ) === $eo_before );

$eo_clerk = wp_insert_user(
	array(
		'user_login' => 'eo_m10_clerk_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'eo-m10-' . wp_generate_password( 6, false ) . '@example.invalid',
		'role'       => 'editor',
	)
);
$eo_t['users'][] = $eo_clerk;
get_userdata( $eo_clerk )->add_cap( 'edit_shop_orders' );
wp_set_current_user( $eo_clerk );
$_POST['_wpnonce']   = wp_create_nonce( Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION );
$_REQUEST            = $_POST;
$eo_threw            = false;
try {
	Edit_Orders_For_WooCommerce_Admin_Page::handle_save();
} catch ( RuntimeException $e ) {
	$eo_threw = true; // Refused through wp_die().
} catch ( LogicException $e ) {
	$eo_threw = false; // It saved and redirected: not refused.
}
$eo_check( 'a user who can edit orders but not manage WooCommerce cannot save settings', $eo_threw && get_option( $eo_option ) === $eo_before );
$_GET['tab'] = 'settings';
$eo_check( 'and is sent to Activity when asking for Settings', 'activity' === Edit_Orders_For_WooCommerce_Admin_Page::current_tab() );
ob_start();
Edit_Orders_For_WooCommerce_Admin_Page::render();
$eo_page = ob_get_clean();
$eo_check( 'their page has no Settings tab and no settings form', false === strpos( $eo_page, 'tab=settings' ) && false === strpos( $eo_page, 'data-w-form' ) && false !== strpos( $eo_page, 'Cancellation requests' ) );
unset( $_GET['tab'] );

$eo_customer = wp_insert_user(
	array(
		'user_login' => 'eo_m10_cust_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'eo-m10c-' . wp_generate_password( 6, false ) . '@example.invalid',
		'role'       => 'customer',
	)
);
$eo_t['users'][] = $eo_customer;
wp_set_current_user( $eo_customer );
ob_start();
Edit_Orders_For_WooCommerce_Admin_Page::render();
$eo_page = ob_get_clean();
$eo_check( 'a customer sees nothing at all', '' === trim( $eo_page ) );
wp_set_current_user( 1 );
$_POST     = array(
	'action'   => Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION,
	'_wpnonce' => wp_create_nonce( Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION ),
	$eo_option => array(
		'window_minutes'  => '45',
		'admin_enabled'   => 'yes',
		'cancel_reasons'  => 'Slashed \\\' quote',
		'note_max_length' => '500',
	),
);
$_REQUEST  = $_POST;
$eo_threw  = false;
try {
	Edit_Orders_For_WooCommerce_Admin_Page::handle_save();
} catch ( LogicException $e ) {
	$eo_threw = true;
}
$eo_s = wp_parse_args( (array) get_option( $eo_option, array() ), Edit_Orders_For_WooCommerce_Settings::defaults() );
$eo_check( 'an admin with a valid nonce saves, with slashes removed', 45 === (int) $eo_s['window_minutes'] && false === strpos( $eo_s['cancel_reasons'], '\\' ), $eo_s['cancel_reasons'] );
$eo_check( 'and is sent back to Settings with the saved flag', $eo_threw && false !== strpos( $eo_went, 'tab=settings' ) && false !== strpos( $eo_went, 'w-saved=1' ), $eo_went );
$_POST    = array();
$_REQUEST = array();
remove_filter( 'wp_die_handler', $eo_die );
remove_filter( 'wp_die_ajax_handler', $eo_die );

// ===========================================================================
WP_CLI::log( '8. Menu, tabs and links' );
global $submenu, $menu, $admin_page_hooks;
Edit_Orders_For_WooCommerce_Admin_Page::register_page();
$eo_parent_count = count( array_filter( (array) $menu, static function ( $m ) { return isset( $m[2] ) && 'wpheka_plugin_panel' === $m[2]; } ) ); // phpcs:ignore Generic.Functions.FunctionCallArgumentSpacing,PEAR.Functions.FunctionCallSignature,Squiz.Functions.MultiLineFunctionDeclaration -- compact test helper.
$eo_check( 'one WPHEKA parent menu', 1 === $eo_parent_count );
Edit_Orders_For_WooCommerce_Admin_Page::register_page();
$eo_parent_count = count( array_filter( (array) $menu, static function ( $m ) { return isset( $m[2] ) && 'wpheka_plugin_panel' === $m[2]; } ) ); // phpcs:ignore Generic.Functions.FunctionCallArgumentSpacing,PEAR.Functions.FunctionCallSignature,Squiz.Functions.MultiLineFunctionDeclaration -- compact test helper.
$eo_check( 'registering again (as a second WPHEKA plugin would) adds no second parent', 1 === $eo_parent_count );
$eo_icon = '';
foreach ( (array) $menu as $eo_m ) {
	if ( isset( $eo_m[2] ) && 'wpheka_plugin_panel' === $eo_m[2] ) {
		$eo_icon = $eo_m[6];
	}
}
$eo_svg = base64_decode( (string) substr( $eo_icon, strlen( 'data:image/svg+xml;base64,' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading the menu icon.
$eo_check( 'the menu icon is an SVG data URI WordPress can repaint (filled, 20px grid)', 0 === strpos( $eo_icon, 'data:image/svg+xml;base64,' ) && false !== strpos( $eo_svg, 'viewBox="0 0 20 20"' ) && false !== strpos( $eo_svg, 'fill="#a7aaad"' ) && false === strpos( $eo_svg, 'stroke' ) );
$eo_check( 'a parent created by another WPHEKA plugin keeps its slug and capability filter', 'manage_options' === apply_filters( 'wpheka_plugin_panel_menu_page_capability', 'manage_options' ) );
foreach ( array( 'settings', 'activity', 'help' ) as $eo_tab ) {
	$eo_check( "the {$eo_tab} tab URL", false !== strpos( Edit_Orders_For_WooCommerce_Admin_Page::url( $eo_tab ), 'page=edit-orders-for-woocommerce&tab=' . $eo_tab ) );
}
$_GET['tab'] = 'nonsense';
$eo_check( 'an unknown tab opens Settings', 'settings' === Edit_Orders_For_WooCommerce_Admin_Page::current_tab() );
unset( $_GET['tab'] );
foreach ( array( 'settings', 'activity', 'help' ) as $eo_tab ) {
	$_GET['tab'] = $eo_tab;
	ob_start();
	Edit_Orders_For_WooCommerce_Admin_Page::render();
	$eo_page = ob_get_clean();
	$eo_check( "the {$eo_tab} tab renders with its tab marked current", false !== strpos( $eo_page, 'aria-current="page">' . ucfirst( $eo_tab ) ) && false !== strpos( $eo_page, 'class="wpheka-ui"' ) );
}
unset( $_GET['tab'] );
$eo_check( 'the Help links point to this plugin on wordpress.org', false !== strpos( $eo_page, 'wordpress.org/support/plugin/wpheka-edit-orders-for-woocommerce/' ) && false !== strpos( $eo_page, 'wordpress.org/plugins/wpheka-edit-orders-for-woocommerce/' ) );
$_GET['tab'] = 'settings';
$_GET['w-saved'] = '1';
ob_start();
Edit_Orders_For_WooCommerce_Admin_Page::render();
$eo_page = ob_get_clean();
$eo_check( 'after a save the page shows "Settings saved"', false !== strpos( $eo_page, 'w-toast is-shown' ) && false !== strpos( $eo_page, 'Settings saved' ) );
unset( $_GET['tab'], $_GET['w-saved'] );

// ===========================================================================
WP_CLI::log( '9. More from WPHEKA' );
$eo_items = Edit_Orders_For_WooCommerce\AdminUI\Catalogue::items();
$eo_check( 'seven plugins in the list, each with a slug, file, name and text', 7 === count( $eo_items ) && 7 === count( array_filter( $eo_items, static function ( $i ) { return $i['slug'] && $i['file'] && $i['name'] && $i['text']; } ) ) ); // phpcs:ignore Generic.Functions.FunctionCallArgumentSpacing,PEAR.Functions.FunctionCallSignature,Squiz.Functions.MultiLineFunctionDeclaration -- compact test helper.
$eo_self = Edit_Orders_For_WooCommerce\AdminUI\Catalogue::installed( 'wpheka-edit-orders-for-woocommerce.php' );
$eo_check( 'this plugin is found as installed whatever its folder name', '' !== $eo_self && is_plugin_active( $eo_self ) );
$eo_free = array_values( array_filter( $eo_items, static function ( $i ) { return 'wc-backorder-split' === $i['slug']; } ) )[0]; // phpcs:ignore Generic.Functions.FunctionCallArgumentSpacing,PEAR.Functions.FunctionCallSignature,Squiz.Functions.MultiLineFunctionDeclaration -- compact test helper.
$eo_action = Edit_Orders_For_WooCommerce\AdminUI\Catalogue::action( $eo_free, 'wpheka-edit-orders-for-woocommerce' );
$eo_href   = html_entity_decode( $eo_action );
$eo_check( 'a plugin not installed gets Install now, through WordPress with a nonce', false !== strpos( $eo_href, 'update.php?action=install-plugin&plugin=wc-backorder-split&_wpnonce=' ) && false !== strpos( $eo_action, 'Install now' ), $eo_action );
wp_set_current_user( $eo_clerk );
$eo_action = Edit_Orders_For_WooCommerce\AdminUI\Catalogue::action( $eo_free, 'wpheka-edit-orders-for-woocommerce' );
$eo_check( 'a user who cannot install plugins gets a wordpress.org link instead', false === strpos( $eo_action, 'install-plugin' ) && false !== strpos( $eo_action, 'wordpress.org/plugins/wc-backorder-split/' ) );
wp_set_current_user( 1 );
$eo_pro    = array_values( array_filter( $eo_items, static function ( $i ) { return ! empty( $i['url'] ); } ) )[0]; // phpcs:ignore Generic.Functions.FunctionCallArgumentSpacing,PEAR.Functions.FunctionCallSignature,Squiz.Functions.MultiLineFunctionDeclaration -- compact test helper.
$eo_action = Edit_Orders_For_WooCommerce\AdminUI\Catalogue::action( $eo_pro, 'wpheka-edit-orders-for-woocommerce' );
$eo_check( 'the paid plugin links to wpheka.com with UTM tags, in a new tab', false !== strpos( $eo_action, 'wpheka.com/product/wc-moneris-payment-gateway-pro/' ) && false !== strpos( $eo_action, 'utm_source=wpheka-edit-orders-for-woocommerce' ) && false !== strpos( $eo_action, 'target="_blank"' ) );
$eo_check( 'the page lists the other six plugins, not this one', 6 === substr_count( $eo_page, 'class="w-plugin"' ) && false === strpos( $eo_page, 'icons/wpheka-edit-orders-for-woocommerce.png" alt="" width="48"' ) );

// ===========================================================================
WP_CLI::log( '10. Stats, getting started and Activity' );
global $wpdb;
$eo_table = Edit_Orders_For_WooCommerce_Audit_Log::table();
$eo_base  = Edit_Orders_For_WooCommerce_Settings_Form::stats();
Edit_Orders_For_WooCommerce_Audit_Log::add( array( 'order_id' => 999811, 'actor_type' => 'guest', 'action' => 'cancelled', 'refund_id' => 55, 'amount' => 30 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test row.
Edit_Orders_For_WooCommerce_Audit_Log::add( array( 'order_id' => 999812, 'actor_type' => 'staff', 'action' => 'edit' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test row.
$wpdb->insert( $eo_table, array( 'order_id' => 999813, 'actor_type' => 'staff', 'action' => 'edit', 'amount' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$eo_now = Edit_Orders_For_WooCommerce_Settings_Form::stats();
$eo_check( 'a guest cancellation with a refund counts as a refund and a customer change', 1 === $eo_now['refunds'] - $eo_base['refunds'] && abs( 30 - ( $eo_now['refunded'] - $eo_base['refunded'] ) ) < 0.001 && 1 === $eo_now['customer'] - $eo_base['customer'] );
$eo_check( 'an edit older than 30 days is not counted', 1 === $eo_now['edited'] - $eo_base['edited'] );

delete_user_meta( 1, Edit_Orders_For_WooCommerce_Admin_Page::DISMISS_META );
$eo_steps = Edit_Orders_For_WooCommerce_Settings_Form::steps();
$eo_check( 'getting started: four steps, "first order edited" done once an edit is logged', 4 === count( $eo_steps ) && true === $eo_steps[3]['done'] );
$_REQUEST['_wpnonce'] = wp_create_nonce( Edit_Orders_For_WooCommerce_Admin_Page::DISMISS_ACTION );
try {
	Edit_Orders_For_WooCommerce_Admin_Page::handle_dismiss();
} catch ( LogicException $e ) {
	unset( $e ); // The redirect back to the page.
}
unset( $_REQUEST['_wpnonce'] );
remove_filter( 'wp_redirect', $eo_throw, 0 );
if ( $eo_redirect_filters ) {
	$GLOBALS['wp_filter']['wp_redirect'] = $eo_redirect_filters; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- putting WP-CLI's own redirect filter back.
}
$eo_check( 'hiding getting started is remembered for this user', (bool) get_user_meta( 1, Edit_Orders_For_WooCommerce_Admin_Page::DISMISS_META, true ) );
delete_user_meta( 1, Edit_Orders_For_WooCommerce_Admin_Page::DISMISS_META );

for ( $eo_i = 0; $eo_i < 55; $eo_i++ ) {
	Edit_Orders_For_WooCommerce_Audit_Log::add( array( 'order_id' => 999820, 'actor_type' => 'staff', 'action' => 'note_changed', 'after' => array( 'note' => 'n' . $eo_i ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test row.
}
$_GET['tab']        = 'activity';
$_GET['log_action'] = 'note_changed';
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_act = ob_get_clean();
$eo_check( 'Activity filter by change shows only that change, 50 per page with pages', 50 === substr_count( $eo_act, '>Order note changed</span>' ) && false !== strpos( $eo_act, 'w-pages' ) );
$_GET['paged'] = '2';
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_act = ob_get_clean();
$eo_check( 'page 2 holds the rest', substr_count( $eo_act, '>Order note changed</span>' ) >= 5 );
unset( $_GET['tab'], $_GET['log_action'], $_GET['paged'] );
$_GET['from'] = gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS );
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_act = ob_get_clean();
$eo_check( 'a date range with nothing in it shows the empty state', false !== strpos( $eo_act, 'No changes yet.' ) );
unset( $_GET['from'] );
$wpdb->query( "DELETE FROM {$eo_table} WHERE order_id IN ( 999811, 999812, 999813, 999820 )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test cleanup, fixed ids.

$eo_order = wc_create_order();
$eo_order->add_product( wc_get_product( wc_get_product_id_by_sku( 'EO-MUG' ) ), 1 );
$eo_order->set_billing_email( 'customer@example.com' );
$eo_order->set_billing_first_name( 'Ada' );
$eo_order->calculate_totals();
$eo_order->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
$eo_order->save();
$eo_order->payment_complete( 'TEST-M10' );
$eo_t['orders'][] = $eo_order->get_id();
$eo_post( array( 'cancel_mode' => 'approval' ) );
Edit_Orders_For_WooCommerce_Cancellation::request( wc_get_order( $eo_order->get_id() ), 'Changed <b>my</b> mind', 'customer' );
Edit_Orders_For_WooCommerce_Cancellation::forget_pending_count();
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_act = ob_get_clean();
$eo_check( 'a waiting request is listed with Approve and refund, a message box and Decline', false !== strpos( $eo_act, 'data-order="' . $eo_order->get_id() . '"' ) && false !== strpos( $eo_act, 'value="approve"' ) && false !== strpos( $eo_act, 'name="message"' ) && false !== strpos( $eo_act, 'value="decline"' ) );
$eo_check( 'its reason is escaped', false === strpos( $eo_act, '<b>my</b>' ) );
$_GET['tab'] = 'activity';
ob_start();
Edit_Orders_For_WooCommerce_Admin_Page::render();
$eo_page = ob_get_clean();
unset( $_GET['tab'] );
$eo_check( 'the Activity tab shows the waiting count', (bool) preg_match( '/Activity<span class="w-count w-count--alert">\d+<\/span>/', $eo_page ) );
$_GET['eofw_notice'] = 'decline';
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_act = ob_get_clean();
unset( $_GET['eofw_notice'] );
$eo_check( 'after a decision the result notice shows', false !== strpos( $eo_act, 'Cancellation declined. The customer has been emailed.' ) );
Edit_Orders_For_WooCommerce_Activity::decide( wc_get_order( $eo_order->get_id() ), 'decline', 'Already shipped' );
Edit_Orders_For_WooCommerce_Cancellation::forget_pending_count();

} finally {
	WP_CLI::log( 'Cleanup' );
	$eo_cleanup();
}

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'M10 suite failed.' );
}
WP_CLI::success( 'M10 suite passed.' );
