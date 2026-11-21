<?php
/**
 * Unit tests for QueryRequest sanitization and bounds clamping.
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RowCraft\Query\QueryRequest;

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
}
