<?php
/**
 * Safe Bulk Operation & Inline Batch Mutation Engine.
 *
 * Supports staged inline cell commits, Excel multi-cell paste commits, dry-run diffs,
 * safe AST formulas, math adjustments, psychological rounding, find/replace, templates,
 * taxonomy transforms, and automatic delta snapshot recording.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Bulk;

use InvalidArgumentException;
use DataDeck\Entities\EntityRegistry;
use DataDeck\Fields\FieldRegistry;
use DataDeck\Formula\ExpressionEngine;
use DataDeck\History\HistoryService;
use DataDeck\Query\QueryRequest;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BulkOperationEngine {

	public function __construct(
		private readonly EntityRegistry $entities,
		private readonly FieldRegistry $fields,
		private readonly HistoryService $history,
		private readonly ExpressionEngine $formula,
	) {}

	/**
	 * Commit a list of explicit cell edits (inline staged edits or Excel paste).
	 * Supports optimistic concurrency checking via optional expectedModifiedAt per edit.
	 *
	 * @param string                                                                           $entity_slug    Entity slug.
	 * @param array<int, array{id: int, field: string, value: mixed, expectedModifiedAt?: string}> $edits      Cell mutations.
	 * @param string                                                                           $operation_type 'inline' or 'paste'.
	 * @param bool                                                                             $force          Skip optimistic lock check when user resolves conflict.
	 * @return array<string, mixed>|WP_Error
	 */
	public function commit_cell_edits(
		string $entity_slug,
		array $edits,
		string $operation_type = 'inline',
		bool $force = false
	): array|WP_Error {
		$adapter = $this->entities->get( $entity_slug );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_unknown_entity', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		if ( empty( $edits ) ) {
			return [
				'updated'     => 0,
				'errors'      => [],
				'conflicts'   => [],
				'modifiedMap' => [],
				'batchUuid'   => '',
			];
		}

		// Optimistic Concurrency Guard: detect stale records modified by another user/process.
		$conflicts        = [];
		$conflicted_ids   = [];
		$initial_modified = [];

		if ( ! $force ) {
			foreach ( $edits as $edit ) {
				$oid      = (int) ( $edit['id'] ?? 0 );
				$field_id = (string) ( $edit['field'] ?? '' );
				$expected = isset( $edit['expectedModifiedAt'] ) ? trim( (string) $edit['expectedModifiedAt'] ) : '';

				if ( $oid <= 0 || '' === $field_id || '' === $expected ) {
					continue;
				}

				if ( ! array_key_exists( $oid, $initial_modified ) ) {
					$initial_modified[ $oid ] = $this->read_record_modified_gmt( $entity_slug, $oid );
				}

				$current_mod = $initial_modified[ $oid ];
				if ( '' !== $current_mod && $current_mod !== $expected ) {
					$conflicted_ids[ $oid ] = true;
					$conflicts[]            = [
						'id'                 => $oid,
						'field'              => $field_id,
						'expectedModifiedAt' => $expected,
						'currentModifiedAt'  => $current_mod,
						'serverValue'        => $this->fields->read_value( $entity_slug, $oid, $field_id ),
						'attemptedValue'     => $edit['value'] ?? null,
					];
				}
			}
		}

		$applicable = array_values(
			array_filter(
				$edits,
				static fn( array $e ): bool => ! isset( $conflicted_ids[ (int) ( $e['id'] ?? 0 ) ] )
			)
		);

		if ( empty( $applicable ) ) {
			return [
				'updated'     => 0,
				'errors'      => [],
				'conflicts'   => $conflicts,
				'modifiedMap' => [],
				'batchUuid'   => '',
			];
		}

		$summary = sprintf(
			/* translators: 1: edit count, 2: entity label */
			__( '%1$d cell edits on %2$s', 'datadeck' ),
			count( $applicable ),
			$adapter->get_plural_label()
		);

		$batch       = $this->history->open_batch( $entity_slug, $operation_type, $summary );
		$updated     = 0;
		$errors      = [];
		$touched_ids = [];

		foreach ( $applicable as $edit ) {
			$oid       = (int) ( $edit['id'] ?? 0 );
			$field_id  = (string) ( $edit['field'] ?? '' );
			$new_value = $edit['value'] ?? null;

			if ( $oid <= 0 || '' === $field_id ) {
				continue;
			}

			if ( ! $adapter->can_edit( $oid ) ) {
				$errors[] = [
					'id'      => $oid,
					'field'   => $field_id,
					'message' => __( 'Permission denied.', 'datadeck' ),
				];
				continue;
			}

			$old_value = $this->fields->read_value( $entity_slug, $oid, $field_id );
			if ( $this->values_equal( $old_value, $new_value ) ) {
				continue;
			}
			$res = $this->fields->write_value( $entity_slug, $oid, $field_id, $new_value );

			if ( is_wp_error( $res ) ) {
				$errors[] = [
					'id'      => $oid,
					'field'   => $field_id,
					'message' => $res->get_error_message(),
				];
				continue;
			}

			$this->history->record_change( $batch['id'], $oid, $field_id, $old_value, $new_value );
			$touched_ids[ $oid ] = true;
			++$updated;
		}

		$this->history->finalize_batch_count( $batch['id'] );

		$modified_map   = [];
		$is_post_entity = ! in_array( $entity_slug, [ 'user', 'shop_order', 'comment' ], true );
		foreach ( array_keys( $touched_ids ) as $tid ) {
			if ( $is_post_entity ) {
				clean_post_cache( (int) $tid );
			}
			$mod = $this->read_record_modified_gmt( $entity_slug, (int) $tid );
			if ( '' !== $mod ) {
				$modified_map[ (string) $tid ] = $mod;
			}
		}

		return [
			'updated'     => $updated,
			'errors'      => $errors,
			'conflicts'   => $conflicts,
			'modifiedMap' => $modified_map,
			'batchUuid'   => $batch['uuid'],
		];
	}

	/**
	 * Read the latest UTC modified timestamp for optimistic locking.
	 */
	private function read_record_modified_gmt( string $entity_slug, int $record_id ): string {
		if ( in_array( $entity_slug, [ 'user', 'shop_order', 'comment' ], true ) ) {
			return '';
		}
		$post = get_post( $record_id );
		return $post ? (string) $post->post_modified_gmt : '';
	}

	/**
	 * Dry-run preview for a bulk rule across either explicit IDs or a filtered QueryRequest.
	 *
	 * @param string               $entity_slug Entity slug.
	 * @param array<string, mixed> $rule        Rule config (field, action, value, find, replace, formula, rounding).
	 * @param array<int, int>      $target_ids  Explicit selected IDs (empty = use query_params).
	 * @param array<string, mixed> $query_params Filter params when applying to all matching rows.
	 * @return array<string, mixed>|WP_Error
	 */
	public function dry_run(
		string $entity_slug,
		array $rule,
		array $target_ids = [],
		array $query_params = []
	): array|WP_Error {
		$adapter = $this->entities->get( $entity_slug );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_unknown_entity', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$field_id = (string) ( $rule['field'] ?? '' );
		$field    = $this->fields->get_field( $entity_slug, $field_id );
		if ( ! $field || ! $field->editable ) {
			return new WP_Error( 'datadeck_invalid_field', __( 'Selected target field is not editable.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$compiled_ast = $this->maybe_compile_formula( $rule );
		if ( is_wp_error( $compiled_ast ) ) {
			return $compiled_ast;
		}

		$total_matching = 0;
		if ( ! empty( $target_ids ) ) {
			$preview_ids    = array_slice( array_map( 'intval', $target_ids ), 0, 10 );
			$total_matching = count( $target_ids );
		} else {
			$req            = QueryRequest::from_array(
				$entity_slug,
				array_merge(
					$query_params,
					[
						'page'     => 1,
						'per_page' => 10,
					]
				)
			);
			$q              = $adapter->query( $req );
			$preview_ids    = array_map( static fn( array $r ): int => (int) $r['id'], $q->items );
			$total_matching = $q->total;
		}

		$all_field_ids = array_keys( $this->fields->get_fields_for_entity( $entity_slug ) );
		$hydrated      = $adapter->hydrate_records( $preview_ids, $all_field_ids );
		$previews      = [];

		foreach ( $hydrated as $row ) {
			$oid      = (int) $row['id'];
			$old_val  = $row[ $field_id ] ?? null;
			$computed = $this->compute_new_value( $old_val, $row, $rule, $compiled_ast, $field->taxonomy );

			$previews[] = [
				'id'       => $oid,
				'title'    => (string) ( $row['title'] ?? $row['display_name'] ?? ( '#' . $oid ) ),
				'oldValue' => $old_val,
				'newValue' => is_wp_error( $computed ) ? null : $computed,
				'error'    => is_wp_error( $computed ) ? $computed->get_error_message() : null,
				'changed'  => ! is_wp_error( $computed ) && $computed !== $old_val,
			];
		}

		return [
			'totalMatching' => $total_matching,
			'field'         => $field->to_array(),
			'previews'      => $previews,
		];
	}

	/**
	 * Execute one adaptive chunk of a bulk rule with snapshot logging and cursor continuation.
	 *
	 * @param string               $entity_slug       Entity slug.
	 * @param array<string, mixed> $rule              Rule config.
	 * @param array<int, int>      $target_ids        Explicit selected IDs (empty = stream via query_params).
	 * @param array<string, mixed> $query_params      Filter query params.
	 * @param int                  $after_id          Keyset cursor (last processed ID).
	 * @param int                  $chunk_size        Adaptive batch size (10..250).
	 * @param string|null          $batch_uuid        Shared batch UUID across chunks.
	 * @param bool                 $bypass_capability True only when running scheduled system campaigns.
	 * @param string|null          $revert_at         Optional UTC auto-revert timestamp.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_chunk(
		string $entity_slug,
		array $rule,
		array $target_ids = [],
		array $query_params = [],
		int $after_id = 0,
		int $chunk_size = 50,
		?string $batch_uuid = null,
		bool $bypass_capability = false,
		?string $revert_at = null
	): array|WP_Error {
		$start   = microtime( true );
		$adapter = $this->entities->get( $entity_slug );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_unknown_entity', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$field_id = (string) ( $rule['field'] ?? '' );
		$field    = $this->fields->get_field( $entity_slug, $field_id );
		if ( ! $field || ! $field->editable ) {
			return new WP_Error( 'datadeck_invalid_field', __( 'Selected target field is not editable.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$compiled_ast = $this->maybe_compile_formula( $rule );
		if ( is_wp_error( $compiled_ast ) ) {
			return $compiled_ast;
		}

		$chunk_size = min( 250, max( 10, $chunk_size ) );

		if ( ! empty( $target_ids ) ) {
			$sorted = array_values( array_unique( array_map( 'intval', $target_ids ) ) );
			sort( $sorted );
			$remaining = array_values( array_filter( $sorted, static fn( int $id ): bool => $id > $after_id ) );
			$chunk_ids = array_slice( $remaining, 0, $chunk_size );
			$has_more  = count( $remaining ) > count( $chunk_ids );
		} else {
			$req       = QueryRequest::from_array( $entity_slug, $query_params );
			$chunk_ids = $adapter->stream_ids( $req, $after_id, $chunk_size );
			$has_more  = count( $chunk_ids ) >= $chunk_size;
		}

		if ( empty( $chunk_ids ) ) {
			return [
				'batchUuid'     => $batch_uuid ?? '',
				'processed'     => 0,
				'updated'       => 0,
				'lastId'        => $after_id,
				'hasMore'       => false,
				'errors'        => [],
				'durationMs'    => round( ( microtime( true ) - $start ) * 1000, 2 ),
				'nextChunkSize' => $chunk_size,
			];
		}

		$action  = sanitize_key( (string) ( $rule['action'] ?? 'set' ) );
		$summary = sprintf(
			'Bulk %s on %s.%s',
			$action,
			$entity_slug,
			$field->label
		);

		$batch = $this->history->open_batch(
			$entity_slug,
			'bulk',
			$summary,
			$batch_uuid,
			'applied',
			[],
			null,
			$revert_at
		);

		// Hydrate context needed for formulas/templates in 1 selective batch.
		$context_fields = $this->collect_context_fields( $entity_slug, $field_id, $rule );
		$hydrated       = $adapter->hydrate_records( $chunk_ids, $context_fields );
		$updated        = 0;
		$errors         = [];

		foreach ( $hydrated as $row ) {
			$oid = (int) $row['id'];
			if ( ! $bypass_capability && ! $adapter->can_edit( $oid ) ) {
				$errors[] = [
					'id'      => $oid,
					'message' => __( 'Permission denied.', 'datadeck' ),
				];
				continue;
			}

			$old_val  = $this->fields->read_value( $entity_slug, $oid, $field_id );
			$computed = $this->compute_new_value( $old_val, $row, $rule, $compiled_ast, $field->taxonomy );

			if ( is_wp_error( $computed ) ) {
				$errors[] = [
					'id'      => $oid,
					'message' => $computed->get_error_message(),
				];
				continue;
			}

			if ( $this->values_equal( $old_val, $computed ) ) {
				continue;
			}

			$write = $this->fields->write_value( $entity_slug, $oid, $field_id, $computed );
			if ( is_wp_error( $write ) ) {
				$errors[] = [
					'id'      => $oid,
					'message' => $write->get_error_message(),
				];
				continue;
			}

			$this->history->record_change( $batch['id'], $oid, $field_id, $old_val, $computed );
			++$updated;
		}

		$this->history->finalize_batch_count( $batch['id'] );

		$duration_ms = ( microtime( true ) - $start ) * 1000;
		// Adaptive batch sizer: keeps every HTTP chunk comfortably under 1.5s.
		$next_chunk = $chunk_size;
		if ( $duration_ms > 1500 && $chunk_size > 20 ) {
			$next_chunk = max( 20, (int) floor( $chunk_size * 0.6 ) );
		} elseif ( $duration_ms < 450 && $chunk_size < 200 ) {
			$next_chunk = min( 200, (int) ceil( $chunk_size * 1.4 ) );
		}

		return [
			'batchUuid'     => $batch['uuid'],
			'processed'     => count( $chunk_ids ),
			'updated'       => $updated,
			'lastId'        => (int) max( $chunk_ids ),
			'hasMore'       => $has_more,
			'errors'        => $errors,
			'durationMs'    => round( $duration_ms, 2 ),
			'nextChunkSize' => $next_chunk,
		];
	}

	/**
	 * Compute the transformed value for one record based on the rule definition.
	 *
	 * @param mixed                     $current_val  Existing field value.
	 * @param array<string, mixed>      $row_context  Full hydrated record context.
	 * @param array<string, mixed>      $rule         Rule specification.
	 * @param array<string, mixed>|null $compiled_ast Pre-compiled AST when action === 'formula'.
	 */
	private function compute_new_value(
		mixed $current_val,
		array $row_context,
		array $rule,
		?array $compiled_ast,
		string $taxonomy = ''
	): mixed {
		$action = sanitize_key( (string) ( $rule['action'] ?? 'set' ) );
		$value  = $rule['value'] ?? null;

		try {
			switch ( $action ) {
				case 'set':
					return $value;

				case 'clear':
					return '';

				case 'formula':
					if ( null === $compiled_ast ) {
						return new WP_Error( 'datadeck_missing_formula', __( 'Formula is required.', 'datadeck' ) );
					}
					return $this->formula->evaluate_ast( $compiled_ast, $row_context );

				case 'template':
					return $this->formula->render_template( (string) $value, $row_context );

				case 'math_percent':
					if ( null === $current_val || '' === $current_val ) {
						return $current_val;
					}
					$base = (float) $current_val;
					$pct  = (float) $value;
					$calc = $base * ( 1.0 + ( $pct / 100.0 ) );
					return $this->apply_rounding( $calc, (string) ( $rule['rounding'] ?? '2dec' ) );

				case 'math_fixed':
					if ( null === $current_val || '' === $current_val ) {
						return $current_val;
					}
					$base = (float) $current_val;
					$calc = $base + (float) $value;
					return $this->apply_rounding( $calc, (string) ( $rule['rounding'] ?? '2dec' ) );

				case 'round_ending':
					if ( null === $current_val || '' === $current_val ) {
						return $current_val;
					}
					$base   = (float) $current_val;
					$ending = (float) ( $value ?? 0.99 );
					return floor( $base ) + $ending;

				case 'prepend':
					return (string) $value . (string) $current_val;

				case 'append':
					return (string) $current_val . (string) $value;

				case 'find_replace':
					$find    = (string) ( $rule['find'] ?? '' );
					$replace = (string) ( $rule['replace'] ?? '' );
					$regex   = ! empty( $rule['regex'] );
					if ( '' === $find ) {
						return $current_val;
					}
					if ( $regex ) {
						$pattern = '/' . str_replace( '/', '\/', $find ) . '/u';
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$res = @preg_replace( $pattern, $replace, (string) $current_val );
						if ( null === $res ) {
							return new WP_Error( 'datadeck_invalid_regex', __( 'Invalid regular expression.', 'datadeck' ) );
						}
						return $res;
					}
					return str_replace( $find, $replace, (string) $current_val );

				case 'case_transform':
					$mode = sanitize_key( (string) $value );
					$str  = (string) $current_val;
					return match ( $mode ) {
						'upper' => function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $str ) : strtoupper( $str ),
						'lower' => function_exists( 'mb_strtolower' ) ? mb_strtolower( $str ) : strtolower( $str ),
						'title' => function_exists( 'mb_convert_case' ) ? mb_convert_case( $str, MB_CASE_TITLE, 'UTF-8' ) : ucwords( strtolower( $str ) ),
						default => $str,
					};

				case 'taxonomy_add':
					$cur_ids = $this->extract_ids( $current_val, $taxonomy );
					$add_ids = $this->extract_ids( $value, $taxonomy );
					return array_values( array_unique( array_merge( $cur_ids, $add_ids ) ) );

				case 'taxonomy_remove':
					$cur_ids = $this->extract_ids( $current_val, $taxonomy );
					$rem_ids = $this->extract_ids( $value, $taxonomy );
					return array_values( array_diff( $cur_ids, $rem_ids ) );

				case 'taxonomy_replace':
					return $this->extract_ids( $value, $taxonomy );

				case 'date_shift':
					$days = (int) $value;
					$ts   = strtotime( (string) $current_val );
					if ( false === $ts ) {
						$ts = time();
					}
					return gmdate( 'Y-m-d H:i:s', $ts + ( $days * DAY_IN_SECONDS ) );

				default:
					return new WP_Error( 'datadeck_unknown_action', sprintf( 'Unsupported bulk action "%s".', $action ) );
			}
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'datadeck_formula_error', $e->getMessage() );
		}
	}

	/**
	 * Apply optional psychological or decimal rounding to computed numbers.
	 */
	private function apply_rounding( float $num, string $mode ): float {
		$num = max( 0.0, $num );
		return match ( $mode ) {
			'int'  => (float) round( $num ),
			'ceil' => (float) ceil( $num ),
			'99'   => max( 0.99, floor( $num ) + 0.99 ),
			'95'   => max( 0.95, floor( $num ) + 0.95 ),
			default => round( $num, 2 ),
		};
	}

	/**
	 * Pre-compile AST once per request so 10,000 rows evaluate in microseconds.
	 *
	 * @param array<string, mixed> $rule Rule config.
	 * @return array<string, mixed>|null|WP_Error
	 */
	private function maybe_compile_formula( array $rule ): array|null|WP_Error {
		if ( 'formula' !== sanitize_key( (string) ( $rule['action'] ?? '' ) ) ) {
			return null;
		}
		$expr = (string) ( $rule['formula'] ?? $rule['value'] ?? '' );
		try {
			return $this->formula->compile( $expr );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'datadeck_formula_syntax', $e->getMessage(), [ 'status' => 400 ] );
		}
	}

	/**
	 * Determine minimal fields to hydrate for context evaluation.
	 *
	 * @param array<string, mixed> $rule Rule config.
	 * @return array<int, string>
	 */
	private function collect_context_fields( string $entity_slug, string $target_field, array $rule ): array {
		$action = sanitize_key( (string) ( $rule['action'] ?? '' ) );
		if ( in_array( $action, [ 'formula', 'template' ], true ) ) {
			return array_keys( $this->fields->get_fields_for_entity( $entity_slug ) );
		}
		return array_values( array_unique( [ 'id', 'title', $target_field ] ) );
	}

	/**
	 * Extract integer term IDs from arrays, comma-separated IDs, or comma-separated term names/slugs.
	 *
	 * @return array<int, int>
	 */
	private function extract_ids( mixed $val, string $taxonomy = '' ): array {
		$items = is_array( $val )
			? $val
			: array_filter( array_map( 'trim', explode( ',', (string) $val ) ), static fn( string $s ): bool => '' !== $s );

		$out = [];
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$out[] = (int) $item['id'];
			} elseif ( is_numeric( $item ) ) {
				$out[] = (int) $item;
			} elseif ( '' !== $taxonomy && is_string( $item ) && function_exists( 'get_term_by' ) ) {
				$term = get_term_by( 'name', $item, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					$term = get_term_by( 'slug', sanitize_title( $item ), $taxonomy );
				}
				if ( $term instanceof \WP_Term ) {
					$out[] = (int) $term->term_id;
				}
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	private function values_equal( mixed $a, mixed $b ): bool {
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return abs( (float) $a - (float) $b ) < 0.00001;
		}
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}
}
