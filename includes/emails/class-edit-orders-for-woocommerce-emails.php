<?php
/**
 * Registers the plugin's emails and sends them at the right moments (spec section 5.3).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Emails Class.
 */
class Edit_Orders_For_WooCommerce_Emails {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
		add_action( 'edit_orders_for_woocommerce_after_apply', array( __CLASS__, 'after_apply' ), 20, 4 );
		add_action( 'edit_orders_for_woocommerce_balance_paid', array( __CLASS__, 'balance_paid' ), 20, 3 );
		add_action( 'edit_orders_for_woocommerce_manual_refund_needed', array( __CLASS__, 'manual_refund_needed' ), 10, 5 );
		add_action( 'edit_orders_for_woocommerce_cancel_requested', array( __CLASS__, 'cancel_requested' ), 10, 2 );
		add_action( 'edit_orders_for_woocommerce_cancelled', array( __CLASS__, 'cancelled' ), 10, 4 );
		add_action( 'edit_orders_for_woocommerce_cancel_declined', array( __CLASS__, 'cancel_declined' ), 10, 2 );
		add_action( 'edit_orders_for_woocommerce_customer_changed', array( __CLASS__, 'customer_changed' ), 10, 2 );
		add_action( 'woocommerce_email_sent', array( __CLASS__, 'log_sent' ), 10, 3 );
	}

	/**
	 * Log each of our emails WooCommerce sent, or failed to send. Never the recipient.
	 *
	 * @param bool     $sent     Whether wp_mail() accepted it.
	 * @param string   $email_id Email ID.
	 * @param WC_Email $email    Email.
	 */
	public static function log_sent( $sent, $email_id, $email = null ) {
		if ( 0 !== strpos( (string) $email_id, 'edit_orders_for_woocommerce_' ) ) {
			return;
		}
		$order_id = ( $email instanceof WC_Email && $email->object instanceof WC_Order ) ? $email->object->get_id() : 0;
		Edit_Orders_For_WooCommerce_Log::debug(
			sprintf( 'order %d: email %s %s', $order_id, $email_id, $sent ? 'sent' : 'failed to send' ),
			array(
				'order'    => $order_id,
				'email_id' => $email_id,
				'sent'     => (bool) $sent,
			)
		);
	}

	/**
	 * Log an email that is not sent because it is turned off, or because the person
	 * making the change chose not to send it.
	 *
	 * @param WC_Email|null $email    Email.
	 * @param int           $order_id Order ID.
	 * @param bool          $wanted   False when the editor chose not to send it.
	 * @return bool Whether to go on and trigger it.
	 */
	private static function will_send( $email, $order_id, $wanted = true ) {
		if ( ! $email ) {
			return false;
		}
		if ( ! $wanted || ! $email->is_enabled() ) {
			Edit_Orders_For_WooCommerce_Log::debug(
				sprintf( 'order %d: email %s not sent: %s', $order_id, $email->id, $wanted ? 'turned off in WooCommerce > Settings > Emails' : 'the person making the change chose not to send it' ),
				array(
					'order'    => (int) $order_id,
					'email_id' => $email->id,
				)
			);
		}

		return $wanted;
	}

	/**
	 * Add our emails to WooCommerce's list.
	 *
	 * @param WC_Email[] $emails Emails.
	 * @return WC_Email[]
	 */
	public static function register( $emails ) {
		$dir = EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/emails/';

		require_once $dir . 'class-edit-orders-for-woocommerce-email-order-updated.php';
		require_once $dir . 'class-edit-orders-for-woocommerce-email-balance-due.php';
		require_once $dir . 'class-edit-orders-for-woocommerce-email-manual-refund.php';
		require_once $dir . 'class-edit-orders-for-woocommerce-email-base.php';
		require_once $dir . 'class-edit-orders-for-woocommerce-email-cancel-request.php';
		require_once $dir . 'class-edit-orders-for-woocommerce-email-cancelled.php';
		require_once $dir . 'class-edit-orders-for-woocommerce-email-cancel-declined.php';
		require_once $dir . 'class-edit-orders-for-woocommerce-email-customer-changed.php';

		$emails['Edit_Orders_For_WooCommerce_Email_Order_Updated']    = new Edit_Orders_For_WooCommerce_Email_Order_Updated();
		$emails['Edit_Orders_For_WooCommerce_Email_Balance_Due']      = new Edit_Orders_For_WooCommerce_Email_Balance_Due();
		$emails['Edit_Orders_For_WooCommerce_Email_Manual_Refund']    = new Edit_Orders_For_WooCommerce_Email_Manual_Refund();
		$emails['Edit_Orders_For_WooCommerce_Email_Cancel_Request']   = new Edit_Orders_For_WooCommerce_Email_Cancel_Request();
		$emails['Edit_Orders_For_WooCommerce_Email_Cancelled']        = new Edit_Orders_For_WooCommerce_Email_Cancelled();
		$emails['Edit_Orders_For_WooCommerce_Email_Cancel_Declined']  = new Edit_Orders_For_WooCommerce_Email_Cancel_Declined();
		$emails['Edit_Orders_For_WooCommerce_Email_Customer_Changed'] = new Edit_Orders_For_WooCommerce_Email_Customer_Changed();

		return $emails;
	}

	/**
	 * Get one of our email objects.
	 *
	 * @param string $class_name Class name.
	 * @return WC_Email|null
	 */
	private static function email( $class_name ) {
		$emails = WC()->mailer()->get_emails();

		return isset( $emails[ $class_name ] ) ? $emails[ $class_name ] : null;
	}

	/**
	 * After an edit: the "order updated" email, or the pay link for a balance.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result Executor result.
	 * @param string   $actor  Actor.
	 * @param array    $args   Options from the editor: notify_customer, send_pay_link.
	 */
	public static function after_apply( $order, $result, $actor, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'notify_customer' => true,
				'send_pay_link'   => true,
			)
		);

		if ( 'balance_due' === $result['status'] ) {
			$email = self::email( 'Edit_Orders_For_WooCommerce_Email_Balance_Due' );
			if ( self::will_send( $email, (int) $result['balance_order_id'], (bool) $args['send_pay_link'] ) ) {
				$email->trigger( $result['balance_order_id'], false, $result['plan']->get_descriptions() );
			}
			return;
		}

		$email = self::email( 'Edit_Orders_For_WooCommerce_Email_Order_Updated' );
		if ( self::will_send( $email, $order->get_id(), (bool) $args['notify_customer'] ) ) {
			$email->trigger( $order->get_id(), false, self::summary( $result ) );
		}
	}

	/**
	 * After a balance order is paid: tell the customer their order changed.
	 *
	 * @param WC_Order       $balance Balance order.
	 * @param WC_Order       $order   Original order.
	 * @param array|WP_Error $result  Executor result.
	 */
	public static function balance_paid( $balance, $order, $result ) {
		// A linked pay-on-delivery order is "applied" unpaid, inside the edit itself;
		// after_apply() already tells the customer, so don't tell them twice.
		if ( ! $balance->get_date_paid() ) {
			return;
		}

		$email = self::email( 'Edit_Orders_For_WooCommerce_Email_Order_Updated' );
		if ( ! is_wp_error( $result ) && self::will_send( $email, $order->get_id() ) ) {
			$summary                  = self::summary( $result );
			$summary['balance_order'] = $balance->get_order_number();
			$email->trigger( $order->get_id(), false, $summary );
		}
	}

	/**
	 * Tell the store owner a refund must be made by hand.
	 *
	 * @param WC_Order $order         Order.
	 * @param string   $amount        Amount.
	 * @param int      $refund_id     Refund record.
	 * @param string   $gateway_error Gateway error, if any.
	 * @param string   $reason        Why the money is owed, when no refund was recorded.
	 */
	public static function manual_refund_needed( $order, $amount, $refund_id, $gateway_error, $reason = '' ) {
		$email = self::email( 'Edit_Orders_For_WooCommerce_Email_Manual_Refund' );
		if ( self::will_send( $email, $order->get_id() ) ) {
			$email->trigger(
				$order->get_id(),
				false,
				array(
					'amount'        => $amount,
					'gateway_error' => $gateway_error,
					'reason'        => $reason,
				)
			);
		}
	}

	/**
	 * Tell the store owner a customer asked to cancel.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Reason.
	 */
	public static function cancel_requested( $order, $reason ) {
		$email = self::email( 'Edit_Orders_For_WooCommerce_Email_Cancel_Request' );
		if ( self::will_send( $email, $order->get_id() ) ) {
			$email->trigger( $order->get_id(), $order, array( 'reason' => $reason ) );
		}
	}

	/**
	 * Tell the customer their cancellation went through, with the refund.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $actor  Actor.
	 * @param string   $reason Reason.
	 * @param array    $result Result: amount, refund, on_delivery.
	 */
	public static function cancelled( $order, $actor, $reason, $result ) {
		$email = self::email( 'Edit_Orders_For_WooCommerce_Email_Cancelled' );
		if ( self::will_send( $email, $order->get_id() ) ) {
			$email->trigger(
				$order->get_id(),
				$order,
				array(
					'amount'      => $result['amount'],
					'manual'      => ! empty( $result['refund']['manual'] ),
					'on_delivery' => ! empty( $result['on_delivery'] ),
				)
			);
		}
	}

	/**
	 * Tell the customer their cancellation request was declined.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $message Message.
	 */
	public static function cancel_declined( $order, $message ) {
		$email = self::email( 'Edit_Orders_For_WooCommerce_Email_Cancel_Declined' );
		if ( self::will_send( $email, $order->get_id() ) ) {
			$email->trigger( $order->get_id(), $order, array( 'message' => $message ) );
		}
	}

	/**
	 * Tell the store owner a customer changed their order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  changes, balance_due.
	 */
	public static function customer_changed( $order, $data ) {
		$email = self::email( 'Edit_Orders_For_WooCommerce_Email_Customer_Changed' );
		if ( self::will_send( $email, $order->get_id() ) ) {
			$email->trigger( $order->get_id(), $order, $data );
		}
	}

	/**
	 * What the customer should be told about an applied plan.
	 *
	 * @param array $result Executor result.
	 * @return array changes, refund, refund_manual, on_delivery
	 */
	private static function summary( array $result ) {
		$refund = isset( $result['refund'] ) && is_array( $result['refund'] ) ? $result['refund'] : array();

		return array(
			'changes'       => $result['plan']->get_descriptions(),
			'refund'        => $result['plan']->get_refund_amount(),
			'refund_manual' => ! empty( $refund['manual'] ),
			'on_delivery'   => ! empty( $refund['on_delivery'] ) || 'collect_on_delivery' === $result['status'],
		);
	}
}
