<?php
/**
 * Customer changed an order email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/edit-orders-customer-changed.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order $order
 * @var array    $data changes, balance_due
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
echo esc_html( sprintf( __( 'The customer changed order #%s:', 'wpheka-edit-orders-for-woocommerce' ), $order->get_order_number() ) ) . "\n\n";
foreach ( (array) ( isset( $data['changes'] ) ? $data['changes'] : array() ) as $edit_orders_for_woocommerce_change ) {
	echo '- ' . esc_html( $edit_orders_for_woocommerce_change ) . "\n";
}
if ( ! empty( $data['balance_due'] ) ) {
	/* translators: %s: amount. */
	echo "\n" . esc_html( html_entity_decode( sprintf( __( 'The change costs %s more. It applies once the customer pays.', 'wpheka-edit-orders-for-woocommerce' ), wp_strip_all_tags( wc_price( $data['balance_due'], array( 'currency' => $order->get_currency() ) ) ) ) ) ) . "\n";
}
echo "\n" . esc_url_raw( $order->get_edit_order_url() ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
