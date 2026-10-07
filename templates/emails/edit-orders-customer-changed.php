<?php
/**
 * Customer changed an order email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/edit-orders-customer-changed.php.
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

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: order number. */ ?>
<p><?php printf( esc_html__( 'The customer changed order #%s:', 'wpheka-edit-orders-for-woocommerce' ), esc_html( $order->get_order_number() ) ); ?></p>
<?php if ( ! empty( $data['changes'] ) ) : ?>
	<ul>
		<?php foreach ( (array) $data['changes'] as $edit_orders_for_woocommerce_change ) : ?>
			<li><?php echo esc_html( $edit_orders_for_woocommerce_change ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
<?php if ( ! empty( $data['balance_due'] ) ) : ?>
	<?php /* translators: %s: amount. */ ?>
	<p><?php printf( esc_html__( 'The change costs %s more. It applies once the customer pays.', 'wpheka-edit-orders-for-woocommerce' ), wp_kses_post( wc_price( $data['balance_due'], array( 'currency' => $order->get_currency() ) ) ) ); ?></p>
<?php endif; ?>
<p><a class="link" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php esc_html_e( 'View the order', 'wpheka-edit-orders-for-woocommerce' ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
