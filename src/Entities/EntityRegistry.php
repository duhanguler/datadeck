<?php
/**
 * Central Entity Adapter Registry discovering Posts, Pages, CPTs, Media, Users, and WooCommerce.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Entities;

use DataDeck\Contracts\EntityAdapterInterface;
use DataDeck\Fields\FieldRegistry;
use DataDeck\Query\SelectiveHydrator;
use DataDeck\Query\SmartQueryPlanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EntityRegistry {

	/**
	 * Registered adapters keyed by entity slug.
	 *
	 * @var array<string, EntityAdapterInterface>
	 */
	private array $adapters = [];

	private bool $discovered = false;

	public function __construct(
		private readonly FieldRegistry $fields,
		private readonly SmartQueryPlanner $planner,
		private readonly SelectiveHydrator $hydrator,
	) {}

	/**
	 * Register a custom entity adapter.
	 */
	public function register( EntityAdapterInterface $adapter ): void {
		$this->adapters[ $adapter->get_slug() ] = $adapter;
	}

	/**
	 * Discover all active WordPress & WooCommerce entities lazily.
	 */
	public function discover(): void {
		if ( $this->discovered ) {
			return;
		}
		$this->discovered = true;

		// WooCommerce Products & HPOS Orders first when active.
		if ( class_exists( 'WooCommerce' ) ) {
			$this->register( new WooProductEntityAdapter( $this->fields, $this->planner, $this->hydrator ) );
			$this->register( new WooOrderEntityAdapter( $this->fields ) );
		}

		// Core Posts & Pages + any public/show_ui Custom Post Types.
		$post_types = get_post_types( [ 'show_ui' => true ], 'objects' );
		$skip       = [ 'attachment', 'wp_navigation', 'wp_block', 'product', 'shop_order', 'shop_coupon', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' ];

		foreach ( $post_types as $slug => $pt_obj ) {
			if ( in_array( $slug, $skip, true ) || isset( $this->adapters[ $slug ] ) ) {
				continue;
			}
			$this->register( new PostEntityAdapter( (string) $slug, $this->fields, $this->planner, $this->hydrator ) );
		}

		// Media Library, Comments & Users.
		$this->register( new MediaEntityAdapter( $this->fields, $this->planner, $this->hydrator ) );
		$this->register( new CommentEntityAdapter( $this->fields, $this->hydrator ) );
		$this->register( new UserEntityAdapter( $this->fields, $this->hydrator ) );

		/**
		 * Allow third-party extensions to register additional entity adapters.
		 *
		 * @param EntityRegistry $registry Entity registry instance.
		 */
		do_action( 'datadeck_register_entities', $this );
	}

	/**
	 * Retrieve an adapter by slug.
	 */
	public function get( string $slug ): ?EntityAdapterInterface {
		$this->discover();
		$adapter = $this->adapters[ $slug ] ?? null;
		if ( $adapter && $adapter->is_available() ) {
			return $adapter;
		}
		return null;
	}

	/**
	 * Return all available and readable entities for the current user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_readable(): array {
		$this->discover();
		$list = [];
		foreach ( $this->adapters as $adapter ) {
			if ( ! $adapter->is_available() || ! $adapter->can_read() ) {
				continue;
			}
			$list[] = [
				'slug'        => $adapter->get_slug(),
				'label'       => $adapter->get_label(),
				'pluralLabel' => $adapter->get_plural_label(),
				'group'       => $adapter->get_group(),
				'canCreate'   => $adapter->can_create(),
			];
		}
		return $list;
	}

	/**
	 * Return all available entities (used by WP-CLI diagnostics where no user session is set).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_available(): array {
		$this->discover();
		$list = [];
		foreach ( $this->adapters as $adapter ) {
			if ( ! $adapter->is_available() ) {
				continue;
			}
			$list[] = [
				'slug'        => $adapter->get_slug(),
				'label'       => $adapter->get_label(),
				'pluralLabel' => $adapter->get_plural_label(),
				'group'       => $adapter->get_group(),
				'canCreate'   => $adapter->can_create(),
			];
		}
		return $list;
	}
}
