import test from 'node:test';
import assert from 'node:assert/strict';
import {
	computeNumericAggregation,
	generateFillSeries,
	parseCsvClient,
} from '../../.tmp-js-test/series.js';

test( 'generateFillSeries detects arithmetic progression for multi-seed numeric cells', () => {
	const out = generateFillSeries( [ 10, 20 ], 3, 'number' );
	assert.deepEqual( out, [ 30, 40, 50 ] );

	const prices = generateFillSeries( [ 19.99, 29.99 ], 2, 'currency' );
	assert.deepEqual( prices, [ 39.99, 49.99 ] );
} );

test( 'generateFillSeries increments zero-padded trailing numbers in SKUs', () => {
	const skus = generateFillSeries( [ 'SKU-008' ], 4, 'text' );
	assert.deepEqual( skus, [ 'SKU-009', 'SKU-010', 'SKU-011', 'SKU-012' ] );
} );

test( 'generateFillSeries repeats plain values and select options cleanly', () => {
	assert.deepEqual( generateFillSeries( [ 'instock' ], 3, 'select' ), [ 'instock', 'instock', 'instock' ] );
	assert.deepEqual( generateFillSeries( [ 99.99 ], 2, 'currency' ), [ 99.99, 99.99 ] );
} );

test( 'computeNumericAggregation calculates SUM, AVG, MIN, MAX, COUNT and skips empties', () => {
	const stats = computeNumericAggregation( [ 10, 20, null, '', 30, '$40.00' ] );
	assert.equal( stats.count, 6 );
	assert.equal( stats.numericCount, 4 );
	assert.equal( stats.emptyCount, 2 );
	assert.equal( stats.sum, 100 );
	assert.equal( stats.avg, 25 );
	assert.equal( stats.min, 10 );
	assert.equal( stats.max, 40 );
} );

test( 'parseCsvClient strips BOM, detects semicolons, and handles quoted commas/newlines', () => {
	const raw = '\uFEFFsku;title;price\n"A-1";"Desk, ""Oak""";199.90\n"A-2";"Line 1\nLine 2";49.00\n';
	const parsed = parseCsvClient( raw );
	assert.equal( parsed.delimiter, ';' );
	assert.deepEqual( parsed.headers, [ 'sku', 'title', 'price' ] );
	assert.equal( parsed.rows.length, 2 );
	assert.equal( parsed.rows[ 0 ].title, 'Desk, "Oak"' );
	assert.equal( parsed.rows[ 1 ].title, 'Line 1\nLine 2' );
} );
