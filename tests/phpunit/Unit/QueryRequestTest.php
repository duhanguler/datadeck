<?php
/**
 * Unit tests for QueryRequest sanitization and bounds clamping.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DataDeck\Query\QueryRequest;

final class QueryRequestTest extends TestCase {

	public function test_clamps_pagination_and_sanitizes_inputs(): void {
		$req = QueryRequest::from_array(
			'Product!',
			[
				'page'            => -5,
				'per_page'        => 99999,
				'sort_direction'  => 'ASC',
				'filter_relation' => 'or',
				'fields'          => 'id, title, acf:unit_cost, bad$field;DROP',
				'filters'         => json_encode(
					[
						[ 'field' => 'regular_price', 'operator' => 'gte', 'value' => '50' ],
						[ 'field' => '', 'operator' => 'is', 'value' => 'skip' ],
					]
				),
			]
		);

		$this->assertSame( 'product', $req->entity );
		$this->assertSame( 1, $req->page );
		$this->assertSame( 500, $req->per_page );
		$this->assertSame( 'asc', $req->sort_direction );
		$this->assertSame( 'OR', $req->filter_relation );
		$this->assertSame( [ 'id', 'title', 'acf:unit_cost', 'badfieldDROP' ], $req->fields );
		$this->assertCount( 1, $req->filters );
		$this->assertSame( 'regular_price', $req->filters[0]['field'] );
	}

	public function test_sort_field_preserves_colon_for_acf_and_meta_fields(): void {
		$req = QueryRequest::from_array(
			'product',
			[
				'sort_field' => 'acf:unit_cost',
			]
		);
		$this->assertSame( 'acf:unit_cost', $req->sort_field );

		$meta = QueryRequest::from_array(
			'post',
			[
				'sort_field' => 'meta:_custom_rank;DROP',
			]
		);
		$this->assertSame( 'meta:_custom_rankDROP', $meta->sort_field );
	}
}
