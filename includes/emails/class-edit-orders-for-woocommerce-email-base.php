<?php
/**
 * Shared base for the plugin's simpler emails.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Email_Base Class.
 *
 * Subclasses set the ID, title, templates and default subject and heading. The
 * recipient is the customer for customer emails, otherwise the store owner (or
 * the recipients set in WooCommerce > Settings > Emails).
 */
abstract class Edit_Orders_For_WooCommerce_Email_Base extends WC_Email {

	/**
	 * Extra data for the template.
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * Set the template base and placeholders, then let WooCommerce load settings.
	 */
	public function __construct() {
		$this->template_base = EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/';
		$this->placeholders  = array(
			'{order_number}' => '',
			'{order_date}'   => '',
		);

		parent::__construct();

		if ( ! $this->customer_email ) {
			$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		}
	}

	/**
	 * Send the email.
	 *
	 * @param int            $order_id Order ID.
	 * @param WC_Order|false $order    Order.
	 * @param array          $data     Extra template data.
	 */
	public function trigger( $order_id, $order = false, $data = array() ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->data                           = (array) $data;
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			if ( $this->customer_email ) {
				$this->recipient = $order->get_billing_email();
			}
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
	protected function template_args( $plain_text ) {
		return array(
			'order'              => $this->object,
			'data'               => $this->data,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->customer_email,
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
	 * Settings: store-owner emails get a recipient field.
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		if ( $this->customer_email ) {
			return;
		}

		$this->form_fields = array_merge(
			array(
				'recipient' => array(
					'title'       => __( 'Recipient(s)', 'wpheka-edit-orders-for-woocommerce' ),
					'type'        => 'text',
					/* translators: %s: admin email. */
					'description' => sprintf( __( 'Separate several addresses with commas. Defaults to %s.', 'wpheka-edit-orders-for-woocommerce' ), esc_html( get_option( 'admin_email' ) ) ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
			),
			$this->form_fields
		);
	}
}
