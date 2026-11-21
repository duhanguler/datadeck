<?php
/**
 * WP-CLI Command Suite for RowCraft (`wp rowcraft ...`).
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Cli;

use RowCraft\Bulk\BulkOperationEngine;
use RowCraft\Entities\EntityRegistry;
use RowCraft\History\HistoryService;
use RowCraft\Query\QueryRequest;
use RowCraft\Sync\ViewSyncService;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RowCraftCommand {

	public function __construct(
		private readonly EntityRegistry $entities,
		private readonly BulkOperationEngine $bulk,
		private readonly HistoryService $history,
		private readonly ViewSyncService $views,
	) {}

	/**
	 * Display RowCraft entities, active integrations, and local JSON sync status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rowcraft status
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Named options.
	 */
	public function status( array $args, array $assoc_args ): void {
		$entities = $this->entities->list_readable();
		$sync     = $this->views->get_json_sync_status();

		WP_CLI::log( sprintf( 'RowCraft v%s', ROWCRAFT_VERSION ) );
		WP_CLI::log( sprintf( 'Discovered entities: %s', implode( ', ', array_column( $entities, 'slug' ) ) ) );
		WP_CLI::log( sprintf( 'Local JSON sync: %s (%d pending)', $sync['enabled'] ? $sync['savePath'] : 'disabled', count( $sync['syncAvailable'] ) ) );
	}

	/**
	 * Benchmark query count and execution time for an entity (verifies <= 7 SQL target).
	 *
	 * ## OPTIONS
	 *
	 * [<entity>]
	 * : Entity slug to benchmark (default: post).
	 *
	 * [--per-page=<num>]
	 * : Rows to hydrate per page (default: 50).
	 *
	 * ## EXAMPLES
	 *
	 *     wp rowcraft benchmark product --per-page=100
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Named options.
	 */
	public function benchmark( array $args, array $assoc_args ): void {
		$entity   = sanitize_key( (string) ( $args[0] ?? 'post' ) );
		$per_page = (int) ( $assoc_args['per-page'] ?? 50 );
		$adapter  = $this->entities->get( $entity );

		if ( ! $adapter ) {
			WP_CLI::error( sprintf( 'Entity "%s" not found.', $entity ) );
			return;
		}

		$req = QueryRequest::from_array( $entity, [ 'per_page' => $per_page ] );
		$res = $adapter->query( $req );

		WP_CLI::success(
			sprintf(
				'Entity=%s | Rows=%d/%d | SQL Queries=%d | Time=%.2fms | Plan=%s',
				$entity,
				count( $res->items ),
				$res->total,
				$res->query_count,
				$res->duration_ms,
				$res->query_plan
			)
		);
	}

	/**
	 * Execute or dry-run a bulk rule from WP-CLI.
	 *
	 * ## OPTIONS
	 *
	 * <entity>
	 * : Entity slug (e.g. product, post).
	 *
	 * --field=<field>
	 * : Target field ID.
	 *
	 * --action=<action>
	 * : Action (set, formula, math_percent, math_fixed, template, prepend, append).
	 *
	 * [--value=<value>]
	 * : Value, formula, or template string.
	 *
	 * [--dry-run]
	 * : Preview first 10 records without saving.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Named options.
	 */
	public function bulk( array $args, array $assoc_args ): void {
		$entity  = sanitize_key( (string) ( $args[0] ?? '' ) );
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$rule    = [
			'field'   => (string) ( $assoc_args['field'] ?? '' ),
			'action'  => (string) ( $assoc_args['action'] ?? 'set' ),
			'value'   => $assoc_args['value'] ?? '',
			'formula' => $assoc_args['value'] ?? '',
		];

		if ( $dry_run ) {
			$res = $this->bulk->dry_run( $entity, $rule );
			if ( is_wp_error( $res ) ) {
				WP_CLI::error( $res->get_error_message() );
				return;
			}
			WP_CLI::log( sprintf( 'Dry run (%d matching records):', (int) $res['totalMatching'] ) );
			foreach ( (array) $res['previews'] as $p ) {
				WP_CLI::log( sprintf( '  #%d %-30s : %s -> %s', (int) $p['id'], (string) $p['title'], wp_json_encode( $p['oldValue'] ), wp_json_encode( $p['newValue'] ) ) );
			}
			return;
		}

		$after_id   = 0;
		$batch_uuid = null;
		$total      = 0;
		while ( true ) {
			$res = $this->bulk->execute_chunk( $entity, $rule, [], [], $after_id, 100, $batch_uuid, true );
			if ( is_wp_error( $res ) ) {
				WP_CLI::error( $res->get_error_message() );
				return;
			}
			$batch_uuid = (string) $res['batchUuid'];
			$total     += (int) $res['updated'];
			if ( empty( $res['hasMore'] ) ) {
				break;
			}
			$after_id = (int) $res['lastId'];
		}

		WP_CLI::success( sprintf( 'Updated %d records. Rollback UUID: %s', $total, (string) $batch_uuid ) );
	}

	/**
	 * Roll back a previously applied batch by UUID.
	 *
	 * ## OPTIONS
	 *
	 * <uuid>
	 * : Batch UUID from `wp rowcraft history`.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Named options.
	 */
	public function rollback( array $args, array $assoc_args ): void {
		$uuid = sanitize_text_field( (string) ( $args[0] ?? '' ) );
		$res  = $this->history->rollback( $uuid, true );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
			return;
		}
		WP_CLI::success( sprintf( 'Rolled back %d records (audit UUID: %s).', (int) $res['restored'], (string) $res['rollbackUuid'] ) );
	}

	/**
	 * Sync local rowcraft-json/ view files into the WordPress database.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Named options.
	 */
	public function sync_json( array $args, array $assoc_args ): void {
		$res = $this->views->sync_from_json();
		WP_CLI::success( sprintf( 'Synced %d view(s) from rowcraft-json/.', (int) $res['synced'] ) );
	}
}
