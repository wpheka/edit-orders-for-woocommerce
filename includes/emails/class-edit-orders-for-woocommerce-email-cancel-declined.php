<?php
/**
 * Cancellation request declined email.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Email_Cancel_Declined Class.
 */
class Edit_Orders_For_WooCommerce_Email_Cancel_Declined extends Edit_Orders_For_WooCommerce_Email_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'edit_orders_for_woocommerce_cancel_declined';
		$this->customer_email = true;
		$this->title          = __( 'Cancellation request declined', 'wpheka-edit-orders-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when the store declines their cancellation request. The order is not changed.', 'wpheka-edit-orders-for-woocommerce' );
		$this->template_html  = 'emails/edit-orders-cancel-declined.php';
		$this->template_plain = 'emails/plain/edit-orders-cancel-declined.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'About your request to cancel {site_title} order #{order_number}', 'wpheka-edit-orders-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'We could not cancel your order', 'wpheka-edit-orders-for-woocommerce' );
	}
}
