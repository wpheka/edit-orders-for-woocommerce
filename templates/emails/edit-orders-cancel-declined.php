<?php
/**
 * Cancellation request declined email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/edit-orders-cancel-declined.php.
 *
 * @package Edit_Orders_For_WooCommerce
 * @var WC_Order $order
 * @var array    $data message
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
<p><?php printf( esc_html__( 'We could not cancel order #%s. It goes ahead as placed.', 'wpheka-edit-orders-for-woocommerce' ), esc_html( $order->get_order_number() ) ); ?></p>
<?php if ( ! empty( $data['message'] ) ) : ?>
	<p><?php echo esc_html( $data['message'] ); ?></p>
<?php endif; ?>
<p><?php esc_html_e( 'Reply to this email if you have any questions.', 'wpheka-edit-orders-for-woocommerce' ); ?></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
