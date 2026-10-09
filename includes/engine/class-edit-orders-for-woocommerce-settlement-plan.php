<?php
/**
 * Settlement plan: what money and stock move for a change set (spec section 2).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Settlement_Plan Class.
 *
 * Built from an order and a change set without writing anything. The plan holds:
 *
 * - refund lines: WooCommerce refund line items (qty, refund_total, refund_tax);
 * - balance items: product lines and fees for a balance order, when the customer owes more;
 * - repoints: lines to switch to another variation, with no money moving on the line itself.
 *
 * A plan is applied atomically. With a balance it waits for that balance to be
 * paid, refunds included, so a customer is never refunded for half of a change
 * they abandon (spec 2.1 rule 6). Without one it applies at once.
 */
class Edit_Orders_For_WooCommerce_Settlement_Plan {

	/**
	 * Order ID.
	 *
	 * @var int
	 */
	private $order_id = 0;

	/**
	 * Refund lines keyed by order item ID.
	 *
	 * @var array[]
	 */
	private $refund_lines = array();

	/**
	 * Items for the balance order.
	 *
	 * @var array[]
	 */
	private $balance_items = array();

	/**
	 * Lines to switch to another variation: item_id, variation_id.
	 *
	 * @var array[]
	 */
	private $repoints = array();

	/**
	 * New addresses and shipping line details to write once settled, if any.
	 *
	 * @var array
	 */
	private $address_update = array();

	/**
	 * Human-readable description of each change, for notes and the preview.
	 *
	 * @var string[]
	 */
	private $descriptions = array();

	/**
	 * Reasons the plan can't be applied.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * What had been refunded on the order when the plan was built, or null if unknown.
	 *
	 * A balance order applies its plan later; if anything was refunded in between,
	 * the plan's refund lines may no longer be right.
	 *
	 * @var float|null
	 */
	private $refunded_at_build = null;

	/**
	 * The order's lines when the plan was built (see line_states()), so a plan applied
	 * later can tell whether the store changed the order in between.
	 *
	 * @var array|null
	 */
	private $lines_at_build = null;

	/**
	 * Build a plan.
	 *
	 * @param WC_Order                               $order      Order.
	 * @param Edit_Orders_For_WooCommerce_Change_Set $change_set Change set.
	 * @return Edit_Orders_For_WooCommerce_Settlement_Plan
	 */
	public static function build( WC_Order $order, Edit_Orders_For_WooCommerce_Change_Set $change_set ) {
		$plan                    = new self();
		$plan->order_id          = $order->get_id();
		$plan->errors            = $change_set->get_errors();
		$plan->refunded_at_build = (float) $order->get_total_refunded();
		$plan->lines_at_build    = self::line_states( $order );

		foreach ( $change_set->get_changes() as $change ) {
			if ( 'add' === $change['type'] ) {
				$plan->plan_add( $change );
				continue;
			}

			if ( 'address' === $change['type'] ) {
				$plan->plan_address( $order, $change );
				continue;
			}

			$item  = $order->get_item( $change['item_id'] );
			$check = Edit_Orders_For_WooCommerce_Eligibility::check_item( $order, $item );
			if ( is_wp_error( $check ) ) {
				$plan->errors[] = $check->get_error_message();
				continue;
			}

			switch ( $change['type'] ) {
				case 'quantity':
					$plan->plan_quantity( $item, $change['quantity'] );
					break;
				case 'remove':
					$plan->plan_quantity( $item, 0 );
					break;
				case 'swap':
					$plan->plan_swap( $item, $change['variation_id'] );
					break;
			}
		}

		// Refunds are capped by WooCommerce; never plan one it would refuse.
		if ( empty( $plan->errors ) && $plan->get_refund_amount() > (float) $order->get_remaining_refund_amount() ) {
			$plan->errors[] = __( 'The refund would be more than what is left to refund on this order.', 'edit-orders-for-woocommerce' );
		}

		/**
		 * Adjust a settlement plan before it is previewed or applied.
		 *
		 * @since 1.0.0
		 *
		 * @param Edit_Orders_For_WooCommerce_Settlement_Plan $plan       Plan.
		 * @param WC_Order                                    $order      Order.
		 * @param Edit_Orders_For_WooCommerce_Change_Set      $change_set Change set.
		 */
		return apply_filters( 'edit_orders_for_woocommerce_settlement_plan', $plan, $order, $change_set );
	}

	/**
	 * Plan a new quantity for a line. Zero removes it.
	 *
	 * @param WC_Order_Item_Product $item     Line.
	 * @param int                   $quantity New quantity.
	 */
	private function plan_quantity( WC_Order_Item_Product $item, $quantity ) {
		$current = (int) $item->get_quantity();

		if ( $quantity === $current ) {
			return;
		}

		if ( $quantity < $current ) {
			$this->refund_lines[ $item->get_id() ] = Edit_Orders_For_WooCommerce_Pricing::refund_for_quantity( $item, $current - $quantity );
			$this->descriptions[]                  = 0 === $quantity
				/* translators: %s: product name. */
				? sprintf( __( 'Removed "%s".', 'edit-orders-for-woocommerce' ), $item->get_name() )
				/* translators: 1: product name, 2: old quantity, 3: new quantity. */
				: sprintf( __( '"%1$s" quantity %2$d to %3$d.', 'edit-orders-for-woocommerce' ), $item->get_name(), $current, $quantity );
			return;
		}

		// More units: charged at the paid unit price, so coupons and sale prices carry over.
		$product = $item->get_product();
		$extra   = $quantity - $current;

		if ( ! $product->is_in_stock() || ( $product->managing_stock() && ! $product->has_enough_stock( $extra ) ) ) {
			/* translators: %s: product name. */
			$this->errors[] = sprintf( __( 'There is not enough stock of "%s".', 'edit-orders-for-woocommerce' ), $item->get_name() );
			return;
		}

		$this->balance_items[] = array(
			'type'       => 'product',
			'for_item'   => $item->get_id(),
			'product_id' => $product->get_id(),
			'quantity'   => $extra,
			'subtotal'   => Edit_Orders_For_WooCommerce_Pricing::round( (float) $item->get_subtotal() / $current * $extra ),
			'total'      => Edit_Orders_For_WooCommerce_Pricing::round( (float) $item->get_total() / $current * $extra ),
		);
		/* translators: 1: product name, 2: old quantity, 3: new quantity. */
		$this->descriptions[] = sprintf( __( '"%1$s" quantity %2$d to %3$d.', 'edit-orders-for-woocommerce' ), $item->get_name(), $current, $quantity );
	}

	/**
	 * Plan a swap to another variation of the same product.
	 *
	 * @param WC_Order_Item_Product $item         Line.
	 * @param int                   $variation_id New variation ID.
	 */
	private function plan_swap( WC_Order_Item_Product $item, $variation_id ) {
		$new = wc_get_product( $variation_id );

		if ( ! $new || ! $new->is_type( 'variation' ) || (int) $new->get_parent_id() !== (int) $item->get_product_id() ) {
			/* translators: %s: product name. */
			$this->errors[] = sprintf( __( 'That option is not a variation of "%s".', 'edit-orders-for-woocommerce' ), $item->get_name() );
			return;
		}

		if ( (int) $item->get_variation_id() === (int) $new->get_id() ) {
			return;
		}

		// Disabled (private) or unpriced variations can't be bought, so they can't be swapped to.
		if ( 'publish' !== $new->get_status() || ! $new->is_purchasable() ) {
			/* translators: %s: product name. */
			$this->errors[] = sprintf( __( '"%s" is not available.', 'edit-orders-for-woocommerce' ), $new->get_name() );
			return;
		}

		$quantity = (int) $item->get_quantity();
		if ( ! $new->is_in_stock() || ( $new->managing_stock() && ! $new->has_enough_stock( $quantity ) ) ) {
			/* translators: %s: product name. */
			$this->errors[] = sprintf( __( '"%s" is out of stock.', 'edit-orders-for-woocommerce' ), $new->get_name() );
			return;
		}

		$per_unit   = Edit_Orders_For_WooCommerce_Pricing::swap_difference_per_unit( $item, $new );
		$difference = Edit_Orders_For_WooCommerce_Pricing::round( $per_unit * $quantity );

		if ( $difference > 0 ) {
			// A fee taxed like the new variation (its tax status and class), so the tax is reported correctly.
			$this->balance_items[] = array(
				'type'      => 'fee',
				'for_item'  => $item->get_id(),
				/* translators: 1: old product name, 2: new product name. */
				'name'      => sprintf( __( 'Price difference: %1$s to %2$s', 'edit-orders-for-woocommerce' ), $item->get_name(), $new->get_name() ),
				'tax_class' => $new->get_tax_class(),
				'taxable'   => $new->is_taxable(),
				'total'     => $difference,
			);
		} elseif ( $difference < 0 ) {
			$line = Edit_Orders_For_WooCommerce_Pricing::refund_for_amount( $item, abs( $difference ) );
			// A line that cost nothing (a 100% coupon) has nothing to refund: no empty refund.
			if ( Edit_Orders_For_WooCommerce_Pricing::refund_line_amount( $line ) > 0 ) {
				$this->refund_lines[ $item->get_id() ] = $line;
			}
		}

		$this->repoints[] = array(
			'item_id'      => $item->get_id(),
			'variation_id' => $new->get_id(),
		);
		/* translators: 1: old product name, 2: new product name. */
		$this->descriptions[] = sprintf( __( 'Swapped "%1$s" for "%2$s".', 'edit-orders-for-woocommerce' ), $item->get_name(), $new->get_name() );
	}

	/**
	 * Plan adding a product.
	 *
	 * @param array $change Change.
	 */
	private function plan_add( array $change ) {
		$product = wc_get_product( $change['product_id'] );

		if ( ! $product || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || ! $product->exists() ) {
			$this->errors[] = __( 'Choose a single product or a specific variation to add.', 'edit-orders-for-woocommerce' );
			return;
		}

		if ( ! $product->is_in_stock() || ( $product->managing_stock() && ! $product->has_enough_stock( $change['quantity'] ) ) ) {
			/* translators: %s: product name. */
			$this->errors[] = sprintf( __( '"%s" is out of stock.', 'edit-orders-for-woocommerce' ), $product->get_name() );
			return;
		}

		$unit  = isset( $change['price'] ) ? (float) $change['price'] : Edit_Orders_For_WooCommerce_Pricing::current_price( $product );
		$total = Edit_Orders_For_WooCommerce_Pricing::round( $unit * $change['quantity'] );

		$this->balance_items[] = array(
			'type'       => 'product',
			'product_id' => $product->get_id(),
			'quantity'   => $change['quantity'],
			'subtotal'   => $total,
			'total'      => $total,
		);
		/* translators: 1: quantity, 2: product name. */
		$this->descriptions[] = sprintf( __( 'Added %1$d x "%2$s".', 'edit-orders-for-woocommerce' ), $change['quantity'], $product->get_name() );
	}

	/**
	 * Plan an address change.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $change Change.
	 */
	private function plan_address( WC_Order $order, array $change ) {
		$result = Edit_Orders_For_WooCommerce_Address_Change::build( $order, $change );

		if ( is_wp_error( $result ) ) {
			$this->errors[] = $result->get_error_message();
			return;
		}

		$this->refund_lines   = $result['refund_lines'];
		$this->balance_items  = $result['balance_items'];
		$this->address_update = $result['address_update'];
		$this->descriptions   = array_merge( $this->descriptions, $result['descriptions'] );
	}

	/**
	 * New addresses and shipping line details to write once settled.
	 *
	 * @return array
	 */
	public function get_address_update() {
		return $this->address_update;
	}

	/**
	 * Order ID.
	 *
	 * @return int
	 */
	public function get_order_id() {
		return $this->order_id;
	}

	/**
	 * Refund lines keyed by order item ID.
	 *
	 * @return array[]
	 */
	public function get_refund_lines() {
		return $this->refund_lines;
	}

	/**
	 * Total to refund, tax included.
	 *
	 * @return float
	 */
	public function get_refund_amount() {
		$amount = 0.0;
		foreach ( $this->refund_lines as $line ) {
			$amount += Edit_Orders_For_WooCommerce_Pricing::refund_line_amount( $line );
		}

		return Edit_Orders_For_WooCommerce_Pricing::round( $amount );
	}

	/**
	 * Items for the balance order.
	 *
	 * @return array[]
	 */
	public function get_balance_items() {
		return $this->balance_items;
	}

	/**
	 * Does this plan need a balance order?
	 *
	 * @return bool
	 */
	public function needs_balance() {
		return ! empty( $this->balance_items );
	}

	/**
	 * Lines to switch to another variation.
	 *
	 * @return array[]
	 */
	public function get_repoints() {
		return $this->repoints;
	}

	/**
	 * Human-readable descriptions of the changes.
	 *
	 * @return string[]
	 */
	public function get_descriptions() {
		return $this->descriptions;
	}

	/**
	 * Reasons the plan can't be applied.
	 *
	 * @return string[]
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Is there anything to do?
	 *
	 * @return bool
	 */
	public function has_changes() {
		return ! empty( $this->refund_lines ) || ! empty( $this->balance_items ) || ! empty( $this->repoints ) || ! empty( $this->address_update );
	}

	/**
	 * Export for storage on a balance order.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'version'        => 1,
			'order_id'       => $this->order_id,
			'refund_lines'   => $this->refund_lines,
			'balance_items'  => $this->balance_items,
			'repoints'       => $this->repoints,
			'address_update' => $this->address_update,
			'descriptions'   => $this->descriptions,
			'refunded'       => $this->refunded_at_build,
			'lines'          => $this->lines_at_build,
		);
	}

	/**
	 * A short fingerprint of what the plan does, so a confirmed change can be
	 * checked against the preview the person saw.
	 *
	 * @return string
	 */
	public function fingerprint() {
		$data = $this->to_array();
		unset( $data['descriptions'] );

		return substr( md5( (string) wp_json_encode( $data ) ), 0, 12 );
	}

	/**
	 * Can this stored plan still be applied to the order as it is now?
	 *
	 * Used when a balance order is paid: the original may have been cancelled,
	 * refunded or changed by hand while the balance waited.
	 *
	 * @param WC_Order $order Original order.
	 * @return true|WP_Error
	 */
	public function still_applies( WC_Order $order ) {
		// Completed means shipped: a swap or more units can't be added to a parcel that has left.
		if ( $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' ) ) ) {
			return new WP_Error(
				'edit_orders_for_woocommerce_original_closed',
				/* translators: %s: order status. */
				sprintf( __( 'the original order is now %s', 'edit-orders-for-woocommerce' ), wc_get_order_status_name( $order->get_status() ) )
			);
		}

		if ( null !== $this->refunded_at_build && abs( (float) $order->get_total_refunded() - $this->refunded_at_build ) > 0.001 ) {
			return new WP_Error( 'edit_orders_for_woocommerce_refunded_since', __( 'the original order was refunded after the change was made', 'edit-orders-for-woocommerce' ) );
		}

		// The store may have changed the order in WooCommerce's own editor (on-hold orders are
		// editable there): the plan's lines, refunds and repoints may no longer fit.
		if ( null !== $this->lines_at_build && self::line_states( $order ) !== $this->lines_at_build ) {
			return new WP_Error( 'edit_orders_for_woocommerce_changed_since', __( 'the original order\'s items, shipping or fees were changed after the change was made', 'edit-orders-for-woocommerce' ) );
		}

		if ( $this->get_refund_amount() > (float) $order->get_remaining_refund_amount() + 0.001 ) {
			return new WP_Error( 'edit_orders_for_woocommerce_refund_too_big', __( 'the refund is more than what is left to refund on the original order', 'edit-orders-for-woocommerce' ) );
		}

		return true;
	}

	/**
	 * The balance order's total, tax included, as WooCommerce will calculate it.
	 *
	 * Mirrors WooCommerce's order maths: tax per line at the balance order's
	 * address, summed per rate, each rate rounded, then the total rounded. The
	 * preview shows this figure, so it must match the order that gets created.
	 *
	 * @param WC_Order $order Original order.
	 * @return float
	 */
	public function get_balance_estimate( WC_Order $order ) {
		if ( ! $this->balance_items ) {
			return 0.0;
		}

		$billing  = isset( $this->address_update['billing'] ) ? $this->address_update['billing'] : $order->get_address( 'billing' );
		$shipping = isset( $this->address_update['shipping'] ) ? $this->address_update['shipping'] : $order->get_address( 'shipping' );
		$location = Edit_Orders_For_WooCommerce_Address_Change::tax_location( $order, $billing, $shipping );
		$args     = array(
			'country'  => $location[0],
			'state'    => $location[1],
			'postcode' => $location[2],
			'city'     => $location[3],
		);

		$net      = 0.0;
		$per_rate = array();
		foreach ( $this->balance_items as $line ) {
			$net  += (float) $line['total'];
			$taxes = array();

			if ( isset( $line['taxes'] ) ) {
				$taxes = $line['taxes'];
			} elseif ( wc_tax_enabled() && ! Edit_Orders_For_WooCommerce_Pricing::is_vat_exempt( $order ) ) {
				$taxable = false;
				$class   = '';
				if ( 'product' === $line['type'] ) {
					$product = wc_get_product( $line['product_id'] );
					$taxable = $product && $product->is_taxable();
					$class   = $product ? $product->get_tax_class() : '';
				} elseif ( 'fee' === $line['type'] ) {
					$taxable = ! isset( $line['taxable'] ) || $line['taxable'];
					$class   = $line['tax_class'];
				}
				if ( $taxable ) {
					$taxes = WC_Tax::calc_tax( (float) $line['total'], WC_Tax::find_rates( array_merge( $args, array( 'tax_class' => $class ) ) ), false );
				}
			}

			foreach ( $taxes as $rate_id => $amount ) {
				$per_rate[ $rate_id ] = ( isset( $per_rate[ $rate_id ] ) ? $per_rate[ $rate_id ] : 0 ) + (float) $amount;
			}
		}

		$tax = 0.0;
		foreach ( $per_rate as $amount ) {
			$tax += (float) wc_round_tax_total( $amount );
		}

		return Edit_Orders_For_WooCommerce_Pricing::round( $net + $tax );
	}

	/**
	 * Before and after rows for the preview table.
	 *
	 * @param WC_Order $order Original order.
	 * @return array[] name, before, after, state (unchanged, changed, removed, added, waits)
	 */
	public function get_preview_rows( WC_Order $order ) {
		$rows     = array();
		$repoints = array();
		foreach ( $this->repoints as $repoint ) {
			$repoints[ $repoint['item_id'] ] = wc_get_product( $repoint['variation_id'] );
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			$before_qty = (int) $item->get_quantity();
			$after_qty  = $before_qty;
			$after_name = $item->get_name();

			if ( isset( $this->refund_lines[ $item_id ] ) && $this->refund_lines[ $item_id ]['qty'] > 0 ) {
				$after_qty -= (int) $this->refund_lines[ $item_id ]['qty'];
			}
			if ( isset( $repoints[ $item_id ] ) && $repoints[ $item_id ] ) {
				$after_name = $repoints[ $item_id ]->get_name();
			}

			$state = 'unchanged';
			if ( 0 === $after_qty ) {
				$state = 'removed';
			} elseif ( $after_qty !== $before_qty || $after_name !== $item->get_name() ) {
				$state = 'changed';
			}

			$rows[] = array(
				'name'       => $item->get_name(),
				'before'     => $before_qty,
				'after'      => 'removed' === $state ? 0 : $after_qty,
				'after_name' => $after_name,
				'state'      => $state,
			);
		}

		foreach ( $this->balance_items as $line ) {
			if ( 'product' !== $line['type'] ) {
				continue;
			}
			$product = wc_get_product( $line['product_id'] );
			$rows[]  = array(
				'name'       => $product ? $product->get_name() : '',
				'before'     => 0,
				'after'      => (int) $line['quantity'],
				'after_name' => $product ? $product->get_name() : '',
				'state'      => 'added',
			);
		}

		return $rows;
	}

	/**
	 * A comparable record of the order's lines: items, shipping and fees, with what
	 * they are and what they cost. Stock and other line meta are left out.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function line_states( WC_Order $order ) {
		$states = array();
		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item_id => $item ) {
			$states[ (string) $item_id ] = array(
				$item->get_type(),
				$item instanceof WC_Order_Item_Product ? (int) $item->get_product_id() : 0,
				$item instanceof WC_Order_Item_Product ? (int) $item->get_variation_id() : 0,
				(int) $item->get_quantity(),
				wc_format_decimal( $item->get_total(), wc_get_price_decimals() ),
				wc_format_decimal( $item->get_total_tax(), wc_get_price_decimals() ),
			);
		}
		ksort( $states );

		return $states;
	}

	/**
	 * Restore a stored plan.
	 *
	 * @param array $data Data from to_array().
	 * @return Edit_Orders_For_WooCommerce_Settlement_Plan
	 */
	public static function from_array( array $data ) {
		$plan                 = new self();
		$plan->order_id       = isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0;
		$plan->refund_lines   = isset( $data['refund_lines'] ) ? (array) $data['refund_lines'] : array();
		$plan->balance_items  = isset( $data['balance_items'] ) ? (array) $data['balance_items'] : array();
		$plan->repoints       = isset( $data['repoints'] ) ? (array) $data['repoints'] : array();
		$plan->address_update = isset( $data['address_update'] ) ? (array) $data['address_update'] : array();
		$plan->descriptions   = isset( $data['descriptions'] ) ? (array) $data['descriptions'] : array();
		// Plans stored before this was recorded skip the "refunded since" check.
		$plan->refunded_at_build = isset( $data['refunded'] ) ? (float) $data['refunded'] : null;
		$plan->lines_at_build    = isset( $data['lines'] ) ? (array) $data['lines'] : null;

		return $plan;
	}
}
