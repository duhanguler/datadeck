<?php
/**
 * Admin UI Integration — Dedicated DataDeck Workspace + Conditional Inline Toggle on Core Lists.
 *
 * Strict Asset Isolation Guarantee:
 *  - 0 bytes loaded on public frontend.
 *  - 0 bytes loaded on unrelated wp-admin screens.
 *  - Full app bundle ONLY enqueued on DataDeck workspace or when DataDeck View is active.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Admin;

use DataDeck\Entities\EntityRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminIntegration {

	public const MENU_SLUG = 'datadeck';

	public function __construct(
		private readonly EntityRegistry $entities,
	) {}

	/**
	 * Register WordPress admin hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );
	}

	/**
	 * Register top-level clean admin entry point using crisp geometric SVG data URI.
	 */
	public function register_menu(): void {
		$svg_icon = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="black" stroke-width="1.6"><rect x="2.5" y="3" width="15" height="14" rx="2"/><line x1="2.5" y1="7.5" x2="17.5" y2="7.5"/><line x1="2.5" y1="12" x2="17.5" y2="12"/><line x1="8" y1="7.5" x2="8" y2="17"/></svg>'
		);

		add_menu_page(
			__( 'DataDeck', 'datadeck' ),
			__( 'DataDeck', 'datadeck' ),
			'edit_posts',
			self::MENU_SLUG,
			[ $this, 'render_workspace_page' ],
			$svg_icon,
			26
		);
	}

	/**
	 * Render the full-bleed DataDeck workspace container.
	 */
	public function render_workspace_page(): void {
		echo '<div id="datadeck-root" class="datadeck-root" role="application" aria-label="' . esc_attr__( 'DataDeck Data Workspace', 'datadeck' ) . '"></div>';
	}

	/**
	 * Add full-bleed body class on the DataDeck workspace screen.
	 */
	public function add_body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
			$classes .= ' datadeck-fullscreen-admin';
		}
		return $classes;
	}

	/**
	 * Conditionally enqueue ONLY the exact assets required by the current admin screen.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// 1. Dedicated DataDeck Workspace screen.
		if ( 'toplevel_page_' . self::MENU_SLUG === $hook_suffix ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = isset( $_GET['entity'] ) ? sanitize_key( wp_unslash( $_GET['entity'] ) ) : '';
			$this->enqueue_full_workspace( $requested );
			return;
		}

		// 2. Native WordPress list screens: inject lightweight (<1.5KB) segmented toggle pill.
		$mapped_entity = $this->resolve_native_screen_entity( $hook_suffix );
		if ( null === $mapped_entity ) {
			return;
		}

		$toggle_asset_file = DATADECK_DIR . 'build/datadeck-toggle.asset.php';
		$toggle_asset      = is_readable( $toggle_asset_file )
			? require $toggle_asset_file
			: [
				'dependencies' => [],
				'version'      => DATADECK_VERSION,
			];

		wp_enqueue_script(
			'datadeck-toggle',
			DATADECK_URL . 'build/datadeck-toggle.js',
			$toggle_asset['dependencies'],
			$toggle_asset['version'],
			true
		);

		wp_add_inline_script(
			'datadeck-toggle',
			'window.datadeckToggle = ' . wp_json_encode(
				[
					'entity'       => $mapped_entity,
					'workspaceUrl' => admin_url( 'admin.php?page=' . self::MENU_SLUG . '&entity=' . rawurlencode( $mapped_entity ) ),
					'classicLabel' => __( 'Classic List', 'datadeck' ),
					'modernLabel'  => __( 'DataDeck Grid', 'datadeck' ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Enqueue the main DataDeck React application on the workspace screen.
	 */
	private function enqueue_full_workspace( string $initial_entity ): void {
		$asset_file = DATADECK_DIR . 'build/datadeck-app.asset.php';
		$asset      = is_readable( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-element', 'wp-api-fetch', 'wp-data', 'wp-i18n', 'wp-components' ],
				'version'      => DATADECK_VERSION,
			];

		// Load WordPress media modal ONLY for image field editing on workspace.
		wp_enqueue_media();

		wp_enqueue_style( 'wp-components' );

		if ( file_exists( DATADECK_DIR . 'build/datadeck-app.css' ) ) {
			wp_enqueue_style(
				'datadeck-app',
				DATADECK_URL . 'build/datadeck-app.css',
				[ 'wp-components' ],
				(string) filemtime( DATADECK_DIR . 'build/datadeck-app.css' )
			);
		}
		if ( file_exists( DATADECK_DIR . 'build/style-datadeck-app.css' ) ) {
			wp_enqueue_style(
				'datadeck-app-style',
				DATADECK_URL . 'build/style-datadeck-app.css',
				[ 'wp-components' ],
				(string) filemtime( DATADECK_DIR . 'build/style-datadeck-app.css' )
			);
		}

		wp_enqueue_script(
			'datadeck-app',
			DATADECK_URL . 'build/datadeck-app.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$entities_list = $this->entities->list_readable();
		$slugs         = array_column( $entities_list, 'slug' );
		if ( '' === $initial_entity || ! in_array( $initial_entity, $slugs, true ) ) {
			$initial_entity = in_array( 'product', $slugs, true ) ? 'product' : ( $slugs[0] ?? 'post' );
		}

		$user_prefs = get_user_meta( get_current_user_id(), 'datadeck_preferences', true );
		$prefs      = is_array( $user_prefs ) ? $user_prefs : [];

		$bootstrap = [
			'version'       => DATADECK_VERSION,
			'restRoot'      => esc_url_raw( rest_url( 'datadeck/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'adminUrl'      => admin_url(),
			'initialEntity' => $initial_entity,
			'entities'      => $entities_list,
			'preferences'   => [
				'density'          => (string) ( $prefs['density'] ?? 'default' ),
				'saveMode'         => (string) ( $prefs['saveMode'] ?? 'staged' ),
				'defaultForEntity' => is_array( $prefs['defaultForEntity'] ?? null ) ? $prefs['defaultForEntity'] : [],
			],
			'capabilities'  => [
				'manageOptions' => current_user_can( 'manage_options' ),
			],
		];

		wp_add_inline_script(
			'datadeck-app',
			'window.datadeckBootstrap = ' . wp_json_encode( $bootstrap ) . ';',
			'before'
		);
	}

	/**
	 * Map a core wp-admin list screen hook to its corresponding DataDeck entity slug.
	 */
	private function resolve_native_screen_entity( string $hook_suffix ): ?string {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return null;
		}

		if ( 'edit.php' === $hook_suffix && ! empty( $screen->post_type ) ) {
			return $this->entities->get( (string) $screen->post_type ) ? (string) $screen->post_type : null;
		}

		if ( 'upload.php' === $hook_suffix ) {
			return 'attachment';
		}

		if ( 'users.php' === $hook_suffix ) {
			return 'user';
		}

		if ( 'woocommerce_page_wc-orders' === $hook_suffix ) {
			return $this->entities->get( 'shop_order' ) ? 'shop_order' : null;
		}

		return null;
	}
}
