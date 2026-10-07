<?php
/**
 * WooCommerce > Settings > Edit Orders (spec section 6).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Settings_Page Class.
 *
 * Every field saves into the one option Edit_Orders_For_WooCommerce_Settings
 * reads (`edit_orders_for_woocommerce_settings[key]`), so defaults stay in one
 * place. "Delete data on uninstall" is its own option, read by uninstall.php.
 */
class Edit_Orders_For_WooCommerce_Settings_Page extends WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'edit_orders';
		$this->label = __( 'Edit Orders', 'edit-orders-for-woocommerce' );

		parent::__construct();
	}

	/**
	 * The option name for one setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function field( $key ) {
		return Edit_Orders_For_WooCommerce_Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Statuses a store owner may allow editing in. Final and unpaid statuses are left out.
	 *
	 * @return array status => label
	 */
	private function status_options() {
		$statuses = array();
		foreach ( wc_get_order_statuses() as $status => $label ) {
			$status = 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;
			if ( ! in_array( $status, array( 'pending', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) ) {
				$statuses[ $status ] = $label;
			}
		}

		return $statuses;
	}

	/**
	 * The settings.
	 *
	 * @return array
	 */
	protected function get_settings_for_default_section() {
		$defaults = Edit_Orders_For_WooCommerce_Settings::defaults();

		$settings = array(
			array(
				'title' => __( 'Editing orders', 'edit-orders-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Edit orders after payment and settle the difference: decreases are refunded, increases are paid through a pay link for the difference.', 'edit-orders-for-woocommerce' ),
				'id'    => 'edit_orders_general',
			),
			array(
				'title'   => __( 'Store-owner editing', 'edit-orders-for-woocommerce' ),
				'desc'    => __( 'Show "Edit order" on paid orders in the admin', 'edit-orders-for-woocommerce' ),
				'id'      => $this->field( 'admin_enabled' ),
				'type'    => 'checkbox',
				'default' => $defaults['admin_enabled'],
			),
			array(
				'title'   => __( 'Order statuses', 'edit-orders-for-woocommerce' ),
				'desc'    => __( 'Paid orders in these statuses can be changed, by you and by customers.', 'edit-orders-for-woocommerce' ),
				'id'      => $this->field( 'editable_statuses' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $this->status_options(),
				'default' => $defaults['editable_statuses'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'edit_orders_general',
			),

			array(
				'title' => __( 'Customer changes', 'edit-orders-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Customers change or cancel their order from the thank-you page, My Account or the link in their order email, within the time you allow.', 'edit-orders-for-woocommerce' ),
				'id'    => 'edit_orders_customer',
			),
			array(
				'title'   => __( 'Customer changes', 'edit-orders-for-woocommerce' ),
				'desc'    => __( 'Let customers change or cancel their own orders', 'edit-orders-for-woocommerce' ),
				'id'      => $this->field( 'customer_enabled' ),
				'type'    => 'checkbox',
				'default' => $defaults['customer_enabled'],
			),
			array(
				'title'             => __( 'Time allowed (minutes)', 'edit-orders-for-woocommerce' ),
				'desc'              => __( 'Counted from payment (from the order date for cash on delivery). From 5 minutes to 7 days (10080).', 'edit-orders-for-woocommerce' ),
				'id'                => $this->field( 'window_minutes' ),
				'type'              => 'number',
				'default'           => $defaults['window_minutes'],
				'custom_attributes' => array(
					'min'  => 5,
					'max'  => 10080,
					'step' => 1,
				),
			),
			array(
				'title'         => __( 'Customers can', 'edit-orders-for-woocommerce' ),
				'desc'          => __( 'Cancel the order', 'edit-orders-for-woocommerce' ),
				'id'            => $this->field( 'action_cancel' ),
				'type'          => 'checkbox',
				'default'       => $defaults['action_cancel'],
				'checkboxgroup' => 'start',
			),
			array(
				'desc'          => __( 'Change the delivery address', 'edit-orders-for-woocommerce' ),
				'id'            => $this->field( 'action_address' ),
				'type'          => 'checkbox',
				'default'       => $defaults['action_address'],
				'checkboxgroup' => '',
			),
			array(
				'desc'          => __( 'Change size or colour (in-stock options of the same product)', 'edit-orders-for-woocommerce' ),
				'id'            => $this->field( 'action_swap' ),
				'type'          => 'checkbox',
				'default'       => $defaults['action_swap'],
				'checkboxgroup' => '',
			),
			array(
				'desc'          => __( 'Edit their order note', 'edit-orders-for-woocommerce' ),
				'id'            => $this->field( 'action_note' ),
				'type'          => 'checkbox',
				'default'       => $defaults['action_note'],
				'checkboxgroup' => 'end',
			),
			array(
				'title'   => __( 'Cancellations', 'edit-orders-for-woocommerce' ),
				'desc'    => __( 'Instant cancels and refunds straight away. Approval sends you the request first.', 'edit-orders-for-woocommerce' ),
				'id'      => $this->field( 'cancel_mode' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					'approval' => __( 'I approve each request', 'edit-orders-for-woocommerce' ),
					'instant'  => __( 'Cancel and refund straight away', 'edit-orders-for-woocommerce' ),
				),
				'default' => $defaults['cancel_mode'],
			),
			array(
				'title'   => __( 'Cancel reasons', 'edit-orders-for-woocommerce' ),
				'desc'    => __( 'Customers pick one of these reasons when they cancel. Write one reason per line. "Other" is always added at the end of the list, with a box for the customer to explain.', 'edit-orders-for-woocommerce' ),
				'id'      => $this->field( 'cancel_reasons' ),
				'type'    => 'textarea',
				'css'     => 'min-height: 100px;',
				'default' => $defaults['cancel_reasons'],
			),
			array(
				'title'   => __( 'Require a reason', 'edit-orders-for-woocommerce' ),
				'desc'    => __( 'Customers must choose or write a reason to cancel', 'edit-orders-for-woocommerce' ),
				'id'      => $this->field( 'cancel_reason_required' ),
				'type'    => 'checkbox',
				'default' => $defaults['cancel_reason_required'],
			),
			array(
				'title'   => __( 'Cancellation policy', 'edit-orders-for-woocommerce' ),
				'desc'    => __( 'Shown above the cancel button, for example your refund terms. Leave empty to show nothing.', 'edit-orders-for-woocommerce' ),
				'id'      => $this->field( 'cancel_policy' ),
				'type'    => 'textarea',
				'default' => $defaults['cancel_policy'],
			),
			array(
				'title'             => __( 'Order note length', 'edit-orders-for-woocommerce' ),
				'desc'              => __( 'Most characters a customer can write in their order note.', 'edit-orders-for-woocommerce' ),
				'id'                => $this->field( 'note_max_length' ),
				'type'              => 'number',
				'default'           => $defaults['note_max_length'],
				'custom_attributes' => array(
					'min'  => 20,
					'max'  => 2000,
					'step' => 1,
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'edit_orders_customer',
			),

			array(
				'title' => __( 'Emails', 'edit-orders-for-woocommerce' ),
				'type'  => 'title',
				/* translators: %s: link to the email settings. */
				'desc'  => sprintf( __( 'Turn emails on or off and change their wording in %s. Look for the emails starting "Order updated", "Balance due", "Manual refund needed", "Cancellation" and "Customer changed an order".', 'edit-orders-for-woocommerce' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '">' . esc_html__( 'WooCommerce > Settings > Emails', 'edit-orders-for-woocommerce' ) . '</a>' ),
				'id'    => 'edit_orders_emails',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'edit_orders_emails',
			),

			array(
				'title' => __( 'Advanced', 'edit-orders-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'edit_orders_advanced',
			),
			array(
				'title'   => __( 'Delete data on uninstall', 'edit-orders-for-woocommerce' ),
				'desc'    => __( 'Remove the activity log and settings when the plugin is deleted. Balance orders are real orders and are always kept.', 'edit-orders-for-woocommerce' ),
				'id'      => 'edit_orders_for_woocommerce_delete_data',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'edit_orders_advanced',
			),
		);

		return array_map( array( $this, 'with_help' ), $settings );
	}

	/**
	 * Show a field's description on the page and in its "?" tooltip: many store owners never
	 * hover the tooltip. Text boxes get the description below the box, as other fields do.
	 * Checkboxes already show their description as the label.
	 *
	 * @param array $field Setting field.
	 * @return array
	 */
	private function with_help( array $field ) {
		if ( empty( $field['desc'] ) || ! in_array( $field['type'], array( 'number', 'select', 'multiselect', 'textarea' ), true ) ) {
			return $field;
		}

		// A string desc_tip is the tooltip; desc stays visible (true would move desc into the tooltip).
		$field['desc_tip'] = $field['desc'];
		if ( 'textarea' === $field['type'] ) {
			$field['desc_at_end'] = true;
		}

		return $field;
	}

	/**
	 * Clamp the numbers after saving, so a typed value can't break the rules.
	 */
	public function save() {
		parent::save();

		$settings = (array) get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array() );
		if ( isset( $settings['window_minutes'] ) ) {
			$settings['window_minutes'] = min( 10080, max( 5, absint( $settings['window_minutes'] ) ) );
		}
		if ( isset( $settings['note_max_length'] ) ) {
			$settings['note_max_length'] = min( 2000, max( 20, absint( $settings['note_max_length'] ) ) );
		}
		// WooCommerce doesn't check a multiselect against its options: keep only statuses offered.
		$settings['editable_statuses'] = array_values( array_intersect( (array) ( isset( $settings['editable_statuses'] ) ? $settings['editable_statuses'] : array() ), array_keys( $this->status_options() ) ) );
		if ( empty( $settings['editable_statuses'] ) ) {
			$settings['editable_statuses'] = Edit_Orders_For_WooCommerce_Settings::defaults()['editable_statuses'];
		}
		update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $settings );
	}
}
