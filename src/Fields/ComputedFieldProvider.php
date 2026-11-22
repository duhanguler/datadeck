<?php
/**
 * Computed (Virtual Formula) Field Provider.
 *
 * Evaluates read-only AST formulas on hydrated rows without writing to the database.
 * Ships with zero-config WooCommerce formulas (Discount %, Inventory Value) and supports
 * custom user-defined formula columns stored in wp_options.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Fields;

use DataDeck\Contracts\FieldProviderInterface;
use DataDeck\Formula\ExpressionEngine;
use DataDeck\Schema\FieldDefinition;
use InvalidArgumentException;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ComputedFieldProvider implements FieldProviderInterface {

	private const OPTION_KEY = 'datadeck_computed_fields';

	public function __construct(
		private readonly ExpressionEngine $formula,
	) {}

	public function get_id(): string {
		return 'computed';
	}

	public function supports( string $entity_slug ): bool {
		return '' !== $entity_slug;
	}

	/**
	 * Return built-in + user-defined computed fields for an entity.
	 *
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		$fields   = [];
		$currency = function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
			: '$';

		// Built-in zero-config WooCommerce computed formulas.
		if ( 'product' === $entity_slug ) {
			$fields[] = $this->make_definition(
				'computed_discount_pct',
				__( 'Discount %', 'datadeck' ),
				'if(regular_price > 0 && sale_price > 0 && sale_price < regular_price, round((1 - sale_price / regular_price) * 100, 0), 0)',
				'number',
				[
					'suffix'  => '%',
					'builtIn' => true,
				]
			);
			$fields[] = $this->make_definition(
				'computed_inventory_value',
				__( 'Inventory Value', 'datadeck' ),
				'if(regular_price > 0 && stock_quantity > 0, round(regular_price * stock_quantity, 2), 0)',
				'currency',
				[
					'currency' => $currency,
					'builtIn'  => true,
				]
			);
		}

		// User-defined custom computed columns.
		foreach ( $this->list_custom( $entity_slug ) as $item ) {
			$format = in_array( $item['format'], [ 'number', 'currency', 'percent', 'text' ], true ) ? $item['format'] : 'number';
			$type   = match ( $format ) {
				'currency' => 'currency',
				'text'     => 'text',
				default    => 'number',
			};
			$extra = [
				'builtIn' => false,
				'format'  => $format,
			];
			if ( 'currency' === $format ) {
				$extra['currency'] = $currency;
			}
			if ( 'percent' === $format ) {
				$extra['suffix'] = '%';
			}

			$fields[] = $this->make_definition(
				$item['id'],
				$item['label'],
				$item['formula'],
				$type,
				$extra
			);
		}

		return $fields;
	}

	public function get_required_meta_keys( array $fields ): array {
		return [];
	}

	/**
	 * Evaluate compiled AST formulas over hydrated rows (1 compile per column, O(N) eval).
	 */
	public function hydrate(
		string $entity_slug,
		array $ids,
		array $fields,
		array &$rows,
		array $meta_cache
	): void {
		foreach ( $fields as $fid => $field ) {
			$expr = (string) ( $field->extra['formula'] ?? '' );
			if ( '' === $expr ) {
				continue;
			}

			try {
				$ast = $this->formula->compile( $expr );
			} catch ( InvalidArgumentException ) {
				foreach ( $ids as $id ) {
					$rows[ $id ][ $fid ] = null;
				}
				continue;
			}

			foreach ( $ids as $id ) {
				$context = $rows[ $id ] ?? [ 'id' => $id ];
				try {
					$rows[ $id ][ $fid ] = $this->formula->evaluate_ast( $ast, $context );
				} catch ( InvalidArgumentException ) {
					$rows[ $id ][ $fid ] = null;
				}
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		return null;
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): WP_Error {
		return new WP_Error( 'datadeck_computed_readonly', __( 'Computed formula columns are read-only.', 'datadeck' ), [ 'status' => 400 ] );
	}

	/**
	 * List custom saved computed columns for a specific entity (or all when empty).
	 *
	 * @return array<int, array{id: string, entity: string, label: string, formula: string, format: string}>
	 */
	public function list_custom( string $entity_slug = '' ): array {
		$raw = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['formula'] ) ) {
				continue;
			}
			$ent = sanitize_key( (string) ( $item['entity'] ?? '' ) );
			if ( '' !== $entity_slug && $ent !== $entity_slug ) {
				continue;
			}
			$out[] = [
				'id'      => sanitize_key( (string) $item['id'] ),
				'entity'  => $ent,
				'label'   => sanitize_text_field( (string) ( $item['label'] ?? $item['id'] ) ),
				'formula' => (string) $item['formula'],
				'format'  => sanitize_key( (string) ( $item['format'] ?? 'number' ) ),
			];
		}

		return $out;
	}

	/**
	 * Create or update a custom computed formula column after validating AST syntax.
	 *
	 * @param string               $entity_slug Entity slug.
	 * @param array<string, mixed> $payload     Payload with label, formula, format, optional id.
	 * @return array{id: string, entity: string, label: string, formula: string, format: string}|WP_Error
	 */
	public function save_custom( string $entity_slug, array $payload ): array|WP_Error {
		$label   = sanitize_text_field( (string) ( $payload['label'] ?? '' ) );
		$formula = trim( (string) ( $payload['formula'] ?? '' ) );
		$format  = sanitize_key( (string) ( $payload['format'] ?? 'number' ) );

		if ( '' === $label ) {
			return new WP_Error( 'datadeck_missing_label', __( 'Column label is required.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$valid = $this->formula->validate( $formula );
		if ( true !== $valid ) {
			return new WP_Error( 'datadeck_invalid_formula', (string) $valid, [ 'status' => 400 ] );
		}

		if ( ! in_array( $format, [ 'number', 'currency', 'percent', 'text' ], true ) ) {
			$format = 'number';
		}

		$id = ! empty( $payload['id'] )
			? sanitize_key( (string) $payload['id'] )
			: 'computed_' . sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );

		if ( ! str_starts_with( $id, 'computed_' ) ) {
			$id = 'computed_' . $id;
		}

		$record = [
			'id'      => $id,
			'entity'  => sanitize_key( $entity_slug ),
			'label'   => $label,
			'formula' => $formula,
			'format'  => $format,
		];

		$all = get_option( self::OPTION_KEY, [] );
		$all = is_array( $all ) ? $all : [];

		$updated = false;
		foreach ( $all as $idx => $existing ) {
			if ( is_array( $existing ) && ( $existing['id'] ?? '' ) === $id && ( $existing['entity'] ?? '' ) === $record['entity'] ) {
				$all[ $idx ] = $record;
				$updated     = true;
				break;
			}
		}

		if ( ! $updated ) {
			$all[] = $record;
		}

		update_option( self::OPTION_KEY, $all, false );

		return $record;
	}

	/**
	 * Delete a custom computed column by ID.
	 */
	public function delete_custom( string $entity_slug, string $field_id ): bool {
		$all = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $all ) ) {
			return false;
		}

		$entity   = sanitize_key( $entity_slug );
		$id       = sanitize_key( $field_id );
		$filtered = array_values(
			array_filter(
				$all,
				static fn( mixed $item ): bool => ! ( is_array( $item ) && ( $item['entity'] ?? '' ) === $entity && ( $item['id'] ?? '' ) === $id )
			)
		);

		$deleted = count( $filtered ) < count( $all );
		if ( $deleted ) {
			update_option( self::OPTION_KEY, $filtered, false );
		}
		return $deleted;
	}

	/**
	 * Build a FieldDefinition carrying formula metadata & extracted AST dependencies.
	 *
	 * @param array<string, mixed> $extra Extra metadata.
	 */
	private function make_definition(
		string $id,
		string $label,
		string $formula,
		string $type,
		array $extra = []
	): FieldDefinition {
		$deps = $this->formula->extract_variables( $formula );

		return new FieldDefinition(
			id: $id,
			label: $label,
			type: $type,
			group: 'computed',
			provider: $this->get_id(),
			editable: false,
			sortable: false,
			filterable: false,
			default_visible: false,
			width: 158,
			align: 'text' === $type ? 'left' : 'right',
			tabular_nums: 'text' !== $type,
			extra: array_merge(
				$extra,
				[
					'computed'     => true,
					'formula'      => $formula,
					'dependencies' => $deps,
				]
			),
		);
	}
}
