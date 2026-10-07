<?php
/**
 * Cancellation request declined email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/edit-orders-cancel-declined.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order $order
 * @var array    $data message
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
/* translators: %s: order number. */
echo esc_html( sprintf( __( 'We could not cancel order #%s. It goes ahead as placed.', 'wpheka-edit-orders-for-woocommerce' ), $order->get_order_number() ) ) . "\n\n";
if ( ! empty( $data['message'] ) ) {
	echo esc_html( $data['message'] ) . "\n\n";
}
echo esc_html__( 'Reply to this email if you have any questions.', 'wpheka-edit-orders-for-woocommerce' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
