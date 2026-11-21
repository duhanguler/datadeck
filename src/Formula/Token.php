<?php
/**
 * Immutable lexer token for the safe AST Formula Engine.
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Formula;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Token {

	public const NUMBER = 'NUMBER';
	public const STRING = 'STRING';
	public const IDENT  = 'IDENT';
	public const BOOL   = 'BOOL';
	public const OP     = 'OP';
	public const LPAREN = 'LPAREN';
	public const RPAREN = 'RPAREN';
	public const COMMA  = 'COMMA';
	public const EOF    = 'EOF';

	/**
	 * Constructor.
	 *
	 * @param string $type     Token type constant.
	 * @param mixed  $value    Parsed scalar value.
	 * @param int    $position Character offset in source expression.
	 */
	public function __construct(
		public readonly string $type,
		public readonly mixed $value,
		public readonly int $position,
	) {}
}
