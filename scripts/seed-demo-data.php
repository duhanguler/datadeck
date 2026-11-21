<?php
/**
 * Deterministic demo dataset seeder for RowCraft local Docker verification & screenshots.
 * Creates realistic Posts, Pages, WooCommerce Simple & Variable Products, HPOS Orders,
 * ACF/SCF Field Groups, SEO metadata, and Media items with intentional audit lens matches.
 *
 * Usage: wp eval-file /var/www/html/wp-content/plugins/rowcraft/scripts/seed-demo-data.php
 *
 * @package RowCraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( get_option( 'rowcraft_demo_seeded' ) ) {
	echo "Demo dataset already seeded.\n";
	return;
}

// 1. Register an ACF / SCF field group if Secure Custom Fields / ACF is active.
if ( function_exists( 'acf_import_field_group' ) ) {
	acf_import_field_group(
		[
			'key'      => 'group_rowcraft_product_specs',
			'title'    => 'Product Specifications',
			'fields'   => [
				[
					'key'   => 'field_rc_brand',
					'label' => 'Brand',
					'name'  => 'product_brand',
					'type'  => 'text',
				],
				[
					'key'     => 'field_rc_supplier_tier',
					'label'   => 'Supplier Tier',
					'name'    => 'supplier_tier',
					'type'    => 'select',
					'choices' => [
						'tier_a' => 'Tier A (Direct)',
						'tier_b' => 'Tier B (Distributor)',
						'tier_c' => 'Tier C (Dropship)',
					],
				],
				[
					'key'   => 'field_rc_unit_cost',
					'label' => 'Unit Cost',
					'name'  => 'unit_cost',
					'type'  => 'number',
				],
				[
					'key'   => 'field_rc_featured_badge',
					'label' => 'Staff Pick',
					'name'  => 'staff_pick',
					'type'  => 'true_false',
				],
			],
			'location' => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'product',
					],
				],
			],
			'active'   => true,
		]
	);
}

// 2. Generate 4 lightweight local PNG media attachments (2 missing alt text for Media Audit Lens).
$upload_dir  = wp_upload_dir();
$att_ids     = [];
$sample_imgs = [
	[ 'file' => 'rc-keyboard.png', 'title' => 'Mechanical Keyboard Aluminum', 'alt' => 'CNC machined wireless mechanical keyboard' ],
	[ 'file' => 'rc-monitor.png', 'title' => 'Studio 5K Display', 'alt' => 'Minimalist matte aluminum 5K desktop monitor' ],
	[ 'file' => 'rc-headphones.png', 'title' => 'Reference Studio Headphones', 'alt' => '' ],
	[ 'file' => 'rc-deskmat.png', 'title' => 'Wool Desk Mat Charcoal', 'alt' => '' ],
];

// 1x1 crisp neutral PNG pixel encoded in base64 for zero-network offline seeding.
$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAASklEQVR4nO3PQREAAAgDINc/9Izg5wY0sG0rAAAAAAAAAAAAAAAAAAAAAAAAAAAAwHcDG3cAAeG/b8cAAAAASUVORK5CYII=' );

foreach ( $sample_imgs as $img ) {
	$dest = trailingslashit( $upload_dir['path'] ) . $img['file'];
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $dest, $png_bytes );
	$aid = wp_insert_attachment(
		[
			'post_mime_type' => 'image/png',
			'post_title'     => $img['title'],
			'post_content'   => '',
			'post_status'    => 'inherit',
		],
		$dest
	);
	if ( ! is_wp_error( $aid ) && $aid > 0 ) {
		update_post_meta(
			$aid,
			'_wp_attachment_metadata',
			[
				'width'    => 640,
				'height'   => 640,
				'file'     => _wp_relative_upload_path( $dest ),
				'filesize' => strlen( (string) $png_bytes ),
			]
		);
		if ( '' !== $img['alt'] ) {
			update_post_meta( $aid, '_wp_attachment_image_alt', $img['alt'] );
		}
		$att_ids[] = (int) $aid;
	}
}

// 3. Seed realistic blog posts with SEO titles, descriptions, and intentional SEO audit anomalies.
$cat_eng = wp_insert_term( 'Engineering', 'category' );
$cat_ops = wp_insert_term( 'Commerce Ops', 'category' );
$cat_seo = wp_insert_term( 'Technical SEO', 'category' );
$cat_ids = array_filter(
	[
		is_array( $cat_eng ) ? (int) $cat_eng['term_id'] : 0,
		is_array( $cat_ops ) ? (int) $cat_ops['term_id'] : 0,
		is_array( $cat_seo ) ? (int) $cat_seo['term_id'] : 0,
	]
);

$posts_data = [
	[ 'Scaling WooCommerce Catalogs Beyond 100k Products Without EAV Bottlenecks', 'publish', 'Scaling WooCommerce Catalogs Past 100k SKUs', 'Learn how lookup tables, selective hydration, and keyset streaming eliminate N+1 bottlenecks.', 'woocommerce scaling' ],
	[ 'Zero Layout Shift Inline Editing Patterns for Dense Admin Tables', 'publish', 'Zero-Shift Inline Table Editing Architecture', 'Exact CSS box-sizing and inset ring geometry for 60 FPS spreadsheet tables in WordPress.', 'dataviews inline edit' ],
	[ 'Migrating Legacy Post Meta Queries to WordPress 7.0 DataViews and Selective Hydration Strategies for High Traffic Stores', 'publish', '', '', '' ],
	[ 'Safe AST Formula Evaluation in PHP Without Ever Calling eval()', 'publish', 'Safe AST Formula Engine in PHP 8.2', 'Build a recursive-descent arithmetic and conditional parser for bulk catalog operations.', 'php ast parser' ],
	[ 'Designing Audit Trails and Atomic Rollback Snapshots for WordPress Bulk Edits', 'draft', '', 'Delta snapshot tables enable one-click rollback across thousands of modified rows.', 'wordpress rollback' ],
	[ 'Automating Flash Sales with Action Scheduler and Auto-Reverting Price Snapshots', 'publish', 'Auto-Reverting WooCommerce Flash Sales', 'Schedule price reductions and automatically restore original catalog pricing on end date.', 'scheduled sales' ],
	[ 'Version-Controlling Admin Column Layouts via Local JSON Sync in Agency Workflows', 'publish', 'Git-Syncable WordPress Admin Views', 'Mirror saved column presets to rowcraft-json/ for deterministic staging to production sync.', 'git workflow wordpress' ],
	[ 'Bulk Alt Text Auditing for European Accessibility Act Compliance', 'draft', 'EAA Accessibility Alt Text Audit Guide', '', 'accessibility alt text' ],
];

foreach ( $posts_data as $idx => $p ) {
	$pid = wp_insert_post(
		[
			'post_type'    => 'post',
			'post_title'   => $p[0],
			'post_status'  => $p[1],
			'post_excerpt' => $p[3],
			'post_content' => str_repeat( 'High-precision WordPress engineering content focusing on performance, maintainability, and clean architecture. ', 14 ),
		]
	);
	if ( is_int( $pid ) && $pid > 0 ) {
		if ( ! empty( $cat_ids ) ) {
			wp_set_post_categories( $pid, [ $cat_ids[ $idx % count( $cat_ids ) ] ] );
		}
		if ( '' !== $p[2] ) {
			update_post_meta( $pid, '_yoast_wpseo_title', $p[2] );
		}
		if ( '' !== $p[3] ) {
			update_post_meta( $pid, '_yoast_wpseo_metadesc', $p[3] );
		}
		if ( '' !== $p[4] ) {
			update_post_meta( $pid, '_yoast_wpseo_focuskw', $p[4] );
		}
		if ( $idx % 2 === 0 && ! empty( $att_ids ) ) {
			set_post_thumbnail( $pid, $att_ids[ $idx % count( $att_ids ) ] );
		}
	}
}

// 4. Seed WooCommerce Simple + Variable Products, Lookup Table, and HPOS Orders.
if ( class_exists( 'WooCommerce' ) && class_exists( 'WC_Product_Simple' ) ) {
	$pc_keyboards = wp_insert_term( 'Keyboards', 'product_cat' );
	$pc_audio     = wp_insert_term( 'Audio', 'product_cat' );
	$pc_desk      = wp_insert_term( 'Desk Setup', 'product_cat' );

	$cat_map = [
		'Keyboards'  => is_array( $pc_keyboards ) ? (int) $pc_keyboards['term_id'] : 0,
		'Audio'      => is_array( $pc_audio ) ? (int) $pc_audio['term_id'] : 0,
		'Desk Setup' => is_array( $pc_desk ) ? (int) $pc_desk['term_id'] : 0,
	];

	$catalog = [
		[ 'Keychron Q1 Pro Mechanical Keyboard', 'KB-Q1PRO-01', 199.00, 179.00, 42, 'Keyboards', 'Keychron', 'tier_a', 92 ],
		[ 'HHKB Professional Hybrid Type-S', 'KB-HHKB-02', 319.00, '', 8, 'Keyboards', 'PFU', 'tier_a', 175 ],
		[ 'Nuphy Air75 V2 Low-Profile', 'KB-AIR75-03', 129.95, 114.95, 3, 'Keyboards', 'Nuphy', 'tier_b', 64 ],
		[ 'Sennheiser HD 650 Open-Back', 'AU-HD650-04', 449.00, 399.00, 15, 'Audio', 'Sennheiser', 'tier_a', 240 ],
		[ 'Sony MDR-7506 Studio Monitor', 'AU-MDR75-05', 99.99, '', 0, 'Audio', 'Sony', 'tier_b', 51 ],
		[ 'Audio-Technica AT2020 Condenser Mic', 'AU-AT202-06', 99.00, 105.00, 22, 'Audio', 'Audio-Technica', 'tier_b', 48 ], // Intentional pricing anomaly: sale >= regular!
		[ 'Grovemade Wool Felt Desk Pad', 'DS-WOOL-07', 80.00, '', 64, 'Desk Setup', 'Grovemade', 'tier_a', 32 ],
		[ 'BenQ ScreenBar Halo LED Lamp', 'DS-HALO-08', 169.00, 149.00, 4, 'Desk Setup', 'BenQ', 'tier_a', 81 ],
		[ 'CalDigit TS4 Thunderbolt 4 Dock', 'DS-TS4-09', 399.95, '', 11, 'Desk Setup', 'CalDigit', 'tier_a', 215 ],
		[ 'Walnut Ergonomic Wrist Rest', '', 48.00, '', 2, 'Desk Setup', 'Atelier', 'tier_c', 18 ], // Intentional missing SKU + low stock!
		[ 'Braided USB-C Coiled Aviator Cable', 'KB-COIL-11', 39.00, 29.99, 95, 'Keyboards', 'CableMod', 'tier_c', 11 ],
		[ 'Acoustic Felt Desk Organizer Tray', 'DS-TRAY-12', 34.50, '', 0, 'Desk Setup', 'Orbitkey', 'tier_b', 14 ],
	];

	$created_products = [];
	foreach ( $catalog as $idx => $item ) {
		$prod = new WC_Product_Simple();
		$prod->set_name( $item[0] );
		$prod->set_status( 'publish' );
		if ( '' !== $item[1] ) {
			$prod->set_sku( $item[1] );
		}
		$prod->set_regular_price( (string) $item[2] );
		if ( '' !== $item[3] ) {
			$prod->set_sale_price( (string) $item[3] );
		}
		$prod->set_manage_stock( true );
		$prod->set_stock_quantity( $item[4] );
		$prod->set_stock_status( $item[4] > 0 ? 'instock' : 'outofstock' );
		if ( ! empty( $cat_map[ $item[5] ] ) ) {
			$prod->set_category_ids( [ $cat_map[ $item[5] ] ] );
		}
		if ( $idx < 9 && ! empty( $att_ids ) ) {
			$prod->set_image_id( $att_ids[ $idx % count( $att_ids ) ] );
		}
		$pid = $prod->save();

		update_post_meta( $pid, 'product_brand', $item[6] );
		update_post_meta( $pid, 'supplier_tier', $item[7] );
		update_post_meta( $pid, 'unit_cost', $item[8] );
		update_post_meta( $pid, 'staff_pick', $idx % 3 === 0 ? 1 : 0 );
		$created_products[] = $pid;
	}

	// Create a Variable Product with 3 inline-expandable Variations!
	if ( class_exists( 'WC_Product_Variable' ) && class_exists( 'WC_Product_Variation' ) ) {
		$var_parent = new WC_Product_Variable();
		$var_parent->set_name( 'RowCraft Edition Aluminum Keycap Set' );
		$var_parent->set_status( 'publish' );
		$var_parent->set_sku( 'KB-CAPS-VAR' );
		if ( ! empty( $cat_map['Keyboards'] ) ) {
			$var_parent->set_category_ids( [ $cat_map['Keyboards'] ] );
		}
		if ( ! empty( $att_ids ) ) {
			$var_parent->set_image_id( $att_ids[0] );
		}

		$attr = new WC_Product_Attribute();
		$attr->set_name( 'Finish' );
		$attr->set_options( [ 'Anodized Silver', 'Matte Slate', 'Raw Brass' ] );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$var_parent->set_attributes( [ $attr ] );
		$parent_id = $var_parent->save();

		$variants = [
			[ 'Anodized Silver', 'KB-CAPS-SLV', 69.00, 18 ],
			[ 'Matte Slate', 'KB-CAPS-SLT', 74.00, 12 ],
			[ 'Raw Brass', 'KB-CAPS-BRS', 89.00, 4 ],
		];
		foreach ( $variants as $v ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( [ 'finish' => $v[0] ] );
			$variation->set_sku( $v[1] );
			$variation->set_regular_price( (string) $v[2] );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $v[3] );
			$variation->set_stock_status( 'instock' );
			$variation->save();
		}
		WC_Product_Variable::sync( $parent_id );
	}

	// Seed 4 realistic HPOS WooCommerce Orders.
	if ( function_exists( 'wc_create_order' ) && ! empty( $created_products ) ) {
		$customers = [
			[ 'Selin', 'Kaya', 'selin.kaya@example.com', 'processing', 'Leave package with front desk.' ],
			[ 'Marcus', 'Lindholm', 'marcus@nordicstudio.example', 'completed', 'Express courier delivered.' ],
			[ 'Deniz', 'Arslan', 'deniz.arslan@example.com', 'on-hold', 'Awaiting wire confirmation.' ],
			[ 'Elena', 'Rostova', 'elena.r@example.com', 'processing', 'Gift wrap requested.' ],
		];
		foreach ( $customers as $idx => $c ) {
			$order = wc_create_order();
			$prod  = wc_get_product( $created_products[ $idx % count( $created_products ) ] );
			if ( $prod ) {
				$order->add_product( $prod, 1 + ( $idx % 2 ) );
			}
			$order->set_billing_first_name( $c[0] );
			$order->set_billing_last_name( $c[1] );
			$order->set_billing_email( $c[2] );
			$order->set_customer_note( $c[4] );
			$order->calculate_totals();
			$order->set_status( $c[3] );
			$order->save();
		}
	}
}

update_option( 'rowcraft_demo_seeded', 1, false );
echo "RowCraft demo dataset seeded successfully.\n";
