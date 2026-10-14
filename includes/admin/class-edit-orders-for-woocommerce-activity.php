<?php
/**
 * WPHEKA > Edit Orders: cancellation requests and the activity list (spec section 5.2).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Activity Class.
 */
class Edit_Orders_For_WooCommerce_Activity {

	/**
	 * Page slug.
	 */
	const PAGE = 'edit-orders-for-woocommerce';

	/**
	 * The admin-post action for approve and decline.
	 */
	const DECISION_ACTION = 'edit_orders_for_woocommerce_cancel_decision';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::DECISION_ACTION, array( __CLASS__, 'handle_decision' ) );
	}

	/**
	 * URL to approve a request (a nonce link, used from the order screen box).
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $redirect Where to go afterwards.
	 * @return string
	 */
	public static function approve_url( WC_Order $order, $redirect ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::DECISION_ACTION,
					'decision' => 'approve',
					'order_id' => $order->get_id(),
					'redirect' => rawurlencode( $redirect ),
				),
				admin_url( 'admin-post.php' )
			),
			self::DECISION_ACTION . '_' . $order->get_id()
		);
	}

	/**
	 * Approve or decline a request.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $decision approve or decline.
	 * @param string   $message  Message to the customer when declining.
	 * @return array|true|WP_Error
	 */
	public static function decide( WC_Order $order, $decision, $message = '' ) {
		if ( 'approve' === $decision ) {
			return Edit_Orders_For_WooCommerce_Cancellation::approve( $order );
		}

		return Edit_Orders_For_WooCommerce_Cancellation::decline( $order, $message );
	}

	/**
	 * Handle approve and decline (admin-post).
	 *
	 * @throws Throwable An unexpected error, rethrown once it is logged.
	 */
	public static function handle_decision() {
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( wp_unslash( $_REQUEST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line, per order.
		check_admin_referer( self::DECISION_ACTION . '_' . $order_id );

		if ( ! current_user_can( Edit_Orders_For_WooCommerce_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wpheka-edit-orders-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$order    = wc_get_order( $order_id );
		$decision = isset( $_REQUEST['decision'] ) && 'approve' === $_REQUEST['decision'] ? 'approve' : 'decline';
		$message  = isset( $_REQUEST['message'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['message'] ) ) : '';
		$redirect = isset( $_REQUEST['redirect'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect'] ) ) : Edit_Orders_For_WooCommerce_Admin_Page::url( 'activity' );

		try {
			$result = $order ? self::decide( $order, $decision, $message ) : new WP_Error( 'edit_orders_for_woocommerce_not_found', __( 'Order not found.', 'wpheka-edit-orders-for-woocommerce' ) );
		} catch ( Throwable $e ) {
			Edit_Orders_For_WooCommerce_Settlement_Executor::log_exception( 'answering the cancellation request on order ' . $order_id, $e, $order_id );
			throw $e;
		}
		$notice = is_wp_error( $result ) ? 'error' : $decision;
		if ( is_wp_error( $result ) ) {
			// Say why on the next screen, not just that it failed.
			set_transient( 'edit_orders_for_woocommerce_notice_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'eofw_notice', $notice, $redirect ) );
		exit;
	}

	/**
	 * The Activity tab: cancellation requests, then the activity list. Printed inside
	 * the page Edit_Orders_For_WooCommerce_Admin_Page draws.
	 */
	public static function render() {
		if ( ! current_user_can( Edit_Orders_For_WooCommerce_Admin::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters and a notice code.
		$notice = isset( $_GET['eofw_notice'] ) ? sanitize_key( wp_unslash( $_GET['eofw_notice'] ) ) : '';
		$filter = array(
			'action' => isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( $_GET['log_action'] ) ) : '',
			'from'   => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'     => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'page'   => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notices = array(
			'approve' => array( '', __( 'Cancellation approved. The order is cancelled and the customer has been emailed.', 'wpheka-edit-orders-for-woocommerce' ) ),
			'decline' => array( '', __( 'Cancellation declined. The customer has been emailed.', 'wpheka-edit-orders-for-woocommerce' ) ),
			'error'   => array( ' w-notice--warn', __( 'That request could not be handled. It may have been answered already.', 'wpheka-edit-orders-for-woocommerce' ) ),
		);
		$reason  = 'error' === $notice ? get_transient( 'edit_orders_for_woocommerce_notice_' . get_current_user_id() ) : false;
		if ( $reason ) {
			$notices['error'][1] = $reason;
			delete_transient( 'edit_orders_for_woocommerce_notice_' . get_current_user_id() );
		}

		echo '<div class="w-stack edit-orders-activity">';
		if ( isset( $notices[ $notice ] ) ) {
			echo '<div class="w-notice' . esc_attr( $notices[ $notice ][0] ) . '" role="status"><p>' . esc_html( $notices[ $notice ][1] ) . '</p></div>';
		}
		self::render_requests();
		self::render_log( $filter );
		echo '</div>';
	}

	/**
	 * Pending cancellation requests.
	 */
	private static function render_requests() {
		$orders = Edit_Orders_For_WooCommerce_Cancellation::pending_requests();

		echo '<section class="w-section" aria-labelledby="eo-requests-t"><div class="w-section-head"><h2 id="eo-requests-t">' . esc_html__( 'Cancellation requests', 'wpheka-edit-orders-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Customers asking to cancel, waiting for your decision.', 'wpheka-edit-orders-for-woocommerce' ) . '</p></div><div class="w-card">';

		if ( ! $orders ) {
			echo '<div class="w-empty"><h3>' . esc_html__( 'No requests waiting.', 'wpheka-edit-orders-for-woocommerce' ) . '</h3><p>' . esc_html__( 'When a customer asks to cancel, the request appears here and in the order.', 'wpheka-edit-orders-for-woocommerce' ) . '</p></div></div></section>';
			return;
		}

		echo '<div class="w-table-wrap"><table class="w-table edit-orders-requests"><thead><tr><th>' . esc_html__( 'Order', 'wpheka-edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Asked', 'wpheka-edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Reason', 'wpheka-edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Total', 'wpheka-edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Decision', 'wpheka-edit-orders-for-woocommerce' ) . '</th></tr></thead><tbody>';

		foreach ( $orders as $order ) {
			$request = (array) $order->get_meta( Edit_Orders_For_WooCommerce_Cancellation::REQUEST_META );
			$nonce   = self::DECISION_ACTION . '_' . $order->get_id();

			echo '<tr data-order="' . esc_attr( $order->get_id() ) . '">';
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> ' . esc_html( $order->get_formatted_billing_full_name() ) . ' <span class="w-pill w-pill--info w-pill--plain">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span></td>';
			echo '<td>' . esc_html( ! empty( $request['time'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $request['time'] ) : '' ) . '</td>';
			echo '<td class="w-wrap">' . esc_html( ! empty( $request['reason'] ) ? $request['reason'] : __( 'None given', 'wpheka-edit-orders-for-woocommerce' ) ) . '</td>';
			echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td><td>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="w-decision edit-orders-decision">';
			wp_nonce_field( $nonce );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::DECISION_ACTION ) . '" /><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';
			echo '<button type="submit" name="decision" value="approve" class="w-btn w-btn--primary w-btn--sm">' . esc_html__( 'Approve and refund', 'wpheka-edit-orders-for-woocommerce' ) . '</button>';
			echo '<input type="text" class="w-input" name="message" aria-label="' . esc_attr__( 'Message to the customer', 'wpheka-edit-orders-for-woocommerce' ) . '" placeholder="' . esc_attr__( 'Message to the customer (optional, sent when declining)', 'wpheka-edit-orders-for-woocommerce' ) . '" />';
			echo '<button type="submit" name="decision" value="decline" class="w-btn w-btn--secondary w-btn--sm">' . esc_html__( 'Decline', 'wpheka-edit-orders-for-woocommerce' ) . '</button>';
			echo '</form></td></tr>';
		}

		echo '</tbody></table></div></div></section>';
	}

	/**
	 * The activity list, with filters.
	 *
	 * @param array $filter action, from, to, page.
	 */
	private static function render_log( array $filter ) {
		$labels = array(
			'edit'                => __( 'Order edited', 'wpheka-edit-orders-for-woocommerce' ),
			'balance_created'     => __( 'Balance order created', 'wpheka-edit-orders-for-woocommerce' ),
			'balance_paid'        => __( 'Balance paid, changes applied', 'wpheka-edit-orders-for-woocommerce' ),
			'balance_paid_failed' => __( 'Balance paid, changes failed', 'wpheka-edit-orders-for-woocommerce' ),
			'cancel_requested'    => __( 'Cancellation requested', 'wpheka-edit-orders-for-woocommerce' ),
			'cancelled'           => __( 'Cancelled', 'wpheka-edit-orders-for-woocommerce' ),
			'cancel_declined'     => __( 'Cancellation declined', 'wpheka-edit-orders-for-woocommerce' ),
			'note_changed'        => __( 'Order note changed', 'wpheka-edit-orders-for-woocommerce' ),
			'window_closed'       => __( 'Customer confirmed the order', 'wpheka-edit-orders-for-woocommerce' ),
		);
		$pills  = array(
			'edit'                => 'info',
			'balance_created'     => 'info',
			'balance_paid'        => 'ok',
			'balance_paid_failed' => 'bad',
			'cancel_requested'    => 'warn',
			'cancelled'           => 'bad',
			'cancel_declined'     => 'warn',
			'note_changed'        => 'info',
			'window_closed'       => 'ok',
		);
		$actors = array(
			'staff'    => __( 'Store', 'wpheka-edit-orders-for-woocommerce' ),
			'customer' => __( 'Customer', 'wpheka-edit-orders-for-woocommerce' ),
			'guest'    => __( 'Guest', 'wpheka-edit-orders-for-woocommerce' ),
		);

		echo '<section class="w-section" aria-labelledby="eo-log-t"><div class="w-section-head"><h2 id="eo-log-t">' . esc_html__( 'Activity', 'wpheka-edit-orders-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Every change made with Edit Orders, by you or by customers.', 'wpheka-edit-orders-for-woocommerce' ) . '</p></div><div class="w-card">';
		echo '<form method="get" class="w-toolbar w-filters edit-orders-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="tab" value="activity" />';
		echo '<select class="w-select" name="log_action" aria-label="' . esc_attr__( 'Change', 'wpheka-edit-orders-for-woocommerce' ) . '"><option value="">' . esc_html__( 'All changes', 'wpheka-edit-orders-for-woocommerce' ) . '</option>';
		foreach ( $labels as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $filter['action'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><label>' . esc_html__( 'From', 'wpheka-edit-orders-for-woocommerce' ) . ' <input class="w-input" type="date" name="from" value="' . esc_attr( $filter['from'] ) . '" /></label>';
		echo '<label>' . esc_html__( 'To', 'wpheka-edit-orders-for-woocommerce' ) . ' <input class="w-input" type="date" name="to" value="' . esc_attr( $filter['to'] ) . '" /></label>';
		echo '<button type="submit" class="w-btn w-btn--secondary">' . esc_html__( 'Filter', 'wpheka-edit-orders-for-woocommerce' ) . '</button></form>';

		$result = Edit_Orders_For_WooCommerce_Audit_Log::query( $filter );
		if ( ! $result['rows'] ) {
			echo '<div class="w-empty"><h3>' . esc_html__( 'No changes yet.', 'wpheka-edit-orders-for-woocommerce' ) . '</h3><p>' . esc_html__( 'Edits, refunds, pay links and cancellations appear here as they happen.', 'wpheka-edit-orders-for-woocommerce' ) . '</p></div></div></section>';
			return;
		}

		echo '<div class="w-table-wrap"><table class="w-table edit-orders-log"><thead><tr><th>' . esc_html__( 'When', 'wpheka-edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Order', 'wpheka-edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'By', 'wpheka-edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Change', 'wpheka-edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Details', 'wpheka-edit-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Amount', 'wpheka-edit-orders-for-woocommerce' ) . '</th></tr></thead><tbody>';

		foreach ( $result['rows'] as $row ) {
			$order   = wc_get_order( (int) $row['order_id'] );
			$after   = json_decode( (string) $row['after_data'], true );
			$user    = $row['actor_id'] ? get_userdata( (int) $row['actor_id'] ) : null;
			$details = array();
			if ( ! empty( $after['changes'] ) ) {
				$details = (array) $after['changes'];
			} elseif ( ! empty( $after['reason'] ) ) {
				/* translators: %s: reason. */
				$details[] = sprintf( __( 'Reason: %s', 'wpheka-edit-orders-for-woocommerce' ), $after['reason'] );
			} elseif ( isset( $after['note'] ) ) {
				$details[] = $after['note'];
			} elseif ( ! empty( $after['message'] ) ) {
				$details[] = $after['message'];
			}

			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['created_at'] . ' UTC' ) ) ) . '</td>';
			echo '<td>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '#' . esc_html( $row['order_id'] ) ) . '</td>';
			echo '<td>' . esc_html( ( isset( $actors[ $row['actor_type'] ] ) ? $actors[ $row['actor_type'] ] : $row['actor_type'] ) . ( $user ? ' (' . $user->display_name . ')' : '' ) ) . '</td>';
			echo '<td><span class="w-pill w-pill--' . esc_attr( isset( $pills[ $row['action'] ] ) ? $pills[ $row['action'] ] : 'info' ) . '">' . esc_html( isset( $labels[ $row['action'] ] ) ? $labels[ $row['action'] ] : $row['action'] ) . '</span></td>';
			echo '<td class="w-wrap">' . esc_html( implode( ' ', $details ) ) . '</td>';
			echo '<td>' . ( (float) $row['amount'] ? wp_kses_post( wc_price( $row['amount'], array( 'currency' => $order ? $order->get_currency() : '' ) ) ) : '' ) . '</td></tr>';
		}

		echo '</tbody></table></div>';

		$pages = (int) ceil( $result['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<nav class="w-pages edit-orders-pages" aria-label="' . esc_attr__( 'Activity pages', 'wpheka-edit-orders-for-woocommerce' ) . '">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $filter['page'],
						'total'   => $pages,
					)
				)
			) . '</nav>';
		}
		echo '</div></section>';
	}
}
