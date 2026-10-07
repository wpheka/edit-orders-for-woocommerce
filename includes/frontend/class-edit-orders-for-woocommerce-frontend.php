<?php
/**
 * Customer self-service on the storefront (spec section 4).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Frontend Class.
 *
 * One panel, shown once per page, on the thank-you page (classic and block
 * themes) and the My Account order page. Forms post back to the same page; the
 * request is handled on template_redirect. Guests are identified by the order
 * key, as WooCommerce's own thank-you page does.
 */
class Edit_Orders_For_WooCommerce_Frontend {

	/**
	 * Nonce action for customer forms.
	 */
	const NONCE = 'edit_orders_for_woocommerce_customer';

	/**
	 * Has the panel been printed on this page?
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * The result of a request handled on this page (an error or a preview), for the panel.
	 *
	 * @var array|null
	 */
	private static $state = null;

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ), 5 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_for_order' ), 20 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_for_order_id' ), 20 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'order_action' ), 10, 2 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_link' ), 20, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Scripts and styles on the pages that show the panel.
	 */
	public static function enqueue() {
		if ( ! is_account_page() && ! is_order_received_page() ) {
			return;
		}

		wp_enqueue_style( 'edit-orders-for-woocommerce', EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/css/frontend.css', array(), EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION );
		wp_enqueue_script( 'edit-orders-for-woocommerce', EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/js/frontend.js', array(), EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION, true );
		wp_localize_script(
			'edit-orders-for-woocommerce',
			'editOrdersForWooCommerceFront',
			array(
				'states' => WC()->countries->get_states(),
				'closed' => __( 'The time for changes has passed.', 'edit-orders-for-woocommerce' ),
			)
		);
	}

	/**
	 * Handle a posted customer form.
	 */
	public static function handle_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below before anything is read.
		if ( empty( $_POST['edit_orders_for_woocommerce_action'] ) ) {
			return;
		}

		$nonce = isset( $_POST['_eofw_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_eofw_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			self::$state = self::error( __( 'This page has expired. Reload it and try again.', 'edit-orders-for-woocommerce' ) );
			return;
		}

		$data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitised where process() uses it.

		if ( Edit_Orders_For_WooCommerce_Customer_Rules::is_rate_limited() ) {
			status_header( 429 );
			nocache_headers();
			wp_die( esc_html__( 'Too many attempts. Please try again later.', 'edit-orders-for-woocommerce' ), '', array( 'response' => 429 ) );
		}

		$order = wc_get_order( isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0 );
		$actor = Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $order, isset( $data['order_key'] ) ? $data['order_key'] : '' );
		if ( is_wp_error( $actor ) ) {
			$data   = $actor->get_error_data();
			$status = isset( $data['status'] ) ? (int) $data['status'] : 404;
			status_header( $status );
			nocache_headers();
			wp_die( esc_html( $actor->get_error_message() ), '', array( 'response' => $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer HTTP status, not output.
		}

		$result = self::process( $order, $data, $actor );

		if ( ! empty( $result['redirect'] ) ) {
			wp_safe_redirect( $result['redirect'] );
			exit;
		}

		if ( 'success' === $result['type'] ) {
			// Post, redirect, get: show the message once without resubmitting on reload.
			$token = wp_generate_password( 12, false );
			set_transient( 'eofw_msg_' . $token, $result['message'], 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'eofw_msg', $token, remove_query_arg( 'eofw_msg' ) ) . '#edit-order' );
			exit;
		}

		self::$state = $result;
	}

	/**
	 * Carry out a customer request. No output or redirects, so it can be tested.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data, unslashed.
	 * @param string   $actor customer or guest.
	 * @return array { type: success|error|preview, action, message, redirect, preview }
	 */
	public static function process( WC_Order $order, array $data, $actor ) {
		$action = isset( $data['edit_orders_for_woocommerce_action'] ) ? sanitize_key( $data['edit_orders_for_woocommerce_action'] ) : '';

		if ( ! Edit_Orders_For_WooCommerce_Customer_Rules::count_action( $order ) ) {
			return self::error( __( 'Too many changes in a short time. Please try again later.', 'edit-orders-for-woocommerce' ), $action );
		}

		$allowed = Edit_Orders_For_WooCommerce_Customer_Rules::can( $order, $action, $actor );
		if ( is_wp_error( $allowed ) ) {
			return self::error( $allowed->get_error_message(), $action );
		}

		switch ( $action ) {
			case 'close':
				$order->update_meta_data( Edit_Orders_For_WooCommerce_Customer_Rules::WINDOW_CLOSED_META, time() );
				$order->add_order_note( __( 'The customer confirmed the order as it is; changes are closed.', 'edit-orders-for-woocommerce' ) );
				$order->save();
				Edit_Orders_For_WooCommerce_Audit_Log::add(
					array(
						'order_id'   => $order->get_id(),
						'actor_type' => $actor,
						'action'     => 'window_closed',
					)
				);
				/**
				 * Fired when the customer closes the edit window early.
				 *
				 * @since 0.1.0
				 *
				 * @param WC_Order $order Order.
				 */
				do_action( 'edit_orders_for_woocommerce_window_closed', $order );
				return self::success( __( 'Thank you. Your order is confirmed as it is.', 'edit-orders-for-woocommerce' ), $action );

			case 'note':
				return self::change_note( $order, $data, $actor );

			case 'cancel':
				$reason = isset( $data['reason'] ) ? sanitize_text_field( $data['reason'] ) : '';
				if ( '__other' === $reason ) {
					$reason = isset( $data['reason_other'] ) ? sanitize_textarea_field( $data['reason_other'] ) : '';
				}
				$result = Edit_Orders_For_WooCommerce_Cancellation::request( $order, $reason, $actor );
				if ( is_wp_error( $result ) ) {
					return self::error( $result->get_error_message(), $action );
				}
				return self::success(
					'requested' === $result['status']
						? __( 'Your cancellation request has been sent. We will email you when the store has answered.', 'edit-orders-for-woocommerce' )
						: __( 'Your order is cancelled. We have emailed you the details.', 'edit-orders-for-woocommerce' ),
					$action
				);

			case 'address':
			case 'swap':
				return self::change_order( $order, $data, $actor, $action );
		}

		return self::error( __( 'Unknown request.', 'edit-orders-for-woocommerce' ), $action );
	}

	/**
	 * Address change or variation swap: preview first, then confirm.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $data   Posted data.
	 * @param string   $actor  Actor.
	 * @param string   $action address or swap.
	 * @return array
	 */
	private static function change_order( WC_Order $order, array $data, $actor, $action ) {
		$changes = 'address' === $action ? self::address_changes( $order, $data ) : self::swap_changes( $order, $data );
		if ( ! $changes ) {
			return self::error( __( 'Nothing was changed.', 'edit-orders-for-woocommerce' ), $action );
		}

		$confirm = isset( $data['step'] ) && 'confirm' === $data['step'];

		if ( ! $confirm ) {
			$plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $order, $changes, $actor );
			if ( is_wp_error( $plan ) ) {
				$out = self::error( $plan->get_error_message(), $action );
				// The current shipping method isn't offered at the new address: let the customer pick one.
				$rates = 'address' === $action ? Edit_Orders_For_WooCommerce_Address_Change::rate_choices( $order, $changes ) : array();
				if ( $rates ) {
					$out['rates']  = $rates;
					$out['fields'] = $data;
				}
				return $out;
			}

			$fields           = $data;
			$fields['expect'] = $plan->fingerprint();

			return array(
				'type'     => 'preview',
				'action'   => $action,
				'message'  => '',
				'redirect' => '',
				'preview'  => array(
					'changes' => $plan->get_descriptions(),
					'refund'  => $plan->get_refund_amount(),
					'balance' => $plan->get_balance_estimate( $order ),
					'fields'  => $fields,
				),
			);
		}

		$result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
			$order,
			$changes,
			$actor,
			array(
				'notify_customer' => true,
				'send_pay_link'   => true,
				// What the customer confirmed must be what gets applied.
				'expect'          => isset( $data['expect'] ) ? sanitize_key( $data['expect'] ) : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_message(), $action );
		}

		$balance = ! empty( $result['balance_order_id'] ) ? wc_get_order( $result['balance_order_id'] ) : null;

		/**
		 * Fired when a customer changes their own order.
		 *
		 * @since 0.1.0
		 *
		 * @param WC_Order $order Order.
		 * @param array    $data  changes, balance_due.
		 */
		do_action(
			'edit_orders_for_woocommerce_customer_changed',
			wc_get_order( $order->get_id() ),
			array(
				'changes'     => $result['plan']->get_descriptions(),
				'balance_due' => ( $balance && 'balance_due' === $result['status'] ) ? $balance->get_total() : 0,
			)
		);

		if ( 'balance_due' === $result['status'] && $balance ) {
			// The customer is here: take them straight to paying the difference.
			$out             = self::success( __( 'Pay the difference to complete your change.', 'edit-orders-for-woocommerce' ), $action );
			$out['redirect'] = $balance->get_checkout_payment_url();
			return $out;
		}

		return self::success( __( 'Your order has been updated. We have emailed you the details.', 'edit-orders-for-woocommerce' ), $action );
	}

	/**
	 * Change the order note.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 * @param string   $actor Actor.
	 * @return array
	 */
	private static function change_note( WC_Order $order, array $data, $actor ) {
		$max  = max( 1, (int) Edit_Orders_For_WooCommerce_Settings::get( 'note_max_length' ) );
		$note = isset( $data['customer_note'] ) ? sanitize_textarea_field( $data['customer_note'] ) : '';
		$note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, $max ) : substr( $note, 0, $max );
		$old  = $order->get_customer_note();

		if ( $note === $old ) {
			return self::error( __( 'Nothing was changed.', 'edit-orders-for-woocommerce' ), 'note' );
		}

		$order->set_customer_note( $note );
		/* translators: 1: old note, 2: new note. */
		$order->add_order_note( sprintf( __( 'The customer changed the order note from "%1$s" to "%2$s".', 'edit-orders-for-woocommerce' ), $old, $note ) );
		$order->save();

		Edit_Orders_For_WooCommerce_Audit_Log::add(
			array(
				'order_id'   => $order->get_id(),
				'actor_type' => $actor,
				'action'     => 'note_changed',
				'before'     => array( 'note' => $old ),
				'after'      => array( 'note' => $note ),
			)
		);

		/** This action is documented in includes/frontend/class-edit-orders-for-woocommerce-frontend.php */
		do_action(
			'edit_orders_for_woocommerce_customer_changed',
			$order,
			array(
				'changes'     => array( __( 'Order note changed.', 'edit-orders-for-woocommerce' ) ),
				'balance_due' => 0,
			)
		);

		return self::success( __( 'Your order note has been updated.', 'edit-orders-for-woocommerce' ), 'note' );
	}

	/**
	 * Address change from the posted form: only the fields that differ. The billing email is never changed.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 * @return array[]
	 */
	private static function address_changes( WC_Order $order, array $data ) {
		$change = array( 'type' => 'address' );

		foreach ( array( 'shipping', 'billing' ) as $type ) {
			if ( empty( $data[ $type ] ) || ! is_array( $data[ $type ] ) ) {
				continue;
			}
			$current = $order->get_address( $type );
			$fields  = array();
			foreach ( Edit_Orders_For_WooCommerce_Address_Change::FIELDS as $field ) {
				if ( isset( $data[ $type ][ $field ] ) && (string) $data[ $type ][ $field ] !== (string) $current[ $field ] ) {
					$fields[ $field ] = sanitize_text_field( $data[ $type ][ $field ] );
				}
			}
			if ( $fields ) {
				$change[ $type ] = $fields;
			}
		}

		if ( ! empty( $data['shipping_method'] ) ) {
			$change['shipping_method'] = sanitize_text_field( $data['shipping_method'] );
		}

		return count( $change ) > 1 ? array( $change ) : array();
	}

	/**
	 * Variation swaps from the posted form, only to in-stock variations of the same product.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 * @return array[]
	 */
	private static function swap_changes( WC_Order $order, array $data ) {
		$changes = array();

		foreach ( isset( $data['swap'] ) && is_array( $data['swap'] ) ? $data['swap'] : array() as $item_id => $variation_id ) {
			$item = $order->get_item( absint( $item_id ) );
			if ( $item instanceof WC_Order_Item_Product && absint( $variation_id ) && absint( $variation_id ) !== (int) $item->get_variation_id() ) {
				$changes[] = array(
					'type'         => 'swap',
					'item_id'      => $item->get_id(),
					'variation_id' => absint( $variation_id ),
				);
			}
		}

		return $changes;
	}

	/**
	 * Print the panel for an order ID (thank-you hook).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function render_for_order_id( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::render_for_order( $order );
		}
	}

	/**
	 * Print the panel once, if the visitor may act on this order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_for_order( $order ) {
		if ( self::$rendered || ! $order instanceof WC_Order ) {
			return;
		}

		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the credential; checked in authorize().
		$actor = Edit_Orders_For_WooCommerce_Customer_Rules::authorize( $order, $key );
		if ( is_wp_error( $actor ) ) {
			return;
		}

		$panel = self::panel_args( $order, 'guest' === $actor ? $key : '' );
		if ( ! $panel ) {
			return;
		}

		self::$rendered = true;
		wc_get_template( 'myaccount/edit-order.php', $panel, 'edit-orders-for-woocommerce/', EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/' );
	}

	/**
	 * Everything the panel template needs, or null when there is nothing to show.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Order key for guests, or empty.
	 * @return array|null
	 */
	public static function panel_args( WC_Order $order, $key ) {
		$state   = self::$state;
		$message = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a one-time message token, read only.
		$token = isset( $_GET['eofw_msg'] ) ? sanitize_key( wp_unslash( $_GET['eofw_msg'] ) ) : '';
		if ( $token ) {
			$message = (string) get_transient( 'eofw_msg_' . $token );
			delete_transient( 'eofw_msg_' . $token );
		}

		$actions = array();
		foreach ( Edit_Orders_For_WooCommerce_Customer_Rules::enabled_actions() as $action ) {
			if ( true === Edit_Orders_For_WooCommerce_Customer_Rules::can( $order, $action, '' !== $key ? 'guest' : 'customer' ) ) {
				$actions[] = $action;
			}
		}

		$pending = 'pending' === $order->get_meta( Edit_Orders_For_WooCommerce_Cancellation::STATUS_META );

		if ( ! $actions && ! $state && '' === $message && ! $pending ) {
			return null;
		}

		return array(
			'order'           => $order,
			'order_key'       => $key,
			'actions'         => $actions,
			'seconds_left'    => Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $order ),
			'cancel_pending'  => $pending,
			'cancel_mode'     => Edit_Orders_For_WooCommerce_Settings::get( 'cancel_mode' ),
			'reasons'         => Edit_Orders_For_WooCommerce_Settings::cancel_reasons(),
			'reason_required' => Edit_Orders_For_WooCommerce_Settings::is_on( 'cancel_reason_required' ),
			'policy'          => (string) Edit_Orders_For_WooCommerce_Settings::get( 'cancel_policy' ),
			'note_max'        => (int) Edit_Orders_For_WooCommerce_Settings::get( 'note_max_length' ),
			'swap_options'    => in_array( 'swap', $actions, true ) ? self::swap_options( $order ) : array(),
			'state'           => $state,
			'message'         => $message,
		);
	}

	/**
	 * Variation choices for each line that is a variation: in stock, same product.
	 *
	 * @param WC_Order $order Order.
	 * @return array[] item ID => { name, current, options: variation ID => label }
	 */
	private static function swap_options( WC_Order $order ) {
		$options = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item->get_variation_id() || is_wp_error( Edit_Orders_For_WooCommerce_Eligibility::check_item( $order, $item ) ) ) {
				continue;
			}
			$parent = wc_get_product( $item->get_product_id() );
			if ( ! $parent ) {
				continue;
			}

			$choices = array();
			foreach ( $parent->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				$current   = (int) $variation_id === (int) $item->get_variation_id();
				if ( ! $variation ) {
					continue;
				}
				// get_children() includes disabled (private) variations: only offer what can be bought.
				$available = 'publish' === $variation->get_status() && $variation->is_purchasable() && $variation->is_in_stock() && ( ! $variation->managing_stock() || $variation->has_enough_stock( $item->get_quantity() ) );
				if ( ! $current && ! $available ) {
					continue;
				}
				$choices[ $variation_id ] = html_entity_decode( wc_get_formatted_variation( $variation, true, false, false ) . ' (' . wp_strip_all_tags( wc_price( Edit_Orders_For_WooCommerce_Pricing::current_price( $variation ), array( 'currency' => $order->get_currency() ) ) ) . ')' );
			}

			if ( count( $choices ) > 1 ) {
				$options[ $item_id ] = array(
					'name'    => $parent->get_name(),
					'current' => (int) $item->get_variation_id(),
					'options' => $choices,
				);
			}
		}

		return $options;
	}

	/**
	 * "Change or cancel" in the My Account orders list.
	 *
	 * @param array    $actions Actions.
	 * @param WC_Order $order   Order.
	 * @return array
	 */
	public static function order_action( $actions, $order ) {
		foreach ( Edit_Orders_For_WooCommerce_Customer_Rules::enabled_actions() as $action ) {
			if ( true === Edit_Orders_For_WooCommerce_Customer_Rules::can( $order, $action ) ) {
				$actions['edit_order'] = array(
					'url'  => $order->get_view_order_url() . '#edit-order',
					'name' => __( 'Change or cancel', 'edit-orders-for-woocommerce' ),
				);
				break;
			}
		}

		return $actions;
	}

	/**
	 * A link to change or cancel, in the customer's order confirmation emails.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Sent to admin.
	 * @param bool     $plain_text    Plain text.
	 * @param WC_Email $email         Email.
	 */
	public static function email_link( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! $email || ! in_array( $email->id, array( 'customer_processing_order', 'customer_on_hold_order' ), true ) ) {
			return;
		}

		$available = false;
		foreach ( Edit_Orders_For_WooCommerce_Customer_Rules::enabled_actions() as $action ) {
			if ( true === Edit_Orders_For_WooCommerce_Customer_Rules::can( $order, $action ) ) {
				$available = true;
				break;
			}
		}
		if ( ! $available ) {
			return;
		}

		$url  = self::panel_url( $order );
		$text = sprintf(
			/* translators: %s: date and time the window closes. */
			__( 'Need to change or cancel this order? You can do it until %s.', 'edit-orders-for-woocommerce' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() + Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $order ) )
		);

		if ( $plain_text ) {
			echo "\n" . esc_html( $text ) . "\n" . esc_url_raw( $url ) . "\n\n";
			return;
		}

		echo '<p>' . esc_html( $text ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Change or cancel your order', 'edit-orders-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * Where a customer changes their order: My Account for account holders, the
	 * thank-you page with the order key for guests.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function panel_url( WC_Order $order ) {
		$url = $order->get_customer_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();

		return $url . '#edit-order';
	}

	/**
	 * Hidden inputs that repeat a previewed form, so the customer can confirm it.
	 *
	 * @param array  $data   Posted data.
	 * @param string $prefix Name prefix for nested arrays.
	 * @return string HTML.
	 */
	public static function hidden_fields( array $data, $prefix = '' ) {
		$html = '';
		foreach ( $data as $name => $value ) {
			if ( '' === $prefix && in_array( $name, array( '_eofw_nonce', '_wp_http_referer', 'step', 'order_id', 'order_key', 'edit_orders_for_woocommerce_action' ), true ) ) {
				continue;
			}
			$field = '' === $prefix ? (string) $name : $prefix . '[' . $name . ']';
			if ( is_array( $value ) ) {
				$html .= self::hidden_fields( $value, $field );
			} else {
				$html .= '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $value ) . '" />';
			}
		}

		return $html;
	}

	/**
	 * The fields every customer form carries: nonce, order and key.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $order_key Order key for guests, or empty.
	 * @param string   $action    Action name.
	 * @return string HTML.
	 */
	public static function form_fields( WC_Order $order, $order_key, $action ) {
		return wp_nonce_field( self::NONCE, '_eofw_nonce', false, false )
			. '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />'
			. '<input type="hidden" name="order_key" value="' . esc_attr( $order_key ) . '" />'
			. '<input type="hidden" name="edit_orders_for_woocommerce_action" value="' . esc_attr( $action ) . '" />';
	}

	/**
	 * Success result.
	 *
	 * @param string $message Message.
	 * @param string $action  Action.
	 * @return array
	 */
	private static function success( $message, $action = '' ) {
		return array(
			'type'     => 'success',
			'action'   => $action,
			'message'  => $message,
			'redirect' => '',
			'preview'  => null,
		);
	}

	/**
	 * Error result.
	 *
	 * @param string $message Message.
	 * @param string $action  Action.
	 * @return array
	 */
	private static function error( $message, $action = '' ) {
		return array(
			'type'     => 'error',
			'action'   => $action,
			'message'  => $message,
			'redirect' => '',
			'preview'  => null,
		);
	}
}
