<?php
/**
 * Clean uninstallation routine for RowCraft.
 *
 * @package RowCraft
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$rowcraft_settings = get_option( 'rowcraft_settings', [] );
$rowcraft_keep     = is_array( $rowcraft_settings ) && ! empty( $rowcraft_settings['keep_data_on_uninstall'] );

if ( $rowcraft_keep ) {
	return;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rowcraft_changes" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rowcraft_batches" );

delete_option( 'rowcraft_db_version' );
delete_option( 'rowcraft_settings' );
delete_option( 'rowcraft_views' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( 'rowcraft_' ) . '%'
	)
);
