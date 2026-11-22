<?php
/**
 * WordPress Comments & WooCommerce Reviews Entity Adapter.
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
use WP_Comment;
use WP_Comment_Query;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CommentEntityAdapter implements EntityAdapterInterface {

	public function __construct(
		private readonly FieldRegistry $fields,
		private readonly SelectiveHydrator $hydrator,
	) {}

	public function get_slug(): string {
		return 'comment';
	}

	public function get_label(): string {
		return __( 'Comment', 'datadeck' );
	}

	public function get_plural_label(): string {
		return __( 'Comments', 'datadeck' );
	}

	public function get_group(): string {
		return 'content';
	}

	public function is_available(): bool {
		return true;
	}

	public function can_read(): bool {
		return current_user_can( 'moderate_comments' ) || current_user_can( 'edit_posts' );
	}

	public function can_edit( int $record_id ): bool {
		return current_user_can( 'edit_comment', $record_id ) || current_user_can( 'moderate_comments' );
	}

	public function can_create(): bool {
		return false;
	}

	public function can_delete( int $record_id ): bool {
		return current_user_can( 'moderate_comments' );
	}

	public function query( QueryRequest $request ): QueryResult {
		global $wpdb;

		$start_time    = microtime( true );
		$start_queries = (int) $wpdb->num_queries;

		$projected = ! empty( $request->fields )
			? $request->fields
			: $this->fields->get_default_field_ids( 'comment' );

		if ( ! in_array( 'id', $projected, true ) ) {
			array_unshift( $projected, 'id' );
		}

		$orderby = match ( $request->sort_field ) {
			'author_name' => 'comment_author',
			'date'        => 'comment_date_gmt',
			'post_id'     => 'comment_post_ID',
			'status'      => 'comment_approved',
			default       => 'comment_ID',
		};

		$args = [
			'fields'  => 'ids',
			'number'  => $request->per_page,
			'offset'  => ( $request->page - 1 ) * $request->per_page,
			'orderby' => $orderby,
			'order'   => strtoupper( $request->sort_direction ),
			'status'  => 'all',
			'type'    => 'all',
		];

		if ( '' !== $request->search ) {
			$args['search'] = $request->search;
		}

		// Audit lenses for comments moderation.
		if ( 'comment_pending' === $request->lens ) {
			$args['status'] = 'hold';
		} elseif ( 'comment_reviews' === $request->lens ) {
			$args['type'] = 'review';
		} elseif ( 'comment_spam' === $request->lens ) {
			$args['status'] = 'spam';
		}

		foreach ( $request->filters as $f ) {
			$field = (string) ( $f['field'] ?? '' );
			$op    = (string) ( $f['operator'] ?? '' );
			$val   = (string) ( $f['value'] ?? '' );

			if ( 'status' === $field && in_array( $op, [ 'is', 'equals' ], true ) && '' !== $val ) {
				$args['status'] = sanitize_key( $val );
			} elseif ( 'comment_type' === $field && in_array( $op, [ 'is', 'equals' ], true ) && '' !== $val ) {
				$args['type'] = sanitize_key( $val );
			} elseif ( 'post_id' === $field && in_array( $op, [ 'is', 'equals' ], true ) && is_numeric( $val ) ) {
				$args['post_id'] = (int) $val;
			} elseif ( 'rating' === $field && '' !== $val && is_numeric( $val ) ) {
				$compare = match ( $op ) {
					'gt'    => '>',
					'gte'   => '>=',
					'lt'    => '<',
					'lte'   => '<=',
					default => '=',
				};
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				$args['meta_query'][] = [
					'key'     => 'rating',
					'value'   => (int) $val,
					'compare' => $compare,
					'type'    => 'NUMERIC',
				];
			}
		}

		$count_args           = $args;
		$count_args['count']  = true;
		$count_args['number'] = 0;
		$count_args['offset'] = 0;
		unset( $count_args['fields'] );

		$cq    = new WP_Comment_Query();
		$ids   = array_map( 'intval', (array) $cq->query( $args ) );
		$total = (int) ( new WP_Comment_Query() )->query( $count_args );

		return new QueryResult(
			items: $this->hydrate_records( $ids, $projected ),
			total: $total,
			total_pages: max( 1, (int) ceil( $total / $request->per_page ) ),
			page: $request->page,
			per_page: $request->per_page,
			query_count: max( 0, (int) $wpdb->num_queries - $start_queries ),
			duration_ms: ( microtime( true ) - $start_time ) * 1000,
			query_plan: 'wp-comment-query',
		);
	}

	public function stream_ids( QueryRequest $request, int $after_id, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID > %d ORDER BY comment_ID ASC LIMIT %d",
				$after_id,
				max( 1, min( 1000, $limit ) )
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	public function hydrate_records( array $ids, array $field_ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		// Batch fetch comment objects cleanly in 1 query.
		$comments = get_comments(
			[
				'comment__in' => $ids,
				'status'      => 'all',
				'type'        => 'all',
				'number'      => count( $ids ),
			]
		);

		$comment_map = [];
		$post_ids    = [];
		if ( is_array( $comments ) ) {
			foreach ( $comments as $c ) {
				if ( $c instanceof WP_Comment ) {
					$cid                 = (int) $c->comment_ID;
					$comment_map[ $cid ] = $c;
					if ( (int) $c->comment_post_ID > 0 ) {
						$post_ids[] = (int) $c->comment_post_ID;
					}
				}
			}
		}

		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( array_values( array_unique( $post_ids ) ), false, false );
		}

		$meta_keys  = $this->fields->collect_required_meta_keys( 'comment', $field_ids );
		$meta_cache = $this->hydrator->hydrate_comment_meta( $ids, $meta_keys );

		$rows = [];
		foreach ( $ids as $id ) {
			$c           = $comment_map[ $id ] ?? null;
			$rows[ $id ] = [
				'id'          => $id,
				'_comment'    => $c,
				'_editUrl'    => admin_url( 'comment.php?action=editcomment&c=' . $id ),
				'_viewUrl'    => $c ? (string) get_comment_link( $c ) : '',
				'_canEdit'    => $this->can_edit( $id ),
				'_modifiedAt' => $c ? (string) $c->comment_date_gmt : '',
			];
		}

		$this->fields->hydrate_fields( 'comment', $ids, $field_ids, $rows, $meta_cache );

		// Strip internal WP_Comment object before JSON serialization.
		foreach ( $rows as &$row ) {
			unset( $row['_comment'] );
		}
		unset( $row );

		return array_values( $rows );
	}

	public function create_record( array $data ): WP_Error {
		return new WP_Error( 'datadeck_not_supported', __( 'Use the post or product page to create new comments.', 'datadeck' ), [ 'status' => 400 ] );
	}

	public function duplicate_record( int $record_id ): WP_Error {
		return new WP_Error( 'datadeck_not_supported', __( 'Comment duplication is disabled.', 'datadeck' ), [ 'status' => 400 ] );
	}

	public function delete_records( array $ids ): array {
		$deleted = [];
		$errors  = [];

		foreach ( $ids as $id ) {
			if ( ! $this->can_delete( $id ) ) {
				$errors[] = [
					'id'      => $id,
					'message' => __( 'Permission denied.', 'datadeck' ),
				];
				continue;
			}
			$res = wp_trash_comment( $id );
			if ( $res ) {
				$deleted[] = $id;
			} else {
				$errors[] = [
					'id'      => $id,
					'message' => __( 'Failed to move comment to trash.', 'datadeck' ),
				];
			}
		}

		return [
			'deleted' => $deleted,
			'errors'  => $errors,
		];
	}

	public function get_edit_url( int $record_id ): string {
		return admin_url( 'comment.php?action=editcomment&c=' . $record_id );
	}

	public function get_view_url( int $record_id ): string {
		$comment = get_comment( $record_id );
		return $comment instanceof WP_Comment ? (string) get_comment_link( $comment ) : '';
	}
}
