<?php
/**
 * Debug log in WooCommerce > Status > Logs (logging spec sections 2 to 5 and 8).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Log Class.
 *
 * One readable line per step, written to WooCommerce's own logger under one
 * source, so a store owner can download it and share it with support. Nothing
 * leaves the site.
 *
 * error, warning and notice are always written. info and debug are written only
 * when the "Debug log" setting is on (or the edit_orders_for_woocommerce_debug_log
 * filter turns it on), and return before building anything when it is off.
 *
 * Each preview, change and cancellation runs under a short run ID. It is stored on
 * a balance order and on a cancellation request, so the steps that follow in later
 * requests (payment, approval) carry the same ID.
 *
 * Call sites pass only IDs, amounts, codes and country and state codes. As a safety
 * net, context keys that name personal data are dropped, and the order's key and the
 * customer's details are removed from any text before it is written.
 */
class Edit_Orders_For_WooCommerce_Log {

	/**
	 * The log source shown in WooCommerce > Status > Logs.
	 */
	const SOURCE = 'wpheka-edit-orders-for-woocommerce';

	/**
	 * Order meta: the run ID of the change a balance order or cancellation request belongs to.
	 */
	const RUN_META = '_edit_orders_for_woocommerce_run_id';

	/**
	 * Longest text kept from outside the plugin, such as a gateway error.
	 */
	const MAX_TEXT = 300;

	/**
	 * Context keys that are never written. A key is dropped when it is one of these, or
	 * ends with one joined by an underscore (billing_email, first_name, order_key).
	 *
	 * @var string[]
	 */
	private static $private_keys = array( 'key', 'email', 'name', 'phone', 'address_1', 'address_2', 'city', 'postcode', 'ip', 'ip_address', 'note', 'reason', 'message', 'company', 'password', 'token' );

	/**
	 * The current run ID, or empty outside a run.
	 *
	 * @var string
	 */
	private static $run_id = '';

	/**
	 * Personal values per order ID, removed from any text that mentions that order.
	 *
	 * @var array[]
	 */
	private static $secrets = array();

	/**
	 * Is debug logging on?
	 *
	 * @return bool
	 */
	public static function debug_enabled() {
		/**
		 * Turn debug logging (info and debug entries) on or off in code.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $enabled The "Debug log" setting.
		 */
		return (bool) apply_filters( 'edit_orders_for_woocommerce_debug_log', Edit_Orders_For_WooCommerce_Settings::is_on( 'debug_log' ) );
	}

	/**
	 * Start a run, or continue one started earlier (a preview's run, sent back with the change).
	 *
	 * @param string $resume A run ID to continue. Anything that isn't one starts a new run.
	 * @return string The run ID.
	 */
	public static function start_run( $resume = '' ) {
		$resume = is_string( $resume ) ? strtolower( $resume ) : '';
		if ( self::is_run_id( $resume ) ) {
			self::$run_id = $resume;
		} else {
			try {
				self::$run_id = bin2hex( random_bytes( 4 ) );
			} catch ( Throwable $e ) {
				// No source of randomness: a run ID only has to tell runs apart.
				self::$run_id = substr( md5( uniqid( '', true ) ), 0, 8 );
			}
		}

		return self::$run_id;
	}

	/**
	 * The current run ID.
	 *
	 * @return string Empty outside a run.
	 */
	public static function run_id() {
		return self::$run_id;
	}

	/**
	 * Switch to another run for a while, such as a balance order's when it is paid.
	 * Pass the returned value to restore_run() afterwards.
	 *
	 * @param string $run_id Run ID; an invalid one starts a new run.
	 * @return string The run that was current before.
	 */
	public static function switch_run( $run_id ) {
		$before = self::$run_id;
		self::start_run( (string) $run_id );

		return $before;
	}

	/**
	 * Go back to the run that was current before switch_run().
	 *
	 * @param string $run_id Run ID returned by switch_run().
	 */
	public static function restore_run( $run_id ) {
		self::$run_id = self::is_run_id( $run_id ) ? $run_id : '';
	}

	/**
	 * End the current run.
	 */
	public static function end_run() {
		self::$run_id = '';
	}

	/**
	 * Is this a run ID (8 hex characters)?
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_run_id( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}$/', $value );
	}

	/**
	 * Something failed.
	 *
	 * @param string $message Message.
	 * @param array  $context Details.
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Something needs a person, or didn't go as planned.
	 *
	 * @param string $message Message.
	 * @param array  $context Details.
	 */
	public static function warning( $message, array $context = array() ) {
		self::write( 'warning', $message, $context );
	}

	/**
	 * A refusal that blocked a change.
	 *
	 * @param string $message Message.
	 * @param array  $context Details.
	 */
	public static function notice( $message, array $context = array() ) {
		self::write( 'notice', $message, $context );
	}

	/**
	 * A main step. Written only with debug logging on.
	 *
	 * @param string $message Message.
	 * @param array  $context Details.
	 */
	public static function info( $message, array $context = array() ) {
		if ( ! self::debug_enabled() ) {
			return;
		}
		self::write( 'info', $message, $context );
	}

	/**
	 * A detail. Written only with debug logging on.
	 *
	 * @param string $message Message.
	 * @param array  $context Details.
	 */
	public static function debug( $message, array $context = array() ) {
		if ( ! self::debug_enabled() ) {
			return;
		}
		self::write( 'debug', $message, $context );
	}

	/**
	 * Text from outside the plugin (a gateway error), as a single line of at most 300 characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clip( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );
	}

	/**
	 * An amount with its currency code, such as "22.60 CAD".
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function money( $amount, $currency ) {
		return wc_format_decimal( $amount, wc_get_price_decimals() ) . ' ' . $currency;
	}

	/**
	 * A refusal's message for the log. The "someone is editing this order" message names the
	 * staff member holding the lock, so it is replaced; other messages are the plugin's own.
	 *
	 * @param WP_Error $error Refusal.
	 * @return string
	 */
	public static function reason( WP_Error $error ) {
		if ( 'edit_orders_for_woocommerce_locked' === $error->get_error_code() ) {
			return 'another staff member is editing this order';
		}

		return self::clip( $error->get_error_message() );
	}

	/**
	 * Who made a change, as the log names them: "staff #12", "customer" or "guest".
	 *
	 * @param string $actor staff, customer or guest.
	 * @return string
	 */
	public static function actor( $actor ) {
		if ( 'staff' === $actor ) {
			return 'staff #' . get_current_user_id();
		}

		return in_array( $actor, array( 'customer', 'guest' ), true ) ? $actor : 'system';
	}

	/**
	 * Write one entry.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Details.
	 */
	private static function write( $level, $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		// A log handler that fails (a full disk, a remote logging plugin) must never stop the
		// refund or change being recorded: logging is best effort.
		try {
			self::write_entry( $level, $message, $context );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Write one entry: redact, scrub, add the run ID and hand it to WooCommerce.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Details.
	 */
	private static function write_entry( $level, $message, array $context ) {
		// Pro logs through this class with 'source' => 'pro': the entry stays in this log.
		$prefix = '';
		if ( isset( $context['source'] ) ) {
			$component            = sanitize_key( (string) $context['source'] );
			$context['component'] = $component;
			$prefix               = '[' . $component . '] ';
			unset( $context['source'] );
		}

		$context = self::redact( $context );
		$secrets = self::secrets_for( $context );
		$message = self::scrub( $prefix . (string) $message, $secrets );
		$context = self::scrub_values( $context, $secrets );

		if ( '' !== self::$run_id ) {
			$message        = '[run ' . self::$run_id . '] ' . $message;
			$context['run'] = self::$run_id;
		}
		$context['source'] = self::SOURCE;

		wc_get_logger()->log( $level, $message, $context );
	}

	/**
	 * Drop context keys that name personal data, at any depth.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	public static function redact( array $context ) {
		$clean = array();
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && self::is_private_key( $key ) ) {
				continue;
			}
			$clean[ $key ] = is_array( $value ) ? self::redact( $value ) : $value;
		}

		return $clean;
	}

	/**
	 * Does this key name personal data?
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_private_key( $key ) {
		$key = strtolower( $key );
		foreach ( self::$private_keys as $private ) {
			if ( $key === $private || substr( $key, -1 * ( strlen( $private ) + 1 ) ) === '_' . $private ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The personal values of the orders an entry is about (the order, the balance order).
	 *
	 * @param array $context Context.
	 * @return string[]
	 */
	private static function secrets_for( array $context ) {
		$secrets = array();
		foreach ( array( 'order', 'balance' ) as $field ) {
			if ( empty( $context[ $field ] ) || ! is_numeric( $context[ $field ] ) ) {
				continue;
			}
			$order_id = absint( $context[ $field ] );
			if ( ! isset( self::$secrets[ $order_id ] ) ) {
				self::$secrets[ $order_id ] = self::order_secrets( $order_id );
			}
			$secrets = array_merge( $secrets, self::$secrets[ $order_id ] );
		}

		return array_values( array_unique( $secrets ) );
	}

	/**
	 * An order's key and its customer's details.
	 *
	 * @param int $order_id Order ID.
	 * @return string[]
	 */
	private static function order_secrets( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		// Values that can't be ordinary words in a log line. A first name, last name, company
		// or city alone could be one ("Will", "Grant", "Reading"), so only the full name counts.
		$values   = array( $order->get_order_key(), $order->get_customer_ip_address() );
		$customer = $order->get_customer_id() ? get_userdata( $order->get_customer_id() ) : null;
		if ( $customer ) {
			$values[] = $customer->user_email;
		}
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$address  = $order->get_address( $type );
			$values[] = trim( ( isset( $address['first_name'] ) ? $address['first_name'] : '' ) . ' ' . ( isset( $address['last_name'] ) ? $address['last_name'] : '' ) );
			foreach ( array( 'address_1', 'address_2', 'postcode', 'email', 'phone' ) as $field ) {
				if ( isset( $address[ $field ] ) ) {
					$values[] = $address[ $field ];
				}
			}
		}

		$secrets = array();
		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			// Short values and short numbers would match ordinary text (order IDs, amounts).
			if ( strlen( $value ) >= 4 && ( ! preg_match( '/^[\d\s.+()-]+$/', $value ) || strlen( preg_replace( '/\D/', '', $value ) ) >= 7 ) ) {
				$secrets[] = $value;
			}
		}

		return $secrets;
	}

	/**
	 * Remove secrets from a text.
	 *
	 * @param string   $text    Text.
	 * @param string[] $secrets Values to remove.
	 * @return string
	 */
	private static function scrub( $text, array $secrets ) {
		foreach ( $secrets as $secret ) {
			$clean = preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $secret, '/' ) . '(?![\p{L}\p{N}])/iu', '[redacted]', $text );
			// Not valid UTF-8 (text from a gateway): replace it anywhere rather than lose the text.
			$text = null === $clean ? str_ireplace( $secret, '[redacted]', $text ) : $clean;
		}

		return $text;
	}

	/**
	 * Remove secrets from every text in a context.
	 *
	 * @param array    $context Context.
	 * @param string[] $secrets Values to remove.
	 * @return array
	 */
	private static function scrub_values( array $context, array $secrets ) {
		if ( ! $secrets ) {
			return $context;
		}
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$context[ $key ] = self::scrub_values( $value, $secrets );
			} elseif ( is_string( $value ) ) {
				$context[ $key ] = self::scrub( $value, $secrets );
			}
		}

		return $context;
	}

	/**
	 * Forget the personal values read for an order, after its details changed (an address change).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function forget_order( $order_id ) {
		unset( self::$secrets[ absint( $order_id ) ] );
	}
}
