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
	 * Original order meta: the open balance order's ID.
	 */
	const OPEN_BALANCE_META = '_edit_orders_for_woocommerce_open_balance';

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
			$balance->update_status( 'cancelled', sprintf( __( 'The original order is now %s.', 'edit-orders-for-woocommerce' ), wc_get_order_status_name( $order->get_status() ) ) );
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
					return new WP_Error( 'edit_orders_for_woocommerce_balance_product', __( 'A product on the balance order no longer exists.', 'edit-orders-for-woocommerce' ) );
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
			sprintf( __( 'Balance for changes to order #%s.', 'edit-orders-for-woocommerce' ), $order->get_order_number() )
			. ' ' . implode( ' ', $plan->get_descriptions() )
		);
		$balance->save();

		$order->update_meta_data( self::OPEN_BALANCE_META, $balance->get_id() );
		$order->save();

		return $balance;
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
		if ( $balance->get_meta( self::APPLIED_META ) ) {
			Edit_Orders_For_WooCommerce_Lock::unclaim( $guard );
			return;
		}

		// Mark first, so a status change fired while applying can't apply twice.
		$balance->update_meta_data( self::APPLIED_META, time() );
		$balance->save();

		$plan   = Edit_Orders_For_WooCommerce_Settlement_Plan::from_array( $data );
		$result = $plan->still_applies( $order );
		if ( true === $result ) {
			$result = Edit_Orders_For_WooCommerce_Settlement_Executor::run( $order, $plan, $balance );
		}

		$order = wc_get_order( $order->get_id() );
		$order->delete_meta_data( self::OPEN_BALANCE_META );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: balance order number, 2: reason. */
					__( 'Balance order #%1$s was paid, but the changes were not applied: %2$s. Refund the balance order or make the change by hand.', 'edit-orders-for-woocommerce' ),
					$balance->get_order_number(),
					$result->get_error_message()
				)
			);
		} else {
			$order->add_order_note(
				/* translators: %s: balance order number. */
				sprintf( __( 'Balance order #%s paid; changes applied.', 'edit-orders-for-woocommerce' ), $balance->get_order_number() )
			);
		}
		$order->save();

		if ( is_wp_error( $result ) && $balance->get_date_paid() ) {
			// The customer paid for a change that didn't happen: the store owner must refund it.
			Edit_Orders_For_WooCommerce_Refunds::flag_manual_refund(
				$balance,
				wc_format_decimal( $balance->get_total(), wc_get_price_decimals() ),
				0,
				'',
				/* translators: %s: reason. */
				sprintf( __( 'The customer paid this balance order, but the changes it paid for could not be applied: %s. Nothing has been refunded yet.', 'edit-orders-for-woocommerce' ), $result->get_error_message() )
			);
		}

		Edit_Orders_For_WooCommerce_Lock::unclaim( $guard );

		/**
		 * Fired once when a balance order is paid and its changes are applied.
		 *
		 * @since 0.1.0
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
			$order->add_order_note( sprintf( __( 'Balance order #%s cancelled; the requested changes were not applied.', 'edit-orders-for-woocommerce' ), $balance->get_order_number() ) );
			$order->save();
		}
	}
}
