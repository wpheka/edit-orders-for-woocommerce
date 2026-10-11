<?php
/**
 * Customer changed an order email.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Email_Customer_Changed Class.
 */
class Edit_Orders_For_WooCommerce_Email_Customer_Changed extends Edit_Orders_For_WooCommerce_Email_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'edit_orders_for_woocommerce_customer_changed';
		$this->customer_email = false;
		$this->title          = __( 'Customer changed an order', 'wpheka-edit-orders-for-woocommerce' );
		$this->description    = __( 'Sent to the store owner when a customer changes their own order (address, option or order note).', 'wpheka-edit-orders-for-woocommerce' );
		$this->template_html  = 'emails/edit-orders-customer-changed.php';
		$this->template_plain = 'emails/plain/edit-orders-customer-changed.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}]: Order #{order_number} was changed by the customer', 'wpheka-edit-orders-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'A customer changed their order', 'wpheka-edit-orders-for-woocommerce' );
	}
}
