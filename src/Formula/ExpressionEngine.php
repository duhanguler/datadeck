<?php
/**
 * Safe recursive-descent AST parser and evaluator for DataDeck bulk formulas and templates.
 * Completely eliminates PHP eval() while supporting arithmetic, conditionals, and string helpers.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Formula;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ExpressionEngine {

	private const MAX_DEPTH = 32;

	/**
	 * Whitelisted pure formula functions validated at compile time.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_FUNCTIONS = [
		'if',
		'round',
		'ceil',
		'floor',
		'abs',
		'min',
		'max',
		'concat',
		'upper',
		'lower',
		'trim',
		'replace',
		'len',
		'slugify',
		'coalesce',
	];

	private Tokenizer $tokenizer;

	/**
	 * Active token stream during parsing.
	 *
	 * @var array<int, Token>
	 */
	private array $tokens = [];

	private int $cursor = 0;

	private int $depth = 0;

	/**
	 * Constructor.
	 */
	public function __construct( ?Tokenizer $tokenizer = null ) {
		$this->tokenizer = $tokenizer ?? new Tokenizer();
	}

	/**
	 * Validate formula syntax without mutating anything.
	 *
	 * @param string $expression Formula expression.
	 * @return true|string True when valid, error message string otherwise.
	 */
	public function validate( string $expression ): bool|string {
		try {
			$this->compile( $expression );
			return true;
		} catch ( InvalidArgumentException $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * Compile an expression into a reusable AST array so 10,000 rows parse only ONCE.
	 *
	 * @param string $expression Raw expression string.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException On syntax errors.
	 */
	public function compile( string $expression ): array {
		$trimmed = trim( $expression );
		if ( '' === $trimmed ) {
			throw new InvalidArgumentException( 'Formula cannot be empty.' );
		}

		$this->tokens = $this->tokenizer->tokenize( $trimmed );
		$this->cursor = 0;
		$this->depth  = 0;

		$ast = $this->parse_or();
		if ( Token::EOF !== $this->peek()->type ) {
			throw new InvalidArgumentException(
				sprintf( 'Unexpected token "%s" at position %d.', esc_html( (string) $this->peek()->value ), (int) $this->peek()->position )
			);
		}

		return $ast;
	}

	/**
	 * Evaluate a pre-compiled AST against a single record context.
	 *
	 * @param array<string, mixed> $ast     Compiled AST node.
	 * @param array<string, mixed> $context Field values keyed by field ID.
	 */
	public function evaluate_ast( array $ast, array $context ): mixed {
		return match ( $ast['kind'] ) {
			'literal' => $ast['value'],
			'ident'   => $this->resolve_identifier( (string) $ast['name'], $context ),
			'unary'   => $this->eval_unary( (string) $ast['op'], $this->evaluate_ast( $ast['expr'], $context ) ),
			'binary'  => $this->eval_binary( (string) $ast['op'], $ast['left'], $ast['right'], $context ),
			'call'    => $this->eval_call( (string) $ast['name'], $ast['args'], $context ),
			default   => throw new InvalidArgumentException( 'Unknown AST node kind.' ),
		};
	}

	/**
	 * Convenience one-shot compile + evaluate.
	 *
	 * @param string               $expression Raw formula string.
	 * @param array<string, mixed> $context    Record field values.
	 */
	public function evaluate( string $expression, array $context ): mixed {
		return $this->evaluate_ast( $this->compile( $expression ), $context );
	}

	/**
	 * Extract unique variable/field identifiers referenced inside a formula expression.
	 *
	 * @param string $expression Raw formula expression.
	 * @return array<int, string>
	 */
	public function extract_variables( string $expression ): array {
		try {
			$ast = $this->compile( $expression );
		} catch ( InvalidArgumentException ) {
			return [];
		}

		$vars = [];
		$this->collect_ast_identifiers( $ast, $vars );
		return array_values( array_unique( $vars ) );
	}

	/**
	 * Recursively walk an AST node and collect referenced identifier names.
	 *
	 * @param array<string, mixed> $ast  AST node.
	 * @param array<int, string>   $vars Accumulator.
	 */
	private function collect_ast_identifiers( array $ast, array &$vars ): void {
		$kind = (string) ( $ast['kind'] ?? '' );
		if ( 'ident' === $kind ) {
			$name = (string) ( $ast['name'] ?? '' );
			if ( '' !== $name && ! in_array( strtolower( $name ), [ 'true', 'false', 'null' ], true ) ) {
				$vars[] = $name;
			}
			return;
		}
		if ( 'unary' === $kind && is_array( $ast['expr'] ?? null ) ) {
			$this->collect_ast_identifiers( $ast['expr'], $vars );
			return;
		}
		if ( 'binary' === $kind ) {
			if ( is_array( $ast['left'] ?? null ) ) {
				$this->collect_ast_identifiers( $ast['left'], $vars );
			}
			if ( is_array( $ast['right'] ?? null ) ) {
				$this->collect_ast_identifiers( $ast['right'], $vars );
			}
			return;
		}
		if ( 'call' === $kind && is_array( $ast['args'] ?? null ) ) {
			foreach ( $ast['args'] as $arg ) {
				if ( is_array( $arg ) ) {
					$this->collect_ast_identifiers( $arg, $vars );
				}
			}
		}
	}

	/**
	 * Interpolate dynamic template tokens like "{title} - {sku} | {site_name}".
	 *
	 * @param string               $template Template string.
	 * @param array<string, mixed> $context  Record field values.
	 */
	public function render_template( string $template, array $context ): string {
		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$year      = gmdate( 'Y' );

		$result = preg_replace_callback(
			'/\{([a-zA-Z0-9_\-:]+)\}/',
			static function ( array $matches ) use ( $context, $site_name, $year ): string {
				$key = $matches[1];
				if ( 'site_name' === $key ) {
					return $site_name;
				}
				if ( 'year' === $key ) {
					return $year;
				}
				if ( array_key_exists( $key, $context ) ) {
					$val = $context[ $key ];
					if ( is_array( $val ) ) {
						return implode( ', ', array_map( static fn( mixed $item ): string => is_array( $item ) ? (string) ( $item['name'] ?? $item['label'] ?? '' ) : (string) $item, $val ) );
					}
					return (string) $val;
				}
				return '';
			},
			$template
		);

		return is_string( $result ) ? $result : $template;
	}

	/**
	 * Resolve identifier from record context or global constants.
	 *
	 * @param string               $name    Identifier name.
	 * @param array<string, mixed> $context Record context.
	 */
	private function resolve_identifier( string $name, array $context ): mixed {
		if ( array_key_exists( $name, $context ) ) {
			return $context[ $name ];
		}
		if ( 'site_name' === $name ) {
			return function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		}
		if ( 'current_year' === $name ) {
			return (int) gmdate( 'Y' );
		}
		return null;
	}

	/**
	 * Evaluate unary operators.
	 */
	private function eval_unary( string $op, mixed $value ): mixed {
		if ( '-' === $op ) {
			return -( (float) $value );
		}
		if ( '!' === $op ) {
			return ! (bool) $value;
		}
		return $value;
	}

	/**
	 * Evaluate binary operators with short-circuiting for && and ||.
	 *
	 * @param string               $op      Operator symbol.
	 * @param array<string, mixed> $left    Left AST node.
	 * @param array<string, mixed> $right   Right AST node.
	 * @param array<string, mixed> $context Record context.
	 */
	private function eval_binary( string $op, array $left, array $right, array $context ): mixed {
		if ( '&&' === $op ) {
			return (bool) $this->evaluate_ast( $left, $context ) && (bool) $this->evaluate_ast( $right, $context );
		}
		if ( '||' === $op ) {
			return (bool) $this->evaluate_ast( $left, $context ) || (bool) $this->evaluate_ast( $right, $context );
		}

		$lhs = $this->evaluate_ast( $left, $context );
		$rhs = $this->evaluate_ast( $right, $context );

		return match ( $op ) {
			'+'  => ( ( is_string( $lhs ) && '' !== $lhs && ! is_numeric( $lhs ) ) || ( is_string( $rhs ) && '' !== $rhs && ! is_numeric( $rhs ) ) )
				? ( (string) $lhs . (string) $rhs )
				: ( (float) $lhs + (float) $rhs ),
			'-'  => (float) $lhs - (float) $rhs,
			'*'  => (float) $lhs * (float) $rhs,
			'/'  => ( (float) $rhs ) === 0.0 ? 0.0 : ( (float) $lhs / (float) $rhs ),
			'%'  => ( (float) $rhs ) === 0.0 ? 0.0 : fmod( (float) $lhs, (float) $rhs ),
			'==' => $this->loosely_equal( $lhs, $rhs ),
			'!=' => ! $this->loosely_equal( $lhs, $rhs ),
			'>'  => (float) $lhs > (float) $rhs,
			'>=' => (float) $lhs >= (float) $rhs,
			'<'  => (float) $lhs < (float) $rhs,
			'<=' => (float) $lhs <= (float) $rhs,
			default => throw new InvalidArgumentException( sprintf( 'Unsupported operator "%s".', esc_html( $op ) ) ),
		};
	}

	/**
	 * Safe scalar equality handling numeric strings and booleans cleanly.
	 */
	private function loosely_equal( mixed $a, mixed $b ): bool {
		if ( is_bool( $a ) && is_bool( $b ) ) {
			return $a === $b;
		}
		if ( is_bool( $a ) && is_numeric( $b ) ) {
			return ( 0.0 !== (float) $b ) === $a;
		}
		if ( is_bool( $b ) && is_numeric( $a ) ) {
			return ( 0.0 !== (float) $a ) === $b;
		}
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return abs( (float) $a - (float) $b ) < 1e-9;
		}
		return (string) $a === (string) $b;
	}

	/**
	 * Execute whitelisted pure functions only.
	 *
	 * @param string                            $name    Function name.
	 * @param array<int, array<string, mixed>>  $args    AST argument nodes.
	 * @param array<string, mixed>              $context Record context.
	 */
	private function eval_call( string $name, array $args, array $context ): mixed {
		$fn = strtolower( $name );

		// Lazy evaluation for if(cond, then, else).
		if ( 'if' === $fn ) {
			if ( count( $args ) < 2 || count( $args ) > 3 ) {
				throw new InvalidArgumentException( 'if() expects 2 or 3 arguments.' );
			}
			$cond = (bool) $this->evaluate_ast( $args[0], $context );
			if ( $cond ) {
				return $this->evaluate_ast( $args[1], $context );
			}
			return isset( $args[2] ) ? $this->evaluate_ast( $args[2], $context ) : null;
		}

		$values = array_map( fn( array $arg ): mixed => $this->evaluate_ast( $arg, $context ), $args );

		return match ( $fn ) {
			'round'    => round( (float) ( $values[0] ?? 0 ), (int) ( $values[1] ?? 2 ) ),
			'ceil'     => ceil( (float) ( $values[0] ?? 0 ) ),
			'floor'    => floor( (float) ( $values[0] ?? 0 ) ),
			'abs'      => abs( (float) ( $values[0] ?? 0 ) ),
			'min'      => count( $values ) > 0 ? min( array_map( 'floatval', $values ) ) : 0.0,
			'max'      => count( $values ) > 0 ? max( array_map( 'floatval', $values ) ) : 0.0,
			'concat'   => implode( '', array_map( 'strval', $values ) ),
			'upper'    => function_exists( 'mb_strtoupper' ) ? mb_strtoupper( (string) ( $values[0] ?? '' ) ) : strtoupper( (string) ( $values[0] ?? '' ) ),
			'lower'    => function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) ( $values[0] ?? '' ) ) : strtolower( (string) ( $values[0] ?? '' ) ),
			'trim'     => trim( (string) ( $values[0] ?? '' ) ),
			'replace'  => str_replace( (string) ( $values[1] ?? '' ), (string) ( $values[2] ?? '' ), (string) ( $values[0] ?? '' ) ),
			'len'      => function_exists( 'mb_strlen' ) ? mb_strlen( (string) ( $values[0] ?? '' ) ) : strlen( (string) ( $values[0] ?? '' ) ),
			'slugify'  => function_exists( 'sanitize_title' ) ? sanitize_title( (string) ( $values[0] ?? '' ) ) : strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', trim( (string) ( $values[0] ?? '' ) ) ) ),
			'coalesce' => $this->eval_coalesce( $values ),
			default    => throw new InvalidArgumentException( sprintf( 'Unknown formula function "%s()".', esc_html( $name ) ) ),
		};
	}

	/**
	 * Return the first non-null, non-empty argument.
	 *
	 * @param array<int, mixed> $values Evaluated arguments.
	 */
	private function eval_coalesce( array $values ): mixed {
		foreach ( $values as $val ) {
			if ( null !== $val && '' !== $val ) {
				return $val;
			}
		}
		return null;
	}

	/**
	 * Grammar: or := and ( '||' and )*
	 *
	 * @return array<string, mixed>
	 */
	private function parse_or(): array {
		$node = $this->parse_and();
		while ( $this->match_op( '||' ) ) {
			$node = [
				'kind'  => 'binary',
				'op'    => '||',
				'left'  => $node,
				'right' => $this->parse_and(),
			];
		}
		return $node;
	}

	/**
	 * Grammar: and := equality ( '&&' equality )*
	 *
	 * @return array<string, mixed>
	 */
	private function parse_and(): array {
		$node = $this->parse_equality();
		while ( $this->match_op( '&&' ) ) {
			$node = [
				'kind'  => 'binary',
				'op'    => '&&',
				'left'  => $node,
				'right' => $this->parse_equality(),
			];
		}
		return $node;
	}

	/**
	 * Grammar: equality := comparison ( ('==' | '!=') comparison )*
	 *
	 * @return array<string, mixed>
	 */
	private function parse_equality(): array {
		$node = $this->parse_comparison();
		while ( true ) {
			if ( $this->match_op( '==' ) ) {
				$node = [
					'kind'  => 'binary',
					'op'    => '==',
					'left'  => $node,
					'right' => $this->parse_comparison(),
				];
			} elseif ( $this->match_op( '!=' ) ) {
				$node = [
					'kind'  => 'binary',
					'op'    => '!=',
					'left'  => $node,
					'right' => $this->parse_comparison(),
				];
			} else {
				break;
			}
		}
		return $node;
	}

	/**
	 * Grammar: comparison := term ( ('>' | '>=' | '<' | '<=') term )*
	 *
	 * @return array<string, mixed>
	 */
	private function parse_comparison(): array {
		$node = $this->parse_term();
		while ( true ) {
			$matched = false;
			foreach ( [ '>=', '<=', '>', '<' ] as $op ) {
				if ( $this->match_op( $op ) ) {
					$node    = [
						'kind'  => 'binary',
						'op'    => $op,
						'left'  => $node,
						'right' => $this->parse_term(),
					];
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				break;
			}
		}
		return $node;
	}

	/**
	 * Grammar: term := factor ( ('+' | '-') factor )*
	 *
	 * @return array<string, mixed>
	 */
	private function parse_term(): array {
		$node = $this->parse_factor();
		while ( true ) {
			if ( $this->match_op( '+' ) ) {
				$node = [
					'kind'  => 'binary',
					'op'    => '+',
					'left'  => $node,
					'right' => $this->parse_factor(),
				];
			} elseif ( $this->match_op( '-' ) ) {
				$node = [
					'kind'  => 'binary',
					'op'    => '-',
					'left'  => $node,
					'right' => $this->parse_factor(),
				];
			} else {
				break;
			}
		}
		return $node;
	}

	/**
	 * Grammar: factor := unary ( ('*' | '/' | '%') unary )*
	 *
	 * @return array<string, mixed>
	 */
	private function parse_factor(): array {
		$node = $this->parse_unary();
		while ( true ) {
			if ( $this->match_op( '*' ) ) {
				$node = [
					'kind'  => 'binary',
					'op'    => '*',
					'left'  => $node,
					'right' => $this->parse_unary(),
				];
			} elseif ( $this->match_op( '/' ) ) {
				$node = [
					'kind'  => 'binary',
					'op'    => '/',
					'left'  => $node,
					'right' => $this->parse_unary(),
				];
			} elseif ( $this->match_op( '%' ) ) {
				$node = [
					'kind'  => 'binary',
					'op'    => '%',
					'left'  => $node,
					'right' => $this->parse_unary(),
				];
			} else {
				break;
			}
		}
		return $node;
	}

	/**
	 * Grammar: unary := ('-' | '!') unary | primary
	 *
	 * @return array<string, mixed>
	 */
	private function parse_unary(): array {
		if ( $this->match_op( '-' ) ) {
			return [
				'kind' => 'unary',
				'op'   => '-',
				'expr' => $this->parse_unary(),
			];
		}
		if ( $this->match_op( '!' ) ) {
			return [
				'kind' => 'unary',
				'op'   => '!',
				'expr' => $this->parse_unary(),
			];
		}
		return $this->parse_primary();
	}

	/**
	 * Grammar: primary := NUMBER | STRING | BOOL | IDENT ('(' args? ')')? | '(' expr ')'
	 *
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException On invalid syntax or nesting overflow.
	 */
	private function parse_primary(): array {
		++$this->depth;
		if ( $this->depth > self::MAX_DEPTH ) {
			throw new InvalidArgumentException( 'Formula nesting depth exceeds safety limit.' );
		}

		$tok = $this->peek();

		if ( in_array( $tok->type, [ Token::NUMBER, Token::STRING, Token::BOOL ], true ) ) {
			$this->advance();
			--$this->depth;
			return [
				'kind'  => 'literal',
				'value' => $tok->value,
			];
		}

		if ( Token::IDENT === $tok->type ) {
			$this->advance();
			if ( Token::LPAREN === $this->peek()->type ) {
				$this->advance();
				$args = [];
				$next = $this->peek();
				if ( Token::RPAREN !== $next->type ) {
					do {
						$args[] = $this->parse_or();
					} while ( $this->match_type( Token::COMMA ) );
				}
				if ( ! $this->match_type( Token::RPAREN ) ) {
					throw new InvalidArgumentException( sprintf( 'Expected closing ")" for function %s().', esc_html( (string) $tok->value ) ) );
				}
				$fn_name  = (string) $tok->value;
				$fn_lower = strtolower( $fn_name );
				if ( ! in_array( $fn_lower, self::ALLOWED_FUNCTIONS, true ) ) {
					throw new InvalidArgumentException( sprintf( 'Unknown formula function "%s()".', esc_html( $fn_name ) ) );
				}
				if ( 'if' === $fn_lower && ( count( $args ) < 2 || count( $args ) > 3 ) ) {
					throw new InvalidArgumentException( 'if() expects 2 or 3 arguments.' );
				}
				--$this->depth;
				return [
					'kind' => 'call',
					'name' => $fn_name,
					'args' => $args,
				];
			}
			--$this->depth;
			return [
				'kind' => 'ident',
				'name' => (string) $tok->value,
			];
		}

		if ( $this->match_type( Token::LPAREN ) ) {
			$expr = $this->parse_or();
			if ( ! $this->match_type( Token::RPAREN ) ) {
				throw new InvalidArgumentException( 'Expected closing ")".' );
			}
			--$this->depth;
			return $expr;
		}

		throw new InvalidArgumentException(
			sprintf( 'Unexpected token "%s" at position %d.', esc_html( (string) ( $tok->value ?? 'EOF' ) ), (int) $tok->position )
		);
	}

	private function peek(): Token {
		return $this->tokens[ $this->cursor ] ?? new Token( Token::EOF, null, 0 );
	}

	private function advance(): Token {
		$tok = $this->peek();
		++$this->cursor;
		return $tok;
	}

	private function match_type( string $type ): bool {
		if ( $this->peek()->type === $type ) {
			$this->advance();
			return true;
		}
		return false;
	}

	private function match_op( string $op ): bool {
		$tok = $this->peek();
		if ( Token::OP === $tok->type && $tok->value === $op ) {
			$this->advance();
			return true;
		}
		return false;
	}
}
