<?php
/**
 * Native WordPress Custom Meta Field Provider with safe auto-discovery & protection guards.
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

final class NativeMetaFieldProvider implements FieldProviderInterface {

	private const MAX_DISCOVERED_KEYS = 40;

	public function get_id(): string {
		return 'native_meta';
	}

	public function supports( string $entity_slug ): bool {
		return 'user' === $entity_slug || post_type_exists( $entity_slug );
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		$keys   = $this->discover_meta_keys( $entity_slug );
		$fields = [];

		foreach ( $keys as $meta_key => $meta_info ) {
			$label = ucwords( str_replace( [ '_', '-' ], ' ', ltrim( $meta_key, '_' ) ) );
			$type  = $meta_info['type'];

			$fields[] = new FieldDefinition(
				id: 'meta:' . $meta_key,
				label: $label,
				type: $type,
				group: 'meta',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: false,
				width: 'number' === $type || 'integer' === $type ? 110 : 170,
				align: 'number' === $type || 'integer' === $type ? 'right' : 'left',
				tabular_nums: 'number' === $type || 'integer' === $type,
				meta_key: $meta_key,
			);
		}

		return $fields;
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
		foreach ( $ids as $id ) {
			foreach ( $fields as $fid => $field ) {
				$raw = $meta_cache[ $id ][ $field->meta_key ][0] ?? '';
				if ( is_array( $raw ) || is_object( $raw ) ) {
					$rows[ $id ][ $fid ] = wp_json_encode( $raw );
				} elseif ( 'boolean' === $field->type ) {
					$rows[ $id ][ $fid ] = filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
				} elseif ( 'number' === $field->type ) {
					$rows[ $id ][ $fid ] = '' === $raw ? null : (float) $raw;
				} elseif ( 'integer' === $field->type ) {
					$rows[ $id ][ $fid ] = '' === $raw ? null : (int) $raw;
				} else {
					$rows[ $id ][ $fid ] = (string) $raw;
				}
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		if ( 'user' === $entity_slug ) {
			return get_user_meta( $record_id, $field->meta_key, true );
		}
		return get_post_meta( $record_id, $field->meta_key, true );
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): true|WP_Error {
		$meta_key = $field->meta_key;

		// Enforce WordPress protected-meta security guard.
		$meta_type = 'user' === $entity_slug ? 'user' : 'post';
		if ( is_protected_meta( $meta_key, $meta_type ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rowcraft_protected_meta', __( 'You do not have permission to edit protected metadata.', 'rowcraft' ), [ 'status' => 403 ] );
		}

		$clean = match ( $field->type ) {
			'boolean' => filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? '1' : '0',
			'integer' => '' === (string) $value ? '' : (int) $value,
			'number'  => '' === (string) $value ? '' : (float) $value,
			'url'     => esc_url_raw( (string) $value ),
			default   => sanitize_text_field( (string) $value ),
		};

		if ( 'user' === $entity_slug ) {
			update_user_meta( $record_id, $meta_key, $clean );
		} else {
			update_post_meta( $record_id, $meta_key, $clean );
		}

		return true;
	}

	/**
	 * Discover registered meta + non-protected custom meta keys safely (excluding ACF & builder blobs).
	 *
	 * @return array<string, array{type: string}>
	 */
	private function discover_meta_keys( string $entity_slug ): array {
		$discovered  = [];
		$object_type = 'user' === $entity_slug ? 'user' : 'post';

		// 1. Explicitly registered WordPress meta keys.
		$registered = get_registered_meta_keys( $object_type, 'user' === $entity_slug ? '' : $entity_slug );
		foreach ( $registered as $key => $args ) {
			if ( str_starts_with( $key, '_' ) || str_starts_with( $key, 'rowcraft_' ) ) {
				continue;
			}
			$discovered[ $key ] = [
				'type' => match ( $args['type'] ?? 'string' ) {
					'integer' => 'integer',
					'number'  => 'number',
					'boolean' => 'boolean',
					default   => 'text',
				},
			];
		}

		// 2. Non-protected public postmeta keys cached briefly to avoid repeated DISTINCT scans on hot paths.
		if ( 'post' === $object_type ) {
			$cache_key = 'rowcraft_meta_keys_' . $entity_slug;
			$keys      = get_transient( $cache_key );
			if ( ! is_array( $keys ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$keys = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT pm.meta_key
						FROM {$wpdb->postmeta} pm
						INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						WHERE p.post_type = %s
						AND pm.meta_key NOT LIKE %s
						AND pm.meta_key <> ''
						LIMIT %d",
						$entity_slug,
						$wpdb->esc_like( '_' ) . '%',
						self::MAX_DISCOVERED_KEYS
					)
				);
				$keys = is_array( $keys ) ? $keys : [];
				set_transient( $cache_key, $keys, 5 * MINUTE_IN_SECONDS );
			}

			$acf_names = $this->get_acf_field_names( $entity_slug );

			if ( is_array( $keys ) ) {
				foreach ( $keys as $key ) {
					$key = (string) $key;
					if ( isset( $discovered[ $key ] ) || isset( $acf_names[ $key ] ) ) {
						continue;
					}
					if ( str_starts_with( $key, 'rank_math_' ) || str_starts_with( $key, 'yoast_' ) || str_starts_with( $key, 'rowcraft_' ) ) {
						continue;
					}
					$discovered[ $key ] = [ 'type' => 'text' ];
				}
			}
		}

		return $discovered;
	}

	/**
	 * Collect ACF/SCF field names so NativeMetaFieldProvider does not duplicate them.
	 *
	 * @return array<string, true>
	 */
	private function get_acf_field_names( string $entity_slug ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return [];
		}
		$names  = [];
		$groups = acf_get_field_groups( [ 'post_type' => $entity_slug ] );
		if ( is_array( $groups ) ) {
			foreach ( $groups as $group ) {
				$fields = acf_get_fields( $group['key'] ?? '' );
				if ( is_array( $fields ) ) {
					foreach ( $fields as $f ) {
						if ( ! empty( $f['name'] ) ) {
							$names[ (string) $f['name'] ] = true;
						}
					}
				}
			}
		}
		return $names;
	}
}
