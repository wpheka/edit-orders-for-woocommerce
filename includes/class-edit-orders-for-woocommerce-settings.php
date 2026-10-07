<?php
/**
 * Plugin settings with defaults (spec section 6). The settings screen arrives in M3.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Settings Class.
 *
 * Defaults are conservative, so a fresh install cannot move money a store owner
 * didn't expect: cancellations wait for approval and the window is one hour.
 */
class Edit_Orders_For_WooCommerce_Settings {

	/**
	 * Option holding the settings.
	 */
	const OPTION = 'edit_orders_for_woocommerce_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'admin_enabled'          => 'yes',
			'editable_statuses'      => array( 'processing', 'on-hold' ),
			'customer_enabled'       => 'yes',
			'window_minutes'         => 60,
			'action_cancel'          => 'yes',
			'action_address'         => 'yes',
			'action_note'            => 'yes',
			'action_swap'            => 'yes',
			'cancel_mode'            => 'approval',
			'cancel_reasons'         => implode(
				"\n",
				array(
					__( 'Ordered by mistake', 'edit-orders-for-woocommerce' ),
					__( 'Found a better price', 'edit-orders-for-woocommerce' ),
					__( 'Delivery takes too long', 'edit-orders-for-woocommerce' ),
					__( 'Need to change the order', 'edit-orders-for-woocommerce' ),
				)
			),
			'cancel_reason_required' => 'no',
			'cancel_policy'          => '',
			'note_max_length'        => 500,
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );

		/**
		 * Filter a setting.
		 *
		 * @since 0.1.0
		 *
		 * @param mixed  $value Value.
		 * @param string $key   Setting key.
		 */
		return apply_filters( 'edit_orders_for_woocommerce_setting', isset( $settings[ $key ] ) ? $settings[ $key ] : null, $key );
	}

	/**
	 * Is a yes/no setting on?
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * The edit window in seconds, clamped to 5 minutes to 7 days.
	 *
	 * @return int
	 */
	public static function window_seconds() {
		$minutes = (int) self::get( 'window_minutes' );

		return min( 7 * DAY_IN_SECONDS, max( 5 * MINUTE_IN_SECONDS, $minutes * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Cancel reasons, one per line. The panel always adds "Other" after them.
	 *
	 * @return string[]
	 */
	public static function cancel_reasons() {
		$reasons = array_filter( array_map( 'trim', explode( "\n", (string) self::get( 'cancel_reasons' ) ) ) );

		return array_values( $reasons );
	}
}
