<?php
/**
 * Plugin Name: Edit Orders Test Gateway
 * Description: Development-only payment gateway that supports refunds, so the functional suite can test refunds offline. Loaded only in the wp-env store; never shipped.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		/**
		 * A gateway that "takes" payment and "refunds" it without contacting anyone.
		 *
		 * Set the option `edit_orders_test_gateway_fail_refunds` to `yes` to make every
		 * refund fail, for the fallback cases.
		 */
		class Edit_Orders_Test_Gateway extends WC_Payment_Gateway {

			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id                 = 'edit_orders_test';
				$this->method_title       = 'Edit Orders test gateway';
				$this->method_description = 'Development only. Approves payments and refunds without contacting anyone.';
				$this->title              = 'Test payment (refunds supported)';
				$this->has_fields         = false;
				$this->enabled            = 'yes';
				$this->supports           = array( 'products', 'refunds' );
			}

			/**
			 * Approve the payment.
			 *
			 * @param int $order_id Order ID.
			 * @return array
			 */
			public function process_payment( $order_id ) {
				$order = wc_get_order( $order_id );
				$order->payment_complete( 'EOTEST-' . $order_id . '-' . time() );

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			/**
			 * Approve the refund, or fail it on request.
			 *
			 * @param int        $order_id Order ID.
			 * @param float|null $amount   Amount.
			 * @param string     $reason   Reason.
			 * @return bool|WP_Error
			 */
			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				if ( 'yes' === get_option( 'edit_orders_test_gateway_fail_refunds' ) ) {
					return new WP_Error( 'edit_orders_test_refund_failed', 'Test gateway: refund declined on request.' );
				}

				$order    = wc_get_order( $order_id );
				$refunded = (float) $order->get_meta( '_edit_orders_test_refunded' ) + (float) $amount;
				$order->update_meta_data( '_edit_orders_test_refunded', wc_format_decimal( $refunded, 2 ) );
				$order->add_order_note( sprintf( 'Test gateway refunded %s.', wc_format_decimal( $amount, 2 ) ) );
				$order->save();

				return true;
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'Edit_Orders_Test_Gateway';
				return $gateways;
			}
		);
	},
	11
);
