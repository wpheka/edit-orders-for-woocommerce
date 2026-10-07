<?php
/**
 * Customer "balance due" email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/edit-orders-balance-due.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order      $order          The balance order.
 * @var WC_Order|null $original_order The order being changed.
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

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: customer first name. */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'edit-orders-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
<p>
	<?php
	printf(
		/* translators: 1: original order number, 2: amount. */
		esc_html__( 'The change to your order #%1$s costs %2$s more. It will be made as soon as you pay the difference:', 'edit-orders-for-woocommerce' ),
		esc_html( $original_order ? $original_order->get_order_number() : '' ),
		wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) )
	);
	?>
</p>

<?php if ( $changes ) : ?>
	<ul>
		<?php foreach ( $changes as $edit_orders_for_woocommerce_change ) : ?>
			<li><?php echo esc_html( $edit_orders_for_woocommerce_change ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<p><a class="link" href="<?php echo esc_url( $pay_url ); ?>"><?php esc_html_e( 'Pay the difference', 'edit-orders-for-woocommerce' ); ?></a></p>
<p><?php esc_html_e( 'Until it is paid, your order stays as you first placed it.', 'edit-orders-for-woocommerce' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
