<?php
/**
 * Change set: the list of edits requested for one order.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Change_Set Class.
 *
 * A value object. Each change is one of:
 *
 * - `array( 'type' => 'quantity', 'item_id' => 12, 'quantity' => 1 )` new quantity for a line;
 * - `array( 'type' => 'remove', 'item_id' => 12 )` remove a line;
 * - `array( 'type' => 'swap', 'item_id' => 12, 'variation_id' => 34 )` swap a line's variation;
 * - `array( 'type' => 'add', 'product_id' => 56, 'quantity' => 2, 'price' => '5.00' )` add a product.
 *   `price` is optional: a unit price excluding tax that overrides the catalogue price;
 * - `array( 'type' => 'address', 'shipping' => array( ... ), 'billing' => array( ... ), 'shipping_method' => 'flat_rate:3' )`
 *   change addresses. Only the fields given change; `shipping_method` (a rate ID) is
 *   needed only when the order's method isn't offered at the new address. An address
 *   change must be the only change in its set, because it re-taxes every line.
 *
 * Validation against the order (does the item exist, is it refunded) happens in
 * the settlement plan. This class only checks that each change is well formed.
 */
class Edit_Orders_For_WooCommerce_Change_Set {

	/**
	 * Normalised changes.
	 *
	 * @var array[]
	 */
	private $changes = array();

	/**
	 * Problems found while normalising.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Constructor.
	 *
	 * @param array[] $changes Raw changes.
	 */
	public function __construct( array $changes ) {
		$seen_items = array();

		foreach ( $changes as $change ) {
			$change = $this->normalise( (array) $change );

			if ( is_string( $change ) ) {
				$this->errors[] = $change;
				continue;
			}

			// One change per line keeps the money arithmetic unambiguous.
			if ( isset( $change['item_id'] ) ) {
				if ( isset( $seen_items[ $change['item_id'] ] ) ) {
					/* translators: %d: order item ID. */
					$this->errors[] = sprintf( __( 'Item %d is changed more than once.', 'wpheka-edit-orders-for-woocommerce' ), $change['item_id'] );
					continue;
				}
				$seen_items[ $change['item_id'] ] = true;
			}

			$this->changes[] = $change;
		}

		if ( count( $this->changes ) > 1 && in_array( 'address', wp_list_pluck( $this->changes, 'type' ), true ) ) {
			$this->errors[] = __( 'Change the address on its own, then make the other changes.', 'wpheka-edit-orders-for-woocommerce' );
		}

		if ( empty( $this->changes ) && empty( $this->errors ) ) {
			$this->errors[] = __( 'Nothing to change.', 'wpheka-edit-orders-for-woocommerce' );
		}
	}

	/**
	 * Normalise one change.
	 *
	 * @param array $change Raw change.
	 * @return array|string The change, or an error message.
	 */
	private function normalise( array $change ) {
		$type = isset( $change['type'] ) ? sanitize_key( $change['type'] ) : '';

		switch ( $type ) {
			case 'quantity':
				if ( empty( $change['item_id'] ) || ! isset( $change['quantity'] ) || ! is_numeric( $change['quantity'] ) || $change['quantity'] < 0 ) {
					return __( 'A quantity change needs an item and a quantity of zero or more.', 'wpheka-edit-orders-for-woocommerce' );
				}
				$quantity = (int) $change['quantity'];

				// Zero means remove; handled as a removal so the refund is exact.
				if ( 0 === $quantity ) {
					return array(
						'type'    => 'remove',
						'item_id' => absint( $change['item_id'] ),
					);
				}

				return array(
					'type'     => 'quantity',
					'item_id'  => absint( $change['item_id'] ),
					'quantity' => $quantity,
				);

			case 'remove':
				if ( empty( $change['item_id'] ) ) {
					return __( 'A removal needs an item.', 'wpheka-edit-orders-for-woocommerce' );
				}
				return array(
					'type'    => 'remove',
					'item_id' => absint( $change['item_id'] ),
				);

			case 'swap':
				if ( empty( $change['item_id'] ) || empty( $change['variation_id'] ) ) {
					return __( 'A swap needs an item and a variation.', 'wpheka-edit-orders-for-woocommerce' );
				}
				return array(
					'type'         => 'swap',
					'item_id'      => absint( $change['item_id'] ),
					'variation_id' => absint( $change['variation_id'] ),
				);

			case 'add':
				if ( empty( $change['product_id'] ) || empty( $change['quantity'] ) || (int) $change['quantity'] < 1 ) {
					return __( 'Adding a product needs a product and a quantity of one or more.', 'wpheka-edit-orders-for-woocommerce' );
				}
				$add = array(
					'type'       => 'add',
					'product_id' => absint( $change['product_id'] ),
					'quantity'   => (int) $change['quantity'],
				);
				if ( isset( $change['price'] ) && '' !== $change['price'] ) {
					if ( ! is_numeric( $change['price'] ) || $change['price'] < 0 ) {
						return __( 'The price for an added product must be zero or more.', 'wpheka-edit-orders-for-woocommerce' );
					}
					$add['price'] = wc_format_decimal( $change['price'] );
				}
				return $add;

			case 'address':
				$address = array( 'type' => 'address' );
				foreach ( array( 'billing', 'shipping' ) as $address_type ) {
					if ( ! empty( $change[ $address_type ] ) && is_array( $change[ $address_type ] ) ) {
						$address[ $address_type ] = $change[ $address_type ];
					}
				}
				if ( 1 === count( $address ) ) {
					return __( 'An address change needs a billing or shipping address.', 'wpheka-edit-orders-for-woocommerce' );
				}
				if ( ! empty( $change['shipping_method'] ) ) {
					$address['shipping_method'] = sanitize_text_field( $change['shipping_method'] );
				}
				return $address;
		}

		return __( 'Unknown change.', 'wpheka-edit-orders-for-woocommerce' );
	}

	/**
	 * Get the normalised changes.
	 *
	 * @return array[]
	 */
	public function get_changes() {
		return $this->changes;
	}

	/**
	 * Get problems found while normalising.
	 *
	 * @return string[]
	 */
	public function get_errors() {
		return $this->errors;
	}
}
