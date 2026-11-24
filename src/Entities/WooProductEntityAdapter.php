<?php
/**
 * WooCommerce Product Entity Adapter with inline variation sub-rows and lookup-table routing.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Entities;

use DataDeck\Fields\FieldRegistry;
use DataDeck\Query\QueryRequest;
use DataDeck\Query\SelectiveHydrator;
use DataDeck\Query\SmartQueryPlanner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooProductEntityAdapter extends PostEntityAdapter {

	public function __construct(
		FieldRegistry $fields,
		SmartQueryPlanner $planner,
		SelectiveHydrator $hydrator,
	) {
		parent::__construct( 'product', $fields, $planner, $hydrator );
	}

	public function get_group(): string {
		return 'commerce';
	}

	public function is_available(): bool {
		return class_exists( 'WooCommerce' ) && post_type_exists( 'product' );
	}

	protected function get_query_post_types( QueryRequest $request ): array {
		if ( $request->parent_id > 0 ) {
			return [ 'product_variation' ];
		}
		if ( $request->flatten_variations ) {
			return [ 'product', 'product_variation' ];
		}
		return [ 'product' ];
	}

	/**
	 * Attach variation counts & parent links in 1 single grouped SQL query (zero N+1).
	 *
	 * @param array<int, int>                  $ids  Hydrated IDs.
	 * @param array<int, array<string, mixed>> $rows Rows keyed by ID.
	 */
	protected function enrich_hydrated_rows( array $ids, array &$rows ): void {
		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT post_parent, COUNT(ID) AS cnt
			FROM {$wpdb->posts}
			WHERE post_type = 'product_variation'
			AND post_status IN ('publish', 'private')
			AND post_parent IN ({$placeholders})
			GROUP BY post_parent",
			...$ids
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$counts    = $wpdb->get_results( $sql );
		$count_map = [];
		if ( is_array( $counts ) ) {
			foreach ( $counts as $row ) {
				$count_map[ (int) $row->post_parent ] = (int) $row->cnt;
			}
		}

		foreach ( $ids as $id ) {
			$post                        = get_post( $id );
			$is_variation                = $post && 'product_variation' === $post->post_type;
			$rows[ $id ]['_isVariation'] = $is_variation;
			$rows[ $id ]['_parentId']    = $is_variation && $post ? (int) $post->post_parent : 0;
			$rows[ $id ]['_varCount']    = $count_map[ $id ] ?? 0;
		}
	}

	public function create_record( array $data ): int|WP_Error {
		if ( ! $this->can_create() ) {
			return new WP_Error( 'datadeck_cannot_create', __( 'Permission denied.', 'datadeck' ), [ 'status' => 403 ] );
		}

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return parent::create_record( $data );
		}

		$status = sanitize_key( (string) ( $data['status'] ?? 'draft' ) );
		if ( ! in_array( $status, [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
			$status = 'draft';
		}
		$pt_obj = get_post_type_object( 'product' );
		if ( in_array( $status, [ 'publish', 'private' ], true ) && $pt_obj && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
			$status = 'draft';
		}

		$product = new \WC_Product_Simple();
		$product->set_name( sanitize_text_field( (string) ( $data['title'] ?? __( 'New Product', 'datadeck' ) ) ) );
		$product->set_status( $status );

		try {
			if ( isset( $data['regular_price'] ) && '' !== (string) $data['regular_price'] ) {
				$product->set_regular_price( (string) max( 0.0, (float) $data['regular_price'] ) );
			}
			if ( isset( $data['sku'] ) && '' !== (string) $data['sku'] ) {
				$product->set_sku( sanitize_text_field( (string) $data['sku'] ) );
			}

			$product_id = $product->save();
		} catch ( \WC_Data_Exception $e ) {
			return new WP_Error( 'datadeck_wc_create_error', $e->getMessage(), [ 'status' => 400 ] );
		}
		if ( $product_id <= 0 ) {
			return new WP_Error( 'datadeck_wc_create_failed', __( 'Failed to create product.', 'datadeck' ), [ 'status' => 500 ] );
		}

		foreach ( $data as $field_id => $value ) {
			if ( in_array( $field_id, [ 'id', 'title', 'status', 'regular_price', 'sku' ], true ) ) {
				continue;
			}
			$this->fields->write_value( 'product', $product_id, (string) $field_id, $value );
		}

		return $product_id;
	}

	/**
	 * Duplicate a WooCommerce product safely without cloning unique SKUs or historical sales stats,
	 * and sync wc_product_meta_lookup immediately.
	 */
	public function duplicate_record( int $record_id ): int|WP_Error {
		$new_id = parent::duplicate_record( $record_id );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		foreach ( [ '_sku', 'total_sales', '_wc_review_count', '_wc_average_rating', '_wc_rating_count' ] as $reset_key ) {
			delete_post_meta( $new_id, $reset_key );
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$dup = wc_get_product( $new_id );
			if ( $dup ) {
				$dup->set_sku( '' );
				$dup->save();
			}
		}

		return $new_id;
	}
}
