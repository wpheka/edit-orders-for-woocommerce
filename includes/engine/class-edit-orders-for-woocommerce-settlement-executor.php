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
		// Each preview is a run of its own; its ID goes back with the change when it is confirmed.
		Edit_Orders_For_WooCommerce_Log::start_run();

		$plan = self::check( $order, $changes, $actor );
		if ( ! is_wp_error( $plan ) && Edit_Orders_For_WooCommerce_Log::debug_enabled() ) {
			Edit_Orders_For_WooCommerce_Log::debug( sprintf( 'order %d: preview for %s', $order->get_id(), Edit_Orders_For_WooCommerce_Log::actor( $actor ) ) . ': ' . self::plan_summary( $order, $plan ), self::plan_context( $order, $plan, $changes ) );
		}

		return $plan;
	}

	/**
	 * Check an order and build the plan, logging why it was refused.
	 *
	 * @param WC_Order $order   Order.
	 * @param array[]  $changes Raw changes.
	 * @param string   $actor   Actor.
	 * @return Edit_Orders_For_WooCommerce_Settlement_Plan|WP_Error
	 */
	private static function check( WC_Order $order, array $changes, $actor ) {
		$eligible = Edit_Orders_For_WooCommerce_Eligibility::check_order( $order, 'edit', $actor );
		if ( is_wp_error( $eligible ) ) {
			Edit_Orders_For_WooCommerce_Log::debug(
				sprintf( 'order %d: not editable for %s: %s (%s)', $order->get_id(), Edit_Orders_For_WooCommerce_Log::actor( $actor ), Edit_Orders_For_WooCommerce_Log::reason( $eligible ), $eligible->get_error_code() ),
				array(
					'order' => $order->get_id(),
					'code'  => $eligible->get_error_code(),
					'actor' => Edit_Orders_For_WooCommerce_Log::actor( $actor ),
				)
			);
			return $eligible;
		}

		$plan = Edit_Orders_For_WooCommerce_Settlement_Plan::build( $order, new Edit_Orders_For_WooCommerce_Change_Set( $changes ) );

		if ( $plan->get_errors() ) {
			Edit_Orders_For_WooCommerce_Log::notice(
				sprintf( 'order %d: plan refused: %s', $order->get_id(), implode( ' ', $plan->get_errors() ) ),
				array(
					'order'   => $order->get_id(),
					'errors'  => $plan->get_errors(),
					'changes' => self::changes_summary( $changes ),
				)
			);
			return new WP_Error( 'edit_orders_for_woocommerce_plan', implode( ' ', $plan->get_errors() ), $plan );
		}

		if ( ! $plan->has_changes() ) {
			Edit_Orders_For_WooCommerce_Log::debug( sprintf( 'order %d: nothing to change', $order->get_id() ), array( 'order' => $order->get_id() ) );
			return new WP_Error( 'edit_orders_for_woocommerce_no_changes', __( 'Nothing to change.', 'wpheka-edit-orders-for-woocommerce' ) );
		}

		return $plan;
	}

	/**
	 * The changes asked for, without personal details: item and product IDs, quantities,
	 * and for an address only the country and state codes.
	 *
	 * @param array[] $changes Raw changes.
	 * @return array[]
	 */
	public static function changes_summary( array $changes ) {
		$summary = array();
		foreach ( $changes as $change ) {
			$type = isset( $change['type'] ) ? sanitize_key( (string) $change['type'] ) : '';
			$row  = array( 'type' => $type );
			foreach ( array( 'item_id', 'quantity', 'variation_id', 'product_id', 'price', 'shipping_method' ) as $field ) {
				if ( isset( $change[ $field ] ) && is_scalar( $change[ $field ] ) ) {
					$row[ $field ] = $change[ $field ];
				}
			}
			foreach ( array( 'billing', 'shipping' ) as $address ) {
				if ( ! empty( $change[ $address ] ) && is_array( $change[ $address ] ) ) {
					$row[ $address ] = array(
						'country' => isset( $change[ $address ]['country'] ) ? (string) $change[ $address ]['country'] : '',
						'state'   => isset( $change[ $address ]['state'] ) ? (string) $change[ $address ]['state'] : '',
					);
				}
			}
			$summary[] = $row;
		}

		return $summary;
	}

	/**
	 * One line on what a plan does, with amounts and IDs only.
	 *
	 * @param WC_Order                                    $order Order.
	 * @param Edit_Orders_For_WooCommerce_Settlement_Plan $plan  Plan.
	 * @return string
	 */
	private static function plan_summary( WC_Order $order, Edit_Orders_For_WooCommerce_Settlement_Plan $plan ) {
		$currency = $order->get_currency();
		$parts    = array();
		if ( $plan->get_refund_lines() ) {
			$parts[] = 'refund ' . Edit_Orders_For_WooCommerce_Log::money( $plan->get_parent_refund_amount(), $currency ) . ' on order ' . $order->get_id();
		}
		foreach ( array_keys( $plan->get_balance_refunds() ) as $balance_id ) {
			$parts[] = 'refund ' . Edit_Orders_For_WooCommerce_Log::money( $plan->get_balance_refund_amount( $balance_id ), $currency ) . ' on balance order ' . $balance_id;
		}
		if ( $plan->needs_balance() ) {
			$parts[] = 'balance ' . Edit_Orders_For_WooCommerce_Log::money( $plan->get_balance_estimate( $order ), $currency ) . ' (' . count( $plan->get_balance_items() ) . ' items)';
		}
		if ( $plan->get_repoints() || $plan->get_balance_repoints() ) {
			$parts[] = count( $plan->get_repoints() ) + count( $plan->get_balance_repoints() ) . ' variation swaps';
		}
		if ( $plan->get_address_update() ) {
			$parts[] = 'address update';
		}
		if ( ! $parts ) {
			$parts[] = 'no money moves';
		}

		return implode( ', ', $parts ) . '; plan ' . $plan->fingerprint();
	}

	/**
	 * A plan's details for the log context.
	 *
	 * @param WC_Order                                    $order   Order.
	 * @param Edit_Orders_For_WooCommerce_Settlement_Plan $plan    Plan.
	 * @param array[]                                     $changes Raw changes.
	 * @return array
	 */
	private static function plan_context( WC_Order $order, Edit_Orders_For_WooCommerce_Settlement_Plan $plan, array $changes ) {
		$refund_lines = array();
		foreach ( $plan->get_refund_lines() as $item_id => $line ) {
			$refund_lines[ $item_id ] = array(
				'qty'   => isset( $line['qty'] ) ? (int) $line['qty'] : 0,
				'total' => isset( $line['refund_total'] ) ? (float) $line['refund_total'] : 0.0,
				'tax'   => isset( $line['refund_tax'] ) ? array_sum( array_map( 'floatval', (array) $line['refund_tax'] ) ) : 0.0,
			);
		}

		$balance_refunds = array();
		foreach ( array_keys( $plan->get_balance_refunds() ) as $balance_id ) {
			$balance_refunds[ $balance_id ] = $plan->get_balance_refund_amount( $balance_id );
		}

		$balance_items = array();
		foreach ( $plan->get_balance_items() as $line ) {
			$balance_items[] = array(
				'type'       => $line['type'],
				'product_id' => isset( $line['product_id'] ) ? (int) $line['product_id'] : 0,
				'quantity'   => isset( $line['quantity'] ) ? (int) $line['quantity'] : 0,
				'total'      => isset( $line['total'] ) ? (float) $line['total'] : 0.0,
				'for_item'   => isset( $line['for_item'] ) ? (int) $line['for_item'] : 0,
			);
		}

		return array(
			'order'           => $order->get_id(),
			'currency'        => $order->get_currency(),
			'changes'         => self::changes_summary( $changes ),
			'refund_lines'    => $refund_lines,
			'refund_total'    => $plan->get_parent_refund_amount(),
			'balance_refunds' => $balance_refunds,
			'balance_items'   => $balance_items,
			'balance_total'   => $plan->get_balance_estimate( $order ),
			'repoints'        => $plan->get_repoints(),
			'fingerprint'     => $plan->fingerprint(),
		);
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
	 *
	 * @throws Throwable An unexpected error, rethrown once it is logged.
	 */
	public static function apply( WC_Order $order, array $changes, $actor = 'staff', array $args = array() ) {
		// The preview's run continues when its ID came back with the change (args: run).
		Edit_Orders_For_WooCommerce_Log::start_run( isset( $args['run'] ) ? (string) $args['run'] : '' );

		// One apply per order at a time: a double click or two people at once must
		// not both pass the checks before either refund exists.
		$guard = 'apply_' . $order->get_id();
		if ( ! Edit_Orders_For_WooCommerce_Lock::claim( $guard ) ) {
			Edit_Orders_For_WooCommerce_Log::notice(
				sprintf( 'order %d: change refused for %s: another change to this order is running', $order->get_id(), Edit_Orders_For_WooCommerce_Log::actor( $actor ) ),
				array(
					'order' => $order->get_id(),
					'code'  => 'edit_orders_for_woocommerce_busy',
				)
			);
			return new WP_Error( 'edit_orders_for_woocommerce_busy', __( 'This order is being changed right now. Reload the page and try again.', 'wpheka-edit-orders-for-woocommerce' ) );
		}

		try {
			$result = self::apply_claimed( wc_get_order( $order->get_id() ), $changes, $actor, $args );
		} catch ( Throwable $e ) {
			// The guard stays, as it always has after a crash: it goes stale after a minute. Releasing
			// it here could let a second request refund again what this one may already have refunded.
			self::log_exception( 'applying a change to order ' . $order->get_id(), $e, $order->get_id() );
			throw $e;
		}
		Edit_Orders_For_WooCommerce_Lock::unclaim( $guard );

		if ( is_wp_error( $result ) ) {
			Edit_Orders_For_WooCommerce_Log::info(
				sprintf( 'order %d: change not applied: %s (%s)', $order->get_id(), Edit_Orders_For_WooCommerce_Log::reason( $result ), $result->get_error_code() ),
				array(
					'order' => $order->get_id(),
					'code'  => $result->get_error_code(),
				)
			);
		} else {
			Edit_Orders_For_WooCommerce_Log::info(
				sprintf( 'order %d: change finished: %s', $order->get_id(), $result['status'] ) . ( ! empty( $result['balance_order_id'] ) ? ', balance order ' . (int) $result['balance_order_id'] : '' ),
				array(
					'order'   => $order->get_id(),
					'status'  => $result['status'],
					'balance' => ! empty( $result['balance_order_id'] ) ? (int) $result['balance_order_id'] : 0,
				)
			);
		}

		return $result;
	}

	/**
	 * Log an error nobody expected, with where it happened.
	 *
	 * @param string    $where    What was running.
	 * @param Throwable $e        The error.
	 * @param int       $order_id Order ID, if known.
	 */
	public static function log_exception( $where, $e, $order_id = 0 ) {
		static $logged = array();
		$id            = spl_object_hash( $e );
		if ( isset( $logged[ $id ] ) ) {
			return;
		}
		$logged[ $id ] = true;

		Edit_Orders_For_WooCommerce_Log::error(
			sprintf( 'unexpected error while %s: %s', $where, Edit_Orders_For_WooCommerce_Log::clip( $e->getMessage() ) ),
			array(
				'order' => absint( $order_id ),
				'where' => $where,
				'file'  => basename( $e->getFile() ) . ':' . $e->getLine(),
				'type'  => get_class( $e ),
			)
		);
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
		$plan = self::check( $order, $changes, $actor );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( ! empty( $args['expect'] ) && ! hash_equals( $plan->fingerprint(), (string) $args['expect'] ) ) {
			Edit_Orders_For_WooCommerce_Log::notice(
				sprintf( 'order %d: change refused for %s: the order or prices changed since the preview (plan %s, previewed %s)', $order->get_id(), Edit_Orders_For_WooCommerce_Log::actor( $actor ), $plan->fingerprint(), sanitize_key( (string) $args['expect'] ) ),
				array(
					'order' => $order->get_id(),
					'code'  => 'edit_orders_for_woocommerce_changed',
				)
			);
			return new WP_Error( 'edit_orders_for_woocommerce_changed', __( 'The order or prices changed since the preview. Please review the change again.', 'wpheka-edit-orders-for-woocommerce' ) );
		}

		if ( Edit_Orders_For_WooCommerce_Log::debug_enabled() ) {
			Edit_Orders_For_WooCommerce_Log::info( sprintf( 'order %d: change started by %s', $order->get_id(), Edit_Orders_For_WooCommerce_Log::actor( $actor ) ) . ': ' . self::plan_summary( $order, $plan ), self::plan_context( $order, $plan, $changes ) );
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
				Edit_Orders_For_WooCommerce_Log::error(
					sprintf( 'order %d: balance order could not be created: %s', $order->get_id(), Edit_Orders_For_WooCommerce_Log::clip( $balance->get_error_message() ) ),
					array(
						'order' => $order->get_id(),
						'code'  => $balance->get_error_code(),
					)
				);
				return $balance;
			}

			if ( Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $order ) ) {
				Edit_Orders_For_WooCommerce_Log::info(
					sprintf( 'order %d: balance order %d is collected on delivery with the original (%s); the change applies now', $order->get_id(), $balance->get_id(), $order->get_payment_method() ),
					array(
						'order'   => $order->get_id(),
						'balance' => $balance->get_id(),
						'gateway' => $order->get_payment_method(),
					)
				);
				// Paid on delivery like the original: the linked order is collected with it,
				// so the change applies now. Processing reduces its stock and runs the plan.
				$balance->set_payment_method( $order->get_payment_method() );
				$balance->set_payment_method_title( $order->get_payment_method_title() );
				$balance->save();
				$order = wc_get_order( $order->get_id() );
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount, 2: linked order number. */
						__( 'Collect %1$s more on delivery, on linked order #%2$s.', 'wpheka-edit-orders-for-woocommerce' ),
						wc_price( $balance->get_total(), array( 'currency' => $balance->get_currency() ) ),
						$balance->get_order_number()
					)
				);
				$order->save();
				// The gateways' filters keep a cash on delivery order without a paid date when it
				// goes to Processing; make sure they are loaded in this request.
				WC()->payment_gateways();
				$balance->update_status( 'processing', __( 'Payment to be made upon delivery, with the original order.', 'wpheka-edit-orders-for-woocommerce' ) );

				$result = array(
					'status'           => 'collect_on_delivery',
					'plan'             => $plan,
					'balance_order_id' => $balance->get_id(),
				);
			} elseif ( (float) $balance->get_total() <= 0 ) {
				// Nothing to pay (a product added at 0.00, a line on a 100% coupon): WooCommerce
				// can't take a payment of 0.00, so the balance order is completed now and applies.
				Edit_Orders_For_WooCommerce_Log::info(
					sprintf( 'order %d: balance order %d has nothing to pay; completing it so the change applies now', $order->get_id(), $balance->get_id() ),
					array(
						'order'   => $order->get_id(),
						'balance' => $balance->get_id(),
					)
				);
				$balance->payment_complete();

				$result = array(
					'status'           => 'applied',
					'plan'             => $plan,
					'balance_order_id' => $balance->get_id(),
				);
			} else {
				$order = wc_get_order( $order->get_id() );
				$order->add_order_note(
					sprintf(
						/* translators: 1: balance order number, 2: amount, 3: changes. */
						__( 'Changes waiting for payment of balance order #%1$s (%2$s). They apply when it is paid: %3$s', 'wpheka-edit-orders-for-woocommerce' ),
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
		$refund          = null;
		$balance_refunds = array();
		$refunds_made    = array();
		$notes           = $plan->get_descriptions();

		// Money first. The part of a line paid on a balance order is refunded from that
		// order, through the gateway it was paid with, before the original order.
		foreach ( $plan->get_balance_refunds() as $balance_id => $lines ) {
			$paid = wc_get_order( $balance_id );
			if ( ! $paid ) {
				continue;
			}
			if ( $balance ) {
				Edit_Orders_For_WooCommerce_Balance_Orders::renew_applying( $balance );
			}
			$amount = $plan->get_balance_refund_amount( $balance_id );
			$done   = Edit_Orders_For_WooCommerce_Refunds::refund(
				$paid,
				$lines,
				$amount,
				/* translators: %s: original order number. */
				sprintf( __( 'Order #%s edited', 'wpheka-edit-orders-for-woocommerce' ), $order->get_order_number() )
			);
			if ( is_wp_error( $done ) ) {
				self::log_refund_not_recorded( $order, $paid->get_id(), $done, $refunds_made );
				$order = wc_get_order( $order->get_id() );
				$order->add_order_note(
					sprintf(
						/* translators: 1: balance order number, 2: error message. */
						__( 'Order edit not applied: the refund on balance order #%1$s could not be recorded (%2$s).', 'wpheka-edit-orders-for-woocommerce' ),
						$paid->get_order_number(),
						$done->get_error_message()
					) . self::refunds_made_note( $order, $refunds_made )
				);
				$order->save();
				return $done;
			}
			$balance_refunds[ $balance_id ] = $done;
			$refunds_made[]                 = array(
				'order'  => $paid,
				'amount' => $amount,
			);
		}

		// Money first: if the refund can't even be recorded, nothing else changes.
		if ( $plan->get_refund_lines() ) {
			// Replacement items or a swapped variation are still on their way: a refund that
			// happens to equal what is left must not switch the order to Refunded.
			$keep_status = function () {
				return false;
			};
			if ( $plan->get_balance_items() || $plan->get_repoints() ) {
				add_filter( 'woocommerce_order_fully_refunded_status', $keep_status );
			}
			if ( $balance ) {
				Edit_Orders_For_WooCommerce_Balance_Orders::renew_applying( $balance );
			}
			$refund = Edit_Orders_For_WooCommerce_Refunds::refund(
				$order,
				$plan->get_refund_lines(),
				$plan->get_parent_refund_amount(),
				__( 'Order edited', 'wpheka-edit-orders-for-woocommerce' )
			);
			remove_filter( 'woocommerce_order_fully_refunded_status', $keep_status );

			if ( is_wp_error( $refund ) ) {
				self::log_refund_not_recorded( $order, $order->get_id(), $refund, $refunds_made );
				$order = wc_get_order( $order->get_id() );
				$order->add_order_note(
					/* translators: %s: error message. */
					sprintf( __( 'Order edit not applied: the refund could not be recorded (%s).', 'wpheka-edit-orders-for-woocommerce' ), $refund->get_error_message() )
					. self::refunds_made_note( $order, $refunds_made )
				);
				$order->save();
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

		// The same line's units on balance orders change with it, with their stock.
		foreach ( $plan->get_balance_repoints() as $repoint ) {
			$paid      = wc_get_order( $repoint['balance_id'] );
			$item      = $paid ? $paid->get_item( $repoint['item_id'] ) : null;
			$variation = wc_get_product( $repoint['variation_id'] );
			if ( ! $item instanceof WC_Order_Item_Product || ! $variation ) {
				continue;
			}
			$stock_note = Edit_Orders_For_WooCommerce_Stock::repoint( $paid, $item, $variation );
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

		// The paid balance order now holds part of what some lines (and the shipping) cost.
		// Record it: a later edit of those lines, or of the address, would otherwise refund
		// only what the original order holds. Refunds for that part go through the balance order.
		if ( $balance ) {
			foreach ( $plan->get_balance_items() as $line ) {
				$item = empty( $line['for_item'] ) ? null : $order->get_item( $line['for_item'] );
				if ( $item instanceof WC_Order_Item_Product ) {
					$item->update_meta_data( Edit_Orders_For_WooCommerce_Balance_Orders::LINE_META, $balance->get_id() );
					$item->save();
				}
			}
			$applied   = (array) $order->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_BALANCES_META );
			$applied[] = $balance->get_id();
			$order->update_meta_data( Edit_Orders_For_WooCommerce_Balance_Orders::APPLIED_BALANCES_META, array_values( array_unique( array_filter( array_map( 'absint', $applied ) ) ) ) );
		}

		$order->add_order_note(
			( $balance
				/* translators: %s: balance order number. */
				? sprintf( __( 'Order edited (paid through balance order #%s):', 'wpheka-edit-orders-for-woocommerce' ), $balance->get_order_number() )
				: __( 'Order edited:', 'wpheka-edit-orders-for-woocommerce' ) )
			. ' ' . implode( ' ', $notes )
		);
		$order->save();
		if ( $address ) {
			// The order's details changed: read them again before the next entry about it.
			Edit_Orders_For_WooCommerce_Log::forget_order( $order->get_id() );
		}
		Edit_Orders_For_WooCommerce_Log::info(
			sprintf( 'order %d: plan %s applied', $order->get_id(), $plan->fingerprint() ) . ( $balance ? sprintf( ' (paid through balance order %d)', $balance->get_id() ) : '' ),
			array(
				'order'   => $order->get_id(),
				'balance' => $balance ? $balance->get_id() : 0,
			)
		);

		// One refund summary for the emails and the activity log: the original order's
		// refund, or the first balance order's, flagged manual if any of them is.
		if ( $balance_refunds ) {
			$summary = is_array( $refund ) ? $refund : reset( $balance_refunds );
			foreach ( $balance_refunds as $done ) {
				if ( ! empty( $done['manual'] ) ) {
					$summary['manual'] = true;
				}
			}
			$refund = $summary;
		}

		return array(
			'status'          => 'applied',
			'plan'            => $plan,
			'refund'          => $refund,
			'balance_refunds' => $balance_refunds,
		);
	}

	/**
	 * Log a refund WooCommerce could not even record, with the refunds this run made before it.
	 *
	 * @param WC_Order $order        Original order.
	 * @param int      $refunded_on  Order the refund was for.
	 * @param WP_Error $error        WooCommerce's error.
	 * @param array[]  $refunds_made { order: WC_Order, amount }.
	 */
	private static function log_refund_not_recorded( WC_Order $order, $refunded_on, WP_Error $error, array $refunds_made ) {
		$made = array();
		foreach ( $refunds_made as $done ) {
			$made[] = Edit_Orders_For_WooCommerce_Log::money( $done['amount'], $order->get_currency() ) . ' on balance order ' . $done['order']->get_id();
		}

		Edit_Orders_For_WooCommerce_Log::error(
			sprintf( 'order %d: refund on order %d could not be recorded: %s. Refunds already made in this run: %s', $order->get_id(), $refunded_on, Edit_Orders_For_WooCommerce_Log::clip( $error->get_error_message() ), $made ? implode( '; ', $made ) : 'none' ),
			array(
				'order'        => $order->get_id(),
				'refunded_on'  => (int) $refunded_on,
				'code'         => $error->get_error_code(),
				'refunds_made' => $made,
			)
		);
	}

	/**
	 * For a run that stopped part way: the refunds it already made, which stand, so the
	 * store owner can check them against the order.
	 *
	 * @param WC_Order $order        Original order.
	 * @param array[]  $refunds_made { order: WC_Order, amount }.
	 * @return string Empty when none were made, otherwise a sentence starting with a space.
	 */
	private static function refunds_made_note( WC_Order $order, array $refunds_made ) {
		if ( ! $refunds_made ) {
			return '';
		}

		$made = array();
		foreach ( $refunds_made as $done ) {
			$made[] = sprintf(
				/* translators: 1: amount, 2: order number. */
				__( '%1$s on balance order #%2$s', 'wpheka-edit-orders-for-woocommerce' ),
				wp_strip_all_tags( wc_price( $done['amount'], array( 'currency' => $order->get_currency() ) ) ),
				$done['order']->get_order_number()
			);
		}

		/* translators: %s: list of refunds, such as "$22.60 on balance order #123". */
		return ' ' . sprintf( __( 'These refunds for this edit were already made and stand: %s. Check them against the order.', 'wpheka-edit-orders-for-woocommerce' ), implode( '; ', $made ) );
	}
}
