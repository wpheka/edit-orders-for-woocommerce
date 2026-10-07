<?php
/**
 * Customer "order updated" email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/edit-orders-order-updated.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order $order
 * @var array    $summary
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks, called as WooCommerce's templates do.

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

/* translators: %s: customer first name. */
echo esc_html( sprintf( __( 'Hi %s,', 'wpheka-edit-orders-for-woocommerce' ), $order->get_billing_first_name() ) ) . "\n\n";
echo esc_html__( 'We have updated your order. Here is what changed:', 'wpheka-edit-orders-for-woocommerce' ) . "\n\n";

foreach ( (array) ( isset( $summary['changes'] ) ? $summary['changes'] : array() ) as $edit_orders_for_woocommerce_change ) {
	echo '- ' . esc_html( $edit_orders_for_woocommerce_change ) . "\n";
}
echo "\n";

if ( ! empty( $summary['balance_order'] ) ) {
	/* translators: %s: balance order number. */
	echo esc_html( sprintf( __( 'Thank you for paying the difference (order #%s).', 'wpheka-edit-orders-for-woocommerce' ), $summary['balance_order'] ) ) . "\n\n";
}

if ( ! empty( $summary['refund'] ) && (float) $summary['refund'] > 0 ) {
	$edit_orders_for_woocommerce_amount = wp_strip_all_tags( wc_price( $summary['refund'], array( 'currency' => $order->get_currency() ) ) );
	if ( ! empty( $summary['on_delivery'] ) ) {
		/* translators: %s: amount. */
		$edit_orders_for_woocommerce_line = sprintf( __( 'You will pay %s less on delivery.', 'wpheka-edit-orders-for-woocommerce' ), $edit_orders_for_woocommerce_amount );
	} elseif ( ! empty( $summary['refund_manual'] ) ) {
		/* translators: %s: amount. */
		$edit_orders_for_woocommerce_line = sprintf( __( 'We will refund %s to you and let you know when it is done.', 'wpheka-edit-orders-for-woocommerce' ), $edit_orders_for_woocommerce_amount );
	} else {
		/* translators: %s: amount. */
		$edit_orders_for_woocommerce_line = sprintf( __( 'We have refunded %s to your original payment method.', 'wpheka-edit-orders-for-woocommerce' ), $edit_orders_for_woocommerce_amount );
	}
	echo esc_html( html_entity_decode( $edit_orders_for_woocommerce_line ) ) . "\n\n";
}

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
