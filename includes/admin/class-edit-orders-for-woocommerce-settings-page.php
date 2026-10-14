<?php
/**
 * WooCommerce > Settings > Edit Orders: a pointer to the plugin's own page.
 *
 * The settings moved to WPHEKA > Edit Orders in 1.2.0. The tab stays so
 * bookmarks and old documentation still lead somewhere useful.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Settings_Page Class.
 */
class Edit_Orders_For_WooCommerce_Settings_Page extends WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'edit_orders';
		$this->label = __( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' );

		parent::__construct();
	}

	/**
	 * No fields here any more.
	 *
	 * @return array
	 */
	protected function get_settings_for_default_section() {
		return array();
	}

	/**
	 * Say where the settings went, with a link.
	 */
	public function output() {
		echo '<div class="notice notice-info inline edit-orders-moved"><p><strong>' . esc_html__( 'Edit Orders settings have moved.', 'wpheka-edit-orders-for-woocommerce' ) . '</strong> ' . esc_html__( 'Find them, with the activity log, under WPHEKA > Edit Orders.', 'wpheka-edit-orders-for-woocommerce' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( Edit_Orders_For_WooCommerce_Admin_Page::url( 'settings' ) ) . '">' . esc_html__( 'Open Edit Orders settings', 'wpheka-edit-orders-for-woocommerce' ) . '</a></p></div>';
	}

	/**
	 * Nothing to save on this tab.
	 */
	public function save() {}
}
