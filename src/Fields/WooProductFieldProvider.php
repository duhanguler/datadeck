<?php
/**
 * WooCommerce Product & Variation Field Provider.
 *
 * Reads via SelectiveHydrator (zero N+1) and writes strictly through WC_Product CRUD
 * so lookup tables (wc_product_meta_lookup), transients, and HPOS hooks stay 100% consistent.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Fields;

use DataDeck\Contracts\FieldProviderInterface;
use DataDeck\Schema\FieldDefinition;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooProductFieldProvider implements FieldProviderInterface {

	public function get_id(): string {
		return 'woo_product';
	}

	public function supports( string $entity_slug ): bool {
		return 'product' === $entity_slug && class_exists( 'WooCommerce' );
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		if ( ! $this->supports( $entity_slug ) ) {
			return [];
		}

		$currency     = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '$';
		$include_opts = \DataDeck\Plugin::instance()->fields()->should_include_options();
		$cats         = $include_opts ? $this->get_tax_options( 'product_cat' ) : [];
		$tags         = $include_opts ? $this->get_tax_options( 'product_tag' ) : [];

		return [
			new FieldDefinition(
				id: 'sku',
				label: __( 'SKU', 'datadeck' ),
				type: 'text',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 130,
				monospace: true,
				meta_key: '_sku',
			),
			new FieldDefinition(
				id: 'regular_price',
				label: __( 'Regular Price', 'datadeck' ),
				type: 'currency',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 122,
				align: 'right',
				tabular_nums: true,
				meta_key: '_regular_price',
				validation: [ 'min' => 0 ],
				extra: [ 'currency' => $currency ],
			),
			new FieldDefinition(
				id: 'sale_price',
				label: __( 'Sale Price', 'datadeck' ),
				type: 'currency',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 116,
				align: 'right',
				tabular_nums: true,
				meta_key: '_sale_price',
				validation: [ 'min' => 0 ],
				extra: [ 'currency' => $currency ],
			),
			new FieldDefinition(
				id: 'stock_status',
				label: __( 'Stock Status', 'datadeck' ),
				type: 'select',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 132,
				meta_key: '_stock_status',
				options: [
					[
						'value' => 'instock',
						'label' => __( 'In Stock', 'datadeck' ),
					],
					[
						'value' => 'outofstock',
						'label' => __( 'Out of Stock', 'datadeck' ),
					],
					[
						'value' => 'onbackorder',
						'label' => __( 'On Backorder', 'datadeck' ),
					],
				],
			),
			new FieldDefinition(
				id: 'stock_quantity',
				label: __( 'Stock Qty', 'datadeck' ),
				type: 'integer',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 96,
				align: 'right',
				tabular_nums: true,
				meta_key: '_stock',
			),
			new FieldDefinition(
				id: 'manage_stock',
				label: __( 'Manage Stock', 'datadeck' ),
				type: 'boolean',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 108,
				align: 'center',
				meta_key: '_manage_stock',
			),
			new FieldDefinition(
				id: 'product_cat',
				label: __( 'Categories', 'datadeck' ),
				type: 'taxonomy',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: true,
				width: 185,
				taxonomy: 'product_cat',
				options: $cats,
				extra: [ 'hierarchical' => true ],
			),
			new FieldDefinition(
				id: 'product_tag',
				label: __( 'Tags', 'datadeck' ),
				type: 'taxonomy',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 165,
				taxonomy: 'product_tag',
				options: $tags,
			),
			new FieldDefinition(
				id: 'weight',
				label: __( 'Weight', 'datadeck' ),
				type: 'number',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: false,
				width: 96,
				align: 'right',
				tabular_nums: true,
				meta_key: '_weight',
			),
			new FieldDefinition(
				id: 'backorders',
				label: __( 'Backorders', 'datadeck' ),
				type: 'select',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 132,
				meta_key: '_backorders',
				options: [
					[
						'value' => 'no',
						'label' => __( 'Do not allow', 'datadeck' ),
					],
					[
						'value' => 'notify',
						'label' => __( 'Allow, notify', 'datadeck' ),
					],
					[
						'value' => 'yes',
						'label' => __( 'Allow', 'datadeck' ),
					],
				],
			),
			new FieldDefinition(
				id: 'tax_status',
				label: __( 'Tax Status', 'datadeck' ),
				type: 'select',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 120,
				meta_key: '_tax_status',
				options: [
					[
						'value' => 'taxable',
						'label' => __( 'Taxable', 'datadeck' ),
					],
					[
						'value' => 'shipping',
						'label' => __( 'Shipping only', 'datadeck' ),
					],
					[
						'value' => 'none',
						'label' => __( 'None', 'datadeck' ),
					],
				],
			),
			new FieldDefinition(
				id: 'total_sales',
				label: __( 'Total Sales', 'datadeck' ),
				type: 'integer',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: false,
				width: 96,
				align: 'right',
				tabular_nums: true,
				meta_key: 'total_sales',
			),
		];
	}

	public function get_required_meta_keys( array $fields ): array {
		$keys = [];
		foreach ( $fields as $field ) {
			if ( '' !== $field->meta_key ) {
				$keys[] = $field->meta_key;
			}
		}
		return $keys;
	}

	public function hydrate(
		string $entity_slug,
		array $ids,
		array $fields,
		array &$rows,
		array $meta_cache
	): void {
		foreach ( $ids as $pid ) {
			foreach ( $fields as $fid => $field ) {
				if ( 'taxonomy' === $field->type && '' !== $field->taxonomy ) {
					$preloaded            = $meta_cache[ $pid ]['__rc_terms'][0][ $field->taxonomy ] ?? [];
					$rows[ $pid ][ $fid ] = is_array( $preloaded ) ? $preloaded : [];
					continue;
				}

				$raw = $meta_cache[ $pid ][ $field->meta_key ][0] ?? null;

				$rows[ $pid ][ $fid ] = match ( $fid ) {
					'regular_price', 'sale_price', 'weight' => ( null === $raw || '' === $raw ) ? null : (float) $raw,
					'stock_quantity', 'total_sales'         => ( null === $raw || '' === $raw ) ? null : (int) $raw,
					'manage_stock'                          => 'yes' === $raw || true === $raw || '1' === $raw,
					'stock_status'                          => '' !== (string) $raw ? (string) $raw : 'instock',
					default                                 => (string) ( $raw ?? '' ),
				};
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		if ( 'taxonomy' === $field->type && '' !== $field->taxonomy ) {
			$terms = wp_get_object_terms( $record_id, $field->taxonomy, [ 'fields' => 'ids' ] );
			return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $record_id );
			if ( $product ) {
				return match ( $field->id ) {
					'sku'            => $product->get_sku(),
					'regular_price'  => '' === $product->get_regular_price() ? null : (float) $product->get_regular_price(),
					'sale_price'     => '' === $product->get_sale_price() ? null : (float) $product->get_sale_price(),
					'stock_status'   => $product->get_stock_status(),
					'stock_quantity' => $product->get_stock_quantity(),
					'manage_stock'   => $product->get_manage_stock(),
					'weight'         => '' === $product->get_weight() ? null : (float) $product->get_weight(),
					'backorders'     => $product->get_backorders(),
					'tax_status'     => $product->get_tax_status(),
					default          => get_post_meta( $record_id, $field->meta_key, true ),
				};
			}
		}

		return get_post_meta( $record_id, $field->meta_key, true );
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): true|WP_Error {
		if ( 'taxonomy' === $field->type && '' !== $field->taxonomy ) {
			$term_ids = [];
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$term_ids[] = is_array( $item ) ? (int) ( $item['id'] ?? 0 ) : (int) $item;
				}
			} elseif ( is_string( $value ) && '' !== $value ) {
				foreach ( array_map( 'trim', explode( ',', $value ) ) as $name ) {
					$term = get_term_by( 'name', $name, $field->taxonomy );
					if ( $term instanceof \WP_Term ) {
						$term_ids[] = $term->term_id;
					}
				}
			}
			$res = wp_set_object_terms( $record_id, array_values( array_unique( array_filter( $term_ids ) ) ), $field->taxonomy, false );
			return is_wp_error( $res ) ? $res : true;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'datadeck_woo_inactive', __( 'WooCommerce is not active.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$product = wc_get_product( $record_id );
		if ( ! $product ) {
			return new WP_Error( 'datadeck_product_missing', __( 'Product not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		try {
			switch ( $field->id ) {
				case 'sku':
					$product->set_sku( sanitize_text_field( (string) $value ) );
					break;
				case 'regular_price':
					$str = ( null === $value || '' === $value ) ? '' : (string) max( 0.0, (float) $value );
					$product->set_regular_price( $str );
					break;
				case 'sale_price':
					$str = ( null === $value || '' === $value ) ? '' : (string) max( 0.0, (float) $value );
					$product->set_sale_price( $str );
					break;
				case 'stock_status':
					$allowed = [ 'instock', 'outofstock', 'onbackorder' ];
					$st      = sanitize_key( (string) $value );
					if ( ! in_array( $st, $allowed, true ) ) {
						return new WP_Error( 'datadeck_invalid_stock_status', __( 'Invalid stock status.', 'datadeck' ), [ 'status' => 400 ] );
					}
					$product->set_stock_status( $st );
					break;
				case 'stock_quantity':
					if ( null === $value || '' === $value ) {
						$product->set_manage_stock( false );
						$product->set_stock_quantity( null );
					} else {
						$qty = (int) $value;
						$product->set_manage_stock( true );
						$product->set_stock_quantity( $qty );
						$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
					}
					break;
				case 'manage_stock':
					$product->set_manage_stock( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) );
					break;
				case 'weight':
					$product->set_weight( ( null === $value || '' === $value ) ? '' : (string) (float) $value );
					break;
				case 'backorders':
					$product->set_backorders( in_array( $value, [ 'no', 'notify', 'yes' ], true ) ? (string) $value : 'no' );
					break;
				case 'tax_status':
					$product->set_tax_status( in_array( $value, [ 'taxable', 'shipping', 'none' ], true ) ? (string) $value : 'taxable' );
					break;
				default:
					return new WP_Error( 'datadeck_unsupported_woo_field', __( 'Unsupported WooCommerce field.', 'datadeck' ), [ 'status' => 400 ] );
			}

			$product->save();
			return true;
		} catch ( \WC_Data_Exception $e ) {
			return new WP_Error( 'datadeck_wc_data_error', $e->getMessage(), [ 'status' => 400 ] );
		}
	}

	/**
	 * @return array<int, array{value: string, label: string}>
	 */
	private function get_tax_options( string $taxonomy ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 250,
			]
		);
		if ( ! is_array( $terms ) ) {
			return [];
		}
		return array_map(
			static fn( \WP_Term $t ): array => [
				'value' => (string) $t->term_id,
				'label' => $t->name,
			],
			$terms
		);
	}
}
