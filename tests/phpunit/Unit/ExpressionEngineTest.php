<?php
/**
 * Unit tests for the safe recursive-descent AST ExpressionEngine (zero eval).
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use DataDeck\Formula\ExpressionEngine;

final class ExpressionEngineTest extends TestCase {

	private ExpressionEngine $engine;

	protected function setUp(): void {
		$this->engine = new ExpressionEngine();
	}

	public function test_arithmetic_precedence_and_parentheses(): void {
		$this->assertEqualsWithDelta( 14.0, $this->engine->evaluate( '2 + 3 * 4', [] ), 1e-6 );
		$this->assertEqualsWithDelta( 20.0, $this->engine->evaluate( '(2 + 3) * 4', [] ), 1e-6 );
		$this->assertEqualsWithDelta( -5.0, $this->engine->evaluate( '-10 + 5', [] ), 1e-6 );
	}

	public function test_psychological_pricing_formula(): void {
		$ctx = [ 'regular_price' => 199.0 ];
		$val = $this->engine->evaluate( 'round(regular_price * 0.85, 0) - 0.01', $ctx );
		$this->assertEqualsWithDelta( 168.99, (float) $val, 1e-6 );
	}

	public function test_division_by_zero_returns_zero_safely(): void {
		$this->assertEqualsWithDelta( 0.0, $this->engine->evaluate( '100 / 0', [] ), 1e-6 );
		$this->assertEqualsWithDelta( 0.0, $this->engine->evaluate( '100 % 0', [] ), 1e-6 );
	}

	public function test_conditional_if_and_logical_operators(): void {
		$expr = "if(stock_quantity == 0 || stock_quantity < 0, 'outofstock', 'instock')";
		$this->assertSame( 'outofstock', $this->engine->evaluate( $expr, [ 'stock_quantity' => 0 ] ) );
		$this->assertSame( 'instock', $this->engine->evaluate( $expr, [ 'stock_quantity' => 12 ] ) );
	}

	public function test_string_helpers_and_coalesce(): void {
		$ctx = [
			'title' => '  keychron q1  ',
			'sku'   => 'KB-01',
			'brand' => '',
		];
		$this->assertSame( 'KEYCHRON Q1 [KB-01]', $this->engine->evaluate( "concat(upper(trim(title)), ' [', sku, ']')", $ctx ) );
		$this->assertSame( 'Generic', $this->engine->evaluate( "coalesce(brand, 'Generic')", $ctx ) );
		$this->assertSame( 'keychron-q1', $this->engine->evaluate( 'slugify(title)', $ctx ) );
	}

	public function test_template_interpolation(): void {
		$out = $this->engine->render_template(
			'{title} ({sku}) - {year}',
			[ 'title' => 'Studio Lamp', 'sku' => 'DS-08' ]
		);
		$this->assertSame( 'Studio Lamp (DS-08) - ' . gmdate( 'Y' ), $out );
	}

	public function test_rejects_arbitrary_php_functions(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->engine->evaluate( "system('whoami')", [] );
	}

	public function test_rejects_unterminated_string(): void {
		$res = $this->engine->validate( "concat('hello, world)" );
		$this->assertIsString( $res );
		$this->assertStringContainsString( 'Unterminated', $res );
	}

	public function test_rejects_excessive_nesting_depth(): void {
		$deep = str_repeat( '(', 40 ) . '1' . str_repeat( ')', 40 );
		$this->expectException( InvalidArgumentException::class );
		$this->engine->compile( $deep );
	}

	public function test_ast_compiles_once_and_evaluates_many_records(): void {
		$ast = $this->engine->compile( 'max(regular_price - 10, 9.99)' );
		$this->assertEqualsWithDelta( 89.0, (float) $this->engine->evaluate_ast( $ast, [ 'regular_price' => 99 ] ), 1e-6 );
		$this->assertEqualsWithDelta( 9.99, (float) $this->engine->evaluate_ast( $ast, [ 'regular_price' => 12 ] ), 1e-6 );
	}

	public function test_extracts_unique_variables_from_computed_formula(): void {
		$expr = 'if(regular_price > 0 && sale_price > 0, round((1 - sale_price / regular_price) * 100, 0), 0)';
		$vars = $this->engine->extract_variables( $expr );
		$this->assertSame( [ 'regular_price', 'sale_price' ], $vars );

		$this->assertSame( [], $this->engine->extract_variables( '10 + 20 * 3' ) );
		$this->assertSame( [], $this->engine->extract_variables( 'invalid(((syntax' ) );
	}

	public function test_subtraction_without_spaces_around_hyphen(): void {
		$ctx = [
			'regular_price' => 100.0,
			'sale_price'    => 75.0,
		];
		$this->assertEqualsWithDelta( 25.0, (float) $this->engine->evaluate( 'regular_price-sale_price', $ctx ), 1e-6 );
		$this->assertEqualsWithDelta( 90.0, (float) $this->engine->evaluate( 'regular_price-10', $ctx ), 1e-6 );
		$this->assertEqualsWithDelta( 89.99, (float) $this->engine->evaluate( 'round(regular_price*0.9-0.01,2)', $ctx ), 1e-6 );
	}

	public function test_compile_and_validate_reject_unknown_functions_and_bad_if_arity(): void {
		$bad_fn = $this->engine->validate( 'raund(regular_price, 2)' );
		$this->assertIsString( $bad_fn );
		$this->assertStringContainsString( 'Unknown formula function', $bad_fn );

		$bad_if = $this->engine->validate( 'if(regular_price > 0)' );
		$this->assertIsString( $bad_if );
		$this->assertStringContainsString( 'if() expects 2 or 3 arguments', $bad_if );

		$this->assertTrue( $this->engine->validate( 'round(regular_price * 0.9, 2)' ) );
	}

	public function test_empty_string_addition_and_boolean_numeric_equality(): void {
		$this->assertEqualsWithDelta( 100.0, (float) $this->engine->evaluate( 'regular_price + sale_price', [ 'regular_price' => 100, 'sale_price' => '' ] ), 1e-6 );
		$this->assertTrue( (bool) $this->engine->evaluate( 'manage_stock == 0', [ 'manage_stock' => false ] ) );
		$this->assertTrue( (bool) $this->engine->evaluate( 'manage_stock == 1', [ 'manage_stock' => true ] ) );
		$this->assertFalse( (bool) $this->engine->evaluate( 'manage_stock == 1', [ 'manage_stock' => false ] ) );
	}
}
