<?php
/**
 * Central Field Provider Registry with request-level schema caching.
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

final class FieldRegistry {

	/**
	 * Registered providers keyed by provider ID.
	 *
	 * @var array<string, FieldProviderInterface>
	 */
	private array $providers = [];

	/**
	 * Per-entity cached field maps (keyed by "entity:1" or "entity:0").
	 *
	 * @var array<string, array<string, FieldDefinition>>
	 */
	private array $field_cache = [];

	/**
	 * Whether option lists (users/terms dropdowns) should be fetched during schema build.
	 */
	private bool $include_options = true;

	/**
	 * Register a field provider.
	 */
	public function register( FieldProviderInterface $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
		$this->field_cache                      = [];
	}

	/**
	 * Toggle option hydration (disabled on hot /records queries to eliminate unnecessary SQL).
	 */
	public function set_include_options( bool $include_opts ): void {
		$this->include_options = $include_opts;
	}

	public function should_include_options(): bool {
		return $this->include_options;
	}

	/**
	 * Get a provider by ID.
	 */
	public function get_provider( string $id ): ?FieldProviderInterface {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * Return all field definitions for an entity, keyed by field ID.
	 *
	 * @return array<string, FieldDefinition>
	 */
	public function get_fields_for_entity( string $entity_slug, ?bool $include_options = null ): array {
		$prev_opt = $this->include_options;
		if ( null !== $include_options ) {
			$this->include_options = $include_options;
		}
		$cache_key = $entity_slug . ':' . ( $this->include_options ? '1' : '0' );

		if ( isset( $this->field_cache[ $cache_key ] ) ) {
			$this->include_options = $prev_opt;
			return $this->field_cache[ $cache_key ];
		}

		$fields = [];
		foreach ( $this->providers as $provider ) {
			if ( ! $provider->supports( $entity_slug ) ) {
				continue;
			}
			foreach ( $provider->get_fields( $entity_slug ) as $field ) {
				if ( ! isset( $fields[ $field->id ] ) ) {
					$fields[ $field->id ] = $field;
				}
			}
		}

		/**
		 * Allow third-party extensions to register or adjust DataDeck field definitions.
		 *
		 * @param array<string, FieldDefinition> $fields      Field map keyed by ID.
		 * @param string                         $entity_slug Active entity slug.
		 */
		$filtered = apply_filters( 'datadeck_entity_fields', $fields, $entity_slug );

		$this->field_cache[ $cache_key ] = is_array( $filtered ) ? $filtered : $fields;
		$this->include_options           = $prev_opt;

		return $this->field_cache[ $cache_key ];
	}

	/**
	 * Get a single FieldDefinition for an entity.
	 */
	public function get_field( string $entity_slug, string $field_id ): ?FieldDefinition {
		$all = $this->get_fields_for_entity( $entity_slug );
		return $all[ $field_id ] ?? null;
	}

	/**
	 * Return default visible field IDs for an entity.
	 *
	 * @return array<int, string>
	 */
	public function get_default_field_ids( string $entity_slug ): array {
		$ids = [];
		foreach ( $this->get_fields_for_entity( $entity_slug ) as $field ) {
			if ( $field->default_visible ) {
				$ids[] = $field->id;
			}
		}
		return $ids;
	}

	/**
	 * Collect strictly required meta keys for a subset of projected fields.
	 *
	 * @param string             $entity_slug Entity slug.
	 * @param array<int, string> $field_ids   Projected field IDs.
	 * @return array<int, string>
	 */
	public function collect_required_meta_keys( string $entity_slug, array $field_ids ): array {
		$grouped = $this->group_fields_by_provider( $entity_slug, $field_ids );
		$keys    = [];

		foreach ( $grouped as $provider_id => $fields ) {
			$provider = $this->providers[ $provider_id ] ?? null;
			if ( $provider ) {
				array_push( $keys, ...$provider->get_required_meta_keys( $fields ) );
			}
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Collect taxonomy slugs strictly needed for projected fields.
	 *
	 * @param string             $entity_slug Entity slug.
	 * @param array<int, string> $field_ids   Projected field IDs.
	 * @return array<int, string>
	 */
	public function collect_required_taxonomies( string $entity_slug, array $field_ids ): array {
		$all  = $this->get_fields_for_entity( $entity_slug );
		$pool = [];

		foreach ( $field_ids as $fid ) {
			$field = $all[ $fid ] ?? null;
			if ( $field && 'taxonomy' === $field->type && '' !== $field->taxonomy ) {
				$pool[] = $field->taxonomy;
			}
		}

		return array_values( array_unique( $pool ) );
	}

	/**
	 * Hydrate projected fields across all owning providers.
	 *
	 * @param string                                      $entity_slug Entity slug.
	 * @param array<int, int>                             $ids         Record IDs.
	 * @param array<int, string>                          $field_ids   Projected field IDs.
	 * @param array<int, array<string, mixed>>            $rows        Rows keyed by record ID.
	 * @param array<int, array<string, array<int, mixed>>> $meta_cache Pre-hydrated selective meta map.
	 */
	public function hydrate_fields(
		string $entity_slug,
		array $ids,
		array $field_ids,
		array &$rows,
		array $meta_cache
	): void {
		$grouped = $this->group_fields_by_provider( $entity_slug, $field_ids );

		foreach ( $grouped as $provider_id => $fields ) {
			$provider = $this->providers[ $provider_id ] ?? null;
			if ( $provider ) {
				$provider->hydrate( $entity_slug, $ids, $fields, $rows, $meta_cache );
			}
		}
	}

	/**
	 * Read a single field value from its owning provider.
	 */
	public function read_value( string $entity_slug, int $record_id, string $field_id ): mixed {
		$field = $this->get_field( $entity_slug, $field_id );
		if ( ! $field ) {
			return null;
		}
		$provider = $this->providers[ $field->provider ] ?? null;
		if ( ! $provider ) {
			return null;
		}
		return $provider->read_value( $entity_slug, $record_id, $field );
	}

	/**
	 * Write a single field value through its owning provider after editability check.
	 */
	public function write_value( string $entity_slug, int $record_id, string $field_id, mixed $value ): bool|WP_Error {
		$field = $this->get_field( $entity_slug, $field_id );
		if ( ! $field ) {
			return new WP_Error( 'datadeck_unknown_field', sprintf( 'Unknown field "%s".', $field_id ), [ 'status' => 400 ] );
		}
		if ( ! $field->editable ) {
			return new WP_Error( 'datadeck_readonly_field', sprintf( 'Field "%s" is read-only.', $field->label ), [ 'status' => 400 ] );
		}
		$provider = $this->providers[ $field->provider ] ?? null;
		if ( ! $provider ) {
			return new WP_Error( 'datadeck_missing_provider', 'Field provider unavailable.', [ 'status' => 500 ] );
		}

		return $provider->write_value( $entity_slug, $record_id, $field, $value );
	}

	/**
	 * Group projected fields by their owning provider ID.
	 *
	 * @param string             $entity_slug Entity slug.
	 * @param array<int, string> $field_ids   Projected field IDs.
	 * @return array<string, array<string, FieldDefinition>>
	 */
	private function group_fields_by_provider( string $entity_slug, array $field_ids ): array {
		$all     = $this->get_fields_for_entity( $entity_slug );
		$grouped = [];

		foreach ( $field_ids as $fid ) {
			$field = $all[ $fid ] ?? null;
			if ( ! $field ) {
				continue;
			}
			$grouped[ $field->provider ][ $fid ] = $field;
		}

		return $grouped;
	}
}
