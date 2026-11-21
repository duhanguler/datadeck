<?php
/**
 * ACF (Advanced Custom Fields) & SCF (Secure Custom Fields) Zero-Paywall Field Provider.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Fields;

use DataDeck\Contracts\FieldProviderInterface;
use DataDeck\Query\SelectiveHydrator;
use DataDeck\Schema\FieldDefinition;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AcfFieldProvider implements FieldProviderInterface {

	private SelectiveHydrator $hydrator;

	public function __construct( SelectiveHydrator $hydrator ) {
		$this->hydrator = $hydrator;
	}

	public function get_id(): string {
		return 'acf';
	}

	public function supports( string $entity_slug ): bool {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return false;
		}
		return 'user' === $entity_slug || post_type_exists( $entity_slug );
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		if ( ! $this->supports( $entity_slug ) ) {
			return [];
		}

		$query  = 'user' === $entity_slug ? [ 'user_form' => 'all' ] : [ 'post_type' => $entity_slug ];
		$groups = acf_get_field_groups( $query );
		if ( ! is_array( $groups ) ) {
			return [];
		}

		$definitions = [];

		foreach ( $groups as $group ) {
			$acf_fields = acf_get_fields( $group['key'] ?? '' );
			if ( ! is_array( $acf_fields ) ) {
				continue;
			}

			foreach ( $acf_fields as $acf ) {
				$name     = (string) ( $acf['name'] ?? '' );
				$acf_type = (string) ( $acf['type'] ?? 'text' );
				if ( '' === $name ) {
					continue;
				}

				$mapped = $this->map_acf_type( $acf_type );
				if ( null === $mapped ) {
					continue;
				}

				$options = [];
				if ( ! empty( $acf['choices'] ) && is_array( $acf['choices'] ) ) {
					foreach ( $acf['choices'] as $val => $lbl ) {
						$options[] = [
							'value' => (string) $val,
							'label' => (string) $lbl,
						];
					}
				}

				$is_num = in_array( $mapped, [ 'number', 'integer' ], true );

				$validation = array_filter(
					[
						'required'  => ! empty( $acf['required'] ),
						'min'       => isset( $acf['min'] ) && '' !== $acf['min'] ? (float) $acf['min'] : null,
						'max'       => isset( $acf['max'] ) && '' !== $acf['max'] ? (float) $acf['max'] : null,
						'maxLength' => isset( $acf['maxlength'] ) && '' !== $acf['maxlength'] ? (int) $acf['maxlength'] : null,
					],
					static fn( mixed $v ): bool => null !== $v && false !== $v
				);

					$definitions[] = new FieldDefinition(
						id: 'acf:' . $name,
						label: (string) ( $acf['label'] ?? $name ),
						type: $mapped,
						group: 'acf',
						provider: $this->get_id(),
						editable: true,
						sortable: ! in_array( $mapped, [ 'media', 'multiselect' ], true ),
						filterable: true,
						default_visible: false,
						width: $is_num ? 116 : ( 'boolean' === $mapped ? 96 : 175 ),
						align: $is_num ? 'right' : ( 'boolean' === $mapped ? 'center' : 'left' ),
						tabular_nums: $is_num || 'date' === $mapped,
						meta_key: $name,
						options: $options,
						validation: $validation,
						extra: [
							'acfKey'   => (string) ( $acf['key'] ?? '' ),
							'acfType'  => $acf_type,
							'group'    => (string) ( $group['title'] ?? 'ACF' ),
							'multiple' => ! empty( $acf['multiple'] ),
						],
					);
			}
		}

		return $definitions;
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
		// Pre-hydrate media IDs for ACF image fields in 1 query.
		$image_att_ids = [];
		foreach ( $fields as $field ) {
			if ( 'media' === $field->type ) {
				foreach ( $ids as $id ) {
					$att_id = (int) ( $meta_cache[ $id ][ $field->meta_key ][0] ?? 0 );
					if ( $att_id > 0 ) {
						$image_att_ids[] = $att_id;
					}
				}
			}
		}
		$thumb_map = ! empty( $image_att_ids ) ? $this->hydrator->hydrate_thumbnails( $image_att_ids ) : [];

		foreach ( $ids as $id ) {
			foreach ( $fields as $fid => $field ) {
				$raw = $meta_cache[ $id ][ $field->meta_key ][0] ?? null;

				if ( 'media' === $field->type ) {
					$att_id = (int) $raw;
					if ( $att_id > 0 ) {
						$rows[ $id ][ $fid ] = $thumb_map[ $att_id ] ?? [
							'id'       => $att_id,
							'url'      => '',
							'alt'      => '',
							'filename' => '',
						];
					} else {
						$rows[ $id ][ $fid ] = null;
					}
				} elseif ( 'boolean' === $field->type ) {
					$rows[ $id ][ $fid ] = (bool) filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
				} elseif ( 'number' === $field->type ) {
					$rows[ $id ][ $fid ] = ( null === $raw || '' === $raw ) ? null : (float) $raw;
				} elseif ( 'date' === $field->type ) {
					$str = (string) ( $raw ?? '' );
					if ( 8 === strlen( $str ) && ctype_digit( $str ) ) {
						$str = substr( $str, 0, 4 ) . '-' . substr( $str, 4, 2 ) . '-' . substr( $str, 6, 2 );
					}
					$rows[ $id ][ $fid ] = $str;
				} elseif ( is_array( $raw ) ) {
					$rows[ $id ][ $fid ] = $raw;
				} else {
					$rows[ $id ][ $fid ] = (string) ( $raw ?? '' );
				}
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		$target = 'user' === $entity_slug ? 'user_' . $record_id : $record_id;
		$key    = (string) ( $field->extra['acfKey'] ?? $field->meta_key );
		if ( function_exists( 'get_field' ) ) {
			return get_field( $key, $target, false );
		}
		return 'user' === $entity_slug
			? get_user_meta( $record_id, $field->meta_key, true )
			: get_post_meta( $record_id, $field->meta_key, true );
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): bool|WP_Error {
		$target = 'user' === $entity_slug ? 'user_' . $record_id : $record_id;
		$key    = (string) ( $field->extra['acfKey'] ?? $field->meta_key );

		$sanitized = match ( $field->type ) {
			'boolean' => filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0,
			'number'  => '' === (string) $value ? '' : (float) $value,
			'email'   => sanitize_email( (string) $value ),
			'url'     => esc_url_raw( (string) $value ),
			'media'   => is_array( $value ) ? (int) ( $value['id'] ?? 0 ) : (int) $value,
			'date'    => str_replace( '-', '', sanitize_text_field( (string) $value ) ),
			'textarea'=> sanitize_textarea_field( (string) $value ),
			default   => is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( (string) $value ),
		};

		if ( function_exists( 'update_field' ) ) {
			update_field( $key, $sanitized, $target );
			return true;
		}

		if ( 'user' === $entity_slug ) {
			update_user_meta( $record_id, $field->meta_key, $sanitized );
		} else {
			update_post_meta( $record_id, $field->meta_key, $sanitized );
		}

		return true;
	}

	/**
	 * Map ACF field type to DataDeck FieldDefinition type.
	 */
	private function map_acf_type( string $acf_type ): ?string {
		return match ( $acf_type ) {
			'text', 'color_picker'       => 'text',
			'textarea', 'wysiwyg'        => 'textarea',
			'number', 'range'            => 'number',
			'email'                      => 'email',
			'url'                        => 'url',
			'select', 'radio', 'button_group' => 'select',
			'checkbox'                   => 'multiselect',
			'true_false'                 => 'boolean',
			'date_picker'                => 'date',
			'date_time_picker'           => 'datetime',
			'image'                      => 'media',
			default                      => null,
		};
	}
}
