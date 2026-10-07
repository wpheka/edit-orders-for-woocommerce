<?php
/**
 * Customer cancellation: instant, or a request the store approves or declines (spec section 4.4).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Cancellation Class.
 */
class Edit_Orders_For_WooCommerce_Cancellation {

	/**
	 * Order meta: pending, approved or declined. A plain value so pending requests can be queried.
	 */
	const STATUS_META = '_edit_orders_for_woocommerce_cancel_status';

	/**
	 * Order meta: reason, who asked and when.
	 */
	const REQUEST_META = '_edit_orders_for_woocommerce_cancel_request';

	/**
	 * A customer asks to cancel. In instant mode the order is cancelled now.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Reason.
	 * @param string   $actor  customer or guest.
	 * @return array|WP_Error { status: requested|cancelled, refund }
	 */
	public static function request( WC_Order $order, $reason, $actor ) {
		$reason = sanitize_textarea_field( $reason );

		if ( '' === trim( $reason ) && Edit_Orders_For_WooCommerce_Settings::is_on( 'cancel_reason_required' ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_reason', __( 'Please tell us why you want to cancel.', 'edit-orders-for-woocommerce' ) );
		}

		$order->update_meta_data(
			self::REQUEST_META,
			array(
				'reason' => $reason,
				'actor'  => $actor,
				'time'   => time(),
			)
		);

		if ( 'instant' === Edit_Orders_For_WooCommerce_Settings::get( 'cancel_mode' ) ) {
			$order->save();
			return self::execute( $order, $actor, $reason );
		}

		$order->update_meta_data( self::STATUS_META, 'pending' );
		/* translators: %s: reason. */
		$order->add_order_note( sprintf( __( 'The customer asked to cancel this order. Reason: %s', 'edit-orders-for-woocommerce' ), '' !== $reason ? $reason : __( 'none given', 'edit-orders-for-woocommerce' ) ) );
		$order->save();
		self::forget_pending_count();

		Edit_Orders_For_WooCommerce_Audit_Log::add(
			array(
				'order_id'   => $order->get_id(),
				'actor_type' => $actor,
				'actor_id'   => get_current_user_id(),
				'action'     => 'cancel_requested',
				'after'      => array( 'reason' => $reason ),
			)
		);

		/**
		 * Fired when a customer asks to cancel and the store must decide.
		 *
		 * @since 0.1.0
		 *
		 * @param WC_Order $order  Order.
		 * @param string   $reason Reason.
		 */
		do_action( 'edit_orders_for_woocommerce_cancel_requested', $order, $reason );

		return array(
			'status' => 'requested',
			'refund' => null,
		);
	}

	/**
	 * Cancel the order and refund what is left of it.
	 *
	 * The status changes first, so WooCommerce restocks and sends its "Cancelled
	 * order" email; the refund follows. If the gateway can't refund, the order
	 * stays cancelled and is flagged for a manual refund. An unpaid order (such as
	 * pay on delivery) has nothing to refund.
	 *
	 * Linked balance orders go with it: unpaid ones are cancelled, paid ones are
	 * cancelled and refunded, since they paid for changes to this order.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $actor  customer, guest or staff.
	 * @param string   $reason Reason.
	 * @return array|WP_Error { status: cancelled, refund, amount, on_delivery }
	 */
	public static function execute( WC_Order $order, $actor, $reason = '' ) {
		// Two submits at once (a double click, or two tabs) must not both cancel and refund.
		$guard = 'cancel_' . $order->get_id();
		if ( ! Edit_Orders_For_WooCommerce_Lock::claim( $guard ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_busy', __( 'This order is being changed right now. Reload the page and try again.', 'edit-orders-for-woocommerce' ) );
		}

		$order = wc_get_order( $order->get_id() );
		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			Edit_Orders_For_WooCommerce_Lock::unclaim( $guard );
			return new WP_Error( 'edit_orders_for_woocommerce_already_cancelled', __( 'This order is already cancelled.', 'edit-orders-for-woocommerce' ) );
		}

		$result = self::execute_claimed( $order, $actor, $reason );
		Edit_Orders_For_WooCommerce_Lock::unclaim( $guard );

		return $result;
	}

	/**
	 * Cancel and refund while holding the cancel guard.
	 *
	 * @param WC_Order $order  Order, freshly loaded.
	 * @param string   $actor  Actor.
	 * @param string   $reason Reason.
	 * @return array
	 */
	private static function execute_claimed( WC_Order $order, $actor, $reason ) {
		// Nothing was taken: pay on delivery, or any order without a paid date.
		$on_delivery = Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $order ) || ! $order->get_date_paid();

		// Balance orders for earlier changes go too.
		$balances         = self::close_balance_orders( $order );
		$balance_refunded = $balances['amount'];

		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( self::STATUS_META, 'staff' === $actor ? 'approved' : 'cancelled' );
		$order->save();
		self::forget_pending_count();
		$order->update_status(
			'cancelled',
			'' !== $reason
				/* translators: %s: reason. */
				? sprintf( __( 'Cancelled at the customer\'s request. Reason: %s', 'edit-orders-for-woocommerce' ), $reason )
				: __( 'Cancelled at the customer\'s request.', 'edit-orders-for-woocommerce' )
		);

		$refund = null;
		$order  = wc_get_order( $order->get_id() );
		$amount = (float) $order->get_remaining_refund_amount();

		if ( ! $on_delivery && $amount > 0 ) {
			$refund = self::refund_remaining( $order, __( 'Order cancelled', 'edit-orders-for-woocommerce' ) );
		}
		$refunded = ( $on_delivery ? 0 : $amount ) + $balance_refunded;

		Edit_Orders_For_WooCommerce_Audit_Log::add(
			array(
				'order_id'   => $order->get_id(),
				'actor_type' => $actor,
				'actor_id'   => get_current_user_id(),
				'action'     => 'cancelled',
				'after'      => array( 'reason' => $reason ),
				'refund_id'  => is_array( $refund ) ? $refund['refund_id'] : 0,
				'amount'     => $refunded,
			)
		);

		$result = array(
			'status'      => 'cancelled',
			'refund'      => is_array( $refund ) ? $refund : null,
			'amount'      => $refunded,
			'on_delivery' => $on_delivery && $balance_refunded <= 0,
		);
		if ( is_wp_error( $refund ) || ( $balances['manual'] && ! $result['refund'] ) ) {
			// Not even a refund record could be made: the email must not say "refunded".
			$result['refund'] = array( 'manual' => true );
		} elseif ( $balances['manual'] ) {
			$result['refund']['manual'] = true;
		}

		/**
		 * Fired when an order is cancelled through this plugin.
		 *
		 * @since 0.1.0
		 *
		 * @param WC_Order $order  Order.
		 * @param string   $actor  Actor.
		 * @param string   $reason Reason.
		 * @param array    $result Result.
		 */
		do_action( 'edit_orders_for_woocommerce_cancelled', wc_get_order( $order->get_id() ), $actor, $reason, $result );

		return $result;
	}

	/**
	 * Refund everything left on an order, keeping it Cancelled. If not even a
	 * refund record can be made, the order is flagged for a manual refund.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Reason shown on the refund.
	 * @return array|WP_Error Refund result.
	 */
	private static function refund_remaining( WC_Order $order, $reason ) {
		$amount = (float) $order->get_remaining_refund_amount();

		// Keep the order Cancelled; WooCommerce would switch a fully refunded order to Refunded.
		$keep_status = function () {
			return false;
		};
		add_filter( 'woocommerce_order_fully_refunded_status', $keep_status );
		$refund = Edit_Orders_For_WooCommerce_Refunds::refund( $order, self::remaining_lines( $order ), $amount, $reason );
		remove_filter( 'woocommerce_order_fully_refunded_status', $keep_status );

		if ( is_wp_error( $refund ) ) {
			Edit_Orders_For_WooCommerce_Refunds::flag_manual_refund(
				$order,
				wc_format_decimal( $amount, wc_get_price_decimals() ),
				0,
				'',
				/* translators: %s: error message. */
				sprintf( __( 'The order was cancelled, but the refund could not be recorded (%s). Nothing has been refunded yet.', 'edit-orders-for-woocommerce' ), $refund->get_error_message() )
			);
		}

		return $refund;
	}

	/**
	 * Cancel the balance orders linked to an order: unpaid ones are cancelled,
	 * paid ones are cancelled and refunded (they paid for changes to this order).
	 * A linked pay-on-delivery order is cancelled, so it isn't delivered and collected.
	 *
	 * @param WC_Order $order Original order.
	 * @return array { amount: refunded through paid balance orders, manual: a refund must be made by hand }
	 */
	private static function close_balance_orders( WC_Order $order ) {
		$out      = array(
			'amount' => 0.0,
			'manual' => false,
		);
		$balances = wc_get_orders(
			array(
				'parent' => $order->get_id(),
				'type'   => 'shop_order',
				'limit'  => -1,
			)
		);

		foreach ( $balances as $balance ) {
			if ( ! Edit_Orders_For_WooCommerce_Balance_Orders::is_balance_order( $balance ) || $balance->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
				continue;
			}

			$paid = $balance->get_date_paid() && ! Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $balance );
			$balance->update_status( 'cancelled', __( 'The original order was cancelled.', 'edit-orders-for-woocommerce' ) );

			if ( $paid ) {
				$balance = wc_get_order( $balance->get_id() );
				$amount  = (float) $balance->get_remaining_refund_amount();
				if ( $amount > 0 ) {
					$refund         = self::refund_remaining( $balance, __( 'Original order cancelled', 'edit-orders-for-woocommerce' ) );
					$out['amount'] += $amount;
					$out['manual']  = $out['manual'] || is_wp_error( $refund ) || ! empty( $refund['manual'] );
				}
			}
		}

		return $out;
	}

	/**
	 * The store approves a pending request.
	 *
	 * @param WC_Order $order Order.
	 * @return array|WP_Error
	 */
	public static function approve( WC_Order $order ) {
		if ( 'pending' !== $order->get_meta( self::STATUS_META ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_no_request', __( 'There is no cancellation request waiting on this order.', 'edit-orders-for-woocommerce' ) );
		}

		// The order may have moved on since the customer asked (shipped, refunded or cancelled by hand).
		if ( ! in_array( $order->get_status(), array( 'pending', 'processing', 'on-hold' ), true ) ) {
			$order->update_meta_data( self::STATUS_META, 'expired' );
			/* translators: %s: order status. */
			$order->add_order_note( sprintf( __( 'Cancellation request closed without cancelling: the order is now %s.', 'edit-orders-for-woocommerce' ), wc_get_order_status_name( $order->get_status() ) ) );
			$order->save();
			self::forget_pending_count();

			return new WP_Error(
				'edit_orders_for_woocommerce_request_expired',
				/* translators: %s: order status. */
				sprintf( __( 'This order is now %s, so it was not cancelled. Refund or cancel it from the order screen if you still need to.', 'edit-orders-for-woocommerce' ), wc_get_order_status_name( $order->get_status() ) )
			);
		}

		$request = (array) $order->get_meta( self::REQUEST_META );

		return self::execute( $order, 'staff', isset( $request['reason'] ) ? $request['reason'] : '' );
	}

	/**
	 * The store declines a pending request. The order is not changed.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $message Optional message for the customer.
	 * @return true|WP_Error
	 */
	public static function decline( WC_Order $order, $message = '' ) {
		if ( 'pending' !== $order->get_meta( self::STATUS_META ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_no_request', __( 'There is no cancellation request waiting on this order.', 'edit-orders-for-woocommerce' ) );
		}

		$message = sanitize_textarea_field( $message );
		$order->update_meta_data( self::STATUS_META, 'declined' );
		$order->add_order_note( __( 'Cancellation request declined.', 'edit-orders-for-woocommerce' ) . ( '' !== $message ? ' ' . $message : '' ) );
		$order->save();
		self::forget_pending_count();

		Edit_Orders_For_WooCommerce_Audit_Log::add(
			array(
				'order_id' => $order->get_id(),
				'action'   => 'cancel_declined',
				'after'    => array( 'message' => $message ),
			)
		);

		/**
		 * Fired when the store declines a cancellation request.
		 *
		 * @since 0.1.0
		 *
		 * @param WC_Order $order   Order.
		 * @param string   $message Message for the customer.
		 */
		do_action( 'edit_orders_for_woocommerce_cancel_declined', $order, $message );

		return true;
	}

	/**
	 * How many requests are waiting, for the menu badge. Cached until a request changes.
	 *
	 * @return int
	 */
	public static function pending_count() {
		$count = get_transient( 'edit_orders_for_woocommerce_pending_count' );
		if ( false === $count ) {
			$count = count( self::pending_requests() );
			set_transient( 'edit_orders_for_woocommerce_pending_count', $count, 12 * HOUR_IN_SECONDS );
		}

		return (int) $count;
	}

	/**
	 * Drop the cached count after a request is made, answered or closed.
	 */
	public static function forget_pending_count() {
		delete_transient( 'edit_orders_for_woocommerce_pending_count' );
	}

	/**
	 * Orders with a cancellation request waiting.
	 *
	 * @return WC_Order[]
	 */
	public static function pending_requests() {
		// meta_key and meta_value work with both order storages; the legacy (posts)
		// storage ignores meta_query, which would list every order.
		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => self::STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a small admin list, run on demand.
				'meta_value' => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			)
		);

		// Check each one anyway: an unsupported argument fails open, not closed.
		return array_values(
			array_filter(
				$orders,
				function ( $order ) {
					return 'pending' === $order->get_meta( self::STATUS_META );
				}
			)
		);
	}

	/**
	 * Refund lines for everything not yet refunded: items, shipping and fees.
	 *
	 * @param WC_Order $order Order.
	 * @return array[] Keyed by item ID.
	 */
	private static function remaining_lines( WC_Order $order ) {
		$lines = array();

		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item_id => $item ) {
			$type   = $item->get_type();
			$qty    = 'line_item' === $type ? (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ) : 0;
			$total  = (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item_id, $type );
			$taxes  = $item->get_taxes();
			$refund = array();

			foreach ( isset( $taxes['total'] ) ? $taxes['total'] : array() as $rate_id => $tax ) {
				if ( '' === $tax ) {
					continue;
				}
				$left = (float) $tax - (float) $order->get_tax_refunded_for_item( $item_id, $rate_id, $type );
				if ( abs( $left ) > 0.001 ) {
					$refund[ $rate_id ] = Edit_Orders_For_WooCommerce_Pricing::round( $left );
				}
			}

			if ( $qty > 0 || abs( $total ) > 0.001 || $refund ) {
				$lines[ $item_id ] = array(
					'qty'          => max( 0, $qty ),
					'refund_total' => Edit_Orders_For_WooCommerce_Pricing::round( $total ),
					'refund_tax'   => $refund,
				);
			}
		}

		return $lines;
	}
}
