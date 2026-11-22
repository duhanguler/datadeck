<?php
/**
 * Live WordPress + WooCommerce + SCF Integration & Performance SLA Verification Script.
 * Run via: docker compose exec -T wpcli wp eval-file /var/www/html/wp-content/plugins/datadeck/scripts/verify-integration.php
 *
 * @package DataDeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataDeck\Plugin;
use DataDeck\Query\QueryRequest;

$plugin   = Plugin::instance();
$entities = $plugin->entities();
$bulk     = $plugin->bulk();
$history  = $plugin->history();
$failures = [];

$assert = static function ( bool $cond, string $label ) use ( &$failures ): void {
	if ( $cond ) {
		echo "[PASS] {$label}\n";
	} else {
		echo "[FAIL] {$label}\n";
		$failures[] = $label;
	}
};

// 1. Query Budget SLA: 50 WooCommerce Products with ACF + Categories + Lookup Table routing.
$prod_adapter = $entities->get( 'product' );
$assert( null !== $prod_adapter, 'WooCommerce Product entity discovered' );

if ( $prod_adapter ) {
	$req = QueryRequest::from_array(
		'product',
		[
			'per_page'       => 50,
			'sort_field'     => 'regular_price',
			'sort_direction' => 'desc',
			'fields'         => 'id,featured_image,title,sku,regular_price,sale_price,stock_status,stock_quantity,product_cat,acf:product_brand,acf:unit_cost',
		]
	);
	$res = $prod_adapter->query( $req );
	$assert( count( $res->items ) >= 10, sprintf( 'Hydrated %d products cleanly', count( $res->items ) ) );
	$assert( str_contains( $res->query_plan, 'woo-lookup-sort' ), 'SmartQueryPlanner routed price sort to wc_product_meta_lookup: ' . $res->query_plan );
	$assert( $res->query_count <= 9, sprintf( 'SQL query count stayed tight (%d queries, %.2fms)', $res->query_count, $res->duration_ms ) );

	// Verify SelectiveHydrator skips multi-MB unrelated blobs like _elementor_data.
	$first_id = (int) $res->items[0]['id'];
	update_post_meta( $first_id, '_elementor_data', str_repeat( '[{"widget":"heavy"}]', 5000 ) );
	$selective = ( new \DataDeck\Query\SelectiveHydrator() )->hydrate_post_meta( [ $first_id ], [ '_sku', '_regular_price' ] );
	$assert( ! isset( $selective[ $first_id ]['_elementor_data'] ), 'SelectiveHydrator skipped heavy _elementor_data blob' );
	delete_post_meta( $first_id, '_elementor_data' );

	// 2. Inline Variable Product Sub-Rows.
	$var_parents = array_values( array_filter( $res->items, static fn( array $r ): bool => (int) ( $r['_varCount'] ?? 0 ) > 0 ) );
	$assert( count( $var_parents ) >= 1, 'Variable product variation counts hydrated in 1 grouped query' );
	if ( ! empty( $var_parents ) ) {
		$parent_id = (int) $var_parents[0]['id'];
		$var_res   = $prod_adapter->query( QueryRequest::from_array( 'product', [ 'parent_id' => $parent_id, 'fields' => 'id,title,sku,regular_price,stock_quantity' ] ) );
		$assert( count( $var_res->items ) === 3, sprintf( 'Expanded %d variation sub-rows for parent #%d', count( $var_res->items ), $parent_id ) );
	}

	// 3. Built-in Audit Lenses (Pricing Anomaly & Inventory Risk).
	$margin_res = $prod_adapter->query( QueryRequest::from_array( 'product', [ 'lens' => 'woo_margin_audit' ] ) );
	$assert( $margin_res->total >= 2, sprintf( 'Pricing & SKU Audit lens detected %d anomalous products', $margin_res->total ) );

	$inv_res = $prod_adapter->query( QueryRequest::from_array( 'product', [ 'lens' => 'woo_inventory_risk' ] ) );
	$assert( $inv_res->total >= 4, sprintf( 'Inventory Risk lens detected %d low/out-of-stock products', $inv_res->total ) );

	// 4. Inline Edit + Snapshot History + One-Click Rollback.
	wp_set_current_user( 1 );
	$target_id  = $first_id;
	$orig_price = $plugin->fields()->read_value( 'product', $target_id, 'regular_price' );
	$commit     = $bulk->commit_cell_edits( 'product', [ [ 'id' => $target_id, 'field' => 'regular_price', 'value' => 777.99 ] ], 'inline' );
	$assert( ! is_wp_error( $commit ) && 1 === (int) $commit['updated'], 'Inline cell edit committed cleanly' );
	$assert( 777.99 === (float) $plugin->fields()->read_value( 'product', $target_id, 'regular_price' ), 'WooCommerce CRUD persisted updated regular_price=777.99' );

	$rb = $history->rollback( (string) $commit['batchUuid'] );
	$assert( ! is_wp_error( $rb ) && 1 === (int) $rb['restored'], 'One-click rollback restored batch cleanly' );
	$assert( (float) $orig_price === (float) $plugin->fields()->read_value( 'product', $target_id, 'regular_price' ), 'Rolled-back price matches exact original value' );

	// 5. AST Bulk Formula Dry-Run + Chunked Execution + Rollback.
	$two_ids = array_slice( array_map( static fn( array $r ): int => (int) $r['id'], $res->items ), 0, 2 );
	$rule    = [ 'field' => 'sale_price', 'action' => 'formula', 'formula' => 'round(regular_price * 0.90, 0) - 0.01' ];
	$dry     = $bulk->dry_run( 'product', $rule, $two_ids );
	$assert( ! is_wp_error( $dry ) && count( $dry['previews'] ) === 2, 'AST Bulk Formula dry-run produced 2 exact diffs' );

	$run = $bulk->execute_chunk( 'product', $rule, $two_ids, [], 0, 50 );
	$assert( ! is_wp_error( $run ) && 2 === (int) $run['updated'], 'AST Bulk Formula executed across 2 products' );
	$rb2 = $history->rollback( (string) $run['batchUuid'] );
	$assert( ! is_wp_error( $rb2 ) && 2 === (int) $rb2['restored'], 'AST Bulk Formula batch rolled back cleanly' );
}

// 6. HPOS WooCommerce Orders.
$order_adapter = $entities->get( 'shop_order' );
$assert( null !== $order_adapter, 'WooCommerce HPOS Orders entity discovered' );
if ( $order_adapter ) {
	$ores = $order_adapter->query( QueryRequest::from_array( 'shop_order', [ 'per_page' => 20 ] ) );
	$assert( $ores->total >= 4, sprintf( 'Queried %d HPOS orders cleanly', $ores->total ) );
}

// 7. Media Library Missing Alt Text Lens.
$media_adapter = $entities->get( 'attachment' );
if ( $media_adapter ) {
	$mres = $media_adapter->query( QueryRequest::from_array( 'attachment', [ 'lens' => 'media_missing_alt' ] ) );
	$assert( $mres->total >= 2, sprintf( 'Media Missing Alt Text lens caught %d images missing alt', $mres->total ) );
}

// 8. Posts SEO Audit Lens.
$post_adapter = $entities->get( 'post' );
if ( $post_adapter ) {
	$pres = $post_adapter->query( QueryRequest::from_array( 'post', [ 'lens' => 'seo_audit' ] ) );
	$assert( $pres->total >= 1, sprintf( 'Posts SEO Audit lens caught %d posts needing attention', $pres->total ) );
}

// 9. v1.1 Computed Formula Columns (built-in + hidden dependency auto-hydration).
if ( $prod_adapter ) {
	$cres = $prod_adapter->query(
		QueryRequest::from_array(
			'product',
			[
				'per_page' => 10,
				'fields'   => 'id,title,computed_inventory_value,computed_discount_pct',
			]
		)
	);
	$assert(
		isset( $cres->items[0]['computed_inventory_value'] ) && is_numeric( $cres->items[0]['computed_inventory_value'] ),
		'Computed virtual columns hydrated automatically even when regular_price/stock_quantity were hidden'
	);

	// 10. v1.1 Optimistic Concurrency Guard (stale modified_gmt detection).
	$simple_row = null;
	foreach ( $cres->items as $item ) {
		$wc = wc_get_product( (int) $item['id'] );
		if ( $wc && $wc->is_type( 'simple' ) && '' !== (string) $wc->get_sku() ) {
			$simple_row = $item;
			break;
		}
	}
	$cid          = $simple_row ? (int) $simple_row['id'] : (int) $cres->items[0]['id'];
	$stale_commit = $bulk->commit_cell_edits(
		'product',
		[
			[
				'id'                 => $cid,
				'field'              => 'regular_price',
				'value'              => 555.55,
				'expectedModifiedAt' => '2000-01-01 00:00:00',
			],
		],
		'inline',
		false
	);
	$assert(
		! is_wp_error( $stale_commit ) && 0 === (int) $stale_commit['updated'] && count( $stale_commit['conflicts'] ) === 1,
		'Optimistic concurrency guard blocked stale edit and returned structured conflict diff'
	);

	// 11. v1.1 Smart CSV Import (match by SKU -> dry-run -> apply -> One-Click Rollback).
	$sample_sku   = (string) $plugin->fields()->read_value( 'product', $cid, 'sku' );
	$before_price = $plugin->fields()->read_value( 'product', $cid, 'regular_price' );
	if ( '' !== $sample_sku ) {
		$importer = new \DataDeck\Import\CsvImportEngine( $entities, $plugin->fields(), $history );
		$csv_raw  = "SKU,Regular Price\n\"{$sample_sku}\",444.44\n";
		$analysis = $importer->analyze( 'product', $csv_raw, 'csv' );
		$assert( ! is_wp_error( $analysis ) && 'sku' === ( $analysis['suggestedMapping']['SKU'] ?? '' ), 'CSV import auto-mapped SKU header cleanly' );

		$parsed = $importer->parse_csv( $csv_raw );
		$dry_im = $importer->dry_run( 'product', $parsed['rows'], $analysis['suggestedMapping'], 'sku', false );
		$assert( ! is_wp_error( $dry_im ) && 1 === (int) $dry_im['willUpdate'], 'CSV import dry-run matched 1 product by SKU' );

		$app_im = $importer->apply_chunk( 'product', $parsed['rows'], $analysis['suggestedMapping'], 'sku', false );
		$assert( ! is_wp_error( $app_im ) && 1 === (int) $app_im['updated'], 'CSV import applied price update cleanly' );
		$assert( 444.44 === (float) $plugin->fields()->read_value( 'product', $cid, 'regular_price' ), 'CSV import persisted updated price=444.44' );

		// Verify cell-level history & single-cell rollback!
		$cell_hist = $history->list_record_changes( 'product', $cid, 'regular_price', 5 );
		$assert( count( $cell_hist ) >= 1, 'Cell-level audit history returned recorded CSV delta' );

		$single_rb = $history->rollback_change( (int) $cell_hist[0]['changeId'] );
		$assert( ! is_wp_error( $single_rb ), 'Single-cell rollback succeeded cleanly' );
		$assert( (float) $before_price === (float) $plugin->fields()->read_value( 'product', $cid, 'regular_price' ), 'Single-cell rollback restored exact original price' );
	}
}

// 12. v1.1 WordPress Comments Entity Adapter.
$comment_adapter = $entities->get( 'comment' );
$assert( null !== $comment_adapter, 'WordPress Comments entity adapter discovered' );
if ( $comment_adapter ) {
	$cm_res = $comment_adapter->query( QueryRequest::from_array( 'comment', [ 'per_page' => 10 ] ) );
	$assert( $cm_res->total >= 1, sprintf( 'Queried %d WordPress comments cleanly', $cm_res->total ) );
}

// 13. v1.1.1 Bug-Hunt Regressions: Media stream_ids, Order cursor stream_ids, OR stream preservation, ACF sort.
if ( $media_adapter ) {
	$media_stream = $media_adapter->stream_ids( QueryRequest::from_array( 'attachment', [ 'lens' => 'media_missing_alt' ] ), 0, 50 );
	$assert( count( $media_stream ) >= 2, sprintf( 'Media stream_ids() returned %d attachments (inherit status fix)', count( $media_stream ) ) );
}

if ( $order_adapter ) {
	$o_chunk1 = $order_adapter->stream_ids( QueryRequest::from_array( 'shop_order', [] ), 0, 2 );
	$o_chunk2 = ! empty( $o_chunk1 )
		? $order_adapter->stream_ids( QueryRequest::from_array( 'shop_order', [] ), max( $o_chunk1 ), 2 )
		: [];
	$assert(
		count( $o_chunk1 ) === 2 && count( $o_chunk2 ) >= 1 && min( $o_chunk2 ) > max( $o_chunk1 ),
		'WooCommerce HPOS stream_ids() advances cursor accurately across chunks without truncation'
	);
}

if ( $prod_adapter ) {
	$or_req = QueryRequest::from_array(
		'product',
		[
			'filter_relation' => 'OR',
			'filters'         => [
				[ 'field' => 'stock_status', 'operator' => 'is', 'value' => 'outofstock' ],
				[ 'field' => 'regular_price', 'operator' => 'gte', 'value' => 300 ],
			],
		]
	);
	$or_total = $prod_adapter->query( $or_req )->total;
	$or_c1    = $prod_adapter->stream_ids( $or_req, 0, 2 );
	$or_c2    = ! empty( $or_c1 ) ? $prod_adapter->stream_ids( $or_req, max( $or_c1 ), 20 ) : [];
	$assert(
		( count( $or_c1 ) + count( $or_c2 ) ) === $or_total && $or_total >= 3,
		sprintf( 'SmartQueryPlanner stream_post_ids() preserved OR filter across chunks (%d/%d)', count( $or_c1 ) + count( $or_c2 ), $or_total )
	);

	// Verify ACF colon-namespaced sort_field works cleanly.
	$acf_sort = $prod_adapter->query( QueryRequest::from_array( 'product', [ 'sort_field' => 'acf:unit_cost', 'sort_direction' => 'desc', 'per_page' => 5 ] ) );
	$assert( count( $acf_sort->items ) >= 1, 'Sorting by colon-namespaced ACF column (acf:unit_cost) succeeded' );
}

if ( ! empty( $failures ) ) {
	echo "\nFAILED ASSERTIONS (" . count( $failures ) . "):\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}

echo "\nALL LIVE INTEGRATION & PERFORMANCE SLA ASSERTIONS PASSED!\n";
