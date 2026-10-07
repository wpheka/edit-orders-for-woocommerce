<?php
/**
 * Cancellation requested email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/edit-orders-cancel-request.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order $order
 * @var array    $data reason
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks, called as WooCommerce's templates do.

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

/* translators: %s: order number. */
echo esc_html( sprintf( __( 'The customer asked to cancel order #%s.', 'edit-orders-for-woocommerce' ), $order->get_order_number() ) ) . "\n\n";
if ( ! empty( $data['reason'] ) ) {
	/* translators: %s: reason. */
	echo esc_html( sprintf( __( 'Reason: %s', 'edit-orders-for-woocommerce' ), $data['reason'] ) ) . "\n\n";
}
echo esc_html__( 'Approve or decline the request:', 'edit-orders-for-woocommerce' ) . ' ' . esc_url_raw( admin_url( 'admin.php?page=edit-orders-for-woocommerce' ) ) . "\n\n";
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
