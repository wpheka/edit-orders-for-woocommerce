<?php
/**
 * Cancellation requested email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/edit-orders-cancel-request.php.
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

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	printf(
		/* translators: %s: order number. */
		esc_html__( 'The customer asked to cancel order #%s.', 'edit-orders-for-woocommerce' ),
		esc_html( $order->get_order_number() )
	);
	?>
</p>
<?php if ( ! empty( $data['reason'] ) ) : ?>
	<?php /* translators: %s: reason. */ ?>
	<p><?php printf( esc_html__( 'Reason: %s', 'edit-orders-for-woocommerce' ), esc_html( $data['reason'] ) ); ?></p>
<?php endif; ?>
<p><a class="link" href="<?php echo esc_url( admin_url( 'admin.php?page=edit-orders-for-woocommerce' ) ); ?>"><?php esc_html_e( 'Approve or decline the request', 'edit-orders-for-woocommerce' ); ?></a></p>
<?php do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
