<?php
/**
 * Customer "order updated" email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/edit-orders-order-updated.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order $order
 * @var array    $summary changes, refund, refund_manual, on_delivery, balance_order
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
<p><?php printf( esc_html__( 'Hi %s,', 'edit-orders-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
<p><?php esc_html_e( 'We have updated your order. Here is what changed:', 'edit-orders-for-woocommerce' ); ?></p>

<?php if ( ! empty( $summary['changes'] ) ) : ?>
	<ul>
		<?php foreach ( $summary['changes'] as $edit_orders_for_woocommerce_change ) : ?>
			<li><?php echo esc_html( $edit_orders_for_woocommerce_change ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php if ( ! empty( $summary['balance_order'] ) ) : ?>
	<?php /* translators: %s: balance order number. */ ?>
	<p><?php printf( esc_html__( 'Thank you for paying the difference (order #%s).', 'edit-orders-for-woocommerce' ), esc_html( $summary['balance_order'] ) ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $summary['refund'] ) && (float) $summary['refund'] > 0 ) : ?>
	<?php if ( ! empty( $summary['on_delivery'] ) ) : ?>
		<?php /* translators: %s: amount. */ ?>
		<p><?php printf( esc_html__( 'You will pay %s less on delivery.', 'edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $summary['refund'], array( 'currency' => $order->get_currency() ) ) ) ); ?></p>
	<?php elseif ( ! empty( $summary['refund_manual'] ) ) : ?>
		<?php /* translators: %s: amount. */ ?>
		<p><?php printf( esc_html__( 'We will refund %s to you and let you know when it is done.', 'edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $summary['refund'], array( 'currency' => $order->get_currency() ) ) ) ); ?></p>
	<?php else : ?>
		<?php /* translators: %s: amount. */ ?>
		<p><?php printf( esc_html__( 'We have refunded %s to your original payment method.', 'edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $summary['refund'], array( 'currency' => $order->get_currency() ) ) ) ); ?></p>
	<?php endif; ?>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
