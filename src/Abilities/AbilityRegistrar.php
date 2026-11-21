<?php
/**
 * WordPress 6.9/7.0 Abilities API Registrar.
 *
 * Exposes safe, auditable, rollbackable query & bulk capabilities to WordPress Core's
 * native Abilities API & MCP Adapters without adding third-party dependencies.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Abilities;

use DataDeck\Bulk\BulkOperationEngine;
use DataDeck\Entities\EntityRegistry;
use DataDeck\History\HistoryService;
use DataDeck\Query\QueryRequest;
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
	 * Register DataDeck core abilities.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'datadeck/query-records',
			[
				'label'               => __( 'Query DataDeck Records', 'datadeck' ),
				'description'         => __( 'Query WordPress posts, CPTs, media, users, or WooCommerce products/orders with projected columns and filters.', 'datadeck' ),
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
						return new WP_Error( 'datadeck_forbidden', __( 'Entity unavailable or forbidden.', 'datadeck' ) );
					}
					return $adapter->query( QueryRequest::from_array( $entity, $input ) )->to_array();
				},
			]
		);

		wp_register_ability(
			'datadeck/preview-bulk-edit',
			[
				'label'               => __( 'Preview Bulk Edit (Dry-Run)', 'datadeck' ),
				'description'         => __( 'Simulate a DataDeck AST formula or bulk transformation on matching records without modifying the database.', 'datadeck' ),
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
			'datadeck/rollback-batch',
			[
				'label'               => __( 'Rollback DataDeck Batch', 'datadeck' ),
				'description'         => __( 'Restore all field values modified by a DataDeck batch UUID back to their prior snapshot.', 'datadeck' ),
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
