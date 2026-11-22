<?php
/**
 * WordPress User Entity Adapter.
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
use WP_Error;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UserEntityAdapter implements EntityAdapterInterface {

	public function __construct(
		private readonly FieldRegistry $fields,
		private readonly SelectiveHydrator $hydrator,
	) {}

	public function get_slug(): string {
		return 'user';
	}

	public function get_label(): string {
		return __( 'User', 'datadeck' );
	}

	public function get_plural_label(): string {
		return __( 'Users', 'datadeck' );
	}

	public function get_group(): string {
		return 'system';
	}

	public function is_available(): bool {
		return true;
	}

	public function can_read(): bool {
		return current_user_can( 'list_users' );
	}

	public function can_edit( int $record_id ): bool {
		return current_user_can( 'edit_user', $record_id );
	}

	public function can_create(): bool {
		return false;
	}

	public function can_delete( int $record_id ): bool {
		return current_user_can( 'delete_users' ) && get_current_user_id() !== $record_id;
	}

	public function query( QueryRequest $request ): QueryResult {
		global $wpdb;

		$start_time    = microtime( true );
		$start_queries = (int) $wpdb->num_queries;

		$projected = ! empty( $request->fields )
			? $request->fields
			: $this->fields->get_default_field_ids( 'user' );

		if ( ! in_array( 'id', $projected, true ) ) {
			array_unshift( $projected, 'id' );
		}

		$orderby = match ( $request->sort_field ) {
			'user_login'   => 'user_login',
			'display_name' => 'display_name',
			'user_email'   => 'user_email',
			'registered'   => 'user_registered',
			default        => 'ID',
		};

		$args = [
			'fields'      => 'ID',
			'number'      => $request->per_page,
			'paged'       => $request->page,
			'orderby'     => $orderby,
			'order'       => strtoupper( $request->sort_direction ),
			'count_total' => true,
		];

		if ( '' !== $request->search ) {
			$args['search']         = '*' . $request->search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}

		foreach ( $request->filters as $f ) {
			if ( 'role' === ( $f['field'] ?? '' ) && 'is' === ( $f['operator'] ?? '' ) && ! empty( $f['value'] ) ) {
				$args['role'] = sanitize_key( (string) $f['value'] );
			}
		}

		$uq    = new WP_User_Query( $args );
		$ids   = array_map( 'intval', (array) $uq->get_results() );
		$total = (int) $uq->get_total();

		return new QueryResult(
			items: $this->hydrate_records( $ids, $projected ),
			total: $total,
			total_pages: max( 1, (int) ceil( $total / $request->per_page ) ),
			page: $request->page,
			per_page: $request->per_page,
			query_count: max( 0, (int) $wpdb->num_queries - $start_queries ),
			duration_ms: ( microtime( true ) - $start_time ) * 1000,
			query_plan: 'user-id-hydrator',
		);
	}

	public function stream_ids( QueryRequest $request, int $after_id, int $limit ): array {
		global $wpdb;

		$args = [
			'fields'      => 'ID',
			'number'      => max( 1, min( 1000, $limit ) ),
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'count_total' => false,
		];

		if ( '' !== $request->search ) {
			$args['search']         = '*' . $request->search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}

		foreach ( $request->filters as $f ) {
			if ( 'role' === ( $f['field'] ?? '' ) && in_array( (string) ( $f['operator'] ?? '' ), [ 'is', 'equals' ], true ) && ! empty( $f['value'] ) ) {
				$args['role'] = sanitize_key( (string) $f['value'] );
			}
		}

		$cursor_hook = static function ( \WP_User_Query $uq ) use ( $after_id, $wpdb ): void {
			if ( $after_id > 0 ) {
				$uq->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d", $after_id );
			}
		};

		add_action( 'pre_user_query', $cursor_hook );
		try {
			$uq  = new WP_User_Query( $args );
			$ids = array_map( 'intval', (array) $uq->get_results() );
		} finally {
			remove_action( 'pre_user_query', $cursor_hook );
		}

		return $ids;
	}

	public function hydrate_records( array $ids, array $field_ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		cache_users( $ids );
		$meta_keys  = $this->fields->collect_required_meta_keys( 'user', $field_ids );
		$meta_cache = $this->hydrator->hydrate_user_meta( $ids, $meta_keys );

		$rows = [];
		foreach ( $ids as $id ) {
			$rows[ $id ] = [
				'id'       => $id,
				'_editUrl' => $this->get_edit_url( $id ),
				'_viewUrl' => $this->get_view_url( $id ),
				'_canEdit' => $this->can_edit( $id ),
			];
		}

		$this->fields->hydrate_fields( 'user', $ids, $field_ids, $rows, $meta_cache );

		return array_values( $rows );
	}

	public function create_record( array $data ): WP_Error {
		return new WP_Error( 'datadeck_unsupported', __( 'Create users via Users > Add New.', 'datadeck' ), [ 'status' => 400 ] );
	}

	public function duplicate_record( int $record_id ): WP_Error {
		return new WP_Error( 'datadeck_unsupported', __( 'Users cannot be duplicated.', 'datadeck' ), [ 'status' => 400 ] );
	}

	public function delete_records( array $ids ): array {
		return [
			'deleted' => [],
			'errors'  => array_map(
				static fn( int $id ): array => [
					'id'      => $id,
					'message' => __( 'Delete users via the Users screen for content reassignment.', 'datadeck' ),
				],
				$ids
			),
		];
	}

	public function get_edit_url( int $record_id ): string {
		return get_edit_user_link( $record_id );
	}

	public function get_view_url( int $record_id ): string {
		return get_author_posts_url( $record_id );
	}
}
