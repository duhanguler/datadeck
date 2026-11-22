<?php
/**
 * Unit tests for CsvImportEngine parsing, auto-mapping, and value coercion.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Tests\Unit;

use DataDeck\Entities\EntityRegistry;
use DataDeck\Fields\FieldRegistry;
use DataDeck\History\HistoryService;
use DataDeck\Import\CsvImportEngine;
use DataDeck\Query\SelectiveHydrator;
use DataDeck\Query\SmartQueryPlanner;
use DataDeck\Schema\FieldDefinition;
use PHPUnit\Framework\TestCase;

final class CsvImportEngineTest extends TestCase {

	private CsvImportEngine $engine;

	protected function setUp(): void {
		$fields   = new FieldRegistry();
		$entities = new EntityRegistry( $fields, new SmartQueryPlanner(), new SelectiveHydrator() );
		$history  = new HistoryService( $entities, $fields );

		$this->engine = new CsvImportEngine( $entities, $fields, $history );
	}

	public function test_parses_comma_csv_with_bom_and_quoted_values(): void {
		$csv    = "\xEF\xBB\xBFSKU,Name,\"Regular Price\"\n\"KB-01\",\"Keychron, Q1\",199.99\n\"DS-02\",\"Lamp \"\"Pro\"\"\",89.50\n";
		$parsed = $this->engine->parse_csv( $csv );

		$this->assertSame( ',', $parsed['delimiter'] );
		$this->assertSame( [ 'SKU', 'Name', 'Regular Price' ], $parsed['headers'] );
		$this->assertCount( 2, $parsed['rows'] );
		$this->assertSame( 'Keychron, Q1', $parsed['rows'][0]['Name'] );
		$this->assertSame( 'Lamp "Pro"', $parsed['rows'][1]['Name'] );
	}

	public function test_auto_detects_semicolon_delimiter(): void {
		$csv    = "sku;regular_price;stock_quantity\nPRO-1;49,90;12\n";
		$parsed = $this->engine->parse_csv( $csv );

		$this->assertSame( ';', $parsed['delimiter'] );
		$this->assertSame( '49,90', $parsed['rows'][0]['regular_price'] );
		$this->assertSame( '12', $parsed['rows'][0]['stock_quantity'] );
	}

	public function test_suggests_column_mapping_from_aliases_and_labels(): void {
		$field_map = [
			'sku'            => new FieldDefinition( 'sku', 'SKU', 'text', 'woocommerce', 'woo', meta_key: '_sku' ),
			'regular_price'  => new FieldDefinition( 'regular_price', 'Regular Price', 'currency', 'woocommerce', 'woo', meta_key: '_regular_price' ),
			'stock_quantity' => new FieldDefinition( 'stock_quantity', 'Stock Qty', 'integer', 'woocommerce', 'woo', meta_key: '_stock' ),
			'title'          => new FieldDefinition( 'title', 'Title', 'text', 'core', 'core' ),
		];

		$mapping = $this->engine->suggest_mapping(
			[ 'Product Name', 'SKU', 'Price', 'Qty', 'Unmapped Notes' ],
			$field_map
		);

		$this->assertSame( 'title', $mapping['Product Name'] );
		$this->assertSame( 'sku', $mapping['SKU'] );
		$this->assertSame( 'regular_price', $mapping['Price'] );
		$this->assertSame( 'stock_quantity', $mapping['Qty'] );
		$this->assertSame( '', $mapping['Unmapped Notes'] );
	}

	public function test_coerces_currency_integer_and_boolean_values(): void {
		$price_field = new FieldDefinition( 'regular_price', 'Regular Price', 'currency', 'woocommerce', 'woo' );
		$qty_field   = new FieldDefinition( 'stock_quantity', 'Stock', 'integer', 'woocommerce', 'woo' );
		$bool_field  = new FieldDefinition( 'manage_stock', 'Manage Stock', 'boolean', 'woocommerce', 'woo' );

		$this->assertEqualsWithDelta( 1299.5, (float) $this->engine->coerce_value( '$1,299.50', $price_field ), 1e-6 );
		$this->assertEqualsWithDelta( 49.9, (float) $this->engine->coerce_value( '49,90', $price_field ), 1e-6 );
		$this->assertNull( $this->engine->coerce_value( '', $price_field ) );
		$this->assertSame( 42, $this->engine->coerce_value( '42', $qty_field ) );
		$this->assertTrue( $this->engine->coerce_value( 'yes', $bool_field ) );
		$this->assertFalse( $this->engine->coerce_value( 'no', $bool_field ) );
	}
}
