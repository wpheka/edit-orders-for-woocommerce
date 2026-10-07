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
				$refund_tax[ $rate_id ] = self::round( (float) $tax * $share );
			}
		}

		return array(
			'qty'          => (int) $quantity,
			'refund_total' => self::round( (float) $item->get_total() * $share ),
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
				$refund_tax[ $rate_id ] = self::round( (float) $tax * $share );
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
