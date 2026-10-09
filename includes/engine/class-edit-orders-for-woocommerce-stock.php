<?php
/**
 * Stock moves for edits (spec section 2.4).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Stock Class.
 *
 * Refunds restock through wc_create_refund() and balance orders reduce stock
 * when paid, so the only manual move is a variation swap.
 */
class Edit_Orders_For_WooCommerce_Stock {

	/**
	 * Switch a line to another variation and move its reduced stock with it.
	 *
	 * Stock moves only when the order's stock was reduced. `_reduced_stock` on the
	 * line records what was taken from the old variation (WooCommerce only writes it
	 * for products that manage stock); afterwards it records what was taken from the
	 * new one, so a later refund or cancel restocks the right variation. The line's
	 * totals are not touched.
	 *
	 * @param WC_Order              $order         Order.
	 * @param WC_Order_Item_Product $item          Line.
	 * @param WC_Product            $new_variation New variation.
	 * @return string Note describing the stock change, or empty.
	 */
	public static function repoint( WC_Order $order, WC_Order_Item_Product $item, WC_Product $new_variation ) {
		$old_product = $item->get_product();
		$reduced     = (int) $item->get_meta( '_reduced_stock', true );
		$quantity    = (int) $item->get_quantity();
		$moves       = array();

		if ( $order->get_order_stock_reduced() ) {
			if ( $reduced > 0 && $old_product && $old_product->managing_stock() ) {
				wc_update_product_stock( $old_product, $reduced, 'increase' );
				/* translators: 1: quantity, 2: product name. */
				$moves[] = sprintf( __( '%1$d of %2$s back to stock', 'edit-orders-for-woocommerce' ), $reduced, $old_product->get_name() );
			}

			if ( $new_variation->managing_stock() && $quantity > 0 ) {
				wc_update_product_stock( $new_variation, $quantity, 'decrease' );
				$item->update_meta_data( '_reduced_stock', $quantity );
				/* translators: 1: quantity, 2: product name. */
				$moves[] = sprintf( __( '%1$d of %2$s taken', 'edit-orders-for-woocommerce' ), $quantity, $new_variation->get_name() );
			} else {
				$item->delete_meta_data( '_reduced_stock' );
			}
		}

		$item->set_product( $new_variation );
		$item->save();

		/* translators: %s: list of stock moves. */
		return $moves ? sprintf( __( 'Stock moved: %s.', 'edit-orders-for-woocommerce' ), implode( ', ', $moves ) ) : '';
	}
}
