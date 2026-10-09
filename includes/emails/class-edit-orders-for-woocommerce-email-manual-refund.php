<?php
/**
 * "Manual refund needed" email to the store owner.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Email_Manual_Refund Class.
 */
class Edit_Orders_For_WooCommerce_Email_Manual_Refund extends WC_Email {

	/**
	 * Amount and gateway error.
	 *
	 * @var array
	 */
	public $details = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'edit_orders_for_woocommerce_manual_refund';
		$this->title          = __( 'Manual refund needed', 'edit-orders-for-woocommerce' );
		$this->description    = __( 'Sent to the store owner when an order change needs a refund the payment method cannot make automatically.', 'edit-orders-for-woocommerce' );
		$this->template_html  = 'emails/edit-orders-manual-refund.php';
		$this->template_plain = 'emails/plain/edit-orders-manual-refund.php';
		$this->template_base  = EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
		);

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}]: Manual refund needed for order #{order_number}', 'edit-orders-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Manual refund needed', 'edit-orders-for-woocommerce' );
	}

	/**
	 * Send the email.
	 *
	 * @param int            $order_id Order ID.
	 * @param WC_Order|false $order    Order.
	 * @param array          $details  amount, gateway_error.
	 */
	public function trigger( $order_id, $order = false, $details = array() ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->details                        = (array) $details;
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
			'details'            => $this->details,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => true,
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

	/**
	 * Settings: adds the recipient field to the defaults.
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields = array_merge(
			array(
				'recipient' => array(
					'title'       => __( 'Recipient(s)', 'edit-orders-for-woocommerce' ),
					'type'        => 'text',
					/* translators: %s: admin email. */
					'description' => sprintf( __( 'Separate several addresses with commas. Defaults to %s.', 'edit-orders-for-woocommerce' ), esc_html( get_option( 'admin_email' ) ) ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
			),
			$this->form_fields
		);
	}
}
