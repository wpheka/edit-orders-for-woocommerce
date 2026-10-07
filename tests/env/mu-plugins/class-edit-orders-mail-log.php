<?php
/**
 * Plugin Name: Edit Orders Mail Log
 * Description: Development-only. The wp-env containers have no mail server, so every email is stored in the option `edit_orders_mail_log` instead of being sent, for the functional suites to read. Never shipped.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores outgoing mail instead of sending it.
 */
class Edit_Orders_Mail_Log {

	/**
	 * Option holding the log.
	 */
	const OPTION = 'edit_orders_mail_log';

	/**
	 * Keep at most this many messages.
	 */
	const LIMIT = 200;

	/**
	 * Store a message and report it as sent.
	 *
	 * @param null|bool $short_circuit Earlier filter result.
	 * @param array     $atts          wp_mail() arguments.
	 * @return bool
	 */
	public static function capture( $short_circuit, $atts ) {
		$log   = get_option( self::OPTION, array() );
		$log[] = array(
			'to'      => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'],
			'subject' => (string) $atts['subject'],
			'message' => (string) $atts['message'],
			'time'    => time(),
		);
		update_option( self::OPTION, array_slice( $log, -self::LIMIT ), false );

		return true;
	}
}

add_filter( 'pre_wp_mail', array( 'Edit_Orders_Mail_Log', 'capture' ), 5, 2 );
