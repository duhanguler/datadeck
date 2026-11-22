<?php
/**
 * Smart CSV / JSON Import Engine with Column Auto-Mapping, Batch Match Lookup,
 * Dry-Run Diff Preview, Chunked Safe Apply, and Full HistoryService Rollback.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Import;

use DataDeck\Entities\EntityRegistry;
use DataDeck\Fields\FieldRegistry;
use DataDeck\History\HistoryService;
use DataDeck\Schema\FieldDefinition;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CsvImportEngine {

	/**
	 * Common spreadsheet header aliases mapped to DataDeck field IDs.
	 *
	 * @var array<string, string>
	 */
	private const HEADER_ALIASES = [
		'id'                => 'id',
		'post_id'           => 'id',
		'product_id'        => 'id',
		'name'              => 'title',
		'post_title'        => 'title',
		'product_name'      => 'title',
		'sku'               => 'sku',
		'_sku'              => 'sku',
		'price'             => 'regular_price',
		'regular_price'     => 'regular_price',
		'regular price'     => 'regular_price',
		'_regular_price'    => 'regular_price',
		'sale_price'        => 'sale_price',
		'sale price'        => 'sale_price',
		'_sale_price'       => 'sale_price',
		'stock'             => 'stock_quantity',
		'qty'               => 'stock_quantity',
		'quantity'          => 'stock_quantity',
		'stock_quantity'    => 'stock_quantity',
		'stock_status'      => 'stock_status',
		'in_stock'          => 'stock_status',
		'manage_stock'      => 'manage_stock',
		'weight'            => 'weight',
		'status'            => 'status',
		'post_status'       => 'status',
		'slug'              => 'slug',
		'post_name'         => 'slug',
		'excerpt'           => 'excerpt',
		'short_description' => 'excerpt',
		'categories'        => 'product_cat',
		'category'          => 'product_cat',
		'tags'              => 'product_tag',
		'seo_title'         => 'seo_title',
		'meta_title'        => 'seo_title',
		'seo_description'   => 'seo_description',
		'meta_description'  => 'seo_description',
		'focus_keyword'     => 'seo_focus_kw',
		'alt'               => 'alt_text',
		'alt_text'          => 'alt_text',
		'email'             => 'user_email',
		'user_email'        => 'user_email',
		'display_name'      => 'display_name',
	];

	public function __construct(
		private readonly EntityRegistry $entities,
		private readonly FieldRegistry $fields,
		private readonly HistoryService $history,
	) {}

	/**
	 * Parse raw CSV string with automatic delimiter detection (comma, semicolon, tab)
	 * and UTF-8 BOM stripping. Pure PHP — safe for unit tests without WordPress DB.
	 *
	 * @param string      $content   Raw CSV text.
	 * @param string|null $delimiter Optional explicit delimiter.
	 * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, delimiter: string}
	 */
	public function parse_csv( string $content, ?string $delimiter = null ): array {
		// Strip UTF-8 BOM if present.
		if ( str_starts_with( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}

		$content = trim( $content );
		if ( '' === $content ) {
			return [
				'headers'   => [],
				'rows'      => [],
				'delimiter' => ',',
			];
		}

		$detected = $delimiter ?? $this->detect_delimiter( $content );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream = fopen( 'php://temp', 'r+' );
		if ( false === $stream ) {
			return [
				'headers'   => [],
				'rows'      => [],
				'delimiter' => $detected,
			];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $stream, $content );
		rewind( $stream );

		$raw_headers = fgetcsv( $stream, 0, $detected, '"', '' );
		if ( ! is_array( $raw_headers ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $stream );
			return [
				'headers'   => [],
				'rows'      => [],
				'delimiter' => $detected,
			];
		}

		$headers = [];
		foreach ( $raw_headers as $idx => $h ) {
			$clean = trim( (string) $h );
			if ( '' === $clean ) {
				$clean = 'column_' . ( $idx + 1 );
			}
			$headers[] = $clean;
		}

		$rows = [];
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $line = fgetcsv( $stream, 0, $detected, '"', '' ) ) ) {
			// Skip completely blank lines.
			$non_empty = array_filter( $line, static fn( ?string $v ): bool => null !== $v && '' !== trim( $v ) );
			if ( empty( $non_empty ) ) {
				continue;
			}
			$assoc = [];
			foreach ( $headers as $idx => $h ) {
				$assoc[ $h ] = isset( $line[ $idx ] ) ? (string) $line[ $idx ] : '';
			}
			$rows[] = $assoc;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $stream );

		return [
			'headers'   => $headers,
			'rows'      => $rows,
			'delimiter' => $detected,
		];
	}

	/**
	 * Parse a JSON array of flat objects into headers + rows.
	 *
	 * @param string $content Raw JSON string.
	 * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>, delimiter: string}|WP_Error
	 */
	public function parse_json( string $content ): array|WP_Error {
		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'datadeck_invalid_json', __( 'Invalid JSON payload. Expected an array of record objects.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$headers_set = [];
		$rows        = [];
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( array_keys( $item ) as $key ) {
				$headers_set[ (string) $key ] = true;
			}
			$rows[] = $item;
		}

		return [
			'headers'   => array_keys( $headers_set ),
			'rows'      => $rows,
			'delimiter' => 'json',
		];
	}

	/**
	 * Suggest automatic column mapping from spreadsheet headers to DataDeck fields.
	 *
	 * @param array<int, string>            $headers   Incoming column headers.
	 * @param array<string, FieldDefinition> $field_map Available entity fields.
	 * @return array<string, string> Map of incoming header => field ID (or '' to skip).
	 */
	public function suggest_mapping( array $headers, array $field_map ): array {
		$by_label    = [];
		$by_meta_key = [];

		foreach ( $field_map as $fid => $def ) {
			$by_label[ $this->normalize_header( $def->label ) ] = $fid;
			if ( '' !== $def->meta_key ) {
				$by_meta_key[ strtolower( $def->meta_key ) ] = $fid;
			}
		}

		$mapping = [];
		foreach ( $headers as $header ) {
			$norm = $this->normalize_header( $header );
			$raw  = strtolower( trim( $header ) );

			if ( isset( $field_map[ $norm ] ) ) {
				$mapping[ $header ] = $norm;
			} elseif ( isset( $field_map[ $raw ] ) ) {
				$mapping[ $header ] = $raw;
			} elseif ( isset( $by_label[ $norm ] ) ) {
				$mapping[ $header ] = $by_label[ $norm ];
			} elseif ( isset( $by_meta_key[ $raw ] ) ) {
				$mapping[ $header ] = $by_meta_key[ $raw ];
			} elseif ( isset( self::HEADER_ALIASES[ $norm ] ) && isset( $field_map[ self::HEADER_ALIASES[ $norm ] ] ) ) {
				$mapping[ $header ] = self::HEADER_ALIASES[ $norm ];
			} elseif ( isset( self::HEADER_ALIASES[ $raw ] ) && isset( $field_map[ self::HEADER_ALIASES[ $raw ] ] ) ) {
				$mapping[ $header ] = self::HEADER_ALIASES[ $raw ];
			} else {
				$mapping[ $header ] = '';
			}
		}

		return $mapping;
	}

	/**
	 * Analyze uploaded CSV/JSON content for column auto-mapping and sample preview.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function analyze( string $entity_slug, string $content, string $format = 'csv' ): array|WP_Error {
		$adapter = $this->entities->get( $entity_slug );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_unknown_entity', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$parsed = 'json' === $format ? $this->parse_json( $content ) : $this->parse_csv( $content );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( empty( $parsed['headers'] ) ) {
			return new WP_Error( 'datadeck_empty_import', __( 'No columns detected in import file.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$field_map  = $this->fields->get_fields_for_entity( $entity_slug, false );
		$suggested  = $this->suggest_mapping( $parsed['headers'], $field_map );
		$candidates = $this->get_match_candidates( $entity_slug );

		return [
			'headers'          => $parsed['headers'],
			'delimiter'        => $parsed['delimiter'],
			'totalRows'        => count( $parsed['rows'] ),
			'sampleRows'       => array_slice( $parsed['rows'], 0, 5 ),
			'suggestedMapping' => $suggested,
			'matchCandidates'  => $candidates,
			'canCreate'        => $adapter->can_create(),
		];
	}

	/**
	 * Produce a Dry-Run Diff preview showing exact old -> new mutations before applying.
	 *
	 * @param string                             $entity_slug    Entity slug.
	 * @param array<int, array<string, mixed>>   $rows           Parsed import rows.
	 * @param array<string, string>              $mapping        CSV header => field ID map.
	 * @param string                             $match_by       Match key ('id' | 'sku' | 'slug' | 'user_email').
	 * @param bool                               $create_missing Whether unmatched rows create new records.
	 * @return array<string, mixed>|WP_Error
	 */
	public function dry_run(
		string $entity_slug,
		array $rows,
		array $mapping,
		string $match_by = 'id',
		bool $create_missing = false
	): array|WP_Error {
		$adapter = $this->entities->get( $entity_slug );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_unknown_entity', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$field_map    = $this->fields->get_fields_for_entity( $entity_slug, false );
		$match_header = $this->find_header_for_field( $mapping, $match_by );
		$match_values = $this->extract_match_values( $rows, $match_header );
		$matched_ids  = $this->resolve_existing_ids( $entity_slug, $match_by, $match_values );

		$will_update  = 0;
		$will_create  = 0;
		$will_skip    = 0;
		$errors_count = 0;
		$previews     = [];

		foreach ( $rows as $idx => $row ) {
			$row_num   = $idx + 1;
			$raw_key   = null !== $match_header ? trim( (string) ( $row[ $match_header ] ?? '' ) ) : '';
			$lookup    = $this->normalize_match_key( $match_by, $raw_key );
			$record_id = '' !== $lookup ? ( $matched_ids[ $lookup ] ?? 0 ) : 0;

			$mapped_values = $this->extract_row_field_values( $row, $mapping, $field_map );

			if ( $record_id > 0 ) {
				if ( ! $adapter->can_edit( $record_id ) ) {
					++$errors_count;
					if ( count( $previews ) < 25 ) {
						$previews[] = [
							'rowNumber'  => $row_num,
							'matchValue' => $raw_key,
							'recordId'   => $record_id,
							'action'     => 'error',
							'changes'    => [],
							'error'      => __( 'Permission denied.', 'datadeck' ),
						];
					}
					continue;
				}

				$changes = [];
				foreach ( $mapped_values as $fid => $new_val ) {
					$def     = $field_map[ $fid ];
					$old_val = $this->fields->read_value( $entity_slug, $record_id, $fid );
					if ( ! $this->values_equal( $old_val, $new_val ) ) {
						$changes[] = [
							'field'    => $fid,
							'label'    => $def->label,
							'oldValue' => $old_val,
							'newValue' => $new_val,
						];
					}
				}

				if ( empty( $changes ) ) {
					++$will_skip;
				} else {
					++$will_update;
				}

				if ( count( $previews ) < 25 ) {
					$previews[] = [
						'rowNumber'  => $row_num,
						'matchValue' => $raw_key,
						'recordId'   => $record_id,
						'action'     => empty( $changes ) ? 'skip' : 'update',
						'changes'    => $changes,
						'error'      => null,
					];
				}
			} elseif ( $create_missing && $adapter->can_create() ) {
				++$will_create;
				if ( count( $previews ) < 25 ) {
					$changes = [];
					foreach ( $mapped_values as $fid => $new_val ) {
						$changes[] = [
							'field'    => $fid,
							'label'    => $field_map[ $fid ]->label,
							'oldValue' => null,
							'newValue' => $new_val,
						];
					}
					$previews[] = [
						'rowNumber'  => $row_num,
						'matchValue' => $raw_key,
						'recordId'   => null,
						'action'     => 'create',
						'changes'    => $changes,
						'error'      => null,
					];
				}
			} else {
				++$will_skip;
				if ( count( $previews ) < 25 ) {
					$previews[] = [
						'rowNumber'  => $row_num,
						'matchValue' => $raw_key,
						'recordId'   => null,
						'action'     => 'skip',
						'changes'    => [],
						'error'      => null,
					];
				}
			}
		}

		return [
			'totalRows'   => count( $rows ),
			'willUpdate'  => $will_update,
			'willCreate'  => $will_create,
			'willSkip'    => $will_skip,
			'errorsCount' => $errors_count,
			'previews'    => $previews,
		];
	}

	/**
	 * Apply one chunk of import rows with full HistoryService delta snapshots for One-Click Rollback.
	 *
	 * @param string                             $entity_slug    Entity slug.
	 * @param array<int, array<string, mixed>>   $rows           Chunk of parsed import rows.
	 * @param array<string, string>              $mapping        CSV header => field ID map.
	 * @param string                             $match_by       Match key.
	 * @param bool                               $create_missing Whether to create unmatched rows.
	 * @param string|null                        $batch_uuid     Shared batch UUID across chunks.
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply_chunk(
		string $entity_slug,
		array $rows,
		array $mapping,
		string $match_by = 'id',
		bool $create_missing = false,
		?string $batch_uuid = null
	): array|WP_Error {
		$adapter = $this->entities->get( $entity_slug );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_unknown_entity', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$field_map    = $this->fields->get_fields_for_entity( $entity_slug, false );
		$match_header = $this->find_header_for_field( $mapping, $match_by );
		$match_values = $this->extract_match_values( $rows, $match_header );
		$matched_ids  = $this->resolve_existing_ids( $entity_slug, $match_by, $match_values );

		$batch = $this->history->open_batch(
			$entity_slug,
			'import',
			sprintf(
				/* translators: 1: entity plural label, 2: match key */
				__( 'CSV Import on %1$s (matched by %2$s)', 'datadeck' ),
				$adapter->get_plural_label(),
				$match_by
			),
			$batch_uuid
		);

		$updated = 0;
		$created = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $rows as $idx => $row ) {
			$raw_key       = null !== $match_header ? trim( (string) ( $row[ $match_header ] ?? '' ) ) : '';
			$lookup        = $this->normalize_match_key( $match_by, $raw_key );
			$record_id     = '' !== $lookup ? ( $matched_ids[ $lookup ] ?? 0 ) : 0;
			$mapped_values = $this->extract_row_field_values( $row, $mapping, $field_map );

			if ( $record_id <= 0 ) {
				if ( ! $create_missing || ! $adapter->can_create() ) {
					++$skipped;
					continue;
				}

				$new_id = $adapter->create_record(
					[
						'title'         => (string) ( $mapped_values['title'] ?? __( 'Imported Item', 'datadeck' ) ),
						'sku'           => (string) ( $mapped_values['sku'] ?? '' ),
						'regular_price' => $mapped_values['regular_price'] ?? '',
						'status'        => (string) ( $mapped_values['status'] ?? 'draft' ),
					]
				);

				if ( is_wp_error( $new_id ) ) {
					$errors[] = [
						'row'     => $idx + 1,
						'message' => $new_id->get_error_message(),
					];
					continue;
				}

				$record_id = $new_id;
				++$created;

				// Apply remaining mapped fields onto the newly created record.
				foreach ( $mapped_values as $fid => $new_val ) {
					if ( in_array( $fid, [ 'title', 'sku', 'regular_price', 'status' ], true ) ) {
						continue;
					}
					$this->fields->write_value( $entity_slug, $record_id, $fid, $new_val );
				}
				continue;
			}

			if ( ! $adapter->can_edit( $record_id ) ) {
				$errors[] = [
					'row'     => $idx + 1,
					'id'      => $record_id,
					'message' => __( 'Permission denied.', 'datadeck' ),
				];
				continue;
			}

			$row_changed = false;
			foreach ( $mapped_values as $fid => $new_val ) {
				$old_val = $this->fields->read_value( $entity_slug, $record_id, $fid );
				if ( $this->values_equal( $old_val, $new_val ) ) {
					continue;
				}

				$write = $this->fields->write_value( $entity_slug, $record_id, $fid, $new_val );
				if ( is_wp_error( $write ) ) {
					$errors[] = [
						'row'     => $idx + 1,
						'id'      => $record_id,
						'field'   => $fid,
						'message' => $write->get_error_message(),
					];
					continue;
				}

				$this->history->record_change( $batch['id'], $record_id, $fid, $old_val, $new_val );
				$row_changed = true;
			}

			if ( $row_changed ) {
				++$updated;
			} else {
				++$skipped;
			}
		}

		$this->history->finalize_batch_count( $batch['id'] );

		return [
			'batchUuid' => $batch['uuid'],
			'processed' => count( $rows ),
			'updated'   => $updated,
			'created'   => $created,
			'skipped'   => $skipped,
			'errors'    => $errors,
		];
	}

	/**
	 * Detect CSV delimiter from first non-empty line.
	 */
	private function detect_delimiter( string $content ): string {
		$first_line = strtok( $content, "\r\n" );
		if ( false === $first_line ) {
			return ',';
		}

		$counts = [
			','  => substr_count( $first_line, ',' ),
			';'  => substr_count( $first_line, ';' ),
			"\t" => substr_count( $first_line, "\t" ),
		];
		arsort( $counts );
		$best = (string) array_key_first( $counts );

		return $counts[ $best ] > 0 ? $best : ',';
	}

	private function normalize_header( string $header ): string {
		$clean = strtolower( trim( $header ) );
		$clean = (string) preg_replace( '/[^a-z0-9]+/', '_', $clean );
		return trim( $clean, '_' );
	}

	/**
	 * Return valid match-by options for the given entity.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private function get_match_candidates( string $entity_slug ): array {
		$out = [
			[
				'value' => 'id',
				'label' => __( 'Record ID', 'datadeck' ),
			],
		];

		if ( 'product' === $entity_slug ) {
			$out[] = [
				'value' => 'sku',
				'label' => __( 'SKU', 'datadeck' ),
			];
			$out[] = [
				'value' => 'slug',
				'label' => __( 'Slug', 'datadeck' ),
			];
		} elseif ( 'user' === $entity_slug ) {
			$out[] = [
				'value' => 'user_email',
				'label' => __( 'Email', 'datadeck' ),
			];
		} elseif ( ! in_array( $entity_slug, [ 'shop_order', 'comment' ], true ) ) {
			$out[] = [
				'value' => 'slug',
				'label' => __( 'Slug', 'datadeck' ),
			];
		}

		return $out;
	}

	/**
	 * @param array<string, string> $mapping Column map.
	 */
	private function find_header_for_field( array $mapping, string $field_id ): ?string {
		foreach ( $mapping as $header => $mapped_field ) {
			if ( $mapped_field === $field_id ) {
				return (string) $header;
			}
		}
		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows         Rows.
	 * @param string|null                      $match_header CSV header mapped to match key.
	 * @return array<int, string>
	 */
	private function extract_match_values( array $rows, ?string $match_header ): array {
		if ( null === $match_header ) {
			return [];
		}
		$vals = [];
		foreach ( $rows as $row ) {
			$val = trim( (string) ( $row[ $match_header ] ?? '' ) );
			if ( '' !== $val ) {
				$vals[] = $val;
			}
		}
		return array_values( array_unique( $vals ) );
	}

	private function normalize_match_key( string $match_by, string $value ): string {
		if ( 'id' === $match_by ) {
			return is_numeric( $value ) ? (string) (int) $value : '';
		}
		return strtolower( trim( $value ) );
	}

	/**
	 * Resolve incoming match values (IDs, SKUs, slugs, or emails) in 1 single batch SQL query.
	 *
	 * @param array<int, string> $values Match values.
	 * @return array<int|string, int> Map of normalized match value => record ID.
	 */
	private function resolve_existing_ids( string $entity_slug, string $match_by, array $values ): array {
		global $wpdb;

		if ( empty( $values ) ) {
			return [];
		}

		if ( 'id' === $match_by ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $values ) ) ) );
			if ( empty( $ids ) ) {
				return [];
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			if ( 'user' === $entity_slug ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID IN ({$placeholders})", ...$ids ) );
				// phpcs:enable
			} elseif ( 'comment' === $entity_slug ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID IN ({$placeholders})", ...$ids ) );
				// phpcs:enable
			} elseif ( 'product' === $entity_slug ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND ID IN ({$placeholders})", ...$ids ) );
				// phpcs:enable
			} else {
				$params = array_merge( [ $entity_slug ], $ids );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID IN ({$placeholders})", ...$params ) );
				// phpcs:enable
			}

			$map = [];
			foreach ( (array) $found as $fid ) {
				$map[ (string) (int) $fid ] = (int) $fid;
			}
			return $map;
		}

		if ( 'sku' === $match_by && 'product' === $entity_slug ) {
			$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_value
					FROM {$wpdb->postmeta} AS pm
					INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
					WHERE pm.meta_key = '_sku'
					AND p.post_type IN ('product', 'product_variation')
					AND p.post_status <> 'trash'
					AND pm.meta_value IN ({$placeholders})",
					...$values
				)
			);
			// phpcs:enable

			$map = [];
			foreach ( (array) $rows as $r ) {
				$map[ strtolower( trim( (string) $r->meta_value ) ) ] = (int) $r->post_id;
			}
			return $map;
		}

		if ( 'slug' === $match_by ) {
			$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
			$params       = array_merge( [ $entity_slug ], $values );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash' AND post_name IN ({$placeholders})",
					...$params
				)
			);
			// phpcs:enable

			$map = [];
			foreach ( (array) $rows as $r ) {
				$map[ strtolower( trim( (string) $r->post_name ) ) ] = (int) $r->ID;
			}
			return $map;
		}

		if ( 'user_email' === $match_by && 'user' === $entity_slug ) {
			$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, user_email FROM {$wpdb->users} WHERE user_email IN ({$placeholders})",
					...$values
				)
			);
			// phpcs:enable

			$map = [];
			foreach ( (array) $rows as $r ) {
				$map[ strtolower( trim( (string) $r->user_email ) ) ] = (int) $r->ID;
			}
			return $map;
		}

		return [];
	}

	/**
	 * Coerce mapped CSV row values according to each FieldDefinition's type.
	 *
	 * @param array<string, mixed>          $row       Incoming row.
	 * @param array<string, string>         $mapping   CSV header => field ID.
	 * @param array<string, FieldDefinition> $field_map Entity field map.
	 * @return array<string, mixed>
	 */
	private function extract_row_field_values( array $row, array $mapping, array $field_map ): array {
		$out = [];
		foreach ( $mapping as $header => $field_id ) {
			if ( '' === $field_id || 'id' === $field_id || ! isset( $field_map[ $field_id ] ) ) {
				continue;
			}
			$def = $field_map[ $field_id ];
			if ( ! $def->editable ) {
				continue;
			}
			if ( ! array_key_exists( $header, $row ) ) {
				continue;
			}
			$out[ $field_id ] = $this->coerce_value( $row[ $header ], $def );
		}
		return $out;
	}

	/**
	 * Convert a raw CSV string into the target field's native PHP type.
	 */
	public function coerce_value( mixed $raw, FieldDefinition $field ): mixed {
		if ( null === $raw ) {
			return null;
		}

		if ( 'taxonomy' === $field->type ) {
			if ( is_array( $raw ) ) {
				return $raw;
			}
			$names = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
			if ( empty( $names ) ) {
				return [];
			}
			$term_ids = [];
			foreach ( $names as $name ) {
				if ( is_numeric( $name ) ) {
					$term_ids[] = (int) $name;
					continue;
				}
				if ( function_exists( 'get_term_by' ) && '' !== $field->taxonomy ) {
					$term = get_term_by( 'name', $name, $field->taxonomy );
					if ( ! $term ) {
						$term = get_term_by( 'slug', sanitize_title( $name ), $field->taxonomy );
					}
					if ( $term instanceof \WP_Term ) {
						$term_ids[] = (int) $term->term_id;
					}
				}
			}
			return array_values( array_unique( $term_ids ) );
		}

		$str = trim( (string) $raw );

		if ( in_array( $field->type, [ 'number', 'currency' ], true ) ) {
			if ( '' === $str ) {
				return null;
			}
			$clean = (string) preg_replace( '/[^0-9.,\-]/', '', $str );
			if ( substr_count( $clean, ',' ) === 1 && ! str_contains( $clean, '.' ) ) {
				$clean = str_replace( ',', '.', $clean );
			} else {
				$clean = str_replace( ',', '', $clean );
			}
			return is_numeric( $clean ) ? (float) $clean : null;
		}

		if ( 'integer' === $field->type ) {
			if ( '' === $str ) {
				return null;
			}
			return is_numeric( $str ) ? (int) $str : null;
		}

		if ( 'boolean' === $field->type ) {
			return in_array( strtolower( $str ), [ '1', 'true', 'yes', 'on', 'y' ], true );
		}

		return $str;
	}

	private function values_equal( mixed $a, mixed $b ): bool {
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return abs( (float) $a - (float) $b ) < 0.00001;
		}
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}
}
