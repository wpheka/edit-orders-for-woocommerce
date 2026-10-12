<?php
/**
 * The "Edit Orders" section in WooCommerce > Status > System Status (logging spec section 7).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_System_Status Class.
 *
 * Comes with the system report customers already know how to copy for support. It shows
 * settings and counts only: no order details and no customer data.
 */
class Edit_Orders_For_WooCommerce_System_Status {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'woocommerce_system_status_report', array( __CLASS__, 'render' ) );
	}

	/**
	 * The rows: label => value. Values are plain text.
	 *
	 * @return array[] Each { label, value, warning }.
	 */
	public static function rows() {
		$rows = array();
		$add  = function ( $label, $value, $warning = false ) use ( &$rows ) {
			$rows[] = array(
				'label'   => $label,
				'value'   => (string) $value,
				'warning' => (bool) $warning,
			);
		};
		$yes  = function ( $on ) {
			return $on ? __( 'Yes', 'wpheka-edit-orders-for-woocommerce' ) : __( 'No', 'wpheka-edit-orders-for-woocommerce' );
		};

		$add( __( 'Version', 'wpheka-edit-orders-for-woocommerce' ), EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION );
		$add( __( 'Debug log', 'wpheka-edit-orders-for-woocommerce' ), $yes( Edit_Orders_For_WooCommerce_Log::debug_enabled() ) );

		foreach ( array_keys( Edit_Orders_For_WooCommerce_Settings::defaults() ) as $key ) {
			$value = Edit_Orders_For_WooCommerce_Settings::get( $key );
			if ( 'cancel_reasons' === $key ) {
				$value = count( Edit_Orders_For_WooCommerce_Settings::cancel_reasons() ) . ' ' . __( 'reasons', 'wpheka-edit-orders-for-woocommerce' );
			} elseif ( 'cancel_policy' === $key ) {
				$value = '' !== trim( (string) $value ) ? __( 'Set', 'wpheka-edit-orders-for-woocommerce' ) : __( 'Empty', 'wpheka-edit-orders-for-woocommerce' );
			} elseif ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$add( 'Setting: ' . $key, $value );
		}
		$add( 'Setting: delete_data', get_option( 'edit_orders_for_woocommerce_delete_data', 'no' ) );

		$add( __( 'Tax enabled', 'wpheka-edit-orders-for-woocommerce' ), $yes( wc_tax_enabled() ) );
		$add( __( 'Prices entered with tax', 'wpheka-edit-orders-for-woocommerce' ), $yes( wc_prices_include_tax() ) );
		$add( __( 'Round tax at subtotal', 'wpheka-edit-orders-for-woocommerce' ), $yes( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ) );
		$add( __( 'Tax based on', 'wpheka-edit-orders-for-woocommerce' ), get_option( 'woocommerce_tax_based_on', 'shipping' ) );

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$add( __( 'HPOS', 'wpheka-edit-orders-for-woocommerce' ), $yes( $hpos ) );
		$add( __( 'HPOS compatibility mode', 'wpheka-edit-orders-for-woocommerce' ), $yes( 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) ) );

		$gateways = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( 'yes' === $gateway->enabled ) {
				$gateways[] = $gateway->id . ' (' . ( $gateway->supports( 'refunds' ) ? __( 'refunds automatically', 'wpheka-edit-orders-for-woocommerce' ) : __( 'manual refunds', 'wpheka-edit-orders-for-woocommerce' ) ) . ')';
			}
		}
		$add( __( 'Payment gateways', 'wpheka-edit-orders-for-woocommerce' ), $gateways ? implode( ', ', $gateways ) : __( 'None enabled', 'wpheka-edit-orders-for-woocommerce' ), ! $gateways );

		$tracking = self::tracking_plugins();
		$add( __( 'Shipment tracking', 'wpheka-edit-orders-for-woocommerce' ), $tracking ? implode( ', ', $tracking ) : __( 'None detected', 'wpheka-edit-orders-for-woocommerce' ) );

		$add( __( 'Open balance orders', 'wpheka-edit-orders-for-woocommerce' ), self::count_open_balance_orders() );
		$stuck = self::count_with_meta( Edit_Orders_For_WooCommerce_Balance_Orders::STUCK_META );
		$add( __( 'Balance orders marked stuck', 'wpheka-edit-orders-for-woocommerce' ), $stuck, $stuck > 0 );
		// The flag stays after the store owner sends the money, so this counts every order ever flagged.
		$add( __( 'Orders ever flagged for a manual refund', 'wpheka-edit-orders-for-woocommerce' ), self::count_with_meta( Edit_Orders_For_WooCommerce_Refunds::MANUAL_REFUND_META ) );

		return $rows;
	}

	/**
	 * Shipment tracking plugins that are active, by folder name.
	 *
	 * @return string[]
	 */
	private static function tracking_plugins() {
		$known  = array(
			'woocommerce-shipment-tracking'  => 'WooCommerce Shipment Tracking',
			'woo-advanced-shipment-tracking' => 'Advanced Shipment Tracking',
			'aftership-woocommerce-tracking' => 'AfterShip',
		);
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$found = array();
		foreach ( $active as $plugin ) {
			$folder = strtok( (string) $plugin, '/' );
			if ( isset( $known[ $folder ] ) ) {
				$found[] = $known[ $folder ];
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Balance orders still waiting for payment.
	 *
	 * @return int
	 */
	private static function count_open_balance_orders() {
		// A count from the database, without loading the orders. Balance orders are the only
		// orders with this created_via, and both order storages support the argument.
		$found = wc_get_orders(
			array(
				'type'        => 'shop_order',
				'status'      => Edit_Orders_For_WooCommerce_Balance_Orders::OPEN_STATUSES,
				'created_via' => Edit_Orders_For_WooCommerce_Balance_Orders::CREATED_VIA,
				'limit'       => 1,
				'paginate'    => true,
				'return'      => 'ids',
			)
		);

		return is_object( $found ) && isset( $found->total ) ? (int) $found->total : 0;
	}

	/**
	 * Orders with a meta key set.
	 *
	 * @param string $meta_key Meta key.
	 * @return int
	 */
	private static function count_with_meta( $meta_key ) {
		// meta_key works with both order storages (see the cancellation requests list).
		$found = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'status'   => array_keys( wc_get_order_statuses() ),
				'meta_key' => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the system status page, run on demand.
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			)
		);

		return is_object( $found ) && isset( $found->total ) ? (int) $found->total : 0;
	}

	/**
	 * Print the section, in the same table format as WooCommerce's own sections so it is
	 * included when the report is copied.
	 */
	public static function render() {
		?>
		<table class="wc_status_table widefat" cellspacing="0" id="edit-orders-for-woocommerce-status">
			<thead>
				<tr>
					<th colspan="3" data-export-label="Edit Orders"><h2><?php esc_html_e( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' ); ?></h2></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( self::rows() as $row ) : ?>
					<tr>
						<td data-export-label="<?php echo esc_attr( $row['label'] ); ?>"><?php echo esc_html( $row['label'] ); ?>:</td>
						<td class="help">&nbsp;</td>
						<td><?php echo $row['warning'] ? '<mark class="error">' . esc_html( $row['value'] ) . '</mark>' : esc_html( $row['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
