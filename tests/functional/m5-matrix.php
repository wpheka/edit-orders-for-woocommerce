<?php
/**
 * Store-setup matrix: every kind of edit on every common store setup.
 *
 * Setups: prices entered without tax and with tax, times no coupon, a 20% coupon and a
 * $5 fixed cart coupon. Edits: quantity down, remove a line, quantity up, swap to a
 * cheaper and to a dearer variation, add a product, change the address, cancel.
 *
 * Instead of fixed amounts, each scenario checks rules that must hold on any setup:
 * - the refund the preview showed is the refund made;
 * - the balance the preview showed is the balance order's total;
 * - nothing is ever refunded beyond what was paid;
 * - stock equals what the orders hold now, for every product involved;
 * - the balance applies once paid, and the order says so.
 *
 * Run with: bash tests/functional/run.sh m5-matrix (or all suites without an argument).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$eo_t = array(
	'pass'    => 0,
	'fail'    => 0,
	'orders'  => array(),
	'stock'   => array(),
	'product' => array(),
);

$eo_check = static function ( $label, $condition, $detail = '' ) use ( &$eo_t ) {
	if ( $condition ) {
		++$eo_t['pass'];
		WP_CLI::log( "  PASS  {$label}" );
	} else {
		++$eo_t['fail'];
		WP_CLI::log( "  FAIL  {$label}" . ( '' !== $detail ? " ({$detail})" : '' ) );
	}
};

$eo_money = static function ( $amount ) {
	return wc_format_decimal( $amount, 2 );
};

$eo_debug_log  = WP_CONTENT_DIR . '/debug.log';
$eo_debug_from = file_exists( $eo_debug_log ) ? filesize( $eo_debug_log ) : 0;

Edit_Orders_For_WooCommerce_Audit_Log::maybe_install();
wp_set_current_user( 1 );
$eo_settings_before = get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
$eo_incl_before = get_option( 'woocommerce_prices_include_tax' );

foreach ( array( 'EO-TSHIRT', 'EO-MUG', 'EO-POSTER', 'EO-HOODIE-XS', 'EO-HOODIE-S', 'EO-HOODIE-M', 'EO-HOODIE-L' ) as $eo_sku ) {
	$eo_t['product'][ $eo_sku ]                   = wc_get_product_id_by_sku( $eo_sku );
	$eo_t['stock'][ $eo_t['product'][ $eo_sku ] ] = wc_get_product( $eo_t['product'][ $eo_sku ] )->get_stock_quantity();
	// The matrix keeps every order until the end: plenty of stock so none runs out. Restored in cleanup.
	wc_update_product_stock( $eo_t['product'][ $eo_sku ], 500, 'set' );
}

// A $5 fixed cart coupon next to the seeded 20% one.
$eo_fixed_id = wc_get_coupon_id_by_code( 'eo-matrix-5off' );
if ( ! $eo_fixed_id ) {
	$eo_fixed = new WC_Coupon();
	$eo_fixed->set_code( 'eo-matrix-5off' );
	$eo_fixed->set_discount_type( 'fixed_cart' );
	$eo_fixed->set_amount( 5 );
	$eo_fixed_id = $eo_fixed->save();
}

$eo_places = array(
	'ON' => array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '1 Yonge St',
		'city'       => 'Toronto',
		'state'      => 'ON',
		'postcode'   => 'M5E 1E5',
		'country'    => 'CA',
	),
	'AB' => array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '100 8 Ave SW',
		'city'       => 'Calgary',
		'state'      => 'AB',
		'postcode'   => 'T2P 1B3',
		'country'    => 'CA',
	),
);

$eo_make_order = static function ( array $lines, $coupon ) use ( &$eo_t, $eo_places ) {
	$order = wc_create_order();
	$order->set_address( array_merge( $eo_places['ON'], array( 'email' => 'customer@example.com' ) ), 'billing' );
	$order->set_address( $eo_places['ON'], 'shipping' );
	foreach ( $lines as $line ) {
		$order->add_product( wc_get_product( $eo_t['product'][ $line[0] ] ), $line[1] );
	}
	$zone     = WC_Shipping_Zones::get_zone_matching_package(
		array(
			'destination' => array(
				'country'  => 'CA',
				'state'    => 'ON',
				'postcode' => 'M5E 1E5',
			),
		)
	);
	$method   = current( $zone->get_shipping_methods( true ) );
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Flat rate' );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_instance_id( $method->get_instance_id() );
	$shipping->set_total( $method->get_option( 'cost' ) );
	$order->add_item( $shipping );
	$order->calculate_totals();
	if ( '' !== $coupon ) {
		$order->apply_coupon( $coupon );
	}
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$order->save();
	$order->payment_complete( 'TEST-' . $order->get_id() );
	$eo_t['orders'][] = $order->get_id();
	return wc_get_order( $order->get_id() );
};

$eo_item_id = static function ( WC_Order $order, $sku ) use ( &$eo_t ) {
	foreach ( $order->get_items() as $item_id => $item ) {
		$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		if ( (int) $id === (int) $eo_t['product'][ $sku ] ) {
			return $item_id;
		}
	}
	return 0;
};

$eo_pay = static function ( $balance_id ) {
	$balance = wc_get_order( $balance_id );
	$balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$balance->save();
	$balance->payment_complete( 'TEST-BAL-' . $balance_id );
};

$eo_stock_now = static function () use ( &$eo_t ) {
	$stock = array();
	foreach ( $eo_t['product'] as $product_id ) {
		$stock[ $product_id ] = (int) wc_get_product( $product_id )->get_stock_quantity();
	}
	return $stock;
};

/**
 * Units each product should have out of stock for this order and its balance orders:
 * what the lines hold after refunds, plus paid balance orders' lines. Nothing for a
 * cancelled order.
 */
$eo_units_held = static function ( WC_Order $order ) {
	$held = array();
	if ( $order->has_status( 'cancelled' ) ) {
		return $held;
	}
	foreach ( $order->get_items() as $item_id => $item ) {
		$id          = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		$held[ $id ] = ( isset( $held[ $id ] ) ? $held[ $id ] : 0 ) + (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id );
	}
	$balances = wc_get_orders(
		array(
			'parent' => $order->get_id(),
			'type'   => 'shop_order',
			'limit'  => -1,
		)
	);
	foreach ( $balances as $balance ) {
		if ( ! $balance->has_status( array( 'processing', 'completed' ) ) ) {
			continue;
		}
		foreach ( $balance->get_items() as $item ) {
			$id          = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$held[ $id ] = ( isset( $held[ $id ] ) ? $held[ $id ] : 0 ) + (int) $item->get_quantity();
		}
	}
	return $held;
};

WP_CLI::log( 'HPOS ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) );

$eo_edits = array(
	'quantity down' => array(
		'lines'   => array( array( 'EO-TSHIRT', 3 ) ),
		'changes' => static function ( $order ) use ( $eo_item_id ) {
			return array(
				array(
					'type'     => 'quantity',
					'item_id'  => $eo_item_id( $order, 'EO-TSHIRT' ),
					'quantity' => 1,
				),
			);
		},
	),
	'remove a line' => array(
		'lines'   => array( array( 'EO-TSHIRT', 2 ), array( 'EO-MUG', 1 ) ),
		'changes' => static function ( $order ) use ( $eo_item_id ) {
			return array(
				array(
					'type'    => 'remove',
					'item_id' => $eo_item_id( $order, 'EO-MUG' ),
				),
			);
		},
	),
	'quantity up'   => array(
		'lines'   => array( array( 'EO-TSHIRT', 1 ) ),
		'changes' => static function ( $order ) use ( $eo_item_id ) {
			return array(
				array(
					'type'     => 'quantity',
					'item_id'  => $eo_item_id( $order, 'EO-TSHIRT' ),
					'quantity' => 3,
				),
			);
		},
	),
	'swap cheaper'  => array(
		'lines'   => array( array( 'EO-HOODIE-S', 2 ) ),
		'changes' => static function ( $order ) use ( $eo_item_id, &$eo_t ) {
			return array(
				array(
					'type'         => 'swap',
					'item_id'      => $eo_item_id( $order, 'EO-HOODIE-S' ),
					'variation_id' => $eo_t['product']['EO-HOODIE-XS'],
				),
			);
		},
	),
	'swap dearer'   => array(
		'lines'   => array( array( 'EO-HOODIE-S', 2 ) ),
		'changes' => static function ( $order ) use ( $eo_item_id, &$eo_t ) {
			return array(
				array(
					'type'         => 'swap',
					'item_id'      => $eo_item_id( $order, 'EO-HOODIE-S' ),
					'variation_id' => $eo_t['product']['EO-HOODIE-L'],
				),
			);
		},
	),
	'add a product' => array(
		'lines'   => array( array( 'EO-TSHIRT', 1 ) ),
		'changes' => static function () use ( &$eo_t ) {
			return array(
				array(
					'type'       => 'add',
					'product_id' => $eo_t['product']['EO-POSTER'],
					'quantity'   => 2,
				),
			);
		},
	),
	'mixed edit'    => array(
		'lines'   => array( array( 'EO-TSHIRT', 3 ), array( 'EO-MUG', 2 ) ),
		'changes' => static function ( $order ) use ( $eo_item_id, &$eo_t ) {
			return array(
				array(
					'type'     => 'quantity',
					'item_id'  => $eo_item_id( $order, 'EO-TSHIRT' ),
					'quantity' => 1,
				),
				array(
					'type'    => 'remove',
					'item_id' => $eo_item_id( $order, 'EO-MUG' ),
				),
				array(
					'type'       => 'add',
					'product_id' => $eo_t['product']['EO-POSTER'],
					'quantity'   => 5,
				),
			);
		},
	),
	'address'       => array(
		'lines'   => array( array( 'EO-TSHIRT', 2 ) ),
		'changes' => static function () use ( $eo_places ) {
			return array(
				array(
					'type'     => 'address',
					'shipping' => $eo_places['AB'],
					'billing'  => $eo_places['AB'],
				),
			);
		},
	),
	'cancel'        => array(
		'lines'   => array( array( 'EO-TSHIRT', 2 ), array( 'EO-HOODIE-S', 1 ) ),
		'changes' => null,
	),
);

foreach ( array( 'no', 'yes' ) as $eo_incl ) {
	update_option( 'woocommerce_prices_include_tax', $eo_incl );
	foreach ( array(
		''               => 'no coupon',
		'save20'         => '20% coupon',
		'eo-matrix-5off' => '$5 coupon',
	) as $eo_coupon => $eo_coupon_label ) {
		$eo_setup = ( 'yes' === $eo_incl ? 'prices with tax' : 'prices without tax' ) . ', ' . $eo_coupon_label;
		WP_CLI::log( "Setup: {$eo_setup}" );

		foreach ( $eo_edits as $eo_edit => $eo_spec ) {
			$eo_label = "{$eo_setup}, {$eo_edit}";
			wp_cache_flush();
			$eo_before_stock = $eo_stock_now();
			$eo_order        = $eo_make_order( $eo_spec['lines'], $eo_coupon );
			$eo_paid         = (float) $eo_order->get_total();

			if ( null === $eo_spec['changes'] ) {
				$eo_result = Edit_Orders_For_WooCommerce_Cancellation::execute( $eo_order, 'customer' );
				$eo_order  = wc_get_order( $eo_order->get_id() );
				$eo_check( "{$eo_label}: cancelled and fully refunded", is_array( $eo_result ) && 'cancelled' === $eo_order->get_status() && $eo_money( $eo_paid ) === $eo_money( $eo_order->get_total_refunded() ) && $eo_money( $eo_paid ) === $eo_money( $eo_result['amount'] ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : $eo_money( $eo_order->get_total_refunded() ) . ' of ' . $eo_money( $eo_paid ) );
			} else {
				$eo_changes = call_user_func( $eo_spec['changes'], $eo_order );
				$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
				if ( is_wp_error( $eo_plan ) ) {
					$eo_check( "{$eo_label}: preview", false, $eo_plan->get_error_message() );
					continue;
				}
				$eo_shown_refund  = $eo_money( $eo_plan->get_refund_amount() );
				$eo_shown_balance = $eo_money( $eo_plan->get_balance_estimate( $eo_order ) );
				$eo_result        = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes, 'staff', array( 'expect' => $eo_plan->fingerprint() ) );
				if ( is_wp_error( $eo_result ) ) {
					$eo_check( "{$eo_label}: apply", false, $eo_result->get_error_message() );
					continue;
				}

				$eo_balance = ! empty( $eo_result['balance_order_id'] ) ? wc_get_order( $eo_result['balance_order_id'] ) : null;
				if ( $eo_balance ) {
					$eo_check( "{$eo_label}: balance order total = preview ({$eo_shown_balance})", $eo_shown_balance === $eo_money( $eo_balance->get_total() ), $eo_money( $eo_balance->get_total() ) );
					$eo_pay( $eo_balance->get_id() );
				} else {
					$eo_check( "{$eo_label}: no balance when the preview showed none", '0.00' === $eo_shown_balance );
				}

				$eo_order = wc_get_order( $eo_order->get_id() );
				$eo_check( "{$eo_label}: refunded = preview ({$eo_shown_refund})", $eo_shown_refund === $eo_money( $eo_order->get_total_refunded() ), $eo_money( $eo_order->get_total_refunded() ) );
				$eo_check( "{$eo_label}: never more refunded than paid", (float) $eo_order->get_total_refunded() <= $eo_paid + 0.001 );
				if ( $eo_balance ) {
					$eo_notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $eo_order->get_id() ) ), 'content' ) );
					$eo_check( "{$eo_label}: balance paid, changes applied once", false !== strpos( $eo_notes, 'paid; changes applied' ) && 1 === substr_count( $eo_notes, 'paid; changes applied' ) );
				}
			}

			// Stock: what left the shelf equals what the orders hold now.
			wp_cache_flush();
			$eo_after_stock = $eo_stock_now();
			$eo_held        = $eo_units_held( wc_get_order( $eo_order->get_id() ) );
			$eo_wrong       = array();
			foreach ( $eo_before_stock as $eo_product_id => $eo_qty ) {
				$eo_expect = $eo_qty - ( isset( $eo_held[ $eo_product_id ] ) ? $eo_held[ $eo_product_id ] : 0 );
				if ( $eo_expect !== $eo_after_stock[ $eo_product_id ] ) {
					$eo_wrong[] = get_the_title( $eo_product_id ) . " {$eo_after_stock[ $eo_product_id ]} (expected {$eo_expect})";
				}
			}
			$eo_check( "{$eo_label}: stock matches what the orders hold", array() === $eo_wrong, implode( ', ', $eo_wrong ) );
		}
	}
}

// ---------------------------------------------------------------------------
WP_CLI::log( 'Nothing from this plugin in debug.log' );
clearstatcache();
$eo_new_log = file_exists( $eo_debug_log ) ? (string) file_get_contents( $eo_debug_log, false, null, $eo_debug_from ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$eo_ours    = preg_grep( '/edit-orders-for-woocommerce|edit_orders_for_woocommerce/i', explode( "\n", $eo_new_log ) );
$eo_check( 'no notices, warnings or errors from the plugin during the matrix', empty( $eo_ours ), implode( ' | ', array_slice( $eo_ours, 0, 3 ) ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Cleanup' );
update_option( 'woocommerce_prices_include_tax', $eo_incl_before );
foreach ( $eo_t['orders'] as $eo_order_id ) {
	$eo_order = wc_get_order( $eo_order_id );
	if ( ! $eo_order ) {
		continue;
	}
	$eo_children = wc_get_orders(
		array(
			'parent' => $eo_order_id,
			'type'   => 'shop_order',
			'limit'  => -1,
		)
	);
	foreach ( array_merge( $eo_children, array( $eo_order ) ) as $eo_delete ) {
		foreach ( $eo_delete->get_refunds() as $eo_refund ) {
			$eo_refund->delete( true );
		}
		$wpdb->delete( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'order_id' => $eo_delete->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$eo_delete->delete( true );
	}
}
foreach ( $eo_t['stock'] as $eo_product_id => $eo_quantity ) {
	wc_update_product_stock( $eo_product_id, $eo_quantity, 'set' );
}
wp_delete_post( $eo_fixed_id, true );
if ( null === $eo_settings_before ) {
	delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
} else {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $eo_settings_before );
}

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'Matrix suite failed.' );
}
WP_CLI::success( 'Matrix suite passed.' );
