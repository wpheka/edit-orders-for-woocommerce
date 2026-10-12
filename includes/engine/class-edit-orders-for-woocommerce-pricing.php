<?php
/**
 * Pricing rules for edits (spec section 2.2).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Pricing Class.
 *
 * Every figure is excluding tax unless the method name says otherwise. Tax on
 * refunds is split per rate in proportion to the line's paid tax, so tax
 * reports stay right.
 */
class Edit_Orders_For_WooCommerce_Pricing {

	/**
	 * Round a money amount to the store's price decimals.
	 *
	 * @param float|string $amount Amount.
	 * @return float
	 */
	public static function round( $amount ) {
		return (float) wc_format_decimal( $amount, wc_get_price_decimals() );
	}

	/**
	 * Does the store round tax at subtotal level (WooCommerce > Settings > Tax)?
	 *
	 * WooCommerce then keeps each line's tax unrounded and rounds only the order's
	 * total, so refunds must do the same or they can differ from what was charged.
	 *
	 * @return bool
	 */
	public static function rounds_tax_at_subtotal() {
		return 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' );
	}

	/**
	 * Round a tax amount the way WooCommerce stores line tax: to the price decimals,
	 * or unrounded (WooCommerce's rounding precision) when tax is rounded at subtotal.
	 *
	 * @param float|string $amount Amount.
	 * @return float
	 */
	public static function round_tax( $amount ) {
		if ( self::rounds_tax_at_subtotal() ) {
			return (float) wc_format_decimal( $amount, wc_get_rounding_precision() );
		}

		return self::round( $amount );
	}

	/**
	 * Total of several refund lines, tax included. With tax rounded at subtotal the
	 * unrounded amounts are summed and rounded once, as WooCommerce rounds the order
	 * total; otherwise each line is rounded, as each line was charged.
	 *
	 * @param array[] $refund_lines Refund lines.
	 * @return float
	 */
	public static function refund_lines_amount( array $refund_lines ) {
		$amount = 0.0;
		foreach ( $refund_lines as $line ) {
			$amount += self::rounds_tax_at_subtotal()
				? (float) $line['refund_total'] + array_sum( $line['refund_tax'] )
				: self::refund_line_amount( $line );
		}

		return self::round( $amount );
	}

	/**
	 * Whether the order is free of tax, decided as WooCommerce decides it when it
	 * calculates an order's taxes (including the filter tax-exemption plugins use).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_vat_exempt( WC_Order $order ) {
		return (bool) apply_filters( 'woocommerce_order_is_vat_exempt', 'yes' === $order->get_meta( 'is_vat_exempt' ), $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
	}

	/**
	 * The ratio of what was paid to the list price for a line: 0.8 for a 20% coupon.
	 *
	 * @param WC_Order_Item_Product $item Line.
	 * @return float
	 */
	public static function coupon_ratio( WC_Order_Item_Product $item ) {
		$subtotal = (float) $item->get_subtotal();

		return $subtotal > 0 ? (float) $item->get_total() / $subtotal : 1.0;
	}

	/**
	 * The refund for taking `$quantity` units off a line, at the paid unit price.
	 *
	 * @param WC_Order_Item_Product $item     Line.
	 * @param int                   $quantity Units to take off.
	 * @return array { qty, refund_total, refund_tax: rate ID => amount }
	 */
	public static function refund_for_quantity( WC_Order_Item_Product $item, $quantity ) {
		$line_quantity = (int) $item->get_quantity();
		$taxes         = $item->get_taxes();
		$refund_tax    = array();

		// The whole line: refund the exact paid figures, no rounding drift.
		if ( $quantity >= $line_quantity ) {
			foreach ( $taxes['total'] as $rate_id => $tax ) {
				if ( '' !== $tax ) {
					$refund_tax[ $rate_id ] = (float) $tax;
				}
			}

			return array(
				'qty'          => $line_quantity,
				'refund_total' => (float) $item->get_total(),
				'refund_tax'   => $refund_tax,
			);
		}

		$share = $quantity / $line_quantity;
		foreach ( $taxes['total'] as $rate_id => $tax ) {
			if ( '' !== $tax ) {
				$refund_tax[ $rate_id ] = self::round_tax( (float) $tax * $share );
			}
		}

		return array(
			'qty'          => (int) $quantity,
			'refund_total' => self::round( (float) $item->get_total() * $share ),
			'refund_tax'   => $refund_tax,
		);
	}

	/**
	 * The refund for part of a line paid on a balance order, from what is left of it
	 * after earlier refunds (see Balance_Orders::line_parts()).
	 *
	 * @param array      $part  Part: qty_left, total_left, tax_left.
	 * @param int        $units Units to take off (product parts).
	 * @param float|null $share Share of the money to refund instead (fees, or a price
	 *                          difference on a product part): 0 to 1. Returns no units.
	 * @return array { qty, refund_total, refund_tax: rate ID => amount }
	 */
	public static function refund_for_part( array $part, $units, $share = null ) {
		$qty = 0;
		if ( null === $share ) {
			$left  = (int) $part['qty_left'];
			$qty   = min( (int) $units, $left );
			$share = $left > 0 ? $qty / $left : 0;
		}
		$share = max( 0.0, min( 1.0, (float) $share ) );

		$refund_tax = array();
		foreach ( $part['tax_left'] as $rate_id => $tax ) {
			// Everything that is left: the exact figures, no rounding drift.
			$refund_tax[ $rate_id ] = $share >= 1.0 ? (float) $tax : self::round_tax( (float) $tax * $share );
		}

		return array(
			'qty'          => $qty,
			'refund_total' => $share >= 1.0 ? (float) $part['total_left'] : self::round( (float) $part['total_left'] * $share ),
			'refund_tax'   => $refund_tax,
		);
	}

	/**
	 * A money-only refund on a line (no units returned), with tax split per rate.
	 *
	 * Used for a swap to a cheaper variation.
	 *
	 * @param WC_Order_Item_Product $item   Line.
	 * @param float                 $amount Amount excluding tax.
	 * @return array { qty, refund_total, refund_tax: rate ID => amount }
	 */
	public static function refund_for_amount( WC_Order_Item_Product $item, $amount ) {
		$line_total = (float) $item->get_total();
		// Never more than was paid on the line, whatever the catalogue prices say now.
		$amount     = min( (float) $amount, max( 0.0, $line_total ) );
		$share      = $line_total > 0 ? $amount / $line_total : 0;
		$taxes      = $item->get_taxes();
		$refund_tax = array();

		foreach ( $taxes['total'] as $rate_id => $tax ) {
			if ( '' !== $tax ) {
				$refund_tax[ $rate_id ] = self::round_tax( (float) $tax * $share );
			}
		}

		return array(
			'qty'          => 0,
			'refund_total' => self::round( $amount ),
			'refund_tax'   => $refund_tax,
		);
	}

	/**
	 * Total of a refund line, tax included.
	 *
	 * @param array $refund_line Refund line.
	 * @return float
	 */
	public static function refund_line_amount( array $refund_line ) {
		return self::round( $refund_line['refund_total'] + array_sum( $refund_line['refund_tax'] ) );
	}

	/**
	 * A product's current catalogue price excluding tax, for one unit.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function current_price( WC_Product $product ) {
		return (float) wc_get_price_excluding_tax( $product, array( 'qty' => 1 ) );
	}

	/**
	 * The per-unit price difference for swapping a line to another variation.
	 *
	 * Current price of the new variation minus current price of the old one,
	 * times the line's coupon ratio. Equal current prices give zero, even when
	 * the original was bought on sale or with a coupon.
	 *
	 * @param WC_Order_Item_Product $item          Line.
	 * @param WC_Product            $new_variation New variation.
	 * @return float Positive when the customer owes more.
	 */
	public static function swap_difference_per_unit( WC_Order_Item_Product $item, WC_Product $new_variation ) {
		$old = $item->get_product();
		if ( ! $old ) {
			return 0.0;
		}

		$difference = ( self::current_price( $new_variation ) - self::current_price( $old ) ) * self::coupon_ratio( $item );

		return self::round( $difference );
	}
}
