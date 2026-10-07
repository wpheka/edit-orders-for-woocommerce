<?php
/**
 * Main plugin class.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce Class.
 *
 * Singleton that loads the plugin's parts. The engine (spec section 7) is
 * added here milestone by milestone, starting with prototype P1.
 */
final class Edit_Orders_For_WooCommerce {

	/**
	 * The single instance of the class.
	 *
	 * @var Edit_Orders_For_WooCommerce|null
	 */
	private static $instance = null;

	/**
	 * Get the single instance.
	 *
	 * @return Edit_Orders_For_WooCommerce
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include required files.
	 *
	 * @return void
	 */
	private function includes() {
		$engine = EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/engine/';

		require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/class-edit-orders-for-woocommerce-settings.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-change-set.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-pricing.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-eligibility.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-address-change.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-settlement-plan.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-refunds.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-stock.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-balance-orders.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-settlement-executor.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-lock.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-audit-log.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-cancellation.php';
		require_once $engine . 'class-edit-orders-for-woocommerce-customer-rules.php';
		require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/emails/class-edit-orders-for-woocommerce-emails.php';
		require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/frontend/class-edit-orders-for-woocommerce-frontend.php';

		if ( is_admin() ) {
			require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/admin/class-edit-orders-for-woocommerce-admin.php';
			require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/admin/class-edit-orders-for-woocommerce-activity.php';
		}
	}

	/**
	 * Hook into actions and filters.
	 *
	 * @return void
	 */
	private function init_hooks() {
		Edit_Orders_For_WooCommerce_Balance_Orders::init();
		Edit_Orders_For_WooCommerce_Audit_Log::init();
		Edit_Orders_For_WooCommerce_Emails::init();
		Edit_Orders_For_WooCommerce_Frontend::init();

		if ( is_admin() ) {
			Edit_Orders_For_WooCommerce_Admin::init();
			Edit_Orders_For_WooCommerce_Activity::init();
		}
	}

	/**
	 * Sanitise a posted form on the way in: every value as a single line of text, except
	 * the fields customers write in several lines (their order note and cancel reason).
	 * Nested arrays (addresses, items) are cleaned the same way. Callers still validate
	 * each field for its meaning (IDs, amounts, emails).
	 *
	 * @param array $data Unslashed form data.
	 * @return array
	 */
	public static function sanitize_request( array $data ) {
		$clean = array();
		foreach ( $data as $key => $value ) {
			$key = is_int( $key ) ? $key : sanitize_text_field( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_request( $value );
			} elseif ( in_array( $key, array( 'customer_note', 'reason_other' ), true ) ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $value );
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $clean;
	}
}
