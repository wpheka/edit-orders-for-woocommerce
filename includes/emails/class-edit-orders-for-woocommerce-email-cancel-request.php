<?php
/**
 * Cancellation requested email.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Email_Cancel_Request Class.
 */
class Edit_Orders_For_WooCommerce_Email_Cancel_Request extends Edit_Orders_For_WooCommerce_Email_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'edit_orders_for_woocommerce_cancel_request';
		$this->customer_email = false;
		$this->title          = __( 'Cancellation requested', 'edit-orders-for-woocommerce' );
		$this->description    = __( 'Sent to the store owner when a customer asks to cancel an order and the request needs a decision.', 'edit-orders-for-woocommerce' );
		$this->template_html  = 'emails/edit-orders-cancel-request.php';
		$this->template_plain = 'emails/plain/edit-orders-cancel-request.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}]: Cancellation requested for order #{order_number}', 'edit-orders-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Cancellation requested', 'edit-orders-for-woocommerce' );
	}
}
