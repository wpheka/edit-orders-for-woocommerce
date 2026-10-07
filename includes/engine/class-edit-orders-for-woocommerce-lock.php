<?php
/**
 * Edit lock: one store owner edits an order at a time (spec section 2.3).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Lock Class.
 *
 * Opening the editor takes the lock for 15 minutes and every preview renews
 * it. A second admin sees who holds it and can't apply changes until it is
 * released or expires.
 */
class Edit_Orders_For_WooCommerce_Lock {

	/**
	 * Order meta: array( 'user' => ID, 'expires' => timestamp ).
	 */
	const META = '_edit_orders_for_woocommerce_lock';

	/**
	 * Lock length in seconds.
	 */
	const TTL = 900;

	/**
	 * Take or renew the lock.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $user_id User taking it.
	 * @return true|int True when held by this user, otherwise the ID of the user holding it.
	 */
	public static function acquire( WC_Order $order, $user_id ) {
		$holder = self::holder( $order );
		if ( $holder && $holder !== (int) $user_id ) {
			return $holder;
		}

		$order->update_meta_data(
			self::META,
			array(
				'user'    => (int) $user_id,
				'expires' => time() + self::TTL,
			)
		);
		$order->save_meta_data();

		return true;
	}

	/**
	 * Release the lock if this user holds it.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $user_id User releasing it.
	 */
	public static function release( WC_Order $order, $user_id ) {
		if ( self::holder( $order ) === (int) $user_id ) {
			$order->delete_meta_data( self::META );
			$order->save_meta_data();
		}
	}

	/**
	 * The user holding a live lock, or 0.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function holder( WC_Order $order ) {
		$lock = $order->get_meta( self::META );

		if ( ! is_array( $lock ) || empty( $lock['user'] ) || empty( $lock['expires'] ) || (int) $lock['expires'] < time() ) {
			return 0;
		}

		return (int) $lock['user'];
	}

	/**
	 * Is the order locked by someone other than this user?
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $user_id User asking.
	 * @return int The other user's ID, or 0.
	 */
	public static function held_by_other( WC_Order $order, $user_id ) {
		$holder = self::holder( $order );

		return ( $holder && $holder !== (int) $user_id ) ? $holder : 0;
	}

	/**
	 * Claim a short-lived, atomic guard around work that moves money, such as
	 * applying a change or cancelling. A second request for the same key, such
	 * as a double click or a payment webhook racing the return page, is refused
	 * until the first one finishes.
	 *
	 * Uses a row in the options table: the INSERT either creates it or fails on
	 * the unique option name, so two requests can't both get it.
	 *
	 * @param string $key Key, such as apply_123.
	 * @return bool True when claimed.
	 */
	public static function claim( $key ) {
		global $wpdb;

		$name = 'edit_orders_for_woocommerce_guard_' . sanitize_key( $key );

		for ( $try = 0; $try < 2; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the options cache to be atomic.
			$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
			if ( 1 === (int) $claimed ) {
				return true;
			}

			// A guard left behind by a request that died: older than a minute is stale.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( $since && $since > time() - MINUTE_IN_SECONDS ) {
				return false;
			}
			self::unclaim( $key );
		}

		return false;
	}

	/**
	 * Release a guard taken with claim().
	 *
	 * @param string $key Key.
	 */
	public static function unclaim( $key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as in claim().
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'edit_orders_for_woocommerce_guard_' . sanitize_key( $key ) ) );
	}
}
