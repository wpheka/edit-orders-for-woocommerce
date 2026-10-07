<?php
/**
 * Order cancelled at your request email.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Email_Cancelled Class.
 */
class Edit_Orders_For_WooCommerce_Email_Cancelled extends Edit_Orders_For_WooCommerce_Email_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'edit_orders_for_woocommerce_cancelled';
		$this->customer_email = true;
		$this->title          = __( 'Order cancelled at your request', 'wpheka-edit-orders-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when their cancellation is made, at once or after the store approves it, with the refund.', 'wpheka-edit-orders-for-woocommerce' );
		$this->template_html  = 'emails/edit-orders-cancelled.php';
		$this->template_plain = 'emails/plain/edit-orders-cancelled.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} is cancelled', 'wpheka-edit-orders-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your order is cancelled', 'wpheka-edit-orders-for-woocommerce' );
	}
}
