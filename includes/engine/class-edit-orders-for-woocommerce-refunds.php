<?php
/**
 * Refunds with a safe fallback (spec section 2.1 rule 2).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Refunds Class.
 */
class Edit_Orders_For_WooCommerce_Refunds {

	/**
	 * Order meta holding the amount still to refund by hand.
	 */
	const MANUAL_REFUND_META = '_edit_orders_for_woocommerce_manual_refund';

	/**
	 * Create a WooCommerce refund with line items and restock.
	 *
	 * The money goes back through the gateway when it supports refunds. If the
	 * gateway refuses, or can't refund, the refund is recorded without a payment
	 * so stock and quantities stay right, and the order is flagged for a manual
	 * refund. It never claims a refund happened when it didn't.
	 *
	 * Two cases move no money:
	 * - an unpaid pay-on-delivery order: the refund lowers what is collected on delivery;
	 * - a zero net amount, where a refund only re-books tax between rates (address changes).
	 *
	 * WooCommerce's "your order has been refunded" email goes out only when money is
	 * sent back: a refund that is only recorded would tell the customer something
	 * that didn't happen. Callers with their own email (cancellation) turn it off.
	 *
	 * @param WC_Order $order          Order.
	 * @param array[]  $refund_lines   Refund lines keyed by item ID.
	 * @param float    $amount         Total, tax included.
	 * @param string   $reason         Reason shown on the refund.
	 * @param bool     $customer_email Allow WooCommerce's refunded email when money is sent back.
	 * @return array|WP_Error { refund_id, manual, gateway_error, on_delivery }
	 */
	public static function refund( WC_Order $order, array $refund_lines, $amount, $reason, $customer_email = true ) {
		$on_delivery = Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $order );
		$gateway     = wc_get_payment_gateway_by_order( $order );
		$can_send    = ! $on_delivery && (float) $amount > 0 && $gateway && $gateway->supports( 'refunds' );

		$args = array(
			'amount'         => wc_format_decimal( $amount, wc_get_price_decimals() ),
			'reason'         => $reason,
			'order_id'       => $order->get_id(),
			'line_items'     => $refund_lines,
			'refund_payment' => $can_send,
			'restock_items'  => true,
		);

		// WooCommerce's refunded email checks a different filter for a partial refund.
		$no_email = function () {
			return false;
		};
		$quiet    = function () use ( $no_email ) {
			add_filter( 'woocommerce_email_enabled_customer_refunded_order', $no_email );
			add_filter( 'woocommerce_email_enabled_customer_partially_refunded_order', $no_email );
		};
		if ( ! $customer_email || ! $can_send ) {
			$quiet();
		}

		$gateway_error = '';
		$refund        = wc_create_refund( $args );

		if ( is_wp_error( $refund ) && $can_send ) {
			// wc_create_refund() deleted the refund record when the gateway failed; record it
			// without payment, and without telling the customer it was refunded.
			$gateway_error          = $refund->get_error_message();
			$args['refund_payment'] = false;
			$quiet();
			$refund = wc_create_refund( $args );
		}
		remove_filter( 'woocommerce_email_enabled_customer_refunded_order', $no_email );
		remove_filter( 'woocommerce_email_enabled_customer_partially_refunded_order', $no_email );

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		$manual = ! $on_delivery && (float) $amount > 0 && ( ! $can_send || '' !== $gateway_error );

		if ( $manual ) {
			self::flag_manual_refund( $order, $args['amount'], $refund->get_id(), $gateway_error );
		} elseif ( $on_delivery ) {
			$order   = wc_get_order( $order->get_id() );
			$collect = (float) $order->get_total() - (float) $order->get_total_refunded();
			$order->add_order_note(
				/* translators: %s: amount. */
				sprintf( __( 'Amount to collect on delivery is now %s.', 'edit-orders-for-woocommerce' ), wc_price( $collect, array( 'currency' => $order->get_currency() ) ) )
			);
		}

		return array(
			'refund_id'     => $refund->get_id(),
			'manual'        => $manual,
			'gateway_error' => $gateway_error,
			'on_delivery'   => $on_delivery,
		);
	}

	/**
	 * Flag an order for a refund the store owner must make by hand.
	 *
	 * @param WC_Order $order         Order.
	 * @param string   $amount        Amount.
	 * @param int      $refund_id     WooCommerce refund record.
	 * @param string   $gateway_error Why the gateway didn't refund, if it tried.
	 * @param string   $reason        Why a refund is owed when no refund was recorded (no record, money only).
	 */
	public static function flag_manual_refund( WC_Order $order, $amount, $refund_id, $gateway_error, $reason = '' ) {
		$order = wc_get_order( $order->get_id() );
		$total = (float) $order->get_meta( self::MANUAL_REFUND_META ) + (float) $amount;
		$money = wc_price( $amount, array( 'currency' => $order->get_currency() ) );

		if ( '' !== $reason ) {
			/* translators: 1: amount, 2: reason. */
			$note = sprintf( __( 'Manual refund needed: %1$s. %2$s', 'edit-orders-for-woocommerce' ), $money, $reason );
		} elseif ( '' !== $gateway_error ) {
			/* translators: 1: amount, 2: gateway error. */
			$note = sprintf( __( 'Manual refund needed: %1$s. The payment gateway refused the refund: %2$s', 'edit-orders-for-woocommerce' ), $money, $gateway_error );
		} else {
			/* translators: %s: amount. */
			$note = sprintf( __( 'Manual refund needed: %s. This payment method cannot refund automatically.', 'edit-orders-for-woocommerce' ), $money );
		}

		$order->update_meta_data( self::MANUAL_REFUND_META, wc_format_decimal( $total, wc_get_price_decimals() ) );
		$order->add_order_note( $note );
		$order->save();

		/**
		 * Fired when an edit needs a refund the store owner must make by hand.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Order $order         Order.
		 * @param string   $amount        Amount.
		 * @param int      $refund_id     WooCommerce refund record.
		 * @param string   $gateway_error Gateway error, or empty when the gateway can't refund.
		 * @param string   $reason        Set when no refund was recorded: why the money is owed.
		 */
		do_action( 'edit_orders_for_woocommerce_manual_refund_needed', $order, $amount, $refund_id, $gateway_error, $reason );
	}
}
