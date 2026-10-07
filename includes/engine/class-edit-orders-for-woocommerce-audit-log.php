<?php
/**
 * Audit log of every change (spec section 5.1).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Audit_Log Class.
 *
 * One row per applied change set, balance order and cancellation: who, when,
 * what changed, what money moved and the linked refund or balance order.
 */
class Edit_Orders_For_WooCommerce_Audit_Log {

	/**
	 * Schema version, bumped when the table changes.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'edit_orders_for_woocommerce_db_version';

	/**
	 * Item snapshots taken before a change set is applied, keyed by order ID.
	 *
	 * @var array[]
	 */
	private static $before = array();

	/**
	 * Table name with the site prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'edit_orders_for_woocommerce_log';
	}

	/**
	 * Hook in.
	 */
	public static function init() {
		// On init, not admin_init: customers log changes too, and a site the plugin
		// reached without its activation hook (network activation, file copy) must
		// get the table before the first front-end change.
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'edit_orders_for_woocommerce_before_apply', array( __CLASS__, 'remember_before' ) );
		add_action( 'edit_orders_for_woocommerce_after_apply', array( __CLASS__, 'log_apply' ), 10, 3 );
		add_action( 'edit_orders_for_woocommerce_balance_paid', array( __CLASS__, 'log_balance_paid' ), 10, 3 );
	}

	/**
	 * Create or upgrade the table when the schema version changes.
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				actor_type varchar(20) NOT NULL,
				actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(40) NOT NULL,
				before_data longtext NULL,
				after_data longtext NULL,
				refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
				balance_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(19,4) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY created_at (created_at)
			) {$collate};"
		);

		// Autoloaded: maybe_install() reads it on every request.
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Add a row.
	 *
	 * @param array $row order_id, actor_type, actor_id, action, before, after, refund_id, balance_order_id, amount.
	 * @return int Row ID, or 0 on failure.
	 */
	public static function add( array $row ) {
		global $wpdb;

		$row = wp_parse_args(
			$row,
			array(
				'order_id'         => 0,
				'actor_type'       => 'staff',
				'actor_id'         => get_current_user_id(),
				'action'           => 'edit',
				'before'           => array(),
				'after'            => array(),
				'refund_id'        => 0,
				'balance_order_id' => 0,
				'amount'           => 0,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our own table; nothing to cache on insert.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'order_id'         => absint( $row['order_id'] ),
				'actor_type'       => sanitize_key( $row['actor_type'] ),
				'actor_id'         => absint( $row['actor_id'] ),
				'action'           => sanitize_key( $row['action'] ),
				'before_data'      => wp_json_encode( $row['before'] ),
				'after_data'       => wp_json_encode( $row['after'] ),
				'refund_id'        => absint( $row['refund_id'] ),
				'balance_order_id' => absint( $row['balance_order_id'] ),
				'amount'           => wc_format_decimal( $row['amount'], 4 ),
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Rows for an order, newest first.
	 *
	 * @param int $order_id Order ID.
	 * @return array[]
	 */
	public static function for_order( $order_id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table name; the value is prepared.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC", $order_id ), ARRAY_A );
	}

	/**
	 * Rows for the activity list, newest first, with optional filters.
	 *
	 * @param array $args action, from (Y-m-d), to (Y-m-d), per_page, page.
	 * @return array { rows, total }
	 */
	public static function query( array $args ) {
		global $wpdb;

		$args  = wp_parse_args(
			$args,
			array(
				'action'   => '',
				'from'     => '',
				'to'       => '',
				'per_page' => 50,
				'page'     => 1,
			)
		);
		$table = self::table();
		$where = array( '1=1' );
		$vals  = array();

		if ( '' !== $args['action'] ) {
			$where[] = 'action = %s';
			$vals[]  = sanitize_key( $args['action'] );
		}
		// The filter dates are the site's local days; rows are stored in UTC.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['from'] ) ) {
			$where[] = 'created_at >= %s';
			$vals[]  = get_gmt_from_date( $args['from'] . ' 00:00:00' );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['to'] ) ) {
			$where[] = 'created_at <= %s';
			$vals[]  = get_gmt_from_date( $args['to'] . ' 23:59:59' );
		}

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$sql      = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; the WHERE clause is built from placeholders whose values are in $vals.
		$total = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql}", $vals ) : "SELECT COUNT(*) FROM {$table} WHERE {$sql}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Remember the order's items before a change set is applied.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function remember_before( $order ) {
		self::$before[ $order->get_id() ] = self::snapshot( $order );
	}

	/**
	 * Log an applied change set or a balance order created for one.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result Executor result.
	 * @param string   $actor  Actor.
	 */
	public static function log_apply( $order, $result, $actor ) {
		$plan = $result['plan'];

		self::add(
			array(
				'order_id'         => $order->get_id(),
				'actor_type'       => $actor,
				'action'           => 'applied' === $result['status'] ? 'edit' : 'balance_created',
				'before'           => array( 'items' => isset( self::$before[ $order->get_id() ] ) ? self::$before[ $order->get_id() ] : array() ),
				'after'            => array(
					'changes'     => $plan->get_descriptions(),
					'refund'      => $plan->get_refund_amount(),
					'balance_due' => isset( $result['balance_order_id'] ) ? wc_get_order( $result['balance_order_id'] )->get_total() : 0,
				),
				'refund_id'        => ! empty( $result['refund']['refund_id'] ) ? $result['refund']['refund_id'] : 0,
				'balance_order_id' => isset( $result['balance_order_id'] ) ? $result['balance_order_id'] : 0,
				'amount'           => isset( $result['balance_order_id'] ) ? wc_get_order( $result['balance_order_id'] )->get_total() : $plan->get_refund_amount(),
			)
		);
	}

	/**
	 * Log a paid balance order and the changes it applied.
	 *
	 * @param WC_Order       $balance Balance order.
	 * @param WC_Order       $order   Original order.
	 * @param array|WP_Error $result  Executor result.
	 */
	public static function log_balance_paid( $balance, $order, $result ) {
		// A linked pay-on-delivery order is applied unpaid, inside the edit that
		// log_apply() already records; only log it here if it went wrong.
		if ( ! $balance->get_date_paid() && ! is_wp_error( $result ) ) {
			return;
		}

		self::add(
			array(
				'order_id'         => $order->get_id(),
				'actor_type'       => 'customer',
				'actor_id'         => $balance->get_customer_id(),
				'action'           => is_wp_error( $result ) ? 'balance_paid_failed' : 'balance_paid',
				'after'            => is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'changes' => $result['plan']->get_descriptions() ),
				'refund_id'        => ( ! is_wp_error( $result ) && ! empty( $result['refund']['refund_id'] ) ) ? $result['refund']['refund_id'] : 0,
				'balance_order_id' => $balance->get_id(),
				'amount'           => $balance->get_total(),
			)
		);
	}

	/**
	 * Item names, variations and quantities, for the "before" record.
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	private static function snapshot( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$items[] = array(
				'item_id'      => $item_id,
				'name'         => $item->get_name(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => $item->get_quantity(),
				'total'        => $item->get_total(),
			);
		}

		return $items;
	}
}
