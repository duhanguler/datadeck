<?php
/**
 * Field Provider contract for Core, Meta, ACF/SCF, MetaBox, WooCommerce, and SEO integrations.
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Contracts;

use RowCraft\Schema\FieldDefinition;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface FieldProviderInterface {

	/**
	 * Provider identifier (e.g. 'core', 'meta', 'acf', 'woocommerce', 'seo').
	 */
	public function get_id(): string;

	/**
	 * Whether this provider supplies fields for the given entity slug.
	 */
	public function supports( string $entity_slug ): bool;

	/**
	 * Return field definitions for an entity.
	 *
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array;

	/**
	 * Bulk-hydrate requested fields onto record maps without N+1 queries.
	 *
	 * @param string                                      $entity_slug Entity slug.
	 * @param array<int, int>                             $ids         Record IDs.
	 * @param array<string, FieldDefinition>              $fields      Requested fields owned by this provider.
	 * @param array<int, array<string, mixed>>            $rows        Rows keyed by record ID (passed by reference).
	 * @param array<int, array<string, array<int, mixed>>> $meta_cache Pre-hydrated selective meta map.
	 */
	public function hydrate(
		string $entity_slug,
		array $ids,
		array $fields,
		array &$rows,
		array $meta_cache
	): void;

	/**
	 * Meta keys required by the requested fields so SelectiveHydrator loads ONLY those keys.
	 *
	 * @param array<string, FieldDefinition> $fields Fields owned by this provider.
	 * @return array<int, string>
	 */
	public function get_required_meta_keys( array $fields ): array;

	/**
	 * Read a single normalized value (used before mutation for delta snapshot).
	 */
	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed;

	/**
	 * Write a validated, sanitized value back to WordPress / WooCommerce / ACF CRUD.
	 */
	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): true|WP_Error;
}
