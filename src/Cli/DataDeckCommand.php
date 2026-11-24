<?php
/**
 * WP-CLI Command Suite for DataDeck (`wp datadeck ...`).
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Cli;

use DataDeck\Bulk\BulkOperationEngine;
use DataDeck\Entities\EntityRegistry;
use DataDeck\History\HistoryService;
use DataDeck\Import\CsvImportEngine;
use DataDeck\Query\QueryRequest;
use DataDeck\Sync\ViewSyncService;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DataDeckCommand {

	public function __construct(
		private readonly EntityRegistry $entities,
		private readonly BulkOperationEngine $bulk,
		private readonly HistoryService $history,
		private readonly ViewSyncService $views,
		private readonly CsvImportEngine $importer,
	) {}

	/**
	 * Display DataDeck entities, active integrations, and local JSON sync status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datadeck status
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Named options.
	 */
	public function status( array $args, array $assoc_args ): void {
		$entities = $this->entities->list_available();
		$sync     = $this->views->get_json_sync_status();

		WP_CLI::log( sprintf( 'DataDeck v%s', DATADECK_VERSION ) );
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
	 *     wp datadeck benchmark product --per-page=100
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
	 * : Batch UUID from `wp datadeck history`.
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
	 * Sync local datadeck-json/ view files into the WordPress database.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Named options.
	 */
	public function sync_json( array $args, array $assoc_args ): void {
		$res = $this->views->sync_from_json();
		WP_CLI::success( sprintf( 'Synced %d view(s) from datadeck-json/.', (int) $res['synced'] ) );
	}

	/**
	 * Import CSV or JSON updates with automatic column mapping, dry-run preview, and rollback support.
	 *
	 * ## OPTIONS
	 *
	 * <entity>
	 * : Target entity slug (e.g. product, post, user).
	 *
	 * <file>
	 * : Path to local CSV or JSON file.
	 *
	 * [--match=<key>]
	 * : Column to match existing records on (id, sku, slug, user_email). Default: id.
	 *
	 * [--create-missing]
	 * : Create new records for unmatched rows.
	 *
	 * [--dry-run]
	 * : Preview changes without modifying the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datadeck import product supplier-prices.csv --match=sku --dry-run
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Named options.
	 */
	public function import( array $args, array $assoc_args ): void {
		$entity   = sanitize_key( (string) ( $args[0] ?? '' ) );
		$filepath = (string) ( $args[1] ?? '' );
		$match_by = sanitize_key( (string) ( $assoc_args['match'] ?? 'id' ) );
		$create   = isset( $assoc_args['create-missing'] );
		$dry_run  = isset( $assoc_args['dry-run'] );

		if ( '' === $entity || '' === $filepath || ! is_readable( $filepath ) ) {
			WP_CLI::error( 'Provide a valid entity slug and readable CSV/JSON file path.' );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $filepath );
		if ( false === $raw ) {
			WP_CLI::error( 'Failed to read import file.' );
			return;
		}

		$format   = str_ends_with( strtolower( $filepath ), '.json' ) ? 'json' : 'csv';
		$analysis = $this->importer->analyze( $entity, $raw, $format );
		if ( is_wp_error( $analysis ) ) {
			WP_CLI::error( $analysis->get_error_message() );
			return;
		}

		$parsed = 'json' === $format ? $this->importer->parse_json( $raw ) : $this->importer->parse_csv( $raw );
		if ( is_wp_error( $parsed ) ) {
			WP_CLI::error( $parsed->get_error_message() );
			return;
		}

		$mapping = is_array( $analysis['suggestedMapping'] ?? null ) ? $analysis['suggestedMapping'] : [];
		if ( $dry_run ) {
			$preview = $this->importer->dry_run( $entity, $parsed['rows'], $mapping, $match_by, $create );
			if ( is_wp_error( $preview ) ) {
				WP_CLI::error( $preview->get_error_message() );
				return;
			}
			WP_CLI::log(
				sprintf(
					'Dry Run (%d rows): update=%d | create=%d | skip=%d | errors=%d',
					(int) $preview['totalRows'],
					(int) $preview['willUpdate'],
					(int) $preview['willCreate'],
					(int) $preview['willSkip'],
					(int) $preview['errorsCount']
				)
			);
			return;
		}

		$chunks     = array_chunk( $parsed['rows'], 50 );
		$batch_uuid = null;
		$updated    = 0;
		$created    = 0;

		foreach ( $chunks as $chunk ) {
			$res = $this->importer->apply_chunk( $entity, $chunk, $mapping, $match_by, $create, $batch_uuid, true );
			if ( is_wp_error( $res ) ) {
				WP_CLI::error( $res->get_error_message() );
				return;
			}
			$batch_uuid = (string) $res['batchUuid'];
			$updated   += (int) $res['updated'];
			$created   += (int) $res['created'];
		}

		WP_CLI::success( sprintf( 'Imported %d updated, %d created. Rollback UUID: %s', $updated, $created, (string) $batch_uuid ) );
	}
}
