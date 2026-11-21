<?php
/**
 * Meta Box Zero-Paywall Field Provider.
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

final class MetaBoxFieldProvider implements FieldProviderInterface {

	public function get_id(): string {
		return 'metabox';
	}

	public function supports( string $entity_slug ): bool {
		return function_exists( 'rwmb_get_object_fields' ) && post_type_exists( $entity_slug );
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		if ( ! $this->supports( $entity_slug ) ) {
			return [];
		}

		$mb_fields   = rwmb_get_object_fields( $entity_slug, 'post' );
		$definitions = [];

		if ( ! is_array( $mb_fields ) ) {
			return [];
		}

		foreach ( $mb_fields as $field_id => $cfg ) {
			$mb_type = (string) ( $cfg['type'] ?? 'text' );
			$type    = match ( $mb_type ) {
				'text', 'color'          => 'text',
				'textarea', 'wysiwyg'    => 'textarea',
				'number', 'range'        => 'number',
				'email'                  => 'email',
				'url'                    => 'url',
				'checkbox', 'switch'     => 'boolean',
				'select', 'radio'        => 'select',
				'date'                   => 'date',
				default                  => null,
			};

			if ( null === $type ) {
				continue;
			}

			$options = [];
			if ( ! empty( $cfg['options'] ) && is_array( $cfg['options'] ) ) {
				foreach ( $cfg['options'] as $k => $v ) {
					$options[] = [
						'value' => (string) $k,
						'label' => (string) $v,
					];
				}
			}

			$definitions[] = new FieldDefinition(
				id: 'mb:' . (string) $field_id,
				label: (string) ( $cfg['name'] ?? $field_id ),
				type: $type,
				group: 'metabox',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: false,
				width: 'number' === $type ? 116 : 170,
				align: 'number' === $type ? 'right' : 'left',
				tabular_nums: 'number' === $type || 'date' === $type,
				meta_key: (string) $field_id,
				options: $options,
			);
		}

		return $definitions;
	}

	public function get_required_meta_keys( array $fields ): array {
		return array_values( array_filter( array_map( static fn( FieldDefinition $f ): string => $f->meta_key, $fields ) ) );
	}

	public function hydrate( string $entity_slug, array $ids, array $fields, array &$rows, array $meta_cache ): void {
		foreach ( $ids as $id ) {
			foreach ( $fields as $fid => $field ) {
				$raw                 = $meta_cache[ $id ][ $field->meta_key ][0] ?? '';
				$rows[ $id ][ $fid ] = match ( $field->type ) {
					'boolean' => filter_var( $raw, FILTER_VALIDATE_BOOLEAN ),
					'number'  => '' === (string) $raw ? null : (float) $raw,
					default   => (string) $raw,
				};
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		return get_post_meta( $record_id, $field->meta_key, true );
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): bool|WP_Error {
		$clean = match ( $field->type ) {
			'boolean'  => filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? '1' : '0',
			'number'   => '' === (string) $value ? '' : (float) $value,
			'email'    => sanitize_email( (string) $value ),
			'url'      => esc_url_raw( (string) $value ),
			'textarea' => sanitize_textarea_field( (string) $value ),
			default    => sanitize_text_field( (string) $value ),
		};
		update_post_meta( $record_id, $field->meta_key, $clean );
		return true;
	}
}
