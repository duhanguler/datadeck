<?php
/**
 * Entity Adapter contract for universal data sources (Posts, CPTs, Media, Users, WooCommerce).
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Contracts;

use RowCraft\Query\QueryRequest;
use RowCraft\Query\QueryResult;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface EntityAdapterInterface {

	/**
	 * Unique entity slug (e.g. 'post', 'page', 'product', 'shop_order', 'attachment', 'user').
	 */
	public function get_slug(): string;

	/**
	 * Singular display label.
	 */
	public function get_label(): string;

	/**
	 * Plural display label.
	 */
	public function get_plural_label(): string;

	/**
	 * Entity category group ('content', 'commerce', 'system').
	 */
	public function get_group(): string;

	/**
	 * Whether this entity is active in the current WordPress installation.
	 */
	public function is_available(): bool;

	/**
	 * Whether the current user can list records for this entity.
	 */
	public function can_read(): bool;

	/**
	 * Whether the current user can edit a specific record ID.
	 */
	public function can_edit( int $record_id ): bool;

	/**
	 * Whether the current user can create records.
	 */
	public function can_create(): bool;

	/**
	 * Whether the current user can delete a specific record ID.
	 */
	public function can_delete( int $record_id ): bool;

	/**
	 * Execute a paginated, filtered, sorted query returning hydrated records.
	 */
	public function query( QueryRequest $request ): QueryResult;

	/**
	 * Keyset-paginated ID fetcher for O(1) memory streaming exports and bulk batches.
	 *
	 * @return array<int, int>
	 */
	public function stream_ids( QueryRequest $request, int $after_id, int $limit ): array;

	/**
	 * Hydrate specific record IDs with ONLY the requested field IDs (field projection).
	 *
	 * @param array<int, int>    $ids       Record IDs.
	 * @param array<int, string> $field_ids Field IDs to project.
	 * @return array<int, array<string, mixed>>
	 */
	public function hydrate_records( array $ids, array $field_ids ): array;

	/**
	 * Create a new record inline.
	 *
	 * @param array<string, mixed> $data Initial field values.
	 * @return int|WP_Error Created record ID or error.
	 */
	public function create_record( array $data ): int|WP_Error;

	/**
	 * Duplicate an existing record including meta and taxonomies.
	 *
	 * @return int|WP_Error New record ID or error.
	 */
	public function duplicate_record( int $record_id ): int|WP_Error;

	/**
	 * Trash or delete records safely.
	 *
	 * @param array<int, int> $ids Record IDs.
	 * @return array{deleted: array<int, int>, errors: array<int, array{id: int, message: string}>}
	 */
	public function delete_records( array $ids ): array;

	/**
	 * Admin edit URL for a given record ID.
	 */
	public function get_edit_url( int $record_id ): string;

	/**
	 * Public view URL for a given record ID (empty string if non-public).
	 */
	public function get_view_url( int $record_id ): string;
}
