<?php
/**
 * Order cancelled at your request email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/edit-orders-cancelled.php.
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

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: customer first name. */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'wpheka-edit-orders-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
<?php /* translators: %s: order number. */ ?>
<p><?php printf( esc_html__( 'As you asked, we have cancelled order #%s.', 'wpheka-edit-orders-for-woocommerce' ), esc_html( $order->get_order_number() ) ); ?></p>
<?php if ( ! empty( $data['on_delivery'] ) ) : ?>
	<p><?php esc_html_e( 'Nothing was charged, so there is nothing to refund.', 'wpheka-edit-orders-for-woocommerce' ); ?></p>
<?php elseif ( ! empty( $data['amount'] ) && ! empty( $data['manual'] ) ) : ?>
	<?php /* translators: %s: amount. */ ?>
	<p><?php printf( esc_html__( 'We will refund %s to you and let you know when it is done.', 'wpheka-edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $data['amount'], array( 'currency' => $order->get_currency() ) ) ) ); ?></p>
<?php elseif ( ! empty( $data['amount'] ) ) : ?>
	<?php /* translators: %s: amount. */ ?>
	<p><?php printf( esc_html__( 'We have refunded %s to your original payment method.', 'wpheka-edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $data['amount'], array( 'currency' => $order->get_currency() ) ) ) ); ?></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
