<?php
/**
 * Plugin Name:       RowCraft – Admin Columns, Inline & Bulk Editor
 * Plugin URI:        https://github.com/rowcraft/rowcraft
 * Description:       High-performance admin columns, zero-shift inline spreadsheet editor, safe AST bulk formulas, and one-click rollback for WordPress and WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            RowCraft Contributors
 * Author URI:        https://github.com/rowcraft/rowcraft
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rowcraft
 * Domain Path:       /languages
 *
 * @package RowCraft
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ROWCRAFT_VERSION', '1.0.0' );
define( 'ROWCRAFT_FILE', __FILE__ );
define( 'ROWCRAFT_DIR', plugin_dir_path( __FILE__ ) );
define( 'ROWCRAFT_URL', plugin_dir_url( __FILE__ ) );
define( 'ROWCRAFT_DB_VERSION', '1.0.0' );

/**
 * Lightweight PSR-4 autoloader so distributed builds work with or without vendor/.
 */
if ( file_exists( ROWCRAFT_DIR . 'vendor/autoload.php' ) ) {
	require_once ROWCRAFT_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'RowCraft\\';
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = ROWCRAFT_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook(
	ROWCRAFT_FILE,
	static function (): void {
		\RowCraft\History\Schema::install();
		if ( ! wp_next_scheduled( 'rowcraft_daily_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rowcraft_daily_prune' );
		}
		if ( ! wp_next_scheduled( 'rowcraft_process_schedules' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'rowcraft_process_schedules' );
		}
	}
);

register_deactivation_hook(
	ROWCRAFT_FILE,
	static function (): void {
		wp_clear_scheduled_hook( 'rowcraft_daily_prune' );
		wp_clear_scheduled_hook( 'rowcraft_process_schedules' );
	}
);

/**
 * Context-aware bootstrap:
 * Zero frontend footprint — skips loading entirely on public storefront / front-facing requests.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		$is_admin    = is_admin();
		$is_rest     = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$is_cli      = defined( 'WP_CLI' ) && WP_CLI;
		$is_cron     = wp_doing_cron();
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$is_rc_rest  = str_contains( $request_uri, 'rowcraft/v1' ) || str_contains( $request_uri, 'rest_route=' );

		if ( ! $is_admin && ! $is_rest && ! $is_cli && ! $is_cron && ! $is_rc_rest ) {
			return;
		}

		\RowCraft\Plugin::instance()->boot();
	},
	20
);
