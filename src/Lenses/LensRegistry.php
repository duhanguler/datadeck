<?php
/**
 * Built-in Zero-Setup Smart Audit Lenses (SEO, WooCommerce Margin/Inventory, Media Alt Hygiene).
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Lenses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LensRegistry {

	/**
	 * Return contextual audit lenses for an entity.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_lenses_for_entity( string $entity_slug ): array {
		if ( 'product' === $entity_slug ) {
			return [
				[
					'id'          => 'all',
					'label'       => __( 'All Products', 'rowcraft' ),
					'description' => __( 'Complete product catalog.', 'rowcraft' ),
					'columns'     => [ 'title', 'featured_image', 'id', 'sku', 'regular_price', 'sale_price', 'stock_status', 'stock_quantity', 'product_cat', 'status' ],
				],
				[
					'id'          => 'woo_margin_audit',
					'label'       => __( 'Pricing & SKU Audit', 'rowcraft' ),
					'description' => __( 'Products missing prices, missing SKUs, or with sale price >= regular price.', 'rowcraft' ),
					'columns'     => [ 'title', 'id', 'sku', 'regular_price', 'sale_price', 'status' ],
				],
				[
					'id'          => 'woo_inventory_risk',
					'label'       => __( 'Inventory Risk', 'rowcraft' ),
					'description' => __( 'Out-of-stock or low-stock (<= 5) products.', 'rowcraft' ),
					'columns'     => [ 'title', 'id', 'sku', 'stock_status', 'stock_quantity', 'manage_stock', 'regular_price' ],
				],
				[
					'id'          => 'woo_missing_media',
					'label'       => __( 'Missing Images', 'rowcraft' ),
					'description' => __( 'Products without a featured image.', 'rowcraft' ),
					'columns'     => [ 'title', 'featured_image', 'id', 'sku', 'status' ],
				],
			];
		}

		if ( 'attachment' === $entity_slug ) {
			return [
				[
					'id'          => 'all',
					'label'       => __( 'All Media', 'rowcraft' ),
					'description' => __( 'All uploaded files.', 'rowcraft' ),
					'columns'     => [ 'title', 'preview', 'id', 'alt_text', 'caption', 'mime', 'dimensions', 'file_size_kb', 'date' ],
				],
				[
					'id'          => 'media_missing_alt',
					'label'       => __( 'Missing Alt Text', 'rowcraft' ),
					'description' => __( 'Images missing accessibility alt text.', 'rowcraft' ),
					'columns'     => [ 'title', 'preview', 'id', 'alt_text', 'caption', 'dimensions' ],
				],
			];
		}

		if ( in_array( $entity_slug, [ 'user', 'shop_order' ], true ) ) {
			return [
				[
					'id'          => 'all',
					'label'       => __( 'All Records', 'rowcraft' ),
					'description' => __( 'Default list view.', 'rowcraft' ),
					'columns'     => [],
				],
			];
		}

		return [
			[
				'id'          => 'all',
				'label'       => __( 'All Items', 'rowcraft' ),
				'description' => __( 'Complete list.', 'rowcraft' ),
				'columns'     => [],
			],
			[
				'id'          => 'seo_audit',
				'label'       => __( 'SEO Audit', 'rowcraft' ),
				'description' => __( 'Long titles (> 60 chars), missing featured images, or empty excerpts.', 'rowcraft' ),
				'columns'     => [ 'title', 'featured_image', 'id', 'slug', 'seo_title', 'seo_description', 'seo_focus_kw', 'status' ],
			],
			[
				'id'          => 'content_hygiene',
				'label'       => __( 'Content Hygiene', 'rowcraft' ),
				'description' => __( 'Drafts, pending items, or posts missing excerpts.', 'rowcraft' ),
				'columns'     => [ 'title', 'id', 'status', 'author', 'excerpt', 'word_count', 'date' ],
			],
		];
	}
}
