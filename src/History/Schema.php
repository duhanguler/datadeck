<?php
/**
 * Database schema manager for DataDeck audit history, rollback snapshots, and scheduled campaigns.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\History;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {

	/**
	 * Install or upgrade custom tables using dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$batches_table   = self::batches_table();
		$changes_table   = self::changes_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_batches = "CREATE TABLE {$batches_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_uuid char(36) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entity_type varchar(64) NOT NULL,
			operation_type varchar(32) NOT NULL DEFAULT 'inline',
			summary varchar(255) NOT NULL DEFAULT '',
			affected_count int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'applied',
			schedule_payload longtext NULL,
			scheduled_at datetime NULL,
			revert_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_uuid (batch_uuid),
			KEY entity_status (entity_type, status),
			KEY created_at (created_at),
			KEY schedule_lookup (status, scheduled_at, revert_at)
		) {$charset_collate};";

		$sql_changes = "CREATE TABLE {$changes_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			field_key varchar(191) NOT NULL,
			old_value longtext NULL,
			new_value longtext NULL,
			PRIMARY KEY  (id),
			KEY batch_object (batch_id, object_id),
			KEY object_field (object_id, field_key(64))
		) {$charset_collate};";

		dbDelta( $sql_batches );
		dbDelta( $sql_changes );

		update_option( 'datadeck_db_version', DATADECK_DB_VERSION, false );
	}

	/**
	 * Ensure tables exist when plugin is updated without reactivation.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'datadeck_db_version' ) !== DATADECK_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Batches table name.
	 */
	public static function batches_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'datadeck_batches';
	}

	/**
	 * Changes table name.
	 */
	public static function changes_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'datadeck_changes';
	}
}
