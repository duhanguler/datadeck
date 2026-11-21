<?php
/**
 * Tokenizer for the DataDeck safe AST formula engine (zero eval).
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Formula;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tokenizer {

	private const MAX_TOKENS = 160;

	/**
	 * Tokenize an expression string into Token instances.
	 *
	 * @param string $input Raw formula string.
	 * @return array<int, Token>
	 * @throws InvalidArgumentException On invalid syntax or excessive token count.
	 */
	public function tokenize( string $input ): array {
		$tokens = [];
		$length = strlen( $input );
		$i      = 0;

		while ( $i < $length ) {
			if ( count( $tokens ) >= self::MAX_TOKENS ) {
				throw new InvalidArgumentException( 'Formula exceeds maximum complexity limit.' );
			}

			$ch = $input[ $i ];

			if ( ctype_space( $ch ) ) {
				++$i;
				continue;
			}

			// Numbers (integer or decimal).
			if ( ctype_digit( $ch ) || ( '.' === $ch && ( $i + 1 ) < $length && ctype_digit( $input[ $i + 1 ] ) ) ) {
				$start = $i;
				$dot   = false;
				while ( $i < $length && ( ctype_digit( $input[ $i ] ) || ( '.' === $input[ $i ] && ! $dot ) ) ) {
					if ( '.' === $input[ $i ] ) {
						$dot = true;
					}
					++$i;
				}
				$num_str  = substr( $input, $start, $i - $start );
				$tokens[] = new Token( Token::NUMBER, (float) $num_str, $start );
				continue;
			}

			// Quoted strings ('...' or "...").
			if ( "'" === $ch || '"' === $ch ) {
				$quote = $ch;
				$start = $i;
				++$i;
				$buf = '';
				while ( $i < $length && $input[ $i ] !== $quote ) {
					if ( '\\' === $input[ $i ] && ( $i + 1 ) < $length ) {
						++$i;
						$buf .= $input[ $i ];
					} else {
						$buf .= $input[ $i ];
					}
					++$i;
				}
				if ( $i >= $length || $input[ $i ] !== $quote ) {
					throw new InvalidArgumentException(
						sprintf( 'Unterminated string literal at position %d.', (int) $start )
					);
				}
				++$i;
				$tokens[] = new Token( Token::STRING, $buf, $start );
				continue;
			}

			// Identifiers, booleans, and field paths (e.g. regular_price, acf:brand, meta:_sku).
			if ( ctype_alpha( $ch ) || '_' === $ch ) {
				$start = $i;
				while ( $i < $length && ( ctype_alnum( $input[ $i ] ) || '_' === $input[ $i ] || ':' === $input[ $i ] || '-' === $input[ $i ] ) ) {
					++$i;
				}
				$word  = substr( $input, $start, $i - $start );
				$lower = strtolower( $word );
				if ( 'true' === $lower || 'false' === $lower ) {
					$tokens[] = new Token( Token::BOOL, 'true' === $lower, $start );
				} else {
					$tokens[] = new Token( Token::IDENT, $word, $start );
				}
				continue;
			}

			// Two-character operators.
			if ( ( $i + 1 ) < $length ) {
				$two = substr( $input, $i, 2 );
				if ( in_array( $two, [ '==', '!=', '>=', '<=', '&&', '||' ], true ) ) {
					$tokens[] = new Token( Token::OP, $two, $i );
					$i       += 2;
					continue;
				}
			}

			// Single-character operators and punctuation.
			if ( in_array( $ch, [ '+', '-', '*', '/', '%', '>', '<', '!' ], true ) ) {
				$tokens[] = new Token( Token::OP, $ch, $i );
				++$i;
				continue;
			}

			if ( '(' === $ch ) {
				$tokens[] = new Token( Token::LPAREN, '(', $i );
				++$i;
				continue;
			}

			if ( ')' === $ch ) {
				$tokens[] = new Token( Token::RPAREN, ')', $i );
				++$i;
				continue;
			}

			if ( ',' === $ch ) {
				$tokens[] = new Token( Token::COMMA, ',', $i );
				++$i;
				continue;
			}

			throw new InvalidArgumentException(
				sprintf( 'Unexpected character "%s" at position %d.', esc_html( $ch ), (int) $i )
			);
		}

		$tokens[] = new Token( Token::EOF, null, $length );

		return $tokens;
	}
}
