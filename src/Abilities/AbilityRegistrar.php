<?php
/**
 * WordPress 6.9/7.0 Abilities API Registrar.
 *
 * Exposes safe, auditable, rollbackable query & bulk capabilities to WordPress Core's
 * native Abilities API & MCP Adapters without adding third-party dependencies.
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Abilities;

use RowCraft\Bulk\BulkOperationEngine;
use RowCraft\Entities\EntityRegistry;
use RowCraft\History\HistoryService;
use RowCraft\Query\QueryRequest;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AbilityRegistrar {

	public function __construct(
		private readonly EntityRegistry $entities,
		private readonly BulkOperationEngine $bulk,
		private readonly HistoryService $history,
	) {}

	/**
	 * Hook into wp_abilities_api_init when available (WP 6.9+).
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register RowCraft core abilities.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'rowcraft/query-records',
			[
				'label'               => __( 'Query RowCraft Records', 'rowcraft' ),
				'description'         => __( 'Query WordPress posts, CPTs, media, users, or WooCommerce products/orders with projected columns and filters.', 'rowcraft' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'entity'   => [ 'type' => 'string' ],
						'search'   => [ 'type' => 'string' ],
						'per_page' => [
							'type'    => 'integer',
							'default' => 25,
						],
						'lens'     => [ 'type' => 'string' ],
					],
					'required'   => [ 'entity' ],
				],
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'execute_callback'    => function ( array $input ): array|WP_Error {
					$entity  = sanitize_key( (string) ( $input['entity'] ?? 'post' ) );
					$adapter = $this->entities->get( $entity );
					if ( ! $adapter || ! $adapter->can_read() ) {
						return new WP_Error( 'rowcraft_forbidden', __( 'Entity unavailable or forbidden.', 'rowcraft' ) );
					}
					return $adapter->query( QueryRequest::from_array( $entity, $input ) )->to_array();
				},
			]
		);

		wp_register_ability(
			'rowcraft/preview-bulk-edit',
			[
				'label'               => __( 'Preview Bulk Edit (Dry-Run)', 'rowcraft' ),
				'description'         => __( 'Simulate a RowCraft AST formula or bulk transformation on matching records without modifying the database.', 'rowcraft' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'entity' => [ 'type' => 'string' ],
						'rule'   => [ 'type' => 'object' ],
						'ids'    => [ 'type' => 'array' ],
					],
					'required'   => [ 'entity', 'rule' ],
				],
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'execute_callback'    => function ( array $input ): array|WP_Error {
					return $this->bulk->dry_run(
						sanitize_key( (string) ( $input['entity'] ?? 'post' ) ),
						(array) ( $input['rule'] ?? [] ),
						array_map( 'intval', (array) ( $input['ids'] ?? [] ) )
					);
				},
			]
		);

		wp_register_ability(
			'rowcraft/rollback-batch',
			[
				'label'               => __( 'Rollback RowCraft Batch', 'rowcraft' ),
				'description'         => __( 'Restore all field values modified by a RowCraft batch UUID back to their prior snapshot.', 'rowcraft' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'batch_uuid' => [ 'type' => 'string' ],
					],
					'required'   => [ 'batch_uuid' ],
				],
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'execute_callback'    => function ( array $input ): array|WP_Error {
					return $this->history->rollback( sanitize_text_field( (string) ( $input['batch_uuid'] ?? '' ) ), false );
				},
			]
		);
	}
}
