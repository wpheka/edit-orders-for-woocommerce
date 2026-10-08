<?php
/**
 * Uninstall Edit Orders for WooCommerce.
 *
 * Data is kept unless "Delete data on uninstall" is checked (option
 * `edit_orders_for_woocommerce_delete_data`). Balance orders are real orders
 * and are never deleted.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'edit_orders_for_woocommerce_delete_data' ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes our own table and meta.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}edit_orders_for_woocommerce_log" );

// Order meta, in both order storages.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_edit_orders_for_woocommerce_' ) . '%' ) );
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE %s", $wpdb->esc_like( '_edit_orders_for_woocommerce_' ) . '%' ) );
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'edit_orders_for_woocommerce_' ) . '%' ) );

// Transients: one-time messages, rate limit counters, admin notices and the pending requests count.
foreach ( array( 'eofw_', 'edit_orders_for_woocommerce_' ) as $edit_orders_for_woocommerce_prefix ) {
	foreach ( array( '_transient_', '_transient_timeout_' ) as $edit_orders_for_woocommerce_kind ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $edit_orders_for_woocommerce_kind . $edit_orders_for_woocommerce_prefix ) . '%' ) );
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
