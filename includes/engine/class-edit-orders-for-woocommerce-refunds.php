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

		$gateway_id = $order->get_payment_method();
		$context    = array(
			'order'    => $order->get_id(),
			'amount'   => $args['amount'],
			'currency' => $order->get_currency(),
			'gateway'  => $gateway_id,
			'lines'    => self::lines_summary( $order, $refund_lines ),
		);
		Edit_Orders_For_WooCommerce_Log::info(
			sprintf(
				'order %d: refund requested: %s via %s (can refund: %s)%s',
				$order->get_id(),
				Edit_Orders_For_WooCommerce_Log::money( $amount, $order->get_currency() ),
				'' !== $gateway_id ? $gateway_id : 'no payment method',
				$can_send ? 'yes' : 'no',
				$on_delivery ? ', collected on delivery so no money moves' : ''
			),
			$context
		);

		$gateway_error = '';
		$refund        = wc_create_refund( $args );

		if ( is_wp_error( $refund ) && $can_send ) {
			// wc_create_refund() deleted the refund record when the gateway failed; record it
			// without payment, and without telling the customer it was refunded.
			$gateway_error = $refund->get_error_message();
			Edit_Orders_For_WooCommerce_Log::warning(
				sprintf( 'order %d: gateway %s refused the refund of %s: %s. Recording it without payment.', $order->get_id(), $gateway_id, Edit_Orders_For_WooCommerce_Log::money( $amount, $order->get_currency() ), rtrim( Edit_Orders_For_WooCommerce_Log::clip( $gateway_error ), '. ' ) ),
				array_merge( $context, array( 'gateway_error' => Edit_Orders_For_WooCommerce_Log::clip( $gateway_error ) ) )
			);
			$args['refund_payment'] = false;
			$quiet();
			$refund = wc_create_refund( $args );
		}
		remove_filter( 'woocommerce_email_enabled_customer_refunded_order', $no_email );
		remove_filter( 'woocommerce_email_enabled_customer_partially_refunded_order', $no_email );

		if ( is_wp_error( $refund ) ) {
			// The caller logs it as an error, with what else the run did.
			return $refund;
		}

		$transaction = self::transaction_id( $refund );
		Edit_Orders_For_WooCommerce_Log::info(
			sprintf(
				'order %d: refund #%d recorded: %s, money sent through %s: %s%s',
				$order->get_id(),
				$refund->get_id(),
				Edit_Orders_For_WooCommerce_Log::money( $amount, $order->get_currency() ),
				'' !== $gateway_id ? $gateway_id : 'no payment method',
				$refund->get_refunded_payment() ? 'yes' : 'no',
				'' !== $transaction ? ', transaction ' . $transaction : ''
			),
			array_merge(
				$context,
				array(
					'refund_id'      => $refund->get_id(),
					'refunded'       => (bool) $refund->get_refunded_payment(),
					'transaction_id' => $transaction,
				)
			)
		);

		$manual = ! $on_delivery && (float) $amount > 0 && ( ! $can_send || '' !== $gateway_error );

		if ( $manual ) {
			self::flag_manual_refund( $order, $args['amount'], $refund->get_id(), $gateway_error );
		} elseif ( $on_delivery ) {
			$order   = wc_get_order( $order->get_id() );
			$collect = (float) $order->get_total() - (float) $order->get_total_refunded();
			$order->add_order_note(
				/* translators: %s: amount. */
				sprintf( __( 'Amount to collect on delivery is now %s.', 'wpheka-edit-orders-for-woocommerce' ), wc_price( $collect, array( 'currency' => $order->get_currency() ) ) )
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
	 * Refund lines for the log: product, quantity back to stock and amounts per item ID.
	 *
	 * @param WC_Order $order        Order.
	 * @param array[]  $refund_lines Refund lines keyed by item ID.
	 * @return array[]
	 */
	private static function lines_summary( WC_Order $order, array $refund_lines ) {
		$lines = array();
		foreach ( $refund_lines as $item_id => $line ) {
			$item              = $order->get_item( $item_id );
			$lines[ $item_id ] = array(
				'product' => $item instanceof WC_Order_Item_Product ? ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() ) : 0,
				'qty'     => isset( $line['qty'] ) ? (int) $line['qty'] : 0,
				'total'   => isset( $line['refund_total'] ) ? (float) $line['refund_total'] : 0.0,
				'tax'     => isset( $line['refund_tax'] ) ? array_sum( array_map( 'floatval', (array) $line['refund_tax'] ) ) : 0.0,
			);
		}

		return $lines;
	}

	/**
	 * The gateway's ID for a refund, so it can be found in the gateway's dashboard.
	 * WooCommerce has no field for it: gateways that keep one store it on the refund.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @return string Empty when the gateway keeps none.
	 */
	private static function transaction_id( $refund ) {
		/**
		 * Refund meta keys that may hold the gateway's refund ID, checked in order.
		 *
		 * @since 1.1.0
		 *
		 * @param string[]        $keys   Meta keys. Defaults: `_transaction_id` and Stripe's `_stripe_refund_id`.
		 * @param WC_Order_Refund $refund Refund.
		 */
		$keys = (array) apply_filters( 'edit_orders_for_woocommerce_refund_transaction_meta_keys', array( '_transaction_id', '_stripe_refund_id' ), $refund );
		foreach ( $keys as $key ) {
			$value = $refund->get_meta( (string) $key );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return Edit_Orders_For_WooCommerce_Log::clip( (string) $value );
			}
		}

		return '';
	}

	/**
	 * Flag an order for a refund the store owner must make by hand.
	 *
	 * @param WC_Order $order         Order.
	 * @param string   $amount        Amount.
	 * @param int      $refund_id     WooCommerce refund record.
	 * @param string   $gateway_error Why the gateway didn't refund, if it tried.
	 * @param string   $reason        Why a refund is owed when no refund was recorded (no record, money only).
	 * @param string   $code          Short code for the log saying why (since 1.1.0).
	 */
	public static function flag_manual_refund( WC_Order $order, $amount, $refund_id, $gateway_error, $reason = '', $code = '' ) {
		if ( '' === $code ) {
			$code = '' !== $gateway_error ? 'gateway_refused' : ( '' !== $reason ? 'owed_without_refund' : 'no_automatic_refunds' );
		}
		Edit_Orders_For_WooCommerce_Log::warning(
			sprintf( 'order %d: manual refund flagged: %s (%s)%s', $order->get_id(), Edit_Orders_For_WooCommerce_Log::money( $amount, $order->get_currency() ), $code, $refund_id ? ', refund #' . (int) $refund_id . ' recorded without payment' : '' ),
			array(
				'order'     => $order->get_id(),
				'amount'    => $amount,
				'currency'  => $order->get_currency(),
				'code'      => $code,
				'refund_id' => (int) $refund_id,
			)
		);

		$order = wc_get_order( $order->get_id() );
		$total = (float) $order->get_meta( self::MANUAL_REFUND_META ) + (float) $amount;
		$money = wc_price( $amount, array( 'currency' => $order->get_currency() ) );

		if ( '' !== $reason ) {
			/* translators: 1: amount, 2: reason. */
			$note = sprintf( __( 'Manual refund needed: %1$s. %2$s', 'wpheka-edit-orders-for-woocommerce' ), $money, $reason );
		} elseif ( '' !== $gateway_error ) {
			/* translators: 1: amount, 2: gateway error. */
			$note = sprintf( __( 'Manual refund needed: %1$s. The payment gateway refused the refund: %2$s', 'wpheka-edit-orders-for-woocommerce' ), $money, $gateway_error );
		} else {
			/* translators: %s: amount. */
			$note = sprintf( __( 'Manual refund needed: %s. This payment method cannot refund automatically.', 'wpheka-edit-orders-for-woocommerce' ), $money );
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

	/**
	 * Take back a manual refund flagged by mistake, with a note saying why.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $amount Amount flagged.
	 * @param string   $note   Why it is not needed.
	 */
	public static function clear_manual_refund( WC_Order $order, $amount, $note ) {
		Edit_Orders_For_WooCommerce_Log::warning(
			sprintf( 'order %d: manual refund flag of %s taken back: it is not needed after all', $order->get_id(), Edit_Orders_For_WooCommerce_Log::money( $amount, $order->get_currency() ) ),
			array(
				'order'    => $order->get_id(),
				'amount'   => $amount,
				'currency' => $order->get_currency(),
			)
		);

		$order = wc_get_order( $order->get_id() );
		$left  = (float) $order->get_meta( self::MANUAL_REFUND_META ) - (float) $amount;

		if ( $left > 0.001 ) {
			$order->update_meta_data( self::MANUAL_REFUND_META, wc_format_decimal( $left, wc_get_price_decimals() ) );
		} else {
			$order->delete_meta_data( self::MANUAL_REFUND_META );
		}
		$order->add_order_note( $note );
		$order->save();
	}
}
