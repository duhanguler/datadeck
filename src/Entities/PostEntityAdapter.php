<?php
/**
 * Universal WordPress Post, Page, and Custom Post Type Entity Adapter.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Entities;

use DataDeck\Contracts\EntityAdapterInterface;
use DataDeck\Fields\FieldRegistry;
use DataDeck\Query\QueryRequest;
use DataDeck\Query\QueryResult;
use DataDeck\Query\SelectiveHydrator;
use DataDeck\Query\SmartQueryPlanner;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostEntityAdapter implements EntityAdapterInterface {

	public function __construct(
		protected readonly string $post_type,
		protected readonly FieldRegistry $fields,
		protected readonly SmartQueryPlanner $planner,
		protected readonly SelectiveHydrator $hydrator,
	) {}

	public function get_slug(): string {
		return $this->post_type;
	}

	public function get_label(): string {
		$obj = get_post_type_object( $this->post_type );
		return $obj ? (string) $obj->labels->singular_name : ucfirst( $this->post_type );
	}

	public function get_plural_label(): string {
		$obj = get_post_type_object( $this->post_type );
		return $obj ? (string) $obj->labels->name : ucfirst( $this->post_type );
	}

	public function get_group(): string {
		return 'content';
	}

	public function is_available(): bool {
		return post_type_exists( $this->post_type );
	}

	public function can_read(): bool {
		$obj = get_post_type_object( $this->post_type );
		if ( ! $obj ) {
			return false;
		}
		return current_user_can( $obj->cap->edit_posts );
	}

	public function can_edit( int $record_id ): bool {
		return current_user_can( 'edit_post', $record_id );
	}

	public function can_create(): bool {
		$obj = get_post_type_object( $this->post_type );
		return $obj ? current_user_can( $obj->cap->create_posts ) : false;
	}

	public function can_delete( int $record_id ): bool {
		return current_user_can( 'delete_post', $record_id );
	}

	public function query( QueryRequest $request ): QueryResult {
		global $wpdb;

		$field_map = $this->fields->get_fields_for_entity( $this->get_slug(), false );
		$projected = ! empty( $request->fields )
			? $request->fields
			: $this->fields->get_default_field_ids( $this->get_slug() );

		$start_time    = microtime( true );
		$start_queries = (int) $wpdb->num_queries;

		if ( ! in_array( 'id', $projected, true ) ) {
			array_unshift( $projected, 'id' );
		}

		$this->fields->set_include_options( false );
		try {
			$planned = $this->planner->query_post_ids(
				$request,
				$this->get_query_post_types( $request ),
				$field_map
			);

			$items = $this->hydrate_records( $planned['ids'], $projected );
		} finally {
			$this->fields->set_include_options( true );
		}
		$total       = $planned['total'];
		$total_pages = max( 1, (int) ceil( $total / $request->per_page ) );

		return new QueryResult(
			items: $items,
			total: $total,
			total_pages: $total_pages,
			page: $request->page,
			per_page: $request->per_page,
			query_count: max( 0, (int) $wpdb->num_queries - $start_queries ),
			duration_ms: ( microtime( true ) - $start_time ) * 1000,
			query_plan: $planned['plan'],
		);
	}

	public function stream_ids( QueryRequest $request, int $after_id, int $limit ): array {
		$field_map = $this->fields->get_fields_for_entity( $this->get_slug(), false );
		return $this->planner->stream_post_ids(
			$request,
			$this->get_query_post_types( $request ),
			$field_map,
			$after_id,
			$limit
		);
	}

	public function hydrate_records( array $ids, array $field_ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		// Prime wp_posts core objects in 1 single query without loading all postmeta blobs.
		_prime_post_caches( $ids, false, false );

		// Selective meta hydration: ONLY keys strictly needed by projected columns.
		$meta_keys  = $this->fields->collect_required_meta_keys( $this->get_slug(), $field_ids );
		$meta_cache = $this->hydrator->hydrate_post_meta( $ids, $meta_keys );

		// Selective taxonomy hydration: 1 single SQL query for ONLY projected taxonomy columns.
		$taxonomies = $this->fields->collect_required_taxonomies( $this->get_slug(), $field_ids );
		$term_cache = $this->hydrator->hydrate_terms( $ids, $taxonomies );
		if ( ! empty( $term_cache ) ) {
			foreach ( $term_cache as $oid => $tax_map ) {
				$meta_cache[ $oid ]['__rc_terms'] = [ $tax_map ];
			}
		}

		$rows = [];
		foreach ( $ids as $id ) {
			$post        = get_post( $id );
			$rows[ $id ] = [
				'id'          => $id,
				'_editUrl'    => $this->get_edit_url( $id ),
				'_viewUrl'    => $this->get_view_url( $id ),
				'_canEdit'    => $this->can_edit( $id ),
				'_modifiedAt' => $post ? (string) $post->post_modified_gmt : '',
			];
		}

		$this->fields->hydrate_fields( $this->get_slug(), $ids, $field_ids, $rows, $meta_cache );

		$this->enrich_hydrated_rows( $ids, $rows );

		return array_values( $rows );
	}

	public function create_record( array $data ): int|WP_Error {
		if ( ! $this->can_create() ) {
			return new WP_Error( 'datadeck_cannot_create', __( 'You do not have permission to create records.', 'datadeck' ), [ 'status' => 403 ] );
		}

		$title  = sanitize_text_field( (string) ( $data['title'] ?? __( 'Untitled', 'datadeck' ) ) );
		$status = sanitize_key( (string) ( $data['status'] ?? 'draft' ) );
		if ( ! in_array( $status, [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
			$status = 'draft';
		}
		$pt_obj = get_post_type_object( $this->post_type );
		if ( in_array( $status, [ 'publish', 'private' ], true ) && $pt_obj && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
			$status = 'draft';
		}

		$post_id = wp_insert_post(
			[
				'post_type'   => $this->post_type,
				'post_title'  => $title,
				'post_status' => $status,
				'post_author' => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		foreach ( $data as $field_id => $value ) {
			if ( in_array( $field_id, [ 'id', 'title', 'status' ], true ) ) {
				continue;
			}
			$this->fields->write_value( $this->get_slug(), (int) $post_id, (string) $field_id, $value );
		}

		return (int) $post_id;
	}

	public function duplicate_record( int $record_id ): int|WP_Error {
		if ( ! $this->can_create() || ! $this->can_edit( $record_id ) ) {
			return new WP_Error( 'datadeck_cannot_duplicate', __( 'Permission denied.', 'datadeck' ), [ 'status' => 403 ] );
		}

		$source = get_post( $record_id );
		if ( ! $source instanceof WP_Post ) {
			return new WP_Error( 'datadeck_not_found', __( 'Record not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$new_id = wp_insert_post(
			[
				'post_type'      => $source->post_type,
				'post_title'     => $source->post_title . ' ' . __( '(Copy)', 'datadeck' ),
				'post_content'   => $source->post_content,
				'post_excerpt'   => $source->post_excerpt,
				'post_status'    => 'draft',
				'post_author'    => get_current_user_id(),
				'comment_status' => $source->comment_status,
				'menu_order'     => $source->menu_order,
			],
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy taxonomies.
		foreach ( get_object_taxonomies( $source->post_type ) as $tax ) {
			$term_ids = wp_get_object_terms( $record_id, $tax, [ 'fields' => 'ids' ] );
			if ( is_array( $term_ids ) && ! empty( $term_ids ) ) {
				wp_set_object_terms( (int) $new_id, array_map( 'intval', $term_ids ), $tax );
			}
		}

		// Copy metadata (excluding internal lock/slug-history keys).
		$meta = get_post_meta( $record_id );
		$skip = [ '_edit_lock', '_edit_last', '_wp_old_slug' ];
		if ( is_array( $meta ) ) {
			foreach ( $meta as $key => $values ) {
				if ( in_array( $key, $skip, true ) ) {
					continue;
				}
				foreach ( (array) $values as $val ) {
					add_post_meta( (int) $new_id, (string) $key, maybe_unserialize( $val ) );
				}
			}
		}

		return (int) $new_id;
	}

	public function delete_records( array $ids ): array {
		$deleted = [];
		$errors  = [];

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! $this->can_delete( $id ) ) {
				$errors[] = [
					'id'      => $id,
					'message' => __( 'Permission denied.', 'datadeck' ),
				];
				continue;
			}
			$res = wp_trash_post( $id );
			if ( false === $res || null === $res ) {
				$errors[] = [
					'id'      => $id,
					'message' => __( 'Failed to move to trash.', 'datadeck' ),
				];
			} else {
				$deleted[] = $id;
			}
		}

		return [
			'deleted' => $deleted,
			'errors'  => $errors,
		];
	}

	public function get_edit_url( int $record_id ): string {
		return (string) get_edit_post_link( $record_id, 'raw' );
	}

	public function get_view_url( int $record_id ): string {
		return (string) get_permalink( $record_id );
	}

	/**
	 * Post types queried for this adapter (overridden by WooProductEntityAdapter for variations).
	 *
	 * @return array<int, string>
	 */
	protected function get_query_post_types( QueryRequest $request ): array {
		return [ $this->post_type ];
	}

	/**
	 * Hook for subclasses to attach extra lightweight row metadata.
	 *
	 * @param array<int, int>                  $ids  Hydrated IDs.
	 * @param array<int, array<string, mixed>> $rows Rows keyed by ID.
	 */
	protected function enrich_hydrated_rows( array $ids, array &$rows ): void {
		// No-op in base post adapter.
	}
}
