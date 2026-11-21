<?php
/**
 * Clean uninstallation routine for DataDeck.
 *
 * @package DataDeck
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$datadeck_settings = get_option( 'datadeck_settings', [] );
$datadeck_keep     = is_array( $datadeck_settings ) && ! empty( $datadeck_settings['keep_data_on_uninstall'] );

if ( $datadeck_keep ) {
	return;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}datadeck_changes" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}datadeck_batches" );

delete_option( 'datadeck_db_version' );
delete_option( 'datadeck_settings' );
delete_option( 'datadeck_views' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( 'datadeck_' ) . '%'
	)
);
