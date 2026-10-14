<?php
/**
 * The settings form on WPHEKA > Edit Orders: every field, saving, the emails
 * list, the stats and the getting-started steps.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Edit_Orders_For_WooCommerce\AdminUI\Fields;

/**
 * Edit_Orders_For_WooCommerce_Settings_Form Class.
 *
 * Every field saves into the one option Edit_Orders_For_WooCommerce_Settings reads,
 * so defaults stay in one place. "Delete data on uninstall" is its own option, read
 * by uninstall.php. The rules here (clamps, the status allow-list, the change log)
 * are the ones the WooCommerce settings tab applied before 1.2.0.
 */
class Edit_Orders_For_WooCommerce_Settings_Form {

	/**
	 * The admin-post action that saves the form.
	 */
	const SAVE_ACTION = 'edit_orders_for_woocommerce_save_settings';

	/**
	 * Capability needed to change the settings, as for WooCommerce's own settings.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Option read by uninstall.php.
	 */
	const DELETE_OPTION = 'edit_orders_for_woocommerce_delete_data';

	/**
	 * Prefix of this plugin's WooCommerce email ids.
	 */
	const EMAIL_PREFIX = 'edit_orders_for_woocommerce_';

	/**
	 * The keys the form saves into the settings option.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array( 'admin_enabled', 'editable_statuses', 'customer_enabled', 'window_minutes', 'action_cancel', 'action_address', 'action_swap', 'action_note', 'note_max_length', 'cancel_mode', 'cancel_reasons', 'cancel_reason_required', 'cancel_policy', 'debug_log' );
	}

	/**
	 * Statuses a store owner may allow editing in. Final and unpaid statuses are left out.
	 *
	 * @return array status => label
	 */
	public static function status_options() {
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
	 * The current settings with defaults filled in.
	 *
	 * @return array
	 */
	public static function current() {
		return wp_parse_args( (array) get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array() ), Edit_Orders_For_WooCommerce_Settings::defaults() );
	}

	/**
	 * This plugin's WooCommerce emails, in a fixed order.
	 *
	 * @return WC_Email[] id => email
	 */
	public static function emails() {
		$order  = array( 'order_updated', 'balance_due', 'manual_refund', 'customer_changed', 'cancel_request', 'cancelled', 'cancel_declined' );
		$emails = array();
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof WC_Email && 0 === strpos( $email->id, self::EMAIL_PREFIX ) ) {
				$emails[ $email->id ] = $email;
			}
		}
		uksort(
			$emails,
			static function ( $a, $b ) use ( $order ) {
				$pa = array_search( substr( $a, strlen( self::EMAIL_PREFIX ) ), $order, true );
				$pb = array_search( substr( $b, strlen( self::EMAIL_PREFIX ) ), $order, true );

				return ( false === $pa ? 99 : $pa ) - ( false === $pb ? 99 : $pb );
			}
		);

		return $emails;
	}

	/**
	 * Save the posted form.
	 *
	 * @param array $post Unslashed form values: `edit_orders_for_woocommerce_settings[...]`,
	 *                    `edit_orders_for_woocommerce_delete_data` and `emails[id]`.
	 */
	public static function save( array $post ) {
		$option = Edit_Orders_For_WooCommerce_Settings::OPTION;
		$before = self::current();

		$before['delete_data'] = get_option( self::DELETE_OPTION, 'no' );

		$input    = isset( $post[ $option ] ) && is_array( $post[ $option ] ) ? $post[ $option ] : array();
		$settings = (array) get_option( $option, array() );
		$yes_no   = array( 'admin_enabled', 'customer_enabled', 'action_cancel', 'action_address', 'action_swap', 'action_note', 'cancel_reason_required', 'debug_log' );

		foreach ( $yes_no as $key ) {
			$settings[ $key ] = isset( $input[ $key ] ) && in_array( $input[ $key ], array( 'yes', '1', 1, true ), true ) ? 'yes' : 'no';
		}
		$settings['window_minutes']  = min( 10080, max( 5, absint( isset( $input['window_minutes'] ) ? $input['window_minutes'] : $before['window_minutes'] ) ) );
		$settings['note_max_length'] = min( 2000, max( 20, absint( isset( $input['note_max_length'] ) ? $input['note_max_length'] : $before['note_max_length'] ) ) );
		$settings['cancel_mode']     = isset( $input['cancel_mode'] ) && 'instant' === $input['cancel_mode'] ? 'instant' : 'approval';
		$settings['cancel_reasons']  = isset( $input['cancel_reasons'] ) ? sanitize_textarea_field( (string) $input['cancel_reasons'] ) : '';
		$settings['cancel_policy']   = isset( $input['cancel_policy'] ) ? wp_kses_post( trim( (string) $input['cancel_policy'] ) ) : '';

		// Keep only the statuses offered; an empty list falls back to the defaults.
		$statuses                      = isset( $input['editable_statuses'] ) ? array_map( 'sanitize_key', (array) $input['editable_statuses'] ) : array();
		$settings['editable_statuses'] = array_values( array_intersect( $statuses, array_keys( self::status_options() ) ) );
		if ( empty( $settings['editable_statuses'] ) ) {
			$settings['editable_statuses'] = Edit_Orders_For_WooCommerce_Settings::defaults()['editable_statuses'];
		}

		update_option( $option, $settings );
		update_option( self::DELETE_OPTION, isset( $post[ self::DELETE_OPTION ] ) && 'yes' === $post[ self::DELETE_OPTION ] ? 'yes' : 'no' );

		// Email switches write WooCommerce's own "enabled" setting for each email.
		if ( isset( $post['emails'] ) && is_array( $post['emails'] ) && function_exists( 'WC' ) ) {
			foreach ( self::emails() as $id => $email ) {
				if ( isset( $post['emails'][ $id ] ) ) {
					$on = 'yes' === $post['emails'][ $id ] ? 'yes' : 'no';
					if ( $email->get_option( 'enabled' ) !== $on ) {
						$email->update_option( 'enabled', $on );
					}
				}
			}
		}

		$after                = wp_parse_args( $settings, Edit_Orders_For_WooCommerce_Settings::defaults() );
		$after['delete_data'] = get_option( self::DELETE_OPTION, 'no' );
		self::log_changes( $before, $after );
	}

	/**
	 * Log the settings that changed, with their new values.
	 *
	 * @param array $before Settings before saving.
	 * @param array $after  Settings after saving.
	 */
	public static function log_changes( array $before, array $after ) {
		$changed = array();
		foreach ( $after as $key => $value ) {
			$old = isset( $before[ $key ] ) ? $before[ $key ] : null;
			// Numbers come back as strings or integers depending on where they were saved.
			$same = is_array( $value ) || is_array( $old )
				? (string) wp_json_encode( array_values( (array) $old ) ) === (string) wp_json_encode( array_values( (array) $value ) )
				: (string) $old === (string) $value;
			if ( $same ) {
				continue;
			}
			$text      = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			$changed[] = $key . ' = ' . Edit_Orders_For_WooCommerce_Log::clip( $text );
		}

		if ( $changed ) {
			// Switching the debug log is recorded either way: once off, info would not be.
			$level = isset( $before['debug_log'], $after['debug_log'] ) && (string) $before['debug_log'] !== (string) $after['debug_log'] ? 'notice' : 'info';
			call_user_func(
				array( 'Edit_Orders_For_WooCommerce_Log', $level ),
				'settings saved by ' . Edit_Orders_For_WooCommerce_Log::actor( 'staff' ) . ': ' . implode( '; ', $changed ),
				array( 'changed' => $changed )
			);
		}
	}

	/**
	 * The field name for one setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function field( $key ) {
		return Edit_Orders_For_WooCommerce_Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Print the settings sections.
	 */
	public static function render() {
		$s = self::current();

		Fields::section_start( 'editing', __( 'Order editing', 'wpheka-edit-orders-for-woocommerce' ), __( 'Edit orders after payment and settle the difference: decreases are refunded, increases are paid through a pay link for the difference.', 'wpheka-edit-orders-for-woocommerce' ) );
		Fields::switch_row(
			array(
				'name'    => self::field( 'admin_enabled' ),
				'checked' => 'yes' === $s['admin_enabled'],
				'label'   => __( 'Store-owner editing', 'wpheka-edit-orders-for-woocommerce' ),
				'help'    => __( 'Show "Edit order" on paid orders in the admin.', 'wpheka-edit-orders-for-woocommerce' ),
			)
		);
		Fields::chips_row(
			array(
				'name'     => self::field( 'editable_statuses' ),
				'options'  => self::status_options(),
				'selected' => (array) $s['editable_statuses'],
				'label'    => __( 'Order statuses', 'wpheka-edit-orders-for-woocommerce' ),
				'help'     => __( 'Paid orders in these statuses can be changed, by you and by customers.', 'wpheka-edit-orders-for-woocommerce' ),
			)
		);
		Fields::section_end();

		Fields::section_start( 'customers', __( 'Customer changes', 'wpheka-edit-orders-for-woocommerce' ), __( 'Customers change or cancel their order from the thank-you page, My Account or the link in their order email, within the time you allow.', 'wpheka-edit-orders-for-woocommerce' ) );
		Fields::switch_row(
			array(
				'name'     => self::field( 'customer_enabled' ),
				'checked'  => 'yes' === $s['customer_enabled'],
				'label'    => __( 'Let customers change or cancel their own orders', 'wpheka-edit-orders-for-woocommerce' ),
				'controls' => 'edit-orders-customer-rows',
			)
		);
		Fields::sub_start( 'edit-orders-customer-rows' );
		Fields::number_row(
			array(
				'name'    => self::field( 'window_minutes' ),
				'value'   => (int) $s['window_minutes'],
				'label'   => __( 'Time allowed', 'wpheka-edit-orders-for-woocommerce' ),
				'help'    => __( 'Counted from payment (from the order date for cash on delivery). From 5 minutes to 7 days (10080).', 'wpheka-edit-orders-for-woocommerce' ),
				'unit'    => __( 'minutes', 'wpheka-edit-orders-for-woocommerce' ),
				'min'     => 5,
				'max'     => 10080,
				'minutes' => true,
			)
		);
		Fields::options_row(
			array(
				'label' => __( 'Customers can', 'wpheka-edit-orders-for-woocommerce' ),
				'items' => array(
					array(
						'name'    => self::field( 'action_cancel' ),
						'checked' => 'yes' === $s['action_cancel'],
						'title'   => __( 'Cancel the order', 'wpheka-edit-orders-for-woocommerce' ),
						'text'    => __( 'Refunded at once or after your approval, as set under Cancellations.', 'wpheka-edit-orders-for-woocommerce' ),
					),
					array(
						'name'    => self::field( 'action_address' ),
						'checked' => 'yes' === $s['action_address'],
						'title'   => __( 'Change the delivery address', 'wpheka-edit-orders-for-woocommerce' ),
						'text'    => __( 'Shipping and tax are recalculated for the new address.', 'wpheka-edit-orders-for-woocommerce' ),
					),
					array(
						'name'    => self::field( 'action_swap' ),
						'checked' => 'yes' === $s['action_swap'],
						'title'   => __( 'Change size or colour', 'wpheka-edit-orders-for-woocommerce' ),
						'text'    => __( 'In-stock options of the same product.', 'wpheka-edit-orders-for-woocommerce' ),
					),
					array(
						'name'    => self::field( 'action_note' ),
						'checked' => 'yes' === $s['action_note'],
						'title'   => __( 'Edit their order note', 'wpheka-edit-orders-for-woocommerce' ),
						'text'    => __( 'Up to the length set below.', 'wpheka-edit-orders-for-woocommerce' ),
					),
				),
			)
		);
		Fields::number_row(
			array(
				'name'  => self::field( 'note_max_length' ),
				'value' => (int) $s['note_max_length'],
				'label' => __( 'Order note length', 'wpheka-edit-orders-for-woocommerce' ),
				'help'  => __( 'Most characters a customer can write in their order note.', 'wpheka-edit-orders-for-woocommerce' ),
				'unit'  => __( 'characters', 'wpheka-edit-orders-for-woocommerce' ),
				'min'   => 20,
				'max'   => 2000,
			)
		);
		Fields::sub_end();
		Fields::section_end();

		Fields::section_start( 'cancellations', __( 'Cancellations', 'wpheka-edit-orders-for-woocommerce' ), __( 'How customer cancellations are handled and what customers see.', 'wpheka-edit-orders-for-woocommerce' ) );
		Fields::segmented_row(
			array(
				'name'    => self::field( 'cancel_mode' ),
				'value'   => $s['cancel_mode'],
				'label'   => __( 'Cancellations', 'wpheka-edit-orders-for-woocommerce' ),
				'help'    => __( 'Instant cancels and refunds straight away. Approval sends you the request first.', 'wpheka-edit-orders-for-woocommerce' ),
				'stack'   => true,
				'options' => array(
					'approval' => __( 'I approve each request', 'wpheka-edit-orders-for-woocommerce' ),
					'instant'  => __( 'Cancel and refund straight away', 'wpheka-edit-orders-for-woocommerce' ),
				),
			)
		);
		Fields::switch_row(
			array(
				'name'    => self::field( 'cancel_reason_required' ),
				'checked' => 'yes' === $s['cancel_reason_required'],
				'label'   => __( 'Require a reason', 'wpheka-edit-orders-for-woocommerce' ),
				'help'    => __( 'Customers must choose or write a reason to cancel.', 'wpheka-edit-orders-for-woocommerce' ),
			)
		);
		Fields::textarea_row(
			array(
				'name'  => self::field( 'cancel_reasons' ),
				'value' => (string) $s['cancel_reasons'],
				'label' => __( 'Cancel reasons', 'wpheka-edit-orders-for-woocommerce' ),
				'help'  => __( 'Customers pick one of these reasons when they cancel. Write one reason per line. "Other" is always added at the end of the list, with a box for the customer to explain.', 'wpheka-edit-orders-for-woocommerce' ),
				'rows'  => 4,
			)
		);
		Fields::textarea_row(
			array(
				'name'  => self::field( 'cancel_policy' ),
				'value' => (string) $s['cancel_policy'],
				'label' => __( 'Cancellation policy', 'wpheka-edit-orders-for-woocommerce' ),
				'help'  => __( 'Shown above the cancel button, for example your refund terms. Leave empty to show nothing.', 'wpheka-edit-orders-for-woocommerce' ),
				'rows'  => 2,
			)
		);
		Fields::section_end();

		Fields::section_start( 'emails', __( 'Emails', 'wpheka-edit-orders-for-woocommerce' ), __( 'The emails Edit Orders sends. Turn each one on or off here and change its wording in WooCommerce\'s email settings.', 'wpheka-edit-orders-for-woocommerce' ), 'w-list' );
		foreach ( self::emails() as $id => $email ) {
			Fields::list_row(
				array(
					'icon'       => $email->is_customer_email() ? 'user' : 'mail',
					'label'      => $email->get_title(),
					'help'       => $email->get_description(),
					'name'       => 'emails[' . $id . ']',
					'checked'    => 'yes' === $email->get_option( 'enabled', $email->enabled ),
					'link_url'   => admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( get_class( $email ) ) ),
					'link_label' => __( 'Edit wording', 'wpheka-edit-orders-for-woocommerce' ),
				)
			);
		}
		Fields::section_end();

		Fields::section_start( 'advanced', __( 'Advanced', 'wpheka-edit-orders-for-woocommerce' ) );
		Fields::switch_row(
			array(
				'name'    => self::field( 'debug_log' ),
				'checked' => 'yes' === $s['debug_log'],
				'label'   => __( 'Debug log', 'wpheka-edit-orders-for-woocommerce' ),
				'help'    => sprintf(
					/* translators: %s: link to WooCommerce > Status > Logs. */
					__( 'Record every step of each change in %s, to share with support when something goes wrong.', 'wpheka-edit-orders-for-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=' . Edit_Orders_For_WooCommerce_Log::SOURCE ) ) . '">' . esc_html__( 'WooCommerce > Status > Logs', 'wpheka-edit-orders-for-woocommerce' ) . '</a>'
				) . ' ' . __( 'Errors, warnings and refusals are always recorded. Turn this on to record every step as well. The log has no names, emails or addresses.', 'wpheka-edit-orders-for-woocommerce' ),
			)
		);
		Fields::switch_row(
			array(
				'name'    => self::DELETE_OPTION,
				'checked' => 'yes' === get_option( self::DELETE_OPTION, 'no' ),
				'label'   => __( 'Delete data on uninstall', 'wpheka-edit-orders-for-woocommerce' ),
				'help'    => __( 'Remove the activity log, the settings and the plugin\'s data on orders (manual refund flags and cancellation requests) when the plugin is deleted. Balance orders are real orders and are always kept.', 'wpheka-edit-orders-for-woocommerce' ),
			)
		);
		Fields::section_end();

		Fields::find_empty();
	}

	/**
	 * Numbers for the stats strip, from the activity log, for the last 30 days.
	 *
	 * @return array { edited, refunds, refunded, links, links_paid, collected, customer }
	 */
	public static function stats() {
		global $wpdb;

		$table = Edit_Orders_For_WooCommerce_Audit_Log::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table, read once per page view.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT( DISTINCT CASE WHEN action IN ( 'edit', 'balance_created' ) THEN order_id END ) AS edited,
					SUM( CASE WHEN action IN ( 'edit', 'cancelled' ) AND refund_id > 0 THEN 1 ELSE 0 END ) AS refunds,
					SUM( CASE WHEN action IN ( 'edit', 'cancelled' ) AND refund_id > 0 THEN amount ELSE 0 END ) AS refunded,
					SUM( CASE WHEN action = 'balance_created' THEN 1 ELSE 0 END ) AS links,
					SUM( CASE WHEN action = 'balance_paid' THEN 1 ELSE 0 END ) AS links_paid,
					SUM( CASE WHEN action = 'balance_paid' THEN amount ELSE 0 END ) AS collected,
					SUM( CASE WHEN actor_type IN ( 'customer', 'guest' ) AND action IN ( 'edit', 'balance_created', 'cancel_requested', 'cancelled', 'note_changed' ) THEN 1 ELSE 0 END ) AS customer
				FROM {$table} WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( array( 'edited', 'refunds', 'links', 'links_paid', 'customer' ) as $key ) {
			$out[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}
		$out['refunded']  = isset( $row['refunded'] ) ? (float) $row['refunded'] : 0.0;
		$out['collected'] = isset( $row['collected'] ) ? (float) $row['collected'] : 0.0;

		return $out;
	}

	/**
	 * Getting-started steps and whether each is done.
	 *
	 * @return array[] Each { label, done }.
	 */
	public static function steps() {
		global $wpdb;

		$s     = self::current();
		$table = Edit_Orders_For_WooCommerce_Audit_Log::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table name, no input.
		$edited = (bool) $wpdb->get_var( "SELECT 1 FROM {$table} WHERE action IN ( 'edit', 'balance_created' ) LIMIT 1" );

		return array(
			array(
				'label' => __( 'Turn on order editing', 'wpheka-edit-orders-for-woocommerce' ),
				'done'  => 'yes' === $s['admin_enabled'],
			),
			array(
				'label' => __( 'Choose editable statuses', 'wpheka-edit-orders-for-woocommerce' ),
				'done'  => false !== get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, false ),
			),
			array(
				'label' => __( 'Let customers make changes', 'wpheka-edit-orders-for-woocommerce' ),
				'done'  => 'yes' === $s['customer_enabled'],
			),
			array(
				'label' => __( 'Edit your first order', 'wpheka-edit-orders-for-woocommerce' ),
				'done'  => $edited,
			),
		);
	}
}
