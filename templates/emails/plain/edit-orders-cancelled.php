<?php
/**
 * Order cancelled at your request email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/edit-orders-cancelled.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order $order
 * @var array    $data amount, manual, on_delivery
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
echo esc_html( sprintf( __( 'As you asked, we have cancelled order #%s.', 'wpheka-edit-orders-for-woocommerce' ), $order->get_order_number() ) ) . "\n\n";
if ( ! empty( $data['on_delivery'] ) ) {
	echo esc_html__( 'Nothing was charged, so there is nothing to refund.', 'wpheka-edit-orders-for-woocommerce' ) . "\n\n";
} elseif ( ! empty( $data['amount'] ) ) {
	$edit_orders_for_woocommerce_amount = wp_strip_all_tags( wc_price( $data['amount'], array( 'currency' => $order->get_currency() ) ) );
	/* translators: %s: amount. */
	$edit_orders_for_woocommerce_line = ! empty( $data['manual'] ) ? sprintf( __( 'We will refund %s to you and let you know when it is done.', 'wpheka-edit-orders-for-woocommerce' ), $edit_orders_for_woocommerce_amount ) : sprintf( __( 'We have refunded %s to your original payment method.', 'wpheka-edit-orders-for-woocommerce' ), $edit_orders_for_woocommerce_amount ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- comment above applies to both.
	echo esc_html( html_entity_decode( $edit_orders_for_woocommerce_line ) ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
