<?php
/**
 * Store owner "manual refund needed" email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/edit-orders-manual-refund.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order $order
 * @var array    $details
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks, called as WooCommerce's templates do.

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

echo esc_html(
	html_entity_decode(
		sprintf(
			/* translators: 1: amount, 2: order number, 3: payment method. */
			__( 'An edit to order #%2$s needs a refund of %1$s that %3$s could not make automatically. Please refund the customer by hand.', 'edit-orders-for-woocommerce' ),
			wp_strip_all_tags( wc_price( isset( $details['amount'] ) ? $details['amount'] : 0, array( 'currency' => $order->get_currency() ) ) ),
			$order->get_order_number(),
			$order->get_payment_method_title() ? $order->get_payment_method_title() : __( 'the payment method', 'edit-orders-for-woocommerce' )
		)
	)
) . "\n\n";

if ( ! empty( $details['reason'] ) ) {
	echo esc_html( $details['reason'] ) . "\n\n";
} else {
	if ( ! empty( $details['gateway_error'] ) ) {
		/* translators: %s: error from the payment gateway. */
		echo esc_html( sprintf( __( 'The payment gateway said: %s', 'edit-orders-for-woocommerce' ), $details['gateway_error'] ) ) . "\n\n";
	}

	echo esc_html__( 'The refund is already recorded on the order, with stock restored. Only the money still has to be sent.', 'edit-orders-for-woocommerce' ) . "\n\n";
}
echo esc_url_raw( $order->get_edit_order_url() ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
