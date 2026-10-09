<?php
/**
 * Customer "balance due" email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/edit-orders-balance-due.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order      $order
 * @var WC_Order|null $original_order
 * @var string[]      $changes
 * @var string        $pay_url
 * @var string        $email_heading
 * @var string        $additional_content
 * @var bool          $sent_to_admin
 * @var bool          $plain_text
 * @var WC_Email      $email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks, called as WooCommerce's templates do.

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

/* translators: %s: customer first name. */
echo esc_html( sprintf( __( 'Hi %s,', 'edit-orders-for-woocommerce' ), $order->get_billing_first_name() ) ) . "\n\n";

echo esc_html(
	html_entity_decode(
		sprintf(
			/* translators: 1: original order number, 2: amount. */
			__( 'The change to your order #%1$s costs %2$s more. It will be made as soon as you pay the difference:', 'edit-orders-for-woocommerce' ),
			$original_order ? $original_order->get_order_number() : '',
			wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) )
		)
	)
) . "\n\n";

foreach ( $changes as $edit_orders_for_woocommerce_change ) {
	echo '- ' . esc_html( $edit_orders_for_woocommerce_change ) . "\n";
}

echo "\n" . esc_html__( 'Pay the difference:', 'edit-orders-for-woocommerce' ) . ' ' . esc_url_raw( $pay_url ) . "\n\n";
echo esc_html__( 'Until it is paid, your order stays as you first placed it.', 'edit-orders-for-woocommerce' ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
