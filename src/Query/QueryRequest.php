<?php
/**
 * Validated Query Request DTO for record retrieval, filtering, sorting, and projection.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueryRequest {

	/**
	 * Constructor.
	 *
	 * @param string                             $entity             Entity slug.
	 * @param int                                $page               1-indexed page number.
	 * @param int                                $per_page           Page size (1..500).
	 * @param string                             $search             Full-text search term.
	 * @param string                             $sort_field         Field ID to sort by.
	 * @param string                             $sort_direction     'asc' or 'desc'.
	 * @param array<int, array<string, mixed>>   $filters            Structured filter clauses.
	 * @param string                             $filter_relation    'AND' or 'OR'.
	 * @param array<int, string>                 $fields             Projected field IDs to return.
	 * @param string                             $lens               Optional built-in audit lens ID.
	 * @param int                                $parent_id          Optional parent ID (for inline WooCommerce variations).
	 * @param bool                               $flatten_variations Whether to include variations directly in main grid.
	 */
	public function __construct(
		public readonly string $entity,
		public readonly int $page = 1,
		public readonly int $per_page = 50,
		public readonly string $search = '',
		public readonly string $sort_field = 'id',
		public readonly string $sort_direction = 'desc',
		public readonly array $filters = [],
		public readonly string $filter_relation = 'AND',
		public readonly array $fields = [],
		public readonly string $lens = '',
		public readonly int $parent_id = 0,
		public readonly bool $flatten_variations = false,
	) {}

	/**
	 * Create a sanitized QueryRequest from an associative array (REST or CLI params).
	 *
	 * @param string               $entity Entity slug.
	 * @param array<string, mixed> $params Input parameters.
	 */
	public static function from_array( string $entity, array $params ): self {
		$page       = max( 1, (int) ( $params['page'] ?? 1 ) );
		$per_page   = min( 500, max( 1, (int) ( $params['per_page'] ?? 50 ) ) );
		$search     = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';
		$sort_raw   = isset( $params['sort_field'] ) ? (string) $params['sort_field'] : 'id';
		$sort_field = (string) preg_replace( '/[^a-zA-Z0-9_\-:]/', '', $sort_raw );
		$sort_dir   = strtolower( (string) ( $params['sort_direction'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc';
		$relation   = strtoupper( (string) ( $params['filter_relation'] ?? 'AND' ) ) === 'OR' ? 'OR' : 'AND';
		$lens       = isset( $params['lens'] ) ? sanitize_key( (string) $params['lens'] ) : '';
		$parent_id  = max( 0, (int) ( $params['parent_id'] ?? 0 ) );
		$flatten    = ! empty( $params['flatten_variations'] );

		$raw_fields = $params['fields'] ?? [];
		if ( is_string( $raw_fields ) ) {
			$raw_fields = array_filter( array_map( 'trim', explode( ',', $raw_fields ) ) );
		}
		$fields = [];
		if ( is_array( $raw_fields ) ) {
			foreach ( $raw_fields as $field_id ) {
				$clean = preg_replace( '/[^a-zA-Z0-9_\-:]/', '', (string) $field_id );
				if ( is_string( $clean ) && '' !== $clean ) {
					$fields[] = $clean;
				}
			}
		}

		$raw_filters = $params['filters'] ?? [];
		if ( is_string( $raw_filters ) && '' !== $raw_filters ) {
			$decoded     = json_decode( $raw_filters, true );
			$raw_filters = is_array( $decoded ) ? $decoded : [];
		}

		$filters = [];
		if ( is_array( $raw_filters ) ) {
			foreach ( $raw_filters as $filter ) {
				if ( ! is_array( $filter ) || empty( $filter['field'] ) || empty( $filter['operator'] ) ) {
					continue;
				}
				$field_clean = preg_replace( '/[^a-zA-Z0-9_\-:]/', '', (string) $filter['field'] );
				$op_clean    = sanitize_key( (string) $filter['operator'] );
				if ( is_string( $field_clean ) && '' !== $field_clean && '' !== $op_clean ) {
					$filters[] = [
						'field'    => $field_clean,
						'operator' => $op_clean,
						'value'    => $filter['value'] ?? null,
						'value2'   => $filter['value2'] ?? null,
					];
				}
			}
		}

		return new self(
			entity: sanitize_key( $entity ),
			page: $page,
			per_page: $per_page,
			search: $search,
			sort_field: '' !== $sort_field ? $sort_field : 'id',
			sort_direction: $sort_dir,
			filters: $filters,
			filter_relation: $relation,
			fields: array_values( array_unique( $fields ) ),
			lens: $lens,
			parent_id: $parent_id,
			flatten_variations: $flatten,
		);
	}
}
