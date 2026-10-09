<?php
/**
 * Which orders and lines may be edited (spec section 2.3).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Eligibility Class.
 */
class Edit_Orders_For_WooCommerce_Eligibility {

	/**
	 * Statuses whose paid orders can be edited, from the settings. Final and
	 * unpaid statuses are never allowed, whatever is saved.
	 *
	 * @return string[]
	 */
	public static function editable_statuses() {
		$statuses = array_diff(
			array_map( 'sanitize_key', (array) Edit_Orders_For_WooCommerce_Settings::get( 'editable_statuses' ) ),
			array( 'pending', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' )
		);

		return $statuses ? array_values( $statuses ) : array( 'processing', 'on-hold' );
	}

	/**
	 * Can this order be edited at all?
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $action Action name, passed to the filter.
	 * @param string   $actor  `staff`, `customer` or `guest`.
	 * @return true|WP_Error
	 */
	public static function check_order( WC_Order $order, $action = 'edit', $actor = 'staff' ) {
		$result = true;

		if ( 'staff' === $actor && ! Edit_Orders_For_WooCommerce_Settings::is_on( 'admin_enabled' ) ) {
			$result = new WP_Error( 'edit_orders_for_woocommerce_admin_off', __( 'Editing paid orders is turned off in WooCommerce > Settings > Edit Orders.', 'edit-orders-for-woocommerce' ) );
		} elseif ( ! in_array( $order->get_status(), self::editable_statuses(), true ) ) {
			$result = new WP_Error(
				'edit_orders_for_woocommerce_status',
				sprintf(
					/* translators: %s: list of order statuses. */
					__( 'Only orders in these statuses can be edited: %s.', 'edit-orders-for-woocommerce' ),
					implode( ', ', array_map( 'wc_get_order_status_name', self::editable_statuses() ) )
				)
			);
		} elseif ( ! $order->get_date_paid() && ! self::is_pay_on_delivery( $order ) ) {
			// Unpaid orders are edited with WooCommerce's own editor. Pay-on-delivery
			// orders are the exception: Processing while unpaid, and locked by core.
			$result = new WP_Error( 'edit_orders_for_woocommerce_unpaid', __( 'This order is not paid yet. Edit it with the WooCommerce order editor.', 'edit-orders-for-woocommerce' ) );
		} elseif ( $order->get_remaining_refund_amount() <= 0 ) {
			$result = new WP_Error( 'edit_orders_for_woocommerce_refunded', __( 'This order is fully refunded.', 'edit-orders-for-woocommerce' ) );
		} elseif ( Edit_Orders_For_WooCommerce_Balance_Orders::get_open_balance_order( $order ) ) {
			$result = new WP_Error( 'edit_orders_for_woocommerce_open_balance', __( 'This order has an unpaid balance order. Cancel it or wait for payment before editing again.', 'edit-orders-for-woocommerce' ) );
		} elseif ( Edit_Orders_For_WooCommerce_Balance_Orders::is_applying( $order ) ) {
			$result = new WP_Error( 'edit_orders_for_woocommerce_applying', __( 'A balance order for this order was just paid and its changes are being applied. Try again in a moment.', 'edit-orders-for-woocommerce' ) );
		} elseif ( self::is_authorization_only( $order ) ) {
			// A refund would void the whole authorization (Stripe does this).
			$result = new WP_Error( 'edit_orders_for_woocommerce_uncaptured', __( 'This payment is authorized but not captured yet. Capture it before editing.', 'edit-orders-for-woocommerce' ) );
		} elseif ( 'staff' === $actor && Edit_Orders_For_WooCommerce_Lock::held_by_other( $order, get_current_user_id() ) ) {
			$holder = get_userdata( Edit_Orders_For_WooCommerce_Lock::holder( $order ) );
			$result = new WP_Error(
				'edit_orders_for_woocommerce_locked',
				/* translators: %s: name of the user editing the order. */
				sprintf( __( '%s is editing this order. Try again when they are done.', 'edit-orders-for-woocommerce' ), $holder ? $holder->display_name : __( 'Another user', 'edit-orders-for-woocommerce' ) )
			);
		}

		/**
		 * Final say on whether an order can be edited.
		 *
		 * @since 1.0.0
		 *
		 * @param true|WP_Error $result True when allowed.
		 * @param WC_Order      $order  Order.
		 * @param string        $action Action name.
		 * @param string        $actor  `staff`, `customer` or `guest`.
		 */
		return apply_filters( 'edit_orders_for_woocommerce_can_edit', $result, $order, $action, $actor );
	}

	/**
	 * Can this line be changed?
	 *
	 * @param WC_Order      $order Order.
	 * @param WC_Order_Item $item  Line.
	 * @return true|WP_Error
	 */
	public static function check_item( WC_Order $order, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_order_id() !== $order->get_id() ) {
			return new WP_Error( 'edit_orders_for_woocommerce_item', __( 'That item is not on this order.', 'edit-orders-for-woocommerce' ) );
		}

		if ( 0 !== (int) $order->get_qty_refunded_for_item( $item->get_id() ) || 0.0 !== (float) $order->get_total_refunded_for_item( $item->get_id() ) ) {
			/* translators: %s: product name. */
			return new WP_Error( 'edit_orders_for_woocommerce_item_refunded', sprintf( __( '"%s" already has a refund, so it can no longer be changed.', 'edit-orders-for-woocommerce' ), $item->get_name() ) );
		}

		// Part of this line was paid on a balance order (extra units or a price difference):
		// changing it here would refund only the original part. Version 1 refuses it.
		$balance_id = (int) $item->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::LINE_META );
		if ( $balance_id ) {
			$balance = wc_get_order( $balance_id );
			return new WP_Error(
				'edit_orders_for_woocommerce_item_balance',
				sprintf(
					/* translators: 1: product name, 2: balance order number. */
					__( 'Part of "%1$s" was paid on balance order #%2$s, so it can no longer be changed here. Refund that part from balance order #%2$s.', 'edit-orders-for-woocommerce' ),
					$item->get_name(),
					$balance ? $balance->get_order_number() : $balance_id
				)
			);
		}

		if ( ! $item->get_product() ) {
			/* translators: %s: product name. */
			return new WP_Error( 'edit_orders_for_woocommerce_item_product', sprintf( __( 'The product for "%s" no longer exists.', 'edit-orders-for-woocommerce' ), $item->get_name() ) );
		}

		return true;
	}

	/**
	 * Is this an unpaid pay-on-delivery order?
	 *
	 * Cash on delivery orders sit in Processing with no paid date until the
	 * store marks them Completed (core sets the paid date then). Edits to them
	 * change the amount to collect instead of moving money.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_pay_on_delivery( WC_Order $order ) {
		/**
		 * Payment methods paid on delivery.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $gateways Gateway IDs.
		 */
		$gateways = (array) apply_filters( 'edit_orders_for_woocommerce_pay_on_delivery_gateways', array( 'cod' ) );

		return ! $order->get_date_paid() && in_array( $order->get_payment_method(), $gateways, true );
	}

	/**
	 * Was the payment authorized but not captured?
	 *
	 * Stripe records `_stripe_charge_captured = no`. Other gateways can report it
	 * through the filter.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_authorization_only( WC_Order $order ) {
		$uncaptured = 'no' === $order->get_meta( '_stripe_charge_captured' );

		/**
		 * Whether the order's payment is authorized but not captured.
		 *
		 * @since 1.0.0
		 *
		 * @param bool     $uncaptured True when not captured.
		 * @param WC_Order $order      Order.
		 */
		return (bool) apply_filters( 'edit_orders_for_woocommerce_is_authorization_only', $uncaptured, $order );
	}
}
