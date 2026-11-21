<?php
/**
 * Audit History & One-Click Rollback Service.
 *
 * Records minimal scalar/JSON delta snapshots per mutation batch and restores
 * prior values cleanly while recording an auditable rollback batch.
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\History;

use RowCraft\Entities\EntityRegistry;
use RowCraft\Fields\FieldRegistry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HistoryService {

	private const DEFAULT_RETENTION_DAYS = 30;

	public function __construct(
		private readonly EntityRegistry $entities,
		private readonly FieldRegistry $fields,
	) {}

	/**
	 * Begin or append to a batch header row and return its numeric batch ID & UUID.
	 *
	 * @param string               $entity_type      Entity slug.
	 * @param string               $operation_type   Operation kind ('inline', 'bulk', 'paste', 'rollback', 'scheduled').
	 * @param string               $summary          Human-readable summary.
	 * @param string|null          $batch_uuid       Optional existing batch UUID (for multi-chunk bulk batches).
	 * @param string               $status           Initial status ('applied' or 'scheduled').
	 * @param array<string, mixed> $schedule_payload Optional campaign payload.
	 * @param string|null          $scheduled_at     Optional UTC datetime.
	 * @param string|null          $revert_at        Optional UTC auto-revert datetime.
	 * @return array{id: int, uuid: string}
	 */
	public function open_batch(
		string $entity_type,
		string $operation_type,
		string $summary,
		?string $batch_uuid = null,
		string $status = 'applied',
		array $schedule_payload = [],
		?string $scheduled_at = null,
		?string $revert_at = null
	): array {
		global $wpdb;
		$table = Schema::batches_table();

		if ( null !== $batch_uuid && '' !== $batch_uuid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE batch_uuid = %s', $table, $batch_uuid )
			);
			if ( $existing_id > 0 ) {
				return [
					'id'   => $existing_id,
					'uuid' => $batch_uuid,
				];
			}
		}

		$uuid = $batch_uuid && '' !== $batch_uuid ? sanitize_text_field( $batch_uuid ) : wp_generate_uuid4();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			[
				'batch_uuid'       => $uuid,
				'user_id'          => get_current_user_id(),
				'entity_type'      => sanitize_key( $entity_type ),
				'operation_type'   => sanitize_key( $operation_type ),
				'summary'          => substr( sanitize_text_field( $summary ), 0, 250 ),
				'affected_count'   => 0,
				'status'           => sanitize_key( $status ),
				'schedule_payload' => ! empty( $schedule_payload ) ? wp_json_encode( $schedule_payload ) : null,
				'scheduled_at'     => $scheduled_at,
				'revert_at'        => $revert_at,
				'created_at'       => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		return [
			'id'   => (int) $wpdb->insert_id,
			'uuid' => $uuid,
		];
	}

	/**
	 * Record a single field delta inside a batch and increment affected counter.
	 */
	public function record_change(
		int $batch_id,
		int $object_id,
		string $field_key,
		mixed $old_value,
		mixed $new_value
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Schema::changes_table(),
			[
				'batch_id'  => $batch_id,
				'object_id' => $object_id,
				'field_key' => substr( $field_key, 0, 190 ),
				'old_value' => wp_json_encode( $old_value ),
				'new_value' => wp_json_encode( $new_value ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Refresh the distinct affected record count for a batch.
	 */
	public function finalize_batch_count( int $batch_id ): int {
		global $wpdb;
		$batches = Schema::batches_table();
		$changes = Schema::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(DISTINCT object_id) FROM %i WHERE batch_id = %d', $changes, $batch_id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$batches,
			[ 'affected_count' => $count ],
			[ 'id' => $batch_id ],
			[ '%d' ],
			[ '%d' ]
		);

		return $count;
	}

	/**
	 * List recent batches (optionally filtered by entity).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_batches( string $entity_type = '', int $limit = 30 ): array {
		global $wpdb;
		$table = Schema::batches_table();
		$limit = min( 100, max( 1, $limit ) );

		if ( '' !== $entity_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE entity_type = %s ORDER BY id DESC LIMIT %d',
					$table,
					sanitize_key( $entity_type ),
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit )
			);
		}

		$result = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$user     = $row->user_id ? get_userdata( (int) $row->user_id ) : false;
				$result[] = [
					'id'            => (int) $row->id,
					'uuid'          => (string) $row->batch_uuid,
					'entityType'    => (string) $row->entity_type,
					'operationType' => (string) $row->operation_type,
					'summary'       => (string) $row->summary,
					'affectedCount' => (int) $row->affected_count,
					'status'        => (string) $row->status,
					'userName'      => $user ? (string) $user->display_name : __( 'System', 'rowcraft' ),
					'scheduledAt'   => $row->scheduled_at,
					'revertAt'      => $row->revert_at,
					'createdAt'     => (string) $row->created_at,
				];
			}
		}

		return $result;
	}

	/**
	 * Return change details (diffs) for a specific batch UUID.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_batch_detail( string $batch_uuid, int $limit = 100 ): array|WP_Error {
		global $wpdb;
		$batches = Schema::batches_table();
		$changes = Schema::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$batch = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE batch_uuid = %s', $batches, $batch_uuid )
		);

		if ( ! $batch ) {
			return new WP_Error( 'rowcraft_batch_not_found', __( 'History batch not found.', 'rowcraft' ), [ 'status' => 404 ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_id, field_key, old_value, new_value FROM %i WHERE batch_id = %d ORDER BY id ASC LIMIT %d',
				$changes,
				(int) $batch->id,
				min( 250, max( 1, $limit ) )
			)
		);

		$diffs = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$diffs[] = [
					'objectId' => (int) $r->object_id,
					'field'    => (string) $r->field_key,
					'oldValue' => json_decode( (string) $r->old_value, true ),
					'newValue' => json_decode( (string) $r->new_value, true ),
				];
			}
		}

		return [
			'uuid'          => (string) $batch->batch_uuid,
			'entityType'    => (string) $batch->entity_type,
			'operationType' => (string) $batch->operation_type,
			'summary'       => (string) $batch->summary,
			'affectedCount' => (int) $batch->affected_count,
			'status'        => (string) $batch->status,
			'scheduledAt'   => $batch->scheduled_at,
			'revertAt'      => $batch->revert_at,
			'createdAt'     => (string) $batch->created_at,
			'changes'       => $diffs,
		];
	}

	/**
	 * One-click atomic rollback of every field delta stored under a batch UUID.
	 *
	 * @return array{restored: int, errors: array<int, array{id: int, field: string, message: string}>, rollbackUuid: string}|WP_Error
	 */
	public function rollback( string $batch_uuid, bool $bypass_capability = false ): array|WP_Error {
		global $wpdb;
		$batches = Schema::batches_table();
		$changes = Schema::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$batch = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE batch_uuid = %s', $batches, $batch_uuid )
		);

		if ( ! $batch ) {
			return new WP_Error( 'rowcraft_batch_not_found', __( 'Batch not found.', 'rowcraft' ), [ 'status' => 404 ] );
		}

		if ( 'rolled_back' === $batch->status || 'reverted' === $batch->status ) {
			return new WP_Error( 'rowcraft_already_rolled_back', __( 'This batch has already been rolled back.', 'rowcraft' ), [ 'status' => 409 ] );
		}

		$entity_slug = (string) $batch->entity_type;
		$adapter     = $this->entities->get( $entity_slug );
		if ( ! $adapter ) {
			return new WP_Error( 'rowcraft_entity_unavailable', __( 'Entity is not available for rollback.', 'rowcraft' ), [ 'status' => 400 ] );
		}

		// Reverse order so multiple edits to the same cell in one batch restore to the original value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_id, field_key, old_value FROM %i WHERE batch_id = %d ORDER BY id DESC',
				$changes,
				(int) $batch->id
			)
		);

		$rollback_batch = $this->open_batch(
			$entity_slug,
			'rollback',
			sprintf(
				/* translators: %s: original batch summary */
				__( 'Rollback: %s', 'rowcraft' ),
				(string) $batch->summary
			)
		);

		$restored_objects = [];
		$errors           = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$oid       = (int) $row->object_id;
				$field_key = (string) $row->field_key;
				$old_val   = json_decode( (string) $row->old_value, true );

				if ( ! $bypass_capability && ! $adapter->can_edit( $oid ) ) {
					$errors[] = [
						'id'      => $oid,
						'field'   => $field_key,
						'message' => __( 'Permission denied.', 'rowcraft' ),
					];
					continue;
				}

				$current_val = $this->fields->read_value( $entity_slug, $oid, $field_key );
				$write       = $this->fields->write_value( $entity_slug, $oid, $field_key, $old_val );

				if ( is_wp_error( $write ) ) {
					$errors[] = [
						'id'      => $oid,
						'field'   => $field_key,
						'message' => $write->get_error_message(),
					];
					continue;
				}

				$this->record_change( $rollback_batch['id'], $oid, $field_key, $current_val, $old_val );
				$restored_objects[ $oid ] = true;
			}
		}

		$this->finalize_batch_count( $rollback_batch['id'] );

		// Mark original batch as rolled_back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$batches,
			[ 'status' => $bypass_capability ? 'reverted' : 'rolled_back' ],
			[ 'id' => (int) $batch->id ],
			[ '%s' ],
			[ '%d' ]
		);

		return [
			'restored'     => count( $restored_objects ),
			'errors'       => $errors,
			'rollbackUuid' => $rollback_batch['uuid'],
		];
	}

	/**
	 * Prune history records older than configured TTL (default 30 days) via daily cron.
	 */
	public function prune_expired(): int {
		global $wpdb;

		$settings = get_option( 'rowcraft_settings', [] );
		$days     = is_array( $settings ) && ! empty( $settings['history_retention_days'] )
			? max( 1, (int) $settings['history_retention_days'] )
			: self::DEFAULT_RETENTION_DAYS;

		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$batches = Schema::batches_table();
		$changes = Schema::changes_table();

		// Keep pending scheduled batches intact.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE created_at < %s AND status <> 'scheduled'",
				$batches,
				$cutoff
			)
		);

		if ( empty( $old_ids ) ) {
			return 0;
		}

		$old_ids      = array_map( 'intval', $old_ids );
		$placeholders = implode( ',', array_fill( 0, count( $old_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$changes} WHERE batch_id IN ({$placeholders})", ...$old_ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$batches} WHERE id IN ({$placeholders})", ...$old_ids ) );

		return count( $old_ids );
	}
}
