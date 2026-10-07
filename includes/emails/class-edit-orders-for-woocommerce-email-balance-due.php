<?php
/**
 * "Balance due" email with the pay link, to the customer.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Email_Balance_Due Class.
 *
 * Its object is the balance order; the original order is its parent.
 */
class Edit_Orders_For_WooCommerce_Email_Balance_Due extends WC_Email {

	/**
	 * Descriptions of the changes waiting for payment.
	 *
	 * @var string[]
	 */
	public $changes = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'edit_orders_for_woocommerce_balance_due';
		$this->customer_email = true;
		$this->title          = __( 'Balance due for an order change', 'wpheka-edit-orders-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when a change to their order costs more, with a link to pay the difference.', 'wpheka-edit-orders-for-woocommerce' );
		$this->template_html  = 'emails/edit-orders-balance-due.php';
		$this->template_plain = 'emails/plain/edit-orders-balance-due.php';
		$this->template_base  = EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/';
		$this->placeholders   = array(
			'{order_number}'          => '',
			'{original_order_number}' => '',
		);

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Pay the difference for your {site_title} order #{original_order_number}', 'wpheka-edit-orders-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your order change is waiting for payment', 'wpheka-edit-orders-for-woocommerce' );
	}

	/**
	 * Send the email.
	 *
	 * @param int            $balance_id Balance order ID.
	 * @param WC_Order|false $balance    Balance order.
	 * @param string[]       $changes    Descriptions of the changes.
	 */
	public function trigger( $balance_id, $balance = false, $changes = array() ) {
		$this->setup_locale();

		if ( $balance_id && ! is_a( $balance, 'WC_Order' ) ) {
			$balance = wc_get_order( $balance_id );
		}

		if ( is_a( $balance, 'WC_Order' ) ) {
			$original                                      = wc_get_order( $balance->get_parent_id() );
			$this->object                                  = $balance;
			$this->recipient                               = $balance->get_billing_email();
			$this->changes                                 = (array) $changes;
			$this->placeholders['{order_number}']          = $balance->get_order_number();
			$this->placeholders['{original_order_number}'] = $original ? $original->get_order_number() : '';
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
			'original_order'     => $this->object ? wc_get_order( $this->object->get_parent_id() ) : null,
			'changes'            => $this->changes,
			'pay_url'            => $this->object ? $this->object->get_checkout_payment_url() : '',
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
