<?php
/**
 * Plugin Name: WPHEKA Edit Orders for WooCommerce
 * Plugin URI: https://github.com/wpheka/edit-orders-for-woocommerce
 * Description: Edit WooCommerce orders after payment and settle the difference correctly: refunds for decreases, a pay link for increases.
 * Version: 1.0.0
 * Author: WPHEKA
 * Author URI: https://www.wpheka.com/
 * Text Domain: wpheka-edit-orders-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.1.2
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION', '1.0.0' );
define( 'EDIT_ORDERS_FOR_WOOCOMMERCE_FILE', __FILE__ );
define( 'EDIT_ORDERS_FOR_WOOCOMMERCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'EDIT_ORDERS_FOR_WOOCOMMERCE_URL', plugin_dir_url( __FILE__ ) );

/*
 * Declare compatibility with HPOS and the Cart and Checkout blocks. The plugin
 * reads and writes orders only through WC_Order and item CRUD (spec section 8).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EDIT_ORDERS_FOR_WOOCOMMERCE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EDIT_ORDERS_FOR_WOOCOMMERCE_FILE, true );
		}
	}
);

/**
 * Create the audit log table on activation. Upgrades run on admin_init.
 *
 * @return void
 */
function edit_orders_for_woocommerce_activate() {
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/engine/class-edit-orders-for-woocommerce-audit-log.php';
	Edit_Orders_For_WooCommerce_Audit_Log::install();
}
register_activation_hook( __FILE__, 'edit_orders_for_woocommerce_activate' );

/**
 * Load the plugin once WooCommerce is available.
 *
 * @return void
 */
function edit_orders_for_woocommerce_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/class-edit-orders-for-woocommerce.php';

	Edit_Orders_For_WooCommerce::instance();
}
add_action( 'plugins_loaded', 'edit_orders_for_woocommerce_init' );
