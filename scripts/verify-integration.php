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
	$assert( $margin_res->total === 1, sprintf( 'Pricing & SKU Audit lens detected exact %d anomalous product without false-positiving valid on-sale items', $margin_res->total ) );

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

	// 14. v1.1.2 Round-2 Bug-Hunt Regressions: sale_price/regular_price filters, backorder preservation, duplicate SKU clearing, serialized meta guard, security checks.
	$no_sale = $prod_adapter->query(
		QueryRequest::from_array(
			'product',
			[
				'filters' => [ [ 'field' => 'sale_price', 'operator' => 'isempty', 'value' => '' ] ],
			]
		)
	);
	$assert( $no_sale->total >= 7, sprintf( 'sale_price isempty accurately matched %d non-sale products (no lookup min_price confusion)', $no_sale->total ) );

	$reg_199 = $prod_adapter->query(
		QueryRequest::from_array(
			'product',
			[
				'filters' => [ [ 'field' => 'regular_price', 'operator' => 'is', 'value' => '199' ] ],
			]
		)
	);
	$assert( $reg_199->total >= 1, 'regular_price = 199 accurately matched on-sale Keychron Q1 Pro (reg=199, sale=179)' );

	$manage_true = $prod_adapter->query(
		QueryRequest::from_array(
			'product',
			[
				'filters' => [ [ 'field' => 'manage_stock', 'operator' => 'is', 'value' => 'true' ] ],
			]
		)
	);
	$assert( $manage_true->total >= 1, sprintf( 'Boolean meta filter (manage_stock is true) matched %d WooCommerce products', $manage_true->total ) );

	// Duplicate product clears unique SKU cleanly without WC_Data_Exception.
	$dup_id = $prod_adapter->duplicate_record( 27 );
	$assert( ! is_wp_error( $dup_id ) && '' === (string) $plugin->fields()->read_value( 'product', (int) $dup_id, 'sku' ), 'WooProductEntityAdapter::duplicate_record() cleared unique SKU on duplicate' );
	if ( ! is_wp_error( $dup_id ) ) {
		wp_delete_post( (int) $dup_id, true );
	}

	// Serialized array meta guard prevents string corruption.
	update_post_meta( 27, 'dd_test_serialized', [ 'nested' => true ] );
	$native_provider = $plugin->fields()->get_provider( 'native_meta' );
	$ser_field       = new \DataDeck\Schema\FieldDefinition( 'meta:dd_test_serialized', 'Serialized', 'text', 'meta', 'native_meta', meta_key: 'dd_test_serialized' );
	$ser_res         = $native_provider ? $native_provider->write_value( 'product', 27, $ser_field, 'plain-text-overwrite' ) : null;
	$assert( is_wp_error( $ser_res ) && is_array( get_post_meta( 27, 'dd_test_serialized', true ) ), 'NativeMetaFieldProvider blocked overwriting serialized PHP array with plain string' );
	delete_post_meta( 27, 'dd_test_serialized' );
}

// Security regression: unauthorized order delete & contributor publish downgrade.
if ( $order_adapter && $post_adapter ) {
	$prev_uid = get_current_user_id();
	$contrib  = get_users( [ 'role' => 'contributor', 'number' => 1, 'fields' => 'ID' ] );
	$cid_user = ! empty( $contrib ) ? (int) $contrib[0] : (int) wp_insert_user(
		[
			'user_login' => 'dd_qa_contrib',
			'user_pass'  => wp_generate_password( 16 ),
			'user_email' => 'dd_qa_contrib@example.com',
			'role'       => 'contributor',
		]
	);
	wp_set_current_user( $cid_user );
	$order_del = $order_adapter->delete_records( [ 1 ] );
	$assert( empty( $order_del['deleted'] ) && count( $order_del['errors'] ) === 1, 'WooOrderEntityAdapter::delete_records() enforced delete_shop_orders capability' );
	$assert( ! $comment_adapter->can_read(), 'CommentEntityAdapter::can_read() requires moderate_comments (blocked Contributor)' );

	$created_post = $post_adapter->create_record( [ 'title' => 'Contrib Attempt', 'status' => 'publish' ] );
	$assert( ! is_wp_error( $created_post ) && 'draft' === get_post_status( (int) $created_post ), 'PostEntityAdapter::create_record() downgraded unauthorized publish to draft for Contributor' );
	wp_set_current_user( $prev_uid );
	if ( ! is_wp_error( $created_post ) ) {
		wp_delete_post( (int) $created_post, true );
	}
	if ( empty( $contrib ) && $cid_user > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $cid_user );
	}
}

if ( ! empty( $failures ) ) {
	echo "\nFAILED ASSERTIONS (" . count( $failures ) . "):\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}

echo "\nALL LIVE INTEGRATION & PERFORMANCE SLA ASSERTIONS PASSED!\n";
