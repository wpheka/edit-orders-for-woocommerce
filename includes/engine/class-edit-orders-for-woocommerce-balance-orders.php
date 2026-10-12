<?php
/**
 * Balance orders: a linked order for an amount the customer still owes (spec section 2.1 rule 3).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Balance_Orders Class.
 *
 * WooCommerce takes one payment per order, so an increase goes on a child order
 * (`parent_id` = the original, `created_via` = edit-orders) that the customer
 * pays through the normal pay-for-order page with any gateway. Its stored plan
 * is applied to the original order once it is paid, exactly once.
 */
class Edit_Orders_For_WooCommerce_Balance_Orders {

	/**
	 * `created_via` value for balance orders.
	 */
	const CREATED_VIA = 'edit-orders';

	/**
	 * Balance order meta: the stored settlement plan.
	 */
	const PLAN_META = '_edit_orders_for_woocommerce_plan';

	/**
	 * Balance order meta: set once its plan has been applied.
	 */
	const APPLIED_META = '_edit_orders_for_woocommerce_applied';

	/**
	 * Balance order meta: when applying its plan started. Removed once it finished.
	 */
	const APPLYING_META = '_edit_orders_for_woocommerce_applying';

	/**
	 * Balance order meta: applying its plan never finished (fatal error or timeout).
	 */
	const STUCK_META = '_edit_orders_for_woocommerce_apply_stuck';

	/**
	 * Seconds after which an unfinished apply counts as stuck.
	 */
	const APPLYING_TIMEOUT = 300;

	/**
	 * Original order meta: the open balance order's ID.
	 */
	const OPEN_BALANCE_META = '_edit_orders_for_woocommerce_open_balance';

	/**
	 * Line meta: the balance order that holds part of what this line now costs
	 * (extra units or a price difference).
	 */
	const LINE_META = '_edit_orders_for_woocommerce_balance_order';

	/**
	 * Order meta: balance orders whose changes were applied to this order.
	 */
	const APPLIED_BALANCES_META = '_edit_orders_for_woocommerce_applied_balances';

	/**
	 * Balance order item meta: the original order's line this item belongs to.
	 */
	const FOR_ITEM_META = '_edit_orders_for_woocommerce_for_item';

	/**
	 * Statuses in which a balance order is still waiting for payment.
	 */
	const OPEN_STATUSES = array( 'pending', 'failed', 'on-hold' );

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_apply' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_apply' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_apply' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'close_for_original' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'close_for_original' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'close_for_original' ) );
		// Completed means shipped: an unpaid balance for changes to it can't apply any more.
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'close_for_original' ) );
	}

	/**
	 * When the original order is cancelled, refunded or fails anywhere (including
	 * WooCommerce's own order screen), cancel its unpaid balance order so the
	 * customer can't pay for a change to an order that is gone.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function close_for_original( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || self::is_balance_order( $order ) ) {
			return;
		}

		$balance = self::get_open_balance_order( $order );
		if ( $balance ) {
			/* translators: %s: original order status. */
			$balance->update_status( 'cancelled', sprintf( __( 'The original order is now %s.', 'wpheka-edit-orders-for-woocommerce' ), wc_get_order_status_name( $order->get_status() ) ) );
		}

		// Paid balance orders hold money for this order too. Cancelling or refunding it from
		// WooCommerce's own screen doesn't touch them: name them, so they get refunded as well.
		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			foreach ( (array) $order->get_meta( self::APPLIED_BALANCES_META ) as $paid_id ) {
				$paid = wc_get_order( (int) $paid_id );
				$left = $paid ? (float) $paid->get_remaining_refund_amount() : 0.0;
				if ( $paid && $paid->get_date_paid() && $left > 0 ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: balance order number, 2: amount. */
							__( 'Balance order #%1$s was paid for changes to this order and still holds %2$s. Refund it too if the customer is owed it.', 'wpheka-edit-orders-for-woocommerce' ),
							$paid->get_order_number(),
							wc_price( $left, array( 'currency' => $paid->get_currency() ) )
						)
					);
				}
			}
		}
	}

	/**
	 * Is this a balance order?
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_balance_order( WC_Order $order ) {
		return self::CREATED_VIA === $order->get_created_via() && $order->get_parent_id() > 0;
	}

	/**
	 * Create a balance order for a plan.
	 *
	 * @param WC_Order                                    $order Original order.
	 * @param Edit_Orders_For_WooCommerce_Settlement_Plan $plan  Plan.
	 * @return WC_Order|WP_Error
	 */
	public static function create( WC_Order $order, Edit_Orders_For_WooCommerce_Settlement_Plan $plan ) {
		$balance = wc_create_order(
			array(
				'customer_id' => $order->get_customer_id(),
				'created_via' => self::CREATED_VIA,
				'parent'      => $order->get_id(),
			)
		);

		if ( is_wp_error( $balance ) ) {
			return $balance;
		}

		// For an address change the balance belongs to the new address.
		$addresses = $plan->get_address_update();
		$balance->set_address( isset( $addresses['billing'] ) ? $addresses['billing'] : $order->get_address( 'billing' ), 'billing' );
		$balance->set_address( isset( $addresses['shipping'] ) ? $addresses['shipping'] : $order->get_address( 'shipping' ), 'shipping' );
		$balance->set_currency( $order->get_currency() );
		$balance->set_prices_include_tax( $order->get_prices_include_tax() );
		// A VAT-exempt customer stays exempt on what they owe for the change.
		if ( Edit_Orders_For_WooCommerce_Pricing::is_vat_exempt( $order ) ) {
			$balance->update_meta_data( 'is_vat_exempt', 'yes' );
		}

		$explicit_taxes = false;
		foreach ( $plan->get_balance_items() as $line ) {
			if ( 'shipping' === $line['type'] ) {
				$item = new WC_Order_Item_Shipping();
				$item->set_method_title( $line['name'] );
				$item->set_method_id( $line['method_id'] );
				$item->set_instance_id( $line['instance_id'] );
				$item->set_total( $line['total'] );
			} elseif ( 'product' === $line['type'] ) {
				$product = wc_get_product( $line['product_id'] );
				if ( ! $product ) {
					$balance->delete( true );
					return new WP_Error( 'edit_orders_for_woocommerce_balance_product', __( 'A product on the balance order no longer exists.', 'wpheka-edit-orders-for-woocommerce' ) );
				}
				$item = new WC_Order_Item_Product();
				$item->set_product( $product );
				$item->set_quantity( $line['quantity'] );
				$item->set_subtotal( $line['subtotal'] );
				$item->set_total( $line['total'] );
			} else {
				$item = new WC_Order_Item_Fee();
				$item->set_name( $line['name'] );
				$item->set_tax_class( $line['tax_class'] );
				$item->set_tax_status( ( ! isset( $line['taxable'] ) || $line['taxable'] ) ? 'taxable' : 'none' );
				$item->set_total( $line['total'] );
			}
			if ( isset( $line['taxes'] ) ) {
				$item->set_taxes( array( 'total' => $line['taxes'] ) );
				$explicit_taxes = true;
			}
			// Which original line this pays for, so a later edit of that line can refund it.
			if ( ! empty( $line['for_item'] ) ) {
				$item->add_meta_data( self::FOR_ITEM_META, absint( $line['for_item'] ), true );
			}
			$balance->add_item( $item );
		}

		if ( $explicit_taxes ) {
			// Address changes: the plan already worked out tax per rate; keep it as is.
			$balance->update_taxes();
			$balance->calculate_totals( false );
		} else {
			// Tax for the order's address, at today's rates.
			$balance->calculate_totals( true );
		}
		$balance->update_meta_data( self::PLAN_META, $plan->to_array() );
		$balance->add_order_note(
			/* translators: %s: original order number. */
			sprintf( __( 'Balance for changes to order #%s.', 'wpheka-edit-orders-for-woocommerce' ), $order->get_order_number() )
			. ' ' . implode( ' ', $plan->get_descriptions() )
		);
		$balance->save();

		$order->update_meta_data( self::OPEN_BALANCE_META, $balance->get_id() );
		$order->save();

		return $balance;
	}

	/**
	 * The parts of an original order's line that were paid on its balance orders: extra
	 * units (product lines) and price differences (fees), with what is left of each after
	 * any refunds. Newest balance order first, so a decrease takes the last units added.
	 *
	 * Only balance orders whose items the customer has count: paid ones, and unpaid cash on
	 * delivery ones (collected with the original order), unless cancelled or refunded.
	 *
	 * Balance order items carry FOR_ITEM_META. Items of balance orders made by 1.0.0 are
	 * matched to the stored plan's balance items (see part_targets()).
	 *
	 * @param WC_Order              $order Original order.
	 * @param WC_Order_Item_Product $item  Line on the original order.
	 * @return array[] { balance: WC_Order, item: WC_Order_Item, type: product|fee, qty_left, total_left, tax_left: rate ID => amount }
	 */
	public static function line_parts( WC_Order $order, WC_Order_Item_Product $item ) {
		$parts = array();

		foreach ( self::held_balance_orders( $order ) as $balance ) {
			$targets = self::part_targets( $balance );
			if ( null === $targets ) {
				continue;
			}

			foreach ( $balance->get_items( array( 'line_item', 'fee' ) ) as $part_id => $part ) {
				if ( ! isset( $targets[ $part_id ] ) || $targets[ $part_id ] !== (int) $item->get_id() ) {
					continue;
				}

				$type     = $part->get_type();
				$taxes    = $part->get_taxes();
				$tax_left = array();
				foreach ( isset( $taxes['total'] ) ? $taxes['total'] : array() as $rate_id => $tax ) {
					if ( '' !== $tax ) {
						$tax_left[ $rate_id ] = (float) $tax - self::tax_refunded_for_item( $balance, (int) $part->get_id(), $rate_id, $type );
					}
				}

				$parts[] = array(
					'balance'    => $balance,
					'item'       => $part,
					'type'       => 'line_item' === $type ? 'product' : 'fee',
					'qty'        => 'line_item' === $type ? (int) $part->get_quantity() : 0,
					'qty_left'   => 'line_item' === $type ? max( 0, (int) $part->get_quantity() + (int) $balance->get_qty_refunded_for_item( $part->get_id() ) ) : 0,
					'total_left' => (float) $part->get_total() - (float) $balance->get_total_refunded_for_item( $part->get_id(), $type ),
					'tax_left'   => $tax_left,
				);
			}
		}

		return $parts;
	}

	/**
	 * The original order's applied balance orders whose items the customer has, newest
	 * first: paid, or cash on delivery still to be collected. Not cancelled, refunded or
	 * failed ones: their items are not with the customer, or their money is already back.
	 *
	 * @param WC_Order $order Original order.
	 * @return WC_Order[]
	 */
	private static function held_balance_orders( WC_Order $order ) {
		$held = array();
		foreach ( array_reverse( (array) $order->get_meta( self::APPLIED_BALANCES_META ) ) as $balance_id ) {
			$balance = wc_get_order( absint( $balance_id ) );
			if ( ! $balance || ! self::is_balance_order( $balance ) || $balance->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) ) {
				continue;
			}
			if ( $balance->get_date_paid() || ( Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $balance ) && $balance->has_status( array( 'processing', 'on-hold', 'completed' ) ) ) ) {
				$held[] = $balance;
			}
		}

		return $held;
	}

	/**
	 * Which original line each product line and fee of a balance order pays for:
	 * item ID => original line ID (0 for none, such as an added product).
	 *
	 * Balance orders made by 1.0.0 have no FOR_ITEM_META on their items. Their stored plan
	 * lists the items in the order they were added, but the order returns them grouped by
	 * type, so each type is matched on its own, in item ID order, and every pair is checked
	 * (the same product, or the same fee name and amount). When anything doesn't match,
	 * returns null: the lines the plan names stay refused, as they were in 1.0.0.
	 *
	 * @param WC_Order $balance Balance order.
	 * @return int[]|null
	 */
	private static function part_targets( WC_Order $balance ) {
		$targets = array();
		$legacy  = array(
			'line_item' => array(),
			'fee'       => array(),
		);
		foreach ( $balance->get_items( array( 'line_item', 'fee' ) ) as $part_id => $part ) {
			$for = (int) $part->get_meta( self::FOR_ITEM_META );
			if ( $for ) {
				$targets[ $part_id ] = $for;
			} else {
				$legacy[ $part->get_type() ][ $part_id ] = $part;
			}
		}
		if ( ! $legacy['line_item'] && ! $legacy['fee'] ) {
			return $targets;
		}

		$plan  = $balance->get_meta( self::PLAN_META );
		$lines = is_array( $plan ) && isset( $plan['balance_items'] ) ? (array) $plan['balance_items'] : array();
		foreach ( array(
			'line_item' => 'product',
			'fee'       => 'fee',
		) as $type => $line_type ) {
			$planned = array();
			foreach ( $lines as $line ) {
				if ( isset( $line['type'] ) && $line_type === $line['type'] ) {
					$planned[] = $line;
				}
			}
			$parts = $legacy[ $type ];
			ksort( $parts );
			if ( count( $planned ) !== count( $parts ) ) {
				return null;
			}

			foreach ( array_values( $parts ) as $index => $part ) {
				$line = $planned[ $index ];
				if ( 'product' === $line_type ) {
					// A later swap may have moved the item to another variation of the same product.
					$planned_id = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
					$parent_id  = (int) wp_get_post_parent_id( $planned_id );
					$same       = (int) $part->get_product_id() === ( $parent_id ? $parent_id : $planned_id );
				} else {
					$same = isset( $line['name'], $line['total'] ) && (string) $line['name'] === $part->get_name() && abs( (float) $line['total'] - (float) $part->get_total() ) < 0.005;
				}
				if ( ! $same ) {
					return null;
				}
				$targets[ $part->get_id() ] = empty( $line['for_item'] ) ? 0 : (int) $line['for_item'];
			}
		}

		return $targets;
	}

	/**
	 * A balance order the customer has that pays for this line in a way that can't be
	 * matched to its items (see part_targets()). The line can't be changed then.
	 *
	 * @param WC_Order              $order Original order.
	 * @param WC_Order_Item_Product $item  Line.
	 * @return WC_Order|null
	 */
	public static function unmatched_balance_order( WC_Order $order, WC_Order_Item_Product $item ) {
		foreach ( self::held_balance_orders( $order ) as $balance ) {
			if ( null !== self::part_targets( $balance ) ) {
				continue;
			}
			$plan = $balance->get_meta( self::PLAN_META );
			foreach ( is_array( $plan ) && isset( $plan['balance_items'] ) ? (array) $plan['balance_items'] : array() as $line ) {
				if ( ! empty( $line['for_item'] ) && (int) $line['for_item'] === (int) $item->get_id() ) {
					return $balance;
				}
			}
		}

		return null;
	}

	/**
	 * Tax refunded on a balance order item for one rate, as a positive amount.
	 *
	 * WooCommerce rounds the sum to cents. With tax rounded at subtotal the refunds store
	 * unrounded tax, so the rounded sum would leave a fraction of a cent too much or too
	 * little: sum them as stored then.
	 *
	 * @param WC_Order $balance Balance order.
	 * @param int      $part_id Item ID.
	 * @param int      $rate_id Tax rate ID.
	 * @param string   $type    Item type.
	 * @return float
	 */
	private static function tax_refunded_for_item( WC_Order $balance, $part_id, $rate_id, $type ) {
		if ( ! Edit_Orders_For_WooCommerce_Pricing::rounds_tax_at_subtotal() ) {
			return (float) $balance->get_tax_refunded_for_item( $part_id, $rate_id, $type );
		}

		$total = 0.0;
		foreach ( $balance->get_refunds() as $refund ) {
			foreach ( $refund->get_items( $type ) as $refunded_item ) {
				if ( (int) $refunded_item->get_meta( '_refunded_item_id' ) === $part_id ) {
					$taxes  = $refunded_item->get_taxes();
					$total += isset( $taxes['total'][ $rate_id ] ) ? (float) $taxes['total'][ $rate_id ] : 0;
				}
			}
		}

		return -1 * $total;
	}

	/**
	 * A line's quantity including the units paid on balance orders and not refunded.
	 *
	 * @param WC_Order              $order Original order.
	 * @param WC_Order_Item_Product $item  Line.
	 * @param array[]|null          $parts From line_parts(), if already known.
	 * @return int
	 */
	public static function effective_quantity( WC_Order $order, WC_Order_Item_Product $item, $parts = null ) {
		$quantity = (int) $item->get_quantity();
		foreach ( null === $parts ? self::line_parts( $order, $item ) : $parts as $part ) {
			$quantity += (int) $part['qty_left'];
		}

		return $quantity;
	}

	/**
	 * The unpaid balance order for an original order, if there is one.
	 *
	 * @param WC_Order $order Original order.
	 * @return WC_Order|null
	 */
	public static function get_open_balance_order( WC_Order $order ) {
		$balance_id = (int) $order->get_meta( self::OPEN_BALANCE_META );
		if ( ! $balance_id ) {
			return null;
		}

		$balance = wc_get_order( $balance_id );

		return ( $balance && in_array( $balance->get_status(), self::OPEN_STATUSES, true ) ) ? $balance : null;
	}

	/**
	 * Is the order's balance order paid but not applied yet? Between payment and the
	 * plan running, a second edit could refund the same line again: it waits instead.
	 *
	 * @param WC_Order $order Original order.
	 * @return bool
	 */
	public static function is_applying( WC_Order $order ) {
		$balance_id = (int) $order->get_meta( self::OPEN_BALANCE_META );
		$balance    = $balance_id ? wc_get_order( $balance_id ) : null;

		if ( ! $balance
			|| in_array( $balance->get_status(), self::OPEN_STATUSES, true )
			|| $balance->has_status( array( 'cancelled', 'refunded', 'trash' ) )
			|| $balance->get_meta( self::APPLIED_META ) ) {
			return false;
		}

		// Applying started but never finished: the next look at the order settles it.
		if ( self::is_stuck( $balance ) ) {
			self::mark_stuck( $balance );
			return false;
		}

		return true;
	}

	/**
	 * Did applying this balance order's plan start and never finish?
	 *
	 * @param WC_Order $balance Balance order.
	 * @return bool
	 */
	public static function is_stuck( WC_Order $balance ) {
		$started = (int) $balance->get_meta( self::APPLYING_META );

		return $started && ! $balance->get_meta( self::APPLIED_META ) && time() - $started > self::APPLYING_TIMEOUT;
	}

	/**
	 * Renew the "applying" time while a plan runs, before each step that can be slow (a
	 * gateway refund), so a run that is still working is never taken for one that stopped.
	 *
	 * @param WC_Order $balance Balance order being applied.
	 */
	public static function renew_applying( WC_Order $balance ) {
		$balance = wc_get_order( $balance->get_id() );
		if ( $balance && $balance->get_meta( self::APPLYING_META ) && ! $balance->get_meta( self::APPLIED_META ) ) {
			$balance->update_meta_data( self::APPLYING_META, time() );
			$balance->save_meta_data();
		}
	}

	/**
	 * Settle a balance order whose plan never finished applying (a fatal error or a
	 * timeout in the middle): never apply it again, tell the store owner on the original
	 * order and flag the balance order for a manual refund, as when the plan can't apply.
	 *
	 * @param WC_Order $balance Balance order.
	 */
	public static function mark_stuck( WC_Order $balance ) {
		$balance = wc_get_order( $balance->get_id() );
		if ( ! $balance || $balance->get_meta( self::APPLIED_META ) ) {
			return;
		}

		$balance->update_meta_data( self::APPLIED_META, time() );
		$balance->update_meta_data( self::STUCK_META, (int) $balance->get_meta( self::APPLYING_META ) );
		$balance->delete_meta_data( self::APPLYING_META );
		$balance->save();

		$order = wc_get_order( $balance->get_parent_id() );
		if ( $order ) {
			if ( (int) $order->get_meta( self::OPEN_BALANCE_META ) === $balance->get_id() ) {
				$order->delete_meta_data( self::OPEN_BALANCE_META );
			}
			$order->add_order_note(
				sprintf(
					/* translators: %s: balance order number. */
					__( 'Balance order #%s was paid, but applying the changes did not finish (the site stopped part way). Check this order against the change, then refund the balance order or make the rest of the change by hand.', 'wpheka-edit-orders-for-woocommerce' ),
					$balance->get_order_number()
				)
			);
			$order->save();
		}

		if ( $balance->get_date_paid() ) {
			Edit_Orders_For_WooCommerce_Refunds::flag_manual_refund(
				$balance,
				wc_format_decimal( $balance->get_total(), wc_get_price_decimals() ),
				0,
				'',
				__( 'The customer paid this balance order, but applying the changes it paid for did not finish. Check the original order before refunding.', 'wpheka-edit-orders-for-woocommerce' )
			);
		}
	}

	/**
	 * Apply a balance order's plan to the original order once it is paid.
	 *
	 * Hooked to payment complete and the processing and completed statuses; the
	 * applied flag makes the second and third calls do nothing.
	 *
	 * @param int $balance_id Order ID.
	 */
	public static function maybe_apply( $balance_id ) {
		$balance = wc_get_order( $balance_id );
		if ( ! $balance || ! self::is_balance_order( $balance ) || $balance->get_meta( self::APPLIED_META ) ) {
			return;
		}

		// Started before and never finished: settle it, never apply twice.
		if ( $balance->get_meta( self::APPLYING_META ) ) {
			if ( self::is_stuck( $balance ) ) {
				self::mark_stuck( $balance );
			}
			return;
		}

		$order = wc_get_order( $balance->get_parent_id() );
		$data  = $balance->get_meta( self::PLAN_META );
		if ( ! $order || ! is_array( $data ) ) {
			return;
		}

		// Payment complete and the status change can arrive in parallel requests
		// (return page and webhook): only one of them applies the plan.
		$guard = 'balance_' . $balance->get_id();
		if ( ! Edit_Orders_For_WooCommerce_Lock::claim( $guard ) ) {
			return;
		}
		$balance = wc_get_order( $balance->get_id() );
		if ( $balance->get_meta( self::APPLIED_META ) || $balance->get_meta( self::APPLYING_META ) ) {
			Edit_Orders_For_WooCommerce_Lock::unclaim( $guard );
			return;
		}

		// "Applying" first, so a status change fired while applying can't apply twice, and
		// "applied" only once run() has returned: a fatal error or timeout in between leaves
		// "applying", which the next look at the order settles (see mark_stuck()).
		$balance->update_meta_data( self::APPLYING_META, time() );
		$balance->save();

		// Only the order's current balance applies. One paid after it was cancelled (a slow
		// gateway or webhook) may have been replaced by a newer change: leave that one waiting
		// and flag the late payment for a refund.
		$current = (int) $order->get_meta( self::OPEN_BALANCE_META ) === $balance->get_id();
		$plan    = Edit_Orders_For_WooCommerce_Settlement_Plan::from_array( $data );
		$result  = $current ? $plan->still_applies( $order ) : new WP_Error( 'edit_orders_for_woocommerce_balance_superseded', __( 'it was paid after being cancelled', 'wpheka-edit-orders-for-woocommerce' ) );
		if ( true === $result ) {
			$result = Edit_Orders_For_WooCommerce_Settlement_Executor::run( $order, $plan, $balance );
		}

		$balance = wc_get_order( $balance->get_id() );
		// Another request took this run for one that had stopped (see mark_stuck()) while it
		// was still working: it did finish, so put that right below.
		$was_stuck = (bool) $balance->get_meta( self::STUCK_META );
		$balance->update_meta_data( self::APPLIED_META, time() );
		$balance->delete_meta_data( self::APPLYING_META );
		$balance->delete_meta_data( self::STUCK_META );
		$balance->save();

		$order = wc_get_order( $order->get_id() );
		if ( $current ) {
			$order->delete_meta_data( self::OPEN_BALANCE_META );
		}
		if ( $was_stuck ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: balance order number. */
					__( 'Balance order #%s: applying the changes did finish after all. Ignore the earlier note that it did not.', 'wpheka-edit-orders-for-woocommerce' ),
					$balance->get_order_number()
				)
			);
			if ( ! is_wp_error( $result ) && $balance->get_date_paid() ) {
				Edit_Orders_For_WooCommerce_Refunds::clear_manual_refund(
					$balance,
					wc_format_decimal( $balance->get_total(), wc_get_price_decimals() ),
					__( 'No manual refund is needed after all: the changes this balance order paid for did finish applying.', 'wpheka-edit-orders-for-woocommerce' )
				);
			}
		}
		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: balance order number, 2: reason. */
					__( 'Balance order #%1$s was paid, but the changes were not applied: %2$s. Refund the balance order or make the change by hand.', 'wpheka-edit-orders-for-woocommerce' ),
					$balance->get_order_number(),
					$result->get_error_message()
				)
			);
		} else {
			$order->add_order_note(
				/* translators: %s: balance order number. */
				sprintf( __( 'Balance order #%s paid; changes applied.', 'wpheka-edit-orders-for-woocommerce' ), $balance->get_order_number() )
			);
		}
		$order->save();

		if ( is_wp_error( $result ) && $balance->get_date_paid() && ! $was_stuck ) {
			// The customer paid for a change that didn't happen: the store owner must refund it.
			// One marked stuck was flagged already.
			Edit_Orders_For_WooCommerce_Refunds::flag_manual_refund(
				$balance,
				wc_format_decimal( $balance->get_total(), wc_get_price_decimals() ),
				0,
				'',
				/* translators: %s: reason. */
				sprintf( __( 'The customer paid this balance order, but the changes it paid for could not be applied: %s. Nothing has been refunded yet.', 'wpheka-edit-orders-for-woocommerce' ), $result->get_error_message() )
			);
		}

		Edit_Orders_For_WooCommerce_Lock::unclaim( $guard );

		/**
		 * Fired once when a balance order is paid and its changes are applied.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Order       $balance Balance order.
		 * @param WC_Order       $order   Original order.
		 * @param array|WP_Error $result  Result of applying the plan.
		 */
		do_action( 'edit_orders_for_woocommerce_balance_paid', $balance, $order, $result );
	}

	/**
	 * When a balance order is cancelled, free the original order for new edits.
	 *
	 * @param int $balance_id Order ID.
	 */
	public static function release( $balance_id ) {
		$balance = wc_get_order( $balance_id );
		if ( ! $balance || ! self::is_balance_order( $balance ) ) {
			return;
		}

		$order = wc_get_order( $balance->get_parent_id() );
		if ( $order && (int) $order->get_meta( self::OPEN_BALANCE_META ) === $balance->get_id() ) {
			$order->delete_meta_data( self::OPEN_BALANCE_META );
			/* translators: %s: balance order number. */
			$order->add_order_note( sprintf( __( 'Balance order #%s cancelled; the requested changes were not applied.', 'wpheka-edit-orders-for-woocommerce' ), $balance->get_order_number() ) );
			$order->save();
		}
	}
}
