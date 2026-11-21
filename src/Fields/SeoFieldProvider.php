<?php
/**
 * SEO Field Provider — Auto-detects Yoast SEO, Rank Math, or exposes clean fallback SEO metadata.
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Fields;

use RowCraft\Contracts\FieldProviderInterface;
use RowCraft\Schema\FieldDefinition;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SeoFieldProvider implements FieldProviderInterface {

	public function get_id(): string {
		return 'seo';
	}

	public function supports( string $entity_slug ): bool {
		if ( in_array( $entity_slug, [ 'user', 'attachment', 'shop_order' ], true ) ) {
			return false;
		}
		return post_type_exists( $entity_slug );
	}

	/**
	 * Detect active SEO suite ('yoast' | 'rankmath' | 'generic').
	 */
	public function detect_engine(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		return 'generic';
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		$engine = $this->detect_engine();
		$keys   = $this->get_engine_keys( $engine );

		return [
			new FieldDefinition(
				id: 'seo_title',
				label: __( 'SEO Title', 'rowcraft' ),
				type: 'text',
				group: 'seo',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: 'post' === $entity_slug,
				width: 220,
				meta_key: $keys['title'],
				validation: [ 'recommendedMax' => 60 ],
				extra: [ 'seoEngine' => $engine ],
			),
			new FieldDefinition(
				id: 'seo_description',
				label: __( 'Meta Description', 'rowcraft' ),
				type: 'textarea',
				group: 'seo',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: 'post' === $entity_slug,
				width: 260,
				meta_key: $keys['description'],
				validation: [ 'recommendedMax' => 160 ],
				extra: [ 'seoEngine' => $engine ],
			),
			new FieldDefinition(
				id: 'seo_focus_kw',
				label: __( 'Focus Keyword', 'rowcraft' ),
				type: 'text',
				group: 'seo',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: false,
				width: 165,
				meta_key: $keys['focus_kw'],
				extra: [ 'seoEngine' => $engine ],
			),
			new FieldDefinition(
				id: 'seo_canonical',
				label: __( 'Canonical URL', 'rowcraft' ),
				type: 'url',
				group: 'seo',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 210,
				monospace: true,
				meta_key: $keys['canonical'],
				extra: [ 'seoEngine' => $engine ],
			),
			new FieldDefinition(
				id: 'seo_noindex',
				label: __( 'Noindex', 'rowcraft' ),
				type: 'boolean',
				group: 'seo',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 90,
				align: 'center',
				meta_key: $keys['noindex'],
				extra: [ 'seoEngine' => $engine ],
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
		$engine = $this->detect_engine();

		foreach ( $ids as $id ) {
			foreach ( $fields as $fid => $field ) {
				$raw = $meta_cache[ $id ][ $field->meta_key ][0] ?? '';

				if ( 'seo_noindex' === $fid ) {
					if ( 'rankmath' === $engine ) {
						$rows[ $id ][ $fid ] = is_array( $raw ) && in_array( 'noindex', $raw, true );
					} else {
						$rows[ $id ][ $fid ] = '1' === (string) $raw || true === $raw;
					}
				} else {
					$rows[ $id ][ $fid ] = (string) $raw;
				}
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		$raw = get_post_meta( $record_id, $field->meta_key, true );
		if ( 'seo_noindex' === $field->id ) {
			if ( 'rankmath' === $this->detect_engine() ) {
				return is_array( $raw ) && in_array( 'noindex', $raw, true );
			}
			return '1' === (string) $raw;
		}
		return (string) $raw;
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): true|WP_Error {
		$engine = $this->detect_engine();

		if ( 'seo_noindex' === $field->id ) {
			$bool = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			if ( 'rankmath' === $engine ) {
				$current = get_post_meta( $record_id, $field->meta_key, true );
				$arr     = is_array( $current ) ? $current : [];
				$arr     = array_values( array_diff( $arr, [ 'index', 'noindex' ] ) );
				$arr[]   = $bool ? 'noindex' : 'index';
				update_post_meta( $record_id, $field->meta_key, $arr );
			} elseif ( $bool ) {
				update_post_meta( $record_id, $field->meta_key, '1' );
			} else {
				delete_post_meta( $record_id, $field->meta_key );
			}
			return true;
		}

		$clean = match ( $field->id ) {
			'seo_canonical'   => esc_url_raw( (string) $value ),
			'seo_description' => sanitize_textarea_field( (string) $value ),
			default           => sanitize_text_field( (string) $value ),
		};

		if ( '' === $clean ) {
			delete_post_meta( $record_id, $field->meta_key );
		} else {
			update_post_meta( $record_id, $field->meta_key, $clean );
		}

		return true;
	}

	/**
	 * @return array{title: string, description: string, focus_kw: string, canonical: string, noindex: string}
	 */
	private function get_engine_keys( string $engine ): array {
		if ( 'rankmath' === $engine ) {
			return [
				'title'       => 'rank_math_title',
				'description' => 'rank_math_description',
				'focus_kw'    => 'rank_math_focus_keyword',
				'canonical'   => 'rank_math_canonical_url',
				'noindex'     => 'rank_math_robots',
			];
		}

		// Yoast SEO keys also act as clean standard fallback when neither Yoast nor Rank Math is active.
		return [
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
			'focus_kw'    => '_yoast_wpseo_focuskw',
			'canonical'   => '_yoast_wpseo_canonical',
			'noindex'     => '_yoast_wpseo_meta-robots-noindex',
		];
	}
}
