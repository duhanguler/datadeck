<?php
/**
 * Smart Query Planner for WordPress Posts, CPTs, Media, and high-scale WooCommerce catalogs.
 *
 * Optimizations:
 *  - Routes WooCommerce price/stock/SKU/onsale filters & sorting to indexed wc_product_meta_lookup.
 *  - Eliminates SQL_CALC_FOUND_ROWS using two-step lean ID selection + fast COUNT(*).
 *  - Supports AND/OR filter groups, isEmpty/isNotEmpty, between, and inLastDays.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Query;

use DataDeck\Schema\FieldDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SmartQueryPlanner {

	/**
	 * WooCommerce fields backed by the indexed wc_product_meta_lookup table.
	 *
	 * @var array<string, string>
	 */
	private const WOO_LOOKUP_COLUMNS = [
		'regular_price'  => 'max_price',
		'price'          => 'min_price',
		'sku'            => 'sku',
		'stock_quantity' => 'stock_quantity',
		'stock_status'   => 'stock_status',
		'onsale'         => 'onsale',
		'total_sales'    => 'total_sales',
		'average_rating' => 'average_rating',
	];

	/**
	 * Native wp_posts columns eligible for direct SQL filtering/sorting.
	 *
	 * @var array<string, string>
	 */
	private const POST_COLUMNS = [
		'id'       => 'ID',
		'title'    => 'post_title',
		'slug'     => 'post_name',
		'status'   => 'post_status',
		'author'   => 'post_author',
		'date'     => 'post_date',
		'modified' => 'post_modified',
		'excerpt'  => 'post_excerpt',
		'order'    => 'menu_order',
		'parent'   => 'post_parent',
		'mime'     => 'post_mime_type',
	];

	private ?bool $has_woo_lookup = null;

	/**
	 * Execute a lean two-step post ID query and total count.
	 *
	 * @param QueryRequest                   $request    Sanitized query request.
	 * @param array<int, string>             $post_types Allowed post types.
	 * @param array<string, FieldDefinition> $field_map  Field definitions keyed by ID.
	 * @param array<int, string>             $statuses   Default post statuses if no status filter is supplied.
	 * @return array{ids: array<int, int>, total: int, plan: string}
	 */
	public function query_post_ids(
		QueryRequest $request,
		array $post_types,
		array $field_map,
		array $statuses = [ 'publish', 'draft', 'pending', 'future', 'private' ],
		int $after_id = 0
	): array {
		global $wpdb;

		$use_lookup = in_array( 'product', $post_types, true ) && $this->woo_lookup_exists();
		$plan_parts = [ 'two-step-id' ];

		$joins       = [];
		$where_base  = [];
		$params_base = [];

		// Post type filter.
		$pt_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$where_base[]    = "p.post_type IN ({$pt_placeholders})";
		array_push( $params_base, ...$post_types );

		// Keyset pagination cursor (placed outside user AND/OR filter group).
		if ( $after_id > 0 ) {
			$where_base[]  = 'p.ID > %d';
			$params_base[] = $after_id;
		}

		// Parent ID filter (for inline WooCommerce variation expansion).
		if ( $request->parent_id > 0 ) {
			$where_base[]  = 'p.post_parent = %d';
			$params_base[] = $request->parent_id;
		}

		// Default status filter unless explicitly filtered by status.
		$has_status_filter = false;
		foreach ( $request->filters as $f ) {
			if ( 'status' === ( $f['field'] ?? '' ) ) {
				$has_status_filter = true;
				break;
			}
		}
		if ( ! $has_status_filter && ! empty( $statuses ) ) {
			$st_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$where_base[]    = "p.post_status IN ({$st_placeholders})";
			array_push( $params_base, ...$statuses );
		}

		// Multi-author visibility guard for authenticated non-editor sessions (prevents leaking private/other-author drafts).
		if ( is_user_logged_in() && ! in_array( 'attachment', $post_types, true ) ) {
			$primary_pt = $post_types[0] ?? 'post';
			$pt_obj     = get_post_type_object( $primary_pt );
			$uid        = get_current_user_id();
			if ( $pt_obj ) {
				if ( ! current_user_can( $pt_obj->cap->edit_others_posts ) ) {
					$where_base[]  = "(p.post_status = 'publish' OR p.post_author = %d)";
					$params_base[] = $uid;
				} elseif ( ! current_user_can( $pt_obj->cap->read_private_posts ) ) {
					$where_base[]  = "(p.post_status <> 'private' OR p.post_author = %d)";
					$params_base[] = $uid;
				}
			}
		}

		// Search term (title, slug, ID, and SKU including numeric SKUs / EAN barcodes when Woo lookup exists).
		if ( '' !== $request->search ) {
			$like = '%' . $wpdb->esc_like( $request->search ) . '%';
			if ( ctype_digit( $request->search ) && $use_lookup ) {
				$joins['woo_lookup'] = "LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup AS wcl ON p.ID = wcl.product_id";
				$where_base[]        = '(p.ID = %d OR p.post_title LIKE %s OR p.post_name LIKE %s OR wcl.sku LIKE %s)';
				$params_base[]       = (int) $request->search;
				$params_base[]       = $like;
				$params_base[]       = $like;
				$params_base[]       = $like;
				$plan_parts[]        = 'woo-lookup-search';
			} elseif ( ctype_digit( $request->search ) ) {
				$where_base[]  = '(p.ID = %d OR p.post_title LIKE %s OR p.post_name LIKE %s)';
				$params_base[] = (int) $request->search;
				$params_base[] = $like;
				$params_base[] = $like;
			} elseif ( $use_lookup ) {
				$joins['woo_lookup'] = "LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup AS wcl ON p.ID = wcl.product_id";
				$where_base[]        = '(p.post_title LIKE %s OR p.post_name LIKE %s OR wcl.sku LIKE %s)';
				$params_base[]       = $like;
				$params_base[]       = $like;
				$params_base[]       = $like;
				$plan_parts[]        = 'woo-lookup-search';
			} else {
				$where_base[]  = '(p.post_title LIKE %s OR p.post_name LIKE %s)';
				$params_base[] = $like;
				$params_base[] = $like;
			}
		}

		// Structured filters (AND / OR group).
		$filter_clauses = [];
		$filter_params  = [];
		$meta_join_idx  = 0;

		foreach ( $request->filters as $filter ) {
			$field_id = (string) $filter['field'];
			$op       = (string) $filter['operator'];
			$val      = $filter['value'] ?? null;
			$val2     = $filter['value2'] ?? null;
			$field    = $field_map[ $field_id ] ?? null;

			if ( ! $field ) {
				continue;
			}

			// 1. WooCommerce lookup table shortcut (skip regular_price on filter because lookup max_price stores active _price on simple on-sale items).
			if ( $use_lookup && 'regular_price' !== $field_id && isset( self::WOO_LOOKUP_COLUMNS[ $field_id ] ) ) {
				$joins['woo_lookup'] = "LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup AS wcl ON p.ID = wcl.product_id";
				$col                 = 'wcl.' . self::WOO_LOOKUP_COLUMNS[ $field_id ];
				$built               = $this->build_column_condition( $col, $op, $val, $val2, in_array( $field->type, [ 'number', 'currency', 'integer' ], true ) );
				if ( $built ) {
					$filter_clauses[] = $built['sql'];
					array_push( $filter_params, ...$built['params'] );
					$plan_parts[] = 'woo-lookup-filter';
				}
				continue;
			}

			// 2. Native wp_posts column.
			if ( isset( self::POST_COLUMNS[ $field_id ] ) ) {
				$col   = 'p.' . self::POST_COLUMNS[ $field_id ];
				$built = $this->build_column_condition( $col, $op, $val, $val2, in_array( $field->type, [ 'integer', 'number' ], true ) );
				if ( $built ) {
					$filter_clauses[] = $built['sql'];
					array_push( $filter_params, ...$built['params'] );
				}
				continue;
			}

			// 3. Taxonomy filter via EXISTS / NOT EXISTS (avoids duplicate row explosion).
			if ( 'taxonomy' === $field->type && '' !== $field->taxonomy ) {
				$tax = $field->taxonomy;
				if ( 'isempty' === $op ) {
					$filter_clauses[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy = %s)";
					$filter_params[]  = $tax;
				} elseif ( 'isnotempty' === $op ) {
					$filter_clauses[] = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy = %s)";
					$filter_params[]  = $tax;
				} elseif ( null !== $val && '' !== $val ) {
					$raw_items = is_array( $val ) ? $val : explode( ',', (string) $val );
					$term_ids  = array_values( array_filter( array_map( 'intval', $raw_items ) ) );
					if ( ! empty( $term_ids ) ) {
						$in               = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
						$exists_kw        = in_array( $op, [ 'isnot', 'is_not', 'not_equals' ], true ) ? 'NOT EXISTS' : 'EXISTS';
						$filter_clauses[] = "{$exists_kw} (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy = %s AND tt.term_id IN ({$in}))";
						$filter_params[]  = $tax;
						array_push( $filter_params, ...$term_ids );
					}
				}
				continue;
			}

			// 4. Meta key filter (uses EXISTS for empty/non-empty or indexed alias join).
			if ( '' !== $field->meta_key ) {
				if ( 'isempty' === $op ) {
					$filter_clauses[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_e WHERE pm_e.post_id = p.ID AND pm_e.meta_key = %s AND pm_e.meta_value <> '')";
					$filter_params[]  = $field->meta_key;
					continue;
				}
				if ( 'isnotempty' === $op ) {
					$filter_clauses[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_ne WHERE pm_ne.post_id = p.ID AND pm_ne.meta_key = %s AND pm_ne.meta_value <> '')";
					$filter_params[]  = $field->meta_key;
					continue;
				}
				if ( 'boolean' === $field->type && in_array( $op, [ 'is', 'equals', 'isnot', 'is_not', 'not_equals' ], true ) ) {
					$want_true = filter_var( $val, FILTER_VALIDATE_BOOLEAN );
					if ( in_array( $op, [ 'isnot', 'is_not', 'not_equals' ], true ) ) {
						$want_true = ! $want_true;
					}
					if ( 'rank_math_robots' === $field->meta_key ) {
						$kw               = $want_true ? 'EXISTS' : 'NOT EXISTS';
						$filter_clauses[] = "{$kw} (SELECT 1 FROM {$wpdb->postmeta} pm_b WHERE pm_b.post_id = p.ID AND pm_b.meta_key = %s AND pm_b.meta_value LIKE %s)";
						$filter_params[]  = $field->meta_key;
						$filter_params[]  = '%noindex%';
					} else {
						$kw               = $want_true ? 'EXISTS' : 'NOT EXISTS';
						$filter_clauses[] = "{$kw} (SELECT 1 FROM {$wpdb->postmeta} pm_b WHERE pm_b.post_id = p.ID AND pm_b.meta_key = %s AND LOWER(pm_b.meta_value) IN ('1', 'yes', 'true'))";
						$filter_params[]  = $field->meta_key;
					}
					continue;
				}

				++$meta_join_idx;
				$alias = 'pmf' . $meta_join_idx;
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$joins[ $alias ] = $wpdb->prepare(
					"LEFT JOIN {$wpdb->postmeta} AS {$alias} ON p.ID = {$alias}.post_id AND {$alias}.meta_key = %s",
					$field->meta_key
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$is_num     = in_array( $field->type, [ 'number', 'currency', 'integer' ], true );
				$target_col = $is_num
					? "CASE WHEN {$alias}.meta_value REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN CAST({$alias}.meta_value AS DECIMAL(18,4)) ELSE NULL END"
					: "{$alias}.meta_value";
				$built      = $this->build_column_condition( $target_col, $op, $val, $val2, $is_num );
				if ( $built ) {
					$filter_clauses[] = $built['sql'];
					array_push( $filter_params, ...$built['params'] );
				}
			}
		}

		// Built-in Audit Lenses (special SQL conditions like sale_price >= regular_price).
		$lens_sql = $this->build_lens_clause( $request->lens, $use_lookup, $joins );
		if ( null !== $lens_sql ) {
			$where_base[] = $lens_sql['sql'];
			array_push( $params_base, ...$lens_sql['params'] );
			$plan_parts[] = 'lens:' . $request->lens;
		}

		// Combine WHERE.
		$where_sql = implode( ' AND ', $where_base );
		$params    = $params_base;
		if ( ! empty( $filter_clauses ) ) {
			$glue       = 'OR' === $request->filter_relation ? ' OR ' : ' AND ';
			$where_sql .= ' AND (' . implode( $glue, $filter_clauses ) . ')';
			array_push( $params, ...$filter_params );
		}

		// ORDER BY resolution (MySQL 8 ONLY_FULL_GROUP_BY compliant via GROUP BY p.ID + aggregate on joined sort expressions).
		$dir      = 'asc' === $request->sort_direction ? 'ASC' : 'DESC';
		$agg_fn   = 'asc' === $request->sort_direction ? 'MIN' : 'MAX';
		$order_by = "p.ID {$dir}";
		$sort_def = $field_map[ $request->sort_field ] ?? null;

		if ( $use_lookup && isset( self::WOO_LOOKUP_COLUMNS[ $request->sort_field ] ) ) {
			$joins['woo_lookup'] = "LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup AS wcl ON p.ID = wcl.product_id";
			$lookup_col          = 'wcl.' . self::WOO_LOOKUP_COLUMNS[ $request->sort_field ];
			$order_by            = "{$agg_fn}({$lookup_col}) {$dir}, p.ID {$dir}";
			$plan_parts[]        = 'woo-lookup-sort';
		} elseif ( isset( self::POST_COLUMNS[ $request->sort_field ] ) ) {
			$order_by = 'p.' . self::POST_COLUMNS[ $request->sort_field ] . " {$dir}, p.ID {$dir}";
		} elseif ( $sort_def && '' !== $sort_def->meta_key ) {
			$joins['pmsort'] = $wpdb->prepare(
				"LEFT JOIN {$wpdb->postmeta} AS pmsort ON p.ID = pmsort.post_id AND pmsort.meta_key = %s",
				$sort_def->meta_key
			);
			$is_num          = in_array( $sort_def->type, [ 'number', 'currency', 'integer' ], true );
			$sort_col        = $is_num
				? "CASE WHEN pmsort.meta_value REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN CAST(pmsort.meta_value AS DECIMAL(18,4)) ELSE NULL END"
				: 'pmsort.meta_value';
			$order_by        = "{$agg_fn}({$sort_col}) {$dir}, p.ID {$dir}";
		}

		$join_sql = implode( ' ', array_values( $joins ) );
		$offset   = ( $request->page - 1 ) * $request->per_page;

		// Step 1a: Fast grouped ID query (no SQL_CALC_FOUND_ROWS, MySQL 8 ONLY_FULL_GROUP_BY safe).
		$id_params  = array_merge( $params, [ $request->per_page, $offset ] );
		$id_sql_raw = "SELECT p.ID FROM {$wpdb->posts} AS p {$join_sql} WHERE {$where_sql} GROUP BY p.ID ORDER BY {$order_by} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$id_sql = $wpdb->prepare( $id_sql_raw, ...$id_params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $id_sql ) );

		// Step 1b: Lean COUNT(DISTINCT p.ID) query.
		$count_sql_raw = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} AS p {$join_sql} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = ! empty( $params ) ? $wpdb->prepare( $count_sql_raw, ...$params ) : $count_sql_raw;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $count_sql );

		return [
			'ids'   => $ids,
			'total' => $total,
			'plan'  => implode( '+', array_unique( $plan_parts ) ),
		];
	}

	/**
	 * Keyset-paginated ID fetcher (`p.ID > %d`) for O(1) memory streaming exports & bulk batches.
	 * Preserves user AND/OR filter relations intact across every chunk.
	 *
	 * @param QueryRequest                   $request    Sanitized query request.
	 * @param array<int, string>             $post_types Post types.
	 * @param array<string, FieldDefinition> $field_map  Field map.
	 * @param int                            $after_id   Last processed ID.
	 * @param int                            $limit      Chunk size.
	 * @param array<int, string>             $statuses   Default statuses.
	 * @return array<int, int>
	 */
	public function stream_post_ids(
		QueryRequest $request,
		array $post_types,
		array $field_map,
		int $after_id,
		int $limit,
		array $statuses = [ 'publish', 'draft', 'pending', 'future', 'private' ]
	): array {
		$stream_request = new QueryRequest(
			entity: $request->entity,
			page: 1,
			per_page: $limit,
			search: $request->search,
			sort_field: 'id',
			sort_direction: 'asc',
			filters: $request->filters,
			filter_relation: $request->filter_relation,
			fields: $request->fields,
			lens: $request->lens,
			parent_id: $request->parent_id,
			flatten_variations: $request->flatten_variations,
		);

		return $this->query_post_ids( $stream_request, $post_types, $field_map, $statuses, $after_id )['ids'];
	}

	/**
	 * Build a prepared SQL fragment for a single column condition.
	 *
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private function build_column_condition( string $col, string $op, mixed $val, mixed $val2, bool $is_numeric ): ?array {
		global $wpdb;

		$placeholder = $is_numeric ? '%f' : '%s';
		$cast_val    = $is_numeric ? (float) $val : (string) $val;

		return match ( $op ) {
			'is', 'equals'               => [
				'sql'    => "{$col} = {$placeholder}",
				'params' => [ $cast_val ],
			],
			'isnot', 'is_not', 'not_equals' => [
				'sql'    => "({$col} IS NULL OR {$col} <> {$placeholder})",
				'params' => [ $cast_val ],
			],
			'contains'    => [
				'sql'    => "{$col} LIKE %s",
				'params' => [ '%' . $wpdb->esc_like( (string) $val ) . '%' ],
			],
			'notcontains' => [
				'sql'    => "({$col} IS NULL OR {$col} NOT LIKE %s)",
				'params' => [ '%' . $wpdb->esc_like( (string) $val ) . '%' ],
			],
			'gt'          => [
				'sql'    => "{$col} > {$placeholder}",
				'params' => [ $cast_val ],
			],
			'gte'         => [
				'sql'    => "{$col} >= {$placeholder}",
				'params' => [ $cast_val ],
			],
			'lt'          => [
				'sql'    => "{$col} < {$placeholder}",
				'params' => [ $cast_val ],
			],
			'lte'         => [
				'sql'    => "{$col} <= {$placeholder}",
				'params' => [ $cast_val ],
			],
			'between'     => [
				'sql'    => "{$col} BETWEEN {$placeholder} AND {$placeholder}",
				'params' => [ $cast_val, $is_numeric ? (float) $val2 : (string) $val2 ],
			],
			'isempty'     => [
				'sql'    => $is_numeric ? "{$col} IS NULL" : "({$col} IS NULL OR {$col} = '')",
				'params' => [],
			],
			'isnotempty'  => [
				'sql'    => $is_numeric ? "{$col} IS NOT NULL" : "({$col} IS NOT NULL AND {$col} <> '')",
				'params' => [],
			],
			'inlastdays'  => [
				'sql'    => "{$col} >= %s",
				'params' => [ gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $val ) * DAY_IN_SECONDS ) ) ],
			],
			default       => null,
		};
	}

	/**
	 * Specialized SQL clauses for built-in Smart Audit Lenses.
	 *
	 * @param string                $lens       Lens ID.
	 * @param bool                  $use_lookup Whether wc_product_meta_lookup is available.
	 * @param array<string, string> $joins      Active JOIN map (by reference).
	 * @return array{sql: string, params: array<int, mixed>}|null
	 */
	private function build_lens_clause( string $lens, bool $use_lookup, array &$joins ): ?array {
		global $wpdb;

		if ( '' === $lens || 'all' === $lens ) {
			return null;
		}

		if ( 'seo_audit' === $lens ) {
			return [
				'sql'    => "(CHAR_LENGTH(p.post_title) > 60 OR NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_t WHERE pm_t.post_id = p.ID AND pm_t.meta_key = '_thumbnail_id' AND pm_t.meta_value <> '') OR p.post_excerpt = '')",
				'params' => [],
			];
		}

		if ( 'content_hygiene' === $lens ) {
			return [
				'sql'    => "(p.post_status IN ('draft', 'pending') OR p.post_excerpt = '')",
				'params' => [],
			];
		}

		if ( 'woo_margin_audit' === $lens ) {
			if ( $use_lookup ) {
				$joins['woo_lookup'] = "LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup AS wcl ON p.ID = wcl.product_id";
				return [
					'sql'    => "(wcl.min_price IS NULL OR wcl.min_price <= 0 OR wcl.sku IS NULL OR wcl.sku = '' OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_sp INNER JOIN {$wpdb->postmeta} pm_rp ON pm_rp.post_id = pm_sp.post_id AND pm_rp.meta_key = '_regular_price' WHERE pm_sp.post_id = p.ID AND pm_sp.meta_key = '_sale_price' AND pm_sp.meta_value <> '' AND pm_rp.meta_value <> '' AND CAST(pm_sp.meta_value AS DECIMAL(18,4)) >= CAST(pm_rp.meta_value AS DECIMAL(18,4))))",
					'params' => [],
				];
			}
			return null;
		}

		if ( 'woo_inventory_risk' === $lens ) {
			if ( $use_lookup ) {
				$joins['woo_lookup'] = "LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup AS wcl ON p.ID = wcl.product_id";
				return [
					'sql'    => "(wcl.stock_status = 'outofstock' OR (wcl.stock_quantity IS NOT NULL AND wcl.stock_quantity <= 5))",
					'params' => [],
				];
			}
			return null;
		}

		if ( 'woo_missing_media' === $lens ) {
			return [
				'sql'    => "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_img WHERE pm_img.post_id = p.ID AND pm_img.meta_key = '_thumbnail_id' AND pm_img.meta_value <> '')",
				'params' => [],
			];
		}

		if ( 'media_missing_alt' === $lens ) {
			return [
				'sql'    => "(p.post_mime_type LIKE 'image/%' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_alt WHERE pm_alt.post_id = p.ID AND pm_alt.meta_key = '_wp_attachment_image_alt' AND pm_alt.meta_value <> ''))",
				'params' => [],
			];
		}

		return null;
	}

	/**
	 * Cached existence check for WooCommerce's indexed lookup table.
	 */
	private function woo_lookup_exists(): bool {
		if ( null !== $this->has_woo_lookup ) {
			return $this->has_woo_lookup;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_product_meta_lookup';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found                = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->has_woo_lookup = ( $found === $table );

		return $this->has_woo_lookup;
	}
}
