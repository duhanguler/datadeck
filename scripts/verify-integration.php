<?php
/**
 * Live WordPress + WooCommerce + SCF Integration & Performance SLA Verification Script.
 * Run via: docker compose exec -T wpcli wp eval-file /var/www/html/wp-content/plugins/rowcraft/scripts/verify-integration.php
 *
 * @package RowCraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use RowCraft\Plugin;
use RowCraft\Query\QueryRequest;

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
	$selective = ( new \RowCraft\Query\SelectiveHydrator() )->hydrate_post_meta( [ $first_id ], [ '_sku', '_regular_price' ] );
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

if ( ! empty( $failures ) ) {
	echo "\nFAILED ASSERTIONS (" . count( $failures ) . "):\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}

echo "\nALL LIVE INTEGRATION & PERFORMANCE SLA ASSERTIONS PASSED!\n";
