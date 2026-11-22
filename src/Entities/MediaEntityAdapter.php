<?php
/**
 * WordPress Media Library Entity Adapter.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Entities;

use DataDeck\Fields\FieldRegistry;
use DataDeck\Query\QueryRequest;
use DataDeck\Query\QueryResult;
use DataDeck\Query\SelectiveHydrator;
use DataDeck\Query\SmartQueryPlanner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaEntityAdapter extends PostEntityAdapter {

	public function __construct(
		FieldRegistry $fields,
		SmartQueryPlanner $planner,
		SelectiveHydrator $hydrator,
	) {
		parent::__construct( 'attachment', $fields, $planner, $hydrator );
	}

	public function get_label(): string {
		return __( 'Media Item', 'datadeck' );
	}

	public function get_plural_label(): string {
		return __( 'Media Library', 'datadeck' );
	}

	public function get_group(): string {
		return 'content';
	}

	public function can_read(): bool {
		return current_user_can( 'upload_files' );
	}

	public function can_create(): bool {
		return false;
	}

	public function query( QueryRequest $request ): QueryResult {
		global $wpdb;

		$start_time    = microtime( true );
		$start_queries = (int) $wpdb->num_queries;

		$field_map = $this->fields->get_fields_for_entity( 'attachment' );
		$projected = ! empty( $request->fields )
			? $request->fields
			: $this->fields->get_default_field_ids( 'attachment' );

		if ( ! in_array( 'id', $projected, true ) ) {
			array_unshift( $projected, 'id' );
		}

		$planned = $this->planner->query_post_ids(
			$request,
			[ 'attachment' ],
			$field_map,
			[ 'inherit' ]
		);

		$items       = $this->hydrate_records( $planned['ids'], $projected );
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
		$field_map = $this->fields->get_fields_for_entity( 'attachment' );
		return $this->planner->stream_post_ids( $request, [ 'attachment' ], $field_map, $after_id, $limit, [ 'inherit' ] );
	}

	public function duplicate_record( int $record_id ): WP_Error {
		return new WP_Error( 'datadeck_unsupported', __( 'Media attachments cannot be duplicated inline.', 'datadeck' ), [ 'status' => 400 ] );
	}

	public function delete_records( array $ids ): array {
		$deleted = [];
		$errors  = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! current_user_can( 'delete_post', $id ) ) {
				$errors[] = [
					'id'      => $id,
					'message' => __( 'Permission denied.', 'datadeck' ),
				];
				continue;
			}
			$res = wp_delete_attachment( $id, false );
			if ( false === $res || null === $res ) {
				$errors[] = [
					'id'      => $id,
					'message' => __( 'Failed to delete media item.', 'datadeck' ),
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
}
