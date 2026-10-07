<?php
/**
 * Address changes: re-rate shipping and re-tax every line for the new address (spec section 2.2, P2).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Address_Change Class.
 *
 * For each line (products, fees, shipping) the old figures are compared with
 * the figures for the new address: the shipping cost from the store's shipping
 * zones, and tax per rate from its tax tables. The paid lines are never edited.
 * The difference is settled per tax rate, so tax reports stay right:
 *
 * - the customer owes nothing net: one refund. Rates that go down are refunded;
 *   rates that go up appear as negative refund amounts, which WooCommerce books
 *   as tax charged. The refund total is the net amount;
 * - the customer owes more: what goes up (new shipping, tax at new rates) goes
 *   on a balance order and what goes down is refunded once it is paid.
 *
 * Addresses are changed on the order itself once the money is settled.
 */
class Edit_Orders_For_WooCommerce_Address_Change {

	/**
	 * Address fields a change may set.
	 */
	const FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

	/**
	 * Work out the settlement for an address change.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $change Change: billing and/or shipping fields, optional shipping_method rate ID.
	 * @return array|WP_Error { refund_lines, balance_items, address_update, descriptions }
	 */
	public static function build( WC_Order $order, array $change ) {
		if ( (float) $order->get_total_refunded() > 0 ) {
			return new WP_Error( 'edit_orders_for_woocommerce_address_refunded', __( 'This order already has a refund, so its address can no longer be changed here.', 'edit-orders-for-woocommerce' ) );
		}

		$old_billing  = $order->get_address( 'billing' );
		$old_shipping = $order->get_address( 'shipping' );
		$new_billing  = $old_billing;
		$new_shipping = $old_shipping;
		$descriptions = array();

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( empty( $change[ $type ] ) ) {
				continue;
			}
			$merged = self::merge( 'billing' === $type ? $old_billing : $old_shipping, $change[ $type ] );
			$valid  = self::validate( $merged, $type );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			if ( 'billing' === $type ) {
				$new_billing = $merged;
			} else {
				$new_shipping = $merged;
			}
			/* translators: 1: address type, 2: place. */
			$descriptions[] = sprintf( __( '%1$s address changed to %2$s.', 'edit-orders-for-woocommerce' ), 'billing' === $type ? __( 'Billing', 'edit-orders-for-woocommerce' ) : __( 'Shipping', 'edit-orders-for-woocommerce' ), self::describe( $merged ) );
		}

		$old_location = self::tax_location( $old_billing, $old_shipping );
		$new_location = self::tax_location( $new_billing, $new_shipping );
		$tax_moves    = wc_tax_enabled() && $old_location !== $new_location;

		// Re-rate shipping when the destination moved.
		$shipping_items = $order->get_shipping_methods();
		$new_rate       = null;
		if ( $shipping_items && self::destination( $old_shipping ) !== self::destination( $new_shipping ) ) {
			if ( count( $shipping_items ) > 1 ) {
				return new WP_Error( 'edit_orders_for_woocommerce_address_shipping_lines', __( 'This order has more than one shipping line. Change its address in WooCommerce and adjust the shipping by hand.', 'edit-orders-for-woocommerce' ) );
			}
			$new_rate = self::rerate( $order, $new_shipping, current( $shipping_items ), isset( $change['shipping_method'] ) ? (string) $change['shipping_method'] : '' );
			if ( is_wp_error( $new_rate ) ) {
				return $new_rate;
			}
		}

		// New figures per line, and the difference per tax rate.
		$lines = array();
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
			$is_shipping = $item instanceof WC_Order_Item_Shipping;
			$old_total   = (float) $item->get_total();
			$new_total   = ( $is_shipping && $new_rate ) ? $new_rate['cost'] : $old_total;
			$old_taxes   = self::clean_taxes( $item->get_taxes() );
			$new_taxes   = $old_taxes;

			if ( $tax_moves || $new_total !== $old_total ) {
				$new_taxes = self::taxes_for( $order, $item, $new_total, $new_location, $new_rate );
			}

			$tax_delta = array();
			foreach ( array_unique( array_merge( array_keys( $old_taxes ), array_keys( $new_taxes ) ) ) as $rate_id ) {
				$delta = Edit_Orders_For_WooCommerce_Pricing::round( ( isset( $new_taxes[ $rate_id ] ) ? $new_taxes[ $rate_id ] : 0 ) - ( isset( $old_taxes[ $rate_id ] ) ? $old_taxes[ $rate_id ] : 0 ) );
				if ( 0.0 !== $delta ) {
					$tax_delta[ $rate_id ] = $delta;
				}
			}

			$ex_delta = Edit_Orders_For_WooCommerce_Pricing::round( $new_total - $old_total );
			if ( 0.0 !== $ex_delta || $tax_delta ) {
				$lines[ $item_id ] = array(
					'is_shipping' => $is_shipping,
					'ex_delta'    => $ex_delta,
					'tax_delta'   => $tax_delta,
				);
			}
		}

		$net = 0.0;
		foreach ( $lines as $line ) {
			$net += $line['ex_delta'] + array_sum( $line['tax_delta'] );
		}
		$net = Edit_Orders_For_WooCommerce_Pricing::round( $net );

		$refund_lines  = array();
		$balance_items = array();

		if ( $net <= 0 ) {
			// One refund for the net amount; increases ride along as negative entries.
			foreach ( $lines as $item_id => $line ) {
				$refund_lines[ $item_id ] = array(
					'qty'          => 0,
					'refund_total' => -1 * $line['ex_delta'],
					'refund_tax'   => array_map(
						function ( $amount ) {
							return -1 * $amount;
						},
						$line['tax_delta']
					),
				);
			}
		} else {
			// Increases go on the balance order; decreases are refunded once it is paid.
			$new_tax = array();
			foreach ( $lines as $item_id => $line ) {
				$refund = array(
					'qty'          => 0,
					'refund_total' => 0,
					'refund_tax'   => array(),
				);
				$up     = array();
				foreach ( $line['tax_delta'] as $rate_id => $amount ) {
					if ( $amount > 0 ) {
						$up[ $rate_id ] = $amount;
					} else {
						$refund['refund_tax'][ $rate_id ] = -1 * $amount;
					}
				}

				if ( $line['is_shipping'] && $line['ex_delta'] > 0 ) {
					$balance_items[] = array(
						'type'        => 'shipping',
						'name'        => $new_rate ? $new_rate['label'] : __( 'Shipping', 'edit-orders-for-woocommerce' ),
						'method_id'   => $new_rate ? $new_rate['method_id'] : '',
						'instance_id' => $new_rate ? $new_rate['instance_id'] : 0,
						'total'       => $line['ex_delta'],
						'taxes'       => $up,
					);
				} else {
					if ( $line['ex_delta'] < 0 ) {
						$refund['refund_total'] = -1 * $line['ex_delta'];
					}
					foreach ( $up as $rate_id => $amount ) {
						$new_tax[ $rate_id ] = ( isset( $new_tax[ $rate_id ] ) ? $new_tax[ $rate_id ] : 0 ) + $amount;
					}
				}

				if ( $refund['refund_total'] > 0 || $refund['refund_tax'] ) {
					$refund_lines[ $item_id ] = $refund;
				}
			}

			if ( $new_tax ) {
				$balance_items[] = array(
					'type'      => 'fee',
					'name'      => __( 'Tax for the new address', 'edit-orders-for-woocommerce' ),
					'tax_class' => '',
					'total'     => 0,
					'taxes'     => $new_tax,
				);
			}
		}

		if ( $new_rate ) {
			$descriptions[] = sprintf(
				/* translators: 1: old shipping cost, 2: new shipping cost, 3: method name. */
				__( 'Shipping re-rated from %1$s to %2$s (%3$s).', 'edit-orders-for-woocommerce' ),
				html_entity_decode( wp_strip_all_tags( wc_price( current( $shipping_items )->get_total(), array( 'currency' => $order->get_currency() ) ) ) ),
				html_entity_decode( wp_strip_all_tags( wc_price( $new_rate['cost'], array( 'currency' => $order->get_currency() ) ) ) ),
				$new_rate['label']
			);
		}

		$address_update = array(
			'billing'  => $new_billing,
			'shipping' => $new_shipping,
		);
		if ( $new_rate ) {
			$address_update['shipping_line'] = array(
				'item_id'     => current( $shipping_items )->get_id(),
				'method_id'   => $new_rate['method_id'],
				'instance_id' => $new_rate['instance_id'],
				'title'       => $new_rate['label'],
			);
		}

		return array(
			'refund_lines'   => $refund_lines,
			'balance_items'  => $balance_items,
			'address_update' => $address_update,
			'descriptions'   => $descriptions,
		);
	}

	/**
	 * Shipping rates to choose from when an address change needs a new method
	 * because the current one isn't offered at the new address.
	 *
	 * @param WC_Order $order   Order.
	 * @param array[]  $changes Change set.
	 * @return array rate ID => label with cost; empty when there is nothing to choose.
	 */
	public static function rate_choices( WC_Order $order, array $changes ) {
		if ( 1 !== count( $changes ) || 'address' !== $changes[0]['type'] || ! empty( $changes[0]['shipping_method'] ) ) {
			return array();
		}

		$result = self::build( $order, $changes[0] );
		if ( ! is_wp_error( $result ) ) {
			return array();
		}

		$data = $result->get_error_data();
		if ( empty( $data['rates'] ) ) {
			return array();
		}

		$rates = array();
		foreach ( $data['rates'] as $rate_id => $rate ) {
			// Plain text (wc_price() gives "&#36;"): the panel escapes it and the editor inserts it as a text node.
			$price             = html_entity_decode( wp_strip_all_tags( wc_price( $rate['cost'], array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
			$rates[ $rate_id ] = $rate['label'] . ' (' . $price . ')';
		}

		return $rates;
	}

	/**
	 * Merge changed fields into an address. The billing email is never changed here.
	 *
	 * @param array $address Current address.
	 * @param array $fields  Changed fields, already unslashed by the caller.
	 * @return array
	 */
	private static function merge( array $address, array $fields ) {
		foreach ( self::FIELDS as $field ) {
			if ( isset( $fields[ $field ] ) ) {
				$address[ $field ] = sanitize_text_field( $fields[ $field ] );
			}
		}
		$address['country'] = strtoupper( $address['country'] );
		$address['state']   = strtoupper( $address['state'] );
		if ( '' !== $address['postcode'] ) {
			$address['postcode'] = wc_format_postcode( $address['postcode'], $address['country'] );
		}

		return $address;
	}

	/**
	 * Validate an address against the store's countries and states.
	 *
	 * @param array  $address Address.
	 * @param string $type    billing or shipping.
	 * @return true|WP_Error
	 */
	private static function validate( array $address, $type ) {
		$countries = 'shipping' === $type ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();

		if ( '' === $address['country'] || ! isset( $countries[ $address['country'] ] ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_address_country', __( 'The store does not sell to that country.', 'edit-orders-for-woocommerce' ) );
		}

		$states = WC()->countries->get_states( $address['country'] );
		if ( is_array( $states ) && $states && ! isset( $states[ $address['state'] ] ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_address_state', __( 'Choose a valid state or province.', 'edit-orders-for-woocommerce' ) );
		}

		if ( '' !== $address['postcode'] && ! WC_Validation::is_postcode( $address['postcode'], $address['country'] ) ) {
			return new WP_Error( 'edit_orders_for_woocommerce_address_postcode', __( 'That postcode is not valid for the country.', 'edit-orders-for-woocommerce' ) );
		}

		return true;
	}

	/**
	 * The location tax is calculated for, as WooCommerce does for orders.
	 *
	 * @param array $billing  Billing address.
	 * @param array $shipping Shipping address.
	 * @return array country, state, postcode, city
	 */
	public static function tax_location( array $billing, array $shipping ) {
		$based_on = get_option( 'woocommerce_tax_based_on' );

		if ( 'base' === $based_on ) {
			return array( WC()->countries->get_base_country(), WC()->countries->get_base_state(), WC()->countries->get_base_postcode(), WC()->countries->get_base_city() );
		}

		$address = ( 'shipping' === $based_on && '' !== $shipping['country'] ) ? $shipping : $billing;

		return array( $address['country'], $address['state'], $address['postcode'], $address['city'] );
	}

	/**
	 * The parts of a shipping address that decide its shipping zone.
	 *
	 * @param array $address Address.
	 * @return array
	 */
	private static function destination( array $address ) {
		return array( $address['country'], $address['state'], $address['postcode'], $address['city'] );
	}

	/**
	 * Short place name for notes.
	 *
	 * @param array $address Address.
	 * @return string
	 */
	private static function describe( array $address ) {
		return implode( ', ', array_filter( array( $address['address_1'], $address['city'], $address['state'], $address['postcode'], $address['country'] ) ) );
	}

	/**
	 * Taxes as rate ID => amount, without empty entries.
	 *
	 * @param array $taxes Item taxes.
	 * @return array
	 */
	private static function clean_taxes( array $taxes ) {
		$clean = array();
		foreach ( isset( $taxes['total'] ) ? $taxes['total'] : array() as $rate_id => $amount ) {
			if ( '' !== $amount && null !== $amount ) {
				$clean[ $rate_id ] = (float) $amount;
			}
		}

		return $clean;
	}

	/**
	 * Tax per rate for a line at a location.
	 *
	 * @param WC_Order      $order    Order.
	 * @param WC_Order_Item $item     Line.
	 * @param float         $total    Line total excluding tax.
	 * @param array         $location country, state, postcode, city.
	 * @param array|null    $new_rate New shipping rate, for shipping lines.
	 * @return array rate ID => amount
	 */
	private static function taxes_for( WC_Order $order, $item, $total, array $location, $new_rate ) {
		if ( ! wc_tax_enabled() || $total <= 0 || Edit_Orders_For_WooCommerce_Pricing::is_vat_exempt( $order ) ) {
			return array();
		}

		$args = array(
			'country'  => $location[0],
			'state'    => $location[1],
			'postcode' => $location[2],
			'city'     => $location[3],
		);

		if ( $item instanceof WC_Order_Item_Shipping ) {
			$taxable = $new_rate ? $new_rate['taxable'] : (bool) self::clean_taxes( $item->get_taxes() );
			if ( ! $taxable ) {
				return array();
			}
			$args['tax_class'] = self::shipping_tax_class( $order );
			$rates             = WC_Tax::find_shipping_rates( $args );
		} elseif ( $item instanceof WC_Order_Item_Fee ) {
			if ( 'taxable' !== $item->get_tax_status() ) {
				return array();
			}
			$args['tax_class'] = $item->get_tax_class();
			$rates             = WC_Tax::find_rates( $args );
		} else {
			$product = $item->get_product();
			if ( ! $product || ! $product->is_taxable() ) {
				return array();
			}
			$args['tax_class'] = $item->get_tax_class();
			$rates             = WC_Tax::find_rates( $args );
		}

		$taxes = array();
		foreach ( WC_Tax::calc_tax( $total, $rates, false ) as $rate_id => $amount ) {
			$taxes[ $rate_id ] = Edit_Orders_For_WooCommerce_Pricing::round( $amount );
		}

		return $taxes;
	}

	/**
	 * The tax class for shipping, resolving "inherit" from the order's lines.
	 *
	 * Simplified from WooCommerce's cart logic: the standard class when any
	 * shippable taxable line uses it, otherwise the first line's class.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function shipping_tax_class( WC_Order $order ) {
		$class = get_option( 'woocommerce_shipping_tax_class' );
		if ( 'inherit' !== $class ) {
			return (string) $class;
		}

		$classes = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->needs_shipping() && $product->is_taxable() ) {
				$classes[] = $item->get_tax_class();
			}
		}

		if ( ! $classes || in_array( '', $classes, true ) ) {
			return '';
		}

		return (string) $classes[0];
	}

	/**
	 * Re-rate shipping for a new destination with the store's shipping zones.
	 *
	 * Keeps the order's method when it is offered there; otherwise uses the
	 * rate the caller chose. Rates are calculated without tax: WooCommerce's
	 * shipping tax lookup reads the cart, which admin requests don't have.
	 * Tax is worked out by the caller for the new address.
	 *
	 * @param WC_Order               $order    Order.
	 * @param array                  $shipping New shipping address.
	 * @param WC_Order_Item_Shipping $current  Current shipping line.
	 * @param string                 $chosen   Rate ID chosen by the caller, if any.
	 * @return array|WP_Error { cost, method_id, instance_id, label, taxable }
	 */
	private static function rerate( WC_Order $order, array $shipping, WC_Order_Item_Shipping $current, $chosen ) {
		$contents = array();
		$cost     = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}
			$contents[ 'item-' . $item_id ] = array(
				'key'               => 'item-' . $item_id,
				'product_id'        => $item->get_product_id(),
				'variation_id'      => $item->get_variation_id(),
				'variation'         => array(),
				'quantity'          => $item->get_quantity(),
				'data'              => $product,
				'line_total'        => (float) $item->get_total(),
				'line_tax'          => 0,
				'line_subtotal'     => (float) $item->get_subtotal(),
				'line_subtotal_tax' => 0,
			);
			$cost                          += (float) $item->get_total();
		}

		$package = array(
			'contents'        => $contents,
			'contents_cost'   => $cost,
			'applied_coupons' => $order->get_coupon_codes(),
			'user'            => array( 'ID' => $order->get_customer_id() ),
			'destination'     => array(
				'country'   => $shipping['country'],
				'state'     => $shipping['state'],
				'postcode'  => $shipping['postcode'],
				'city'      => $shipping['city'],
				'address'   => $shipping['address_1'],
				'address_1' => $shipping['address_1'],
				'address_2' => $shipping['address_2'],
			),
		);

		$no_tax = function ( $args ) {
			$args['taxes'] = false;
			return $args;
		};
		add_filter( 'woocommerce_shipping_method_add_rate_args', $no_tax );

		$rates = array();
		$zone  = WC_Shipping_Zones::get_zone_matching_package( $package );
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			foreach ( (array) $method->get_rates_for_package( $package ) as $rate ) {
				$rates[ $rate->get_id() ] = array(
					'cost'        => Edit_Orders_For_WooCommerce_Pricing::round( $rate->get_cost() ),
					'method_id'   => $rate->get_method_id(),
					'instance_id' => (int) $rate->get_instance_id(),
					'label'       => $rate->get_label(),
					'taxable'     => $method->is_taxable(),
				);
			}
		}

		remove_filter( 'woocommerce_shipping_method_add_rate_args', $no_tax );

		if ( '' !== $chosen ) {
			return isset( $rates[ $chosen ] ) ? $rates[ $chosen ] : new WP_Error( 'edit_orders_for_woocommerce_address_rate', __( 'That shipping option is not available for the new address.', 'edit-orders-for-woocommerce' ) );
		}

		foreach ( $rates as $rate ) {
			if ( $rate['method_id'] === $current->get_method_id() ) {
				return $rate;
			}
		}

		return new WP_Error(
			'edit_orders_for_woocommerce_address_choose_rate',
			$rates
				? __( 'The current shipping method is not available for the new address. Choose another one.', 'edit-orders-for-woocommerce' )
				: __( 'No shipping is available to the new address.', 'edit-orders-for-woocommerce' ),
			array( 'rates' => $rates )
		);
	}
}
