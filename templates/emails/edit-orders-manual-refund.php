<?php
/**
 * Store owner "manual refund needed" email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/edit-orders-manual-refund.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order $order
 * @var array    $details amount, gateway_error, reason
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks, called as WooCommerce's templates do.

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	printf(
		/* translators: 1: amount, 2: order number, 3: payment method. */
		esc_html__( 'An edit to order #%2$s needs a refund of %1$s that %3$s could not make automatically. Please refund the customer by hand.', 'wpheka-edit-orders-for-woocommerce' ),
		wp_kses_post( wc_price( isset( $details['amount'] ) ? $details['amount'] : 0, array( 'currency' => $order->get_currency() ) ) ),
		esc_html( $order->get_order_number() ),
		esc_html( $order->get_payment_method_title() ? $order->get_payment_method_title() : __( 'the payment method', 'wpheka-edit-orders-for-woocommerce' ) )
	);
	?>
</p>

<?php if ( ! empty( $details['reason'] ) ) : ?>
	<p><?php echo esc_html( $details['reason'] ); ?></p>
<?php else : ?>
	<?php if ( ! empty( $details['gateway_error'] ) ) : ?>
		<?php /* translators: %s: error from the payment gateway. */ ?>
		<p><?php printf( esc_html__( 'The payment gateway said: %s', 'wpheka-edit-orders-for-woocommerce' ), esc_html( $details['gateway_error'] ) ); ?></p>
	<?php endif; ?>

	<p><?php esc_html_e( 'The refund is already recorded on the order, with stock restored. Only the money still has to be sent.', 'wpheka-edit-orders-for-woocommerce' ); ?></p>
<?php endif; ?>
<p><a class="link" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php esc_html_e( 'View the order', 'wpheka-edit-orders-for-woocommerce' ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
