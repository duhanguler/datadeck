<?php
/**
 * WooCommerce HPOS-Native Order Entity Adapter.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Entities;

use DataDeck\Contracts\EntityAdapterInterface;
use DataDeck\Fields\FieldRegistry;
use DataDeck\Query\QueryRequest;
use DataDeck\Query\QueryResult;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooOrderEntityAdapter implements EntityAdapterInterface {

	public function __construct(
		private readonly FieldRegistry $fields,
	) {}

	public function get_slug(): string {
		return 'shop_order';
	}

	public function get_label(): string {
		return __( 'Order', 'datadeck' );
	}

	public function get_plural_label(): string {
		return __( 'Orders', 'datadeck' );
	}

	public function get_group(): string {
		return 'commerce';
	}

	public function is_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' );
	}

	public function can_read(): bool {
		return current_user_can( 'edit_shop_orders' );
	}

	public function can_edit( int $record_id ): bool {
		return current_user_can( 'edit_shop_orders' );
	}

	public function can_create(): bool {
		return false;
	}

	public function can_delete( int $record_id ): bool {
		return current_user_can( 'delete_shop_orders' );
	}

	public function query( QueryRequest $request ): QueryResult {
		global $wpdb;

		$start_time    = microtime( true );
		$start_queries = (int) $wpdb->num_queries;

		$projected = ! empty( $request->fields )
			? $request->fields
			: $this->fields->get_default_field_ids( 'shop_order' );

		if ( ! in_array( 'id', $projected, true ) ) {
			array_unshift( $projected, 'id' );
		}

		$orderby = match ( $request->sort_field ) {
			'date'   => 'date',
			'total'  => 'total',
			'status' => 'status',
			default  => 'ID',
		};

		$args = [
			'limit'    => $request->per_page,
			'paged'    => $request->page,
			'orderby'  => $orderby,
			'order'    => strtoupper( $request->sort_direction ),
			'paginate' => true,
			'return'   => 'ids',
		];

		if ( '' !== $request->search ) {
			$args['s'] = $request->search;
		}

		foreach ( $request->filters as $f ) {
			$fid = (string) ( $f['field'] ?? '' );
			$op  = (string) ( $f['operator'] ?? '' );
			$val = $f['value'] ?? null;

			if ( 'status' === $fid && 'is' === $op && ! empty( $val ) ) {
				$args['status'] = 'wc-' . str_replace( 'wc-', '', sanitize_key( (string) $val ) );
			} elseif ( 'billing_email' === $fid && ! empty( $val ) ) {
				$args['billing_email'] = sanitize_email( (string) $val );
			}
		}

		$result = wc_get_orders( $args );
		$ids    = [];
		$total  = 0;

		if ( is_object( $result ) && isset( $result->orders ) ) {
			$ids   = array_map( 'intval', (array) $result->orders );
			$total = (int) ( $result->total ?? count( $ids ) );
		}

		return new QueryResult(
			items: $this->hydrate_records( $ids, $projected ),
			total: $total,
			total_pages: max( 1, (int) ceil( $total / $request->per_page ) ),
			page: $request->page,
			per_page: $request->per_page,
			query_count: max( 0, (int) $wpdb->num_queries - $start_queries ),
			duration_ms: ( microtime( true ) - $start_time ) * 1000,
			query_plan: 'woocommerce-hpos',
		);
	}

	public function stream_ids( QueryRequest $request, int $after_id, int $limit ): array {
		$result = wc_get_orders(
			[
				'limit'   => $limit,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			]
		);
		if ( ! is_array( $result ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'intval', $result ), static fn( int $id ): bool => $id > $after_id ) );
	}

	public function hydrate_records( array $ids, array $field_ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		$rows = [];
		foreach ( $ids as $id ) {
			$rows[ $id ] = [
				'id'       => $id,
				'_editUrl' => $this->get_edit_url( $id ),
				'_viewUrl' => '',
				'_canEdit' => $this->can_edit( $id ),
			];
		}

		$this->fields->hydrate_fields( 'shop_order', $ids, $field_ids, $rows, [] );

		return array_values( $rows );
	}

	public function create_record( array $data ): WP_Error {
		return new WP_Error( 'datadeck_unsupported', __( 'Orders are created via WooCommerce.', 'datadeck' ), [ 'status' => 400 ] );
	}

	public function duplicate_record( int $record_id ): WP_Error {
		return new WP_Error( 'datadeck_unsupported', __( 'Orders cannot be duplicated inline.', 'datadeck' ), [ 'status' => 400 ] );
	}

	public function delete_records( array $ids ): array {
		$deleted = [];
		$errors  = [];
		foreach ( $ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( ! $order instanceof \WC_Order ) {
				$errors[] = [
					'id'      => (int) $id,
					'message' => __( 'Order not found.', 'datadeck' ),
				];
				continue;
			}
			$order->delete( false );
			$deleted[] = (int) $id;
		}
		return [
			'deleted' => $deleted,
			'errors'  => $errors,
		];
	}

	public function get_edit_url( int $record_id ): string {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $record_id );
			if ( $order instanceof \WC_Order ) {
				return (string) $order->get_edit_order_url();
			}
		}
		return admin_url( 'post.php?post=' . $record_id . '&action=edit' );
	}

	public function get_view_url( int $record_id ): string {
		return '';
	}
}
