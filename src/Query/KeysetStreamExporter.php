<?php
/**
 * O(1) Memory Streaming CSV / NDJSON Exporter using Keyset Pagination.
 *
 * Streams 50,000+ rows directly to php://output in 500-row chunks while clearing
 * WordPress runtime object cache between iterations to stay under ~20 MB RAM.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Query;

use DataDeck\Contracts\EntityAdapterInterface;
use DataDeck\Schema\FieldDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KeysetStreamExporter {

	private const CHUNK_SIZE = 500;

	/**
	 * Stream filtered records to php://output as RFC-4180 CSV or JSON.
	 *
	 * @param EntityAdapterInterface         $adapter Entity adapter.
	 * @param QueryRequest                   $request Sanitized query request.
	 * @param array<string, FieldDefinition> $fields  Ordered projected fields.
	 * @param string                         $format  'csv' or 'json'.
	 */
	public function stream(
		EntityAdapterInterface $adapter,
		QueryRequest $request,
		array $fields,
		string $format = 'csv'
	): void {
		$format   = 'json' === strtolower( $format ) ? 'json' : 'csv';
		$filename = sprintf(
			'datadeck-%s-%s.%s',
			sanitize_file_name( $adapter->get_slug() ),
			gmdate( 'Ymd-His' ),
			$format
		);

		if ( ! headers_sent() ) {
			nocache_headers();
			header(
				'json' === $format
					? 'Content-Type: application/json; charset=utf-8'
					: 'Content-Type: text/csv; charset=utf-8'
			);
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'X-Content-Type-Options: nosniff' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'wb' );
		if ( false === $out ) {
			return;
		}

		$field_ids = array_keys( $fields );

		if ( 'csv' === $format ) {
			// UTF-8 BOM for seamless Microsoft Excel character decoding.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $out, "\xEF\xBB\xBF" );
			$headers = array_map( static fn( FieldDefinition $f ): string => $f->label, array_values( $fields ) );
			fputcsv( $out, $headers, ',', '"', '' );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $out, '[' );
		}

		$after_id  = 0;
		$first_row = true;

		while ( true ) {
			$ids = $adapter->stream_ids( $request, $after_id, self::CHUNK_SIZE );
			if ( empty( $ids ) ) {
				break;
			}

			$records = $adapter->hydrate_records( $ids, $field_ids );

			foreach ( $records as $record ) {
				if ( 'csv' === $format ) {
					$line = [];
					foreach ( $field_ids as $fid ) {
						$line[] = $this->format_csv_cell( $record[ $fid ] ?? '' );
					}
					fputcsv( $out, $line, ',', '"', '' );
				} else {
					$row_out = [];
					foreach ( $field_ids as $fid ) {
						$row_out[ $fid ] = $record[ $fid ] ?? null;
					}
					$prefix = $first_row ? '' : ',';
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					fwrite( $out, $prefix . (string) wp_json_encode( $row_out ) );
					$first_row = false;
				}
			}

			$after_id = (int) max( $ids );

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}

			if ( count( $ids ) < self::CHUNK_SIZE ) {
				break;
			}
		}

		if ( 'json' === $format ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $out, ']' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
	}

	/**
	 * Normalize a field value to a safe CSV scalar with formula-injection neutralization.
	 */
	private function format_csv_cell( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['url'] ) ) {
				return (string) $value['url'];
			}
			$parts = array_map(
				static fn( mixed $item ): string => is_array( $item )
					? (string) ( $item['label'] ?? $item['name'] ?? $item['id'] ?? '' )
					: (string) $item,
				$value
			);
			$str   = implode( ', ', array_filter( $parts, static fn( string $s ): bool => '' !== $s ) );
		} else {
			$str = (string) $value;
		}

		// Protect spreadsheet apps from CSV formula injection (=, +, -, @, tab, CR).
		if ( '' !== $str && in_array( $str[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) && ! is_numeric( $str ) ) {
			$str = "'" . $str;
		}

		return $str;
	}
}
