<?php
/**
 * Laptop, upgrade check (queue 2026-10-14-c item 2): activity made with 1.1.0 before the
 * update to 1.2.0. Run on the wp-env tests site with 1.1.0 active:
 *
 *   wp eval-file <copy of this file>
 *
 * Creates a product and two paid orders, then:
 * - a staff edit on the first order (quantity 3 to 1, refunded through the test gateway);
 * - a customer cancellation request on the second order, declined by the store.
 * Prints the order ids and the log rows as JSON. Not part of the suite.
 *
 * @package Edit_Orders_For_WooCommerce
 */

if ( ! class_exists( 'Edit_Orders_For_WooCommerce_Settlement_Executor' ) ) {
	WP_CLI::error( 'Edit Orders is not active.' );
}

$lap_product = new WC_Product_Simple();
$lap_product->set_name( 'ZZ Upgrade Check Mug' );
$lap_product->set_regular_price( '20' );
$lap_product->set_manage_stock( true );
$lap_product->set_stock_quantity( 50 );
$lap_product->set_status( 'publish' );
$lap_product->save();

$lap_order = static function ( $qty ) use ( $lap_product ) {
	$order = wc_create_order();
	$order->add_product( $lap_product, $qty );
	$order->set_billing_first_name( 'Zed' );
	$order->set_billing_last_name( 'Upgrade' );
	$order->set_billing_email( 'zz-upgrade@example.invalid' );
	$order->set_billing_country( 'CA' );
	$order->set_billing_state( 'ON' );
	$order->set_payment_method( 'edit_orders_test' );
	$order->set_payment_method_title( 'Test gateway' );
	$order->calculate_totals();
	$order->set_transaction_id( 'zz-' . wp_generate_password( 8, false ) );
	$order->set_date_paid( time() );
	$order->set_status( 'processing' );
	$order->save();
	return $order;
};

$lap_edited = $lap_order( 3 );
$lap_items  = $lap_edited->get_items();
$lap_item   = reset( $lap_items );
$lap_result = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$lap_edited,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $lap_item->get_id(),
			'quantity' => 1,
		),
	),
	'staff'
);

$lap_cancel  = $lap_order( 1 );
$lap_request = Edit_Orders_For_WooCommerce_Cancellation::request( $lap_cancel, 'Ordered by mistake', 'customer' );
$lap_decline = Edit_Orders_For_WooCommerce_Cancellation::decline( wc_get_order( $lap_cancel->get_id() ), 'Already packed' );

global $wpdb;
echo wp_json_encode(
	array(
		'product'        => $lap_product->get_id(),
		'edited_order'   => $lap_edited->get_id(),
		'edit_result'    => is_wp_error( $lap_result ) ? $lap_result->get_error_message() : ( $lap_result['status'] ?? $lap_result ),
		'edited_total'   => wc_get_order( $lap_edited->get_id() )->get_total(),
		'edited_refund'  => wc_get_order( $lap_edited->get_id() )->get_total_refunded(),
		'cancel_order'   => $lap_cancel->get_id(),
		'request'        => is_wp_error( $lap_request ) ? $lap_request->get_error_message() : $lap_request,
		'decline'        => is_wp_error( $lap_decline ) ? $lap_decline->get_error_message() : $lap_decline,
		'cancel_status'  => wc_get_order( $lap_cancel->get_id() )->get_status(),
		'log_rows'       => $wpdb->get_results( "SELECT id, order_id, action, actor_type FROM {$wpdb->prefix}edit_orders_for_woocommerce_log ORDER BY id", ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	),
	JSON_PRETTY_PRINT
);
echo "\n";
