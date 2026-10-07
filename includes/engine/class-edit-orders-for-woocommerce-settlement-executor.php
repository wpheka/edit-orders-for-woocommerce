<?php
/**
 * Settlement executor: previews and applies change sets (spec section 3.4).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Settlement_Executor Class.
 *
 * The public entry points for the engine:
 *
 * - preview(): check the order and build the plan, writing nothing;
 * - apply(): apply at once when no money is owed, or create a balance order
 *   whose payment applies the whole plan later;
 * - run(): carry out a plan; also called when a balance order is paid.
 */
class Edit_Orders_For_WooCommerce_Settlement_Executor {

	/**
	 * Check an order and build the plan for a change set, without writing anything.
	 *
	 * @param WC_Order $order   Order.
	 * @param array[]  $changes Raw changes (see the change set class).
	 * @param string   $actor   `staff`, `customer` or `guest`.
	 * @return Edit_Orders_For_WooCommerce_Settlement_Plan|WP_Error
	 */
	public static function preview( WC_Order $order, array $changes, $actor = 'staff' ) {
		$eligible = Edit_Orders_For_WooCommerce_Eligibility::check_order( $order, 'edit', $actor );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$plan = Edit_Orders_For_WooCommerce_Settlement_Plan::build( $order, new Edit_Orders_For_WooCommerce_Change_Set( $changes ) );

		if ( $plan->get_errors() ) {
			return new WP_Error( 'edit_orders_for_woocommerce_plan', implode( ' ', $plan->get_errors() ), $plan );
		}

		if ( ! $plan->has_changes() ) {
			return new WP_Error( 'edit_orders_for_woocommerce_no_changes', __( 'Nothing to change.', 'edit-orders-for-woocommerce' ) );
		}

		return $plan;
	}

	/**
	 * Apply a change set.
	 *
	 * @param WC_Order $order   Order.
	 * @param array[]  $changes Raw changes.
	 * @param string   $actor   `staff`, `customer` or `guest`.
	 * @param array    $args    Options passed on to listeners such as the emails:
	 *                          notify_customer (default true), send_pay_link (default true);
	 *                          expect: fingerprint of the previewed plan (optional).
	 * @return array|WP_Error { status: applied|balance_due|collect_on_delivery, plan, balance_order_id, pay_url, refund }
	 */
	public static function apply( WC_Order $order, array $changes, $actor = 'staff', array $args = array() ) {
		// One apply per order at a time: a double click or two people at once must
		// not both pass the checks before either refund exists.
		$guard = 'apply_' . $order->get_id();
		if ( ! Edit_Orders_For_WooCommerce_Lock::claim( $guard ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_busy', __( 'This order is being changed right now. Reload the page and try again.', 'edit-orders-for-woocommerce' ) );
		}

		$result = self::apply_claimed( wc_get_order( $order->get_id() ), $changes, $actor, $args );
		Edit_Orders_For_WooCommerce_Lock::unclaim( $guard );

		return $result;
	}

	/**
	 * Apply a change set while holding the apply guard.
	 *
	 * @param WC_Order $order   Order, freshly loaded.
	 * @param array[]  $changes Raw changes.
	 * @param string   $actor   Actor.
	 * @param array    $args    Options, plus `expect`: the fingerprint of the
	 *                          previewed plan; a different plan is refused.
	 * @return array|WP_Error
	 */
	private static function apply_claimed( WC_Order $order, array $changes, $actor, array $args ) {
		$plan = self::preview( $order, $changes, $actor );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( ! empty( $args['expect'] ) && ! hash_equals( $plan->fingerprint(), (string) $args['expect'] ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_changed', __( 'The order or prices changed since the preview. Please review the change again.', 'edit-orders-for-woocommerce' ) );
		}

		/**
		 * Fired before a change set is applied or sent for payment.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Order                                    $order Order.
		 * @param Edit_Orders_For_WooCommerce_Settlement_Plan $plan  Plan.
		 * @param string                                      $actor Actor.
		 */
		do_action( 'edit_orders_for_woocommerce_before_apply', $order, $plan, $actor );

		if ( $plan->needs_balance() ) {
			$balance = Edit_Orders_For_WooCommerce_Balance_Orders::create( $order, $plan );
			if ( is_wp_error( $balance ) ) {
				return $balance;
			}

			if ( Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $order ) ) {
				// Paid on delivery like the original: the linked order is collected with it,
				// so the change applies now. Processing reduces its stock and runs the plan.
				$balance->set_payment_method( $order->get_payment_method() );
				$balance->set_payment_method_title( $order->get_payment_method_title() );
				$balance->save();
				$order = wc_get_order( $order->get_id() );
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount, 2: linked order number. */
						__( 'Collect %1$s more on delivery, on linked order #%2$s.', 'edit-orders-for-woocommerce' ),
						wc_price( $balance->get_total(), array( 'currency' => $balance->get_currency() ) ),
						$balance->get_order_number()
					)
				);
				$order->save();
				$balance->update_status( 'processing', __( 'Payment to be made upon delivery, with the original order.', 'edit-orders-for-woocommerce' ) );

				$result = array(
					'status'           => 'collect_on_delivery',
					'plan'             => $plan,
					'balance_order_id' => $balance->get_id(),
				);
			} else {
				$order = wc_get_order( $order->get_id() );
				$order->add_order_note(
					sprintf(
						/* translators: 1: balance order number, 2: amount, 3: changes. */
						__( 'Changes waiting for payment of balance order #%1$s (%2$s). They apply when it is paid: %3$s', 'edit-orders-for-woocommerce' ),
						$balance->get_order_number(),
						wc_price( $balance->get_total(), array( 'currency' => $balance->get_currency() ) ),
						implode( ' ', $plan->get_descriptions() )
					)
				);
				$order->save();

				$result = array(
					'status'           => 'balance_due',
					'plan'             => $plan,
					'balance_order_id' => $balance->get_id(),
					'pay_url'          => $balance->get_checkout_payment_url(),
				);
			}
		} else {
			$result = self::run( $order, $plan );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		/**
		 * Fired after a change set is applied or sent for payment.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Order $order  Order.
		 * @param array    $result Result.
		 * @param string   $actor  Actor.
		 * @param array    $args   Options from the caller.
		 */
		do_action( 'edit_orders_for_woocommerce_after_apply', $order, $result, $actor, $args );

		return $result;
	}

	/**
	 * Carry out a plan on the original order: refund, then switch variations.
	 *
	 * @param WC_Order                                    $order   Original order.
	 * @param Edit_Orders_For_WooCommerce_Settlement_Plan $plan    Plan.
	 * @param WC_Order|null                               $balance Paid balance order, when applying after payment.
	 * @return array|WP_Error { status, plan, refund }
	 */
	public static function run( WC_Order $order, Edit_Orders_For_WooCommerce_Settlement_Plan $plan, $balance = null ) {
		$refund = null;
		$notes  = $plan->get_descriptions();

		// Money first: if the refund can't even be recorded, nothing else changes.
		if ( $plan->get_refund_lines() ) {
			$refund = Edit_Orders_For_WooCommerce_Refunds::refund(
				$order,
				$plan->get_refund_lines(),
				$plan->get_refund_amount(),
				__( 'Order edited', 'edit-orders-for-woocommerce' )
			);

			if ( is_wp_error( $refund ) ) {
				$order->add_order_note(
					/* translators: %s: error message. */
					sprintf( __( 'Order edit not applied: the refund could not be recorded (%s).', 'edit-orders-for-woocommerce' ), $refund->get_error_message() )
				);
				return $refund;
			}

			$order = wc_get_order( $order->get_id() );
		}

		foreach ( $plan->get_repoints() as $repoint ) {
			$item      = $order->get_item( $repoint['item_id'] );
			$variation = wc_get_product( $repoint['variation_id'] );
			if ( ! $item instanceof WC_Order_Item_Product || ! $variation ) {
				continue;
			}
			$stock_note = Edit_Orders_For_WooCommerce_Stock::repoint( $order, $item, $variation );
			if ( '' !== $stock_note ) {
				$notes[] = $stock_note;
			}
		}

		$order   = wc_get_order( $order->get_id() );
		$address = $plan->get_address_update();
		if ( $address ) {
			$order->set_address( $address['billing'], 'billing' );
			$order->set_address( $address['shipping'], 'shipping' );

			// Point the shipping line at the method for the new address; its paid totals stay.
			if ( ! empty( $address['shipping_line'] ) ) {
				$shipping = $order->get_item( $address['shipping_line']['item_id'] );
				if ( $shipping instanceof WC_Order_Item_Shipping ) {
					$shipping->set_method_id( $address['shipping_line']['method_id'] );
					$shipping->set_instance_id( $address['shipping_line']['instance_id'] );
					$shipping->set_method_title( $address['shipping_line']['title'] );
					$shipping->save();
				}
			}
		}

		$order->add_order_note(
			( $balance
				/* translators: %s: balance order number. */
				? sprintf( __( 'Order edited (paid through balance order #%s):', 'edit-orders-for-woocommerce' ), $balance->get_order_number() )
				: __( 'Order edited:', 'edit-orders-for-woocommerce' ) )
			. ' ' . implode( ' ', $notes )
		);
		$order->save();

		return array(
			'status' => 'applied',
			'plan'   => $plan,
			'refund' => $refund,
		);
	}
}
