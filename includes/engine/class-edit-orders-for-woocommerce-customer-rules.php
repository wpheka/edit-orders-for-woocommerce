<?php
/**
 * What a customer may do to their own order, and for how long (spec sections 4.2 to 4.4).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Customer_Rules Class.
 *
 * Everything here is re-checked on the server when a change is saved, so a page
 * left open past the window, or an order shipped in the meantime, is refused.
 */
class Edit_Orders_For_WooCommerce_Customer_Rules {

	/**
	 * Order meta: set when the customer closes the window early ("I'm done").
	 */
	const WINDOW_CLOSED_META = '_edit_orders_for_woocommerce_window_closed';

	/**
	 * Failed key checks allowed per IP per hour.
	 */
	const MAX_FAILED_KEYS = 10;

	/**
	 * Customer actions allowed per order per hour.
	 */
	const MAX_ACTIONS = 20;

	/**
	 * When the window started: payment, or creation for an unpaid pay-on-delivery order.
	 *
	 * @param WC_Order $order Order.
	 * @return int Timestamp, or 0.
	 */
	public static function window_start( WC_Order $order ) {
		$date = $order->get_date_paid();
		if ( ! $date && Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $order ) ) {
			$date = $order->get_date_created();
		}

		return $date ? $date->getTimestamp() : 0;
	}

	/**
	 * Seconds left in the window, or 0 when it is closed.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function seconds_left( WC_Order $order ) {
		$start = self::window_start( $order );
		if ( ! $start || $order->get_meta( self::WINDOW_CLOSED_META ) ) {
			return 0;
		}

		return max( 0, $start + Edit_Orders_For_WooCommerce_Settings::window_seconds() - time() );
	}

	/**
	 * Has the order shipped (completed, a tracking number, or WooCommerce fulfilments)?
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_shipped( WC_Order $order ) {
		$shipped = $order->has_status( 'completed' );

		if ( ! $shipped ) {
			/**
			 * Order meta keys that mean a tracking number was added.
			 *
			 * Defaults cover WooCommerce Shipment Tracking, Advanced Shipment Tracking
			 * (both `_wc_shipment_tracking_items`), AfterShip and generic `_tracking_number`.
			 *
			 * @since 0.1.0
			 *
			 * @param string[] $keys Meta keys.
			 */
			$keys = (array) apply_filters( 'edit_orders_for_woocommerce_tracking_meta_keys', array( '_wc_shipment_tracking_items', '_aftership_tracking_number', '_tracking_number' ) );
			foreach ( $keys as $key ) {
				if ( $order->get_meta( $key ) ) {
					$shipped = true;
					break;
				}
			}
		}

		// WooCommerce Fulfillments (beta): read it when present, don't depend on it.
		if ( ! $shipped && in_array( $order->get_meta( '_fulfillment_status' ), array( 'fulfilled', 'partially_fulfilled' ), true ) ) {
			$shipped = true;
		}

		/**
		 * Whether an order counts as shipped, which stops customer changes.
		 *
		 * @since 0.1.0
		 *
		 * @param bool     $shipped Shipped.
		 * @param WC_Order $order   Order.
		 */
		return (bool) apply_filters( 'edit_orders_for_woocommerce_is_shipped', $shipped, $order );
	}

	/**
	 * The customer actions turned on in the settings.
	 *
	 * @return string[] Any of cancel, address, note, swap.
	 */
	public static function enabled_actions() {
		$actions = array();
		foreach ( array( 'cancel', 'address', 'note', 'swap' ) as $action ) {
			if ( Edit_Orders_For_WooCommerce_Settings::is_on( 'action_' . $action ) ) {
				$actions[] = $action;
			}
		}

		/**
		 * Customer actions offered. Pro adds more.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $actions Actions.
		 */
		return (array) apply_filters( 'edit_orders_for_woocommerce_customer_actions', $actions );
	}

	/**
	 * Can the customer use this action on this order now?
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $action cancel, address, note, swap or close.
	 * @param string   $actor  customer or guest.
	 * @return true|WP_Error
	 */
	public static function can( WC_Order $order, $action, $actor = 'customer' ) {
		if ( ! Edit_Orders_For_WooCommerce_Settings::is_on( 'customer_enabled' ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_customer_off', __( 'Changes to orders are not available.', 'edit-orders-for-woocommerce' ) );
		}

		if ( 'close' !== $action && ! in_array( $action, self::enabled_actions(), true ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_action_off', __( 'That change is not available.', 'edit-orders-for-woocommerce' ) );
		}

		if ( ! in_array( $order->get_status(), Edit_Orders_For_WooCommerce_Eligibility::editable_statuses(), true ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_status', __( 'This order can no longer be changed.', 'edit-orders-for-woocommerce' ) );
		}

		if ( self::is_shipped( $order ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_shipped', __( 'This order has already shipped, so it can no longer be changed.', 'edit-orders-for-woocommerce' ) );
		}

		if ( 0 === self::seconds_left( $order ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_window', __( 'The time for changing this order has passed. Contact us if you need help.', 'edit-orders-for-woocommerce' ) );
		}

		if ( 'cancel' === $action && 'pending' === $order->get_meta( Edit_Orders_For_WooCommerce_Cancellation::STATUS_META ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_cancel_pending', __( 'Your cancellation request is waiting for the store.', 'edit-orders-for-woocommerce' ) );
		}

		// Cancelling is allowed with an open balance order (it is cancelled too); edits aren't.
		if ( in_array( $action, array( 'address', 'swap' ), true ) ) {
			$eligible = Edit_Orders_For_WooCommerce_Eligibility::check_order( $order, $action, $actor );
			if ( is_wp_error( $eligible ) ) {
				return $eligible;
			}
		}

		/**
		 * Final say on a customer action.
		 *
		 * @since 0.1.0
		 *
		 * @param true|WP_Error $result True when allowed.
		 * @param WC_Order      $order  Order.
		 * @param string        $action Action.
		 * @param string        $actor  customer or guest.
		 */
		return apply_filters( 'edit_orders_for_woocommerce_customer_can', true, $order, $action, $actor );
	}

	/**
	 * Who may act on an order: its customer when logged in, or, for a guest order,
	 * anyone holding the order key.
	 *
	 * An order placed from an account needs that account, as WooCommerce's own
	 * order pages do: the key travels in URLs (history, referrers), so on its own
	 * it isn't enough to redirect or cancel someone's order.
	 *
	 * @param WC_Order|false $order Order.
	 * @param string         $key   Order key sent with the request (guests).
	 * @return string|WP_Error `customer` or `guest`; a 404-style error otherwise.
	 */
	public static function authorize( $order, $key ) {
		$not_found = new WP_Error( 'edit_orders_for_woocommerce_not_found', __( 'Order not found.', 'edit-orders-for-woocommerce' ), array( 'status' => 404 ) );

		if ( ! $order instanceof WC_Order || Edit_Orders_For_WooCommerce_Balance_Orders::is_balance_order( $order ) ) {
			return $not_found;
		}

		$user_id     = get_current_user_id();
		$customer_id = (int) $order->get_customer_id();
		if ( $user_id && $customer_id === $user_id ) {
			return 'customer';
		}

		$key = wc_clean( (string) $key );
		if ( ! $customer_id && '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			return 'guest';
		}

		if ( $customer_id && '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			// The right key for an account order: not a guessing attempt, so don't count it.
			return new WP_Error( 'edit_orders_for_woocommerce_login', __( 'Please log in to your account to change this order.', 'edit-orders-for-woocommerce' ), array( 'status' => 403 ) );
		}

		self::count_failed_key();

		return $not_found;
	}

	/**
	 * Has this visitor failed the key check too often?
	 *
	 * @return bool
	 */
	public static function is_rate_limited() {
		return (int) get_transient( self::failed_key_transient() ) >= self::MAX_FAILED_KEYS;
	}

	/**
	 * Count an action on an order; false once the hourly limit is reached.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function count_action( WC_Order $order ) {
		$transient = 'eofw_actions_' . $order->get_id();
		$count     = (int) get_transient( $transient );
		if ( $count >= self::MAX_ACTIONS ) {
			return false;
		}
		set_transient( $transient, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Count a failed key check for this visitor's IP.
	 */
	private static function count_failed_key() {
		$transient = self::failed_key_transient();
		set_transient( $transient, (int) get_transient( $transient ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Transient name for this visitor's failed key checks.
	 *
	 * @return string
	 */
	private static function failed_key_transient() {
		return 'eofw_badkey_' . md5( WC_Geolocation::get_ip_address() );
	}
}
