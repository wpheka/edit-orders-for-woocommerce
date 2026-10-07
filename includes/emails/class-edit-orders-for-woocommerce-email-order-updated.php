<?php
/**
 * "Your order was updated" email to the customer.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Email_Order_Updated Class.
 */
class Edit_Orders_For_WooCommerce_Email_Order_Updated extends WC_Email {

	/**
	 * What changed and what money moved.
	 *
	 * @var array
	 */
	public $summary = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'edit_orders_for_woocommerce_order_updated';
		$this->customer_email = true;
		$this->title          = __( 'Order updated', 'edit-orders-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when their order is changed after payment, with what changed and any refund.', 'edit-orders-for-woocommerce' );
		$this->template_html  = 'emails/edit-orders-order-updated.php';
		$this->template_plain = 'emails/plain/edit-orders-order-updated.php';
		$this->template_base  = EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} was updated', 'edit-orders-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your order was updated', 'edit-orders-for-woocommerce' );
	}

	/**
	 * Send the email.
	 *
	 * @param int            $order_id Order ID.
	 * @param WC_Order|false $order    Order.
	 * @param array          $summary  changes, refund, refund_manual, on_delivery, balance_order.
	 */
	public function trigger( $order_id, $order = false, $summary = array() ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->summary                        = (array) $summary;
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * Template arguments.
	 *
	 * @param bool $plain_text Plain text.
	 * @return array
	 */
	private function template_args( $plain_text ) {
		return array(
			'order'              => $this->object,
			'summary'            => $this->summary,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * HTML content.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain content.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
