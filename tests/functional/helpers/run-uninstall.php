<?php
/**
 * Runs the plugin's uninstall.php in its own process, as WordPress does.
 * Called by m1-admin.php through WP_CLI::runcommand( ..., array( 'launch' => true ) ).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_UNINSTALL_PLUGIN', 'edit-orders-for-woocommerce/edit-orders-for-woocommerce.php' );
require WP_PLUGIN_DIR . '/edit-orders-for-woocommerce/uninstall.php';
