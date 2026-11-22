<?php
/**
 * Plugin Name:       DataDeck – Admin Columns, Inline & Bulk Editor
 * Plugin URI:        https://github.com/duhanguler/datadeck
 * Description:       High-performance admin columns, zero-shift inline spreadsheet editor, safe AST bulk formulas, and one-click rollback for WordPress and WooCommerce.
 * Version:           1.1.1
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Duhan Güler
 * Author URI:        https://github.com/duhanguler
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       datadeck
 * Domain Path:       /languages
 *
 * @package DataDeck
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DATADECK_VERSION', '1.1.1' );
define( 'DATADECK_FILE', __FILE__ );
define( 'DATADECK_DIR', plugin_dir_path( __FILE__ ) );
define( 'DATADECK_URL', plugin_dir_url( __FILE__ ) );
define( 'DATADECK_DB_VERSION', '1.0.0' );

/**
 * Lightweight PSR-4 autoloader so distributed builds work with or without vendor/.
 */
if ( file_exists( DATADECK_DIR . 'vendor/autoload.php' ) ) {
	require_once DATADECK_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'DataDeck\\';
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = DATADECK_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook(
	DATADECK_FILE,
	static function (): void {
		\DataDeck\History\Schema::install();
		if ( ! wp_next_scheduled( 'datadeck_daily_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'datadeck_daily_prune' );
		}
		if ( ! wp_next_scheduled( 'datadeck_process_schedules' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'datadeck_process_schedules' );
		}
	}
);

register_deactivation_hook(
	DATADECK_FILE,
	static function (): void {
		wp_clear_scheduled_hook( 'datadeck_daily_prune' );
		wp_clear_scheduled_hook( 'datadeck_process_schedules' );
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
		$is_rc_rest  = str_contains( $request_uri, 'datadeck/v1' ) || str_contains( $request_uri, 'rest_route=' );

		if ( ! $is_admin && ! $is_rest && ! $is_cli && ! $is_cron && ! $is_rc_rest ) {
			return;
		}

		\DataDeck\Plugin::instance()->boot();
	},
	20
);
