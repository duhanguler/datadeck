<?php
/**
 * Saved Views & Git-Syncable Local JSON (`datadeck-json/`) Service.
 *
 * Mirrors the beloved acf-json workflow: when an active theme contains a `datadeck-json/`
 * folder (or custom filter path), saved column presets & filter views automatically sync
 * to clean, version-controllable JSON files.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Sync;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ViewSyncService {

	private const OPTION_KEY = 'datadeck_views';

	/**
	 * Return all saved views for an entity visible to the current user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_views( string $entity_slug ): array {
		$all      = $this->load_all_db_views();
		$filtered = [];
		$user     = wp_get_current_user();
		$roles    = $user->roles;

		foreach ( $all as $view ) {
			if ( ( $view['entity'] ?? '' ) !== $entity_slug ) {
				continue;
			}
			$allowed_roles = (array) ( $view['roles'] ?? [] );
			if ( ! empty( $allowed_roles ) && ! current_user_can( 'manage_options' ) && empty( array_intersect( $roles, $allowed_roles ) ) ) {
				continue;
			}
			$filtered[] = $view;
		}

		return $filtered;
	}

	/**
	 * Create or update a Saved View and optionally mirror it to datadeck-json/.
	 *
	 * @param string               $entity_slug Entity slug.
	 * @param array<string, mixed> $payload     View configuration.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save_view( string $entity_slug, array $payload ): array|WP_Error {
		$name = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'datadeck_view_name', __( 'View name is required.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$id   = ! empty( $payload['id'] ) ? sanitize_key( (string) $payload['id'] ) : sanitize_title( $entity_slug . '-' . $name . '-' . substr( wp_generate_uuid4(), 0, 6 ) );
		$cols = array_values( array_filter( array_map( static fn( mixed $c ): string => (string) preg_replace( '/[^a-zA-Z0-9_\-:]/', '', (string) $c ), (array) ( $payload['columns'] ?? [] ) ) ) );

		$widths = [];
		if ( ! empty( $payload['columnWidths'] ) && is_array( $payload['columnWidths'] ) ) {
			foreach ( $payload['columnWidths'] as $col_id => $w ) {
				$clean_id            = (string) preg_replace( '/[^a-zA-Z0-9_\-:]/', '', (string) $col_id );
				$widths[ $clean_id ] = min( 640, max( 56, (int) $w ) );
			}
		}

		$density = in_array( $payload['density'] ?? 'default', [ 'compact', 'default', 'comfortable' ], true )
			? (string) $payload['density']
			: 'default';

		$view = [
			'id'             => $id,
			'entity'         => sanitize_key( $entity_slug ),
			'name'           => $name,
			'columns'        => $cols,
			'columnWidths'   => $widths,
			'density'        => $density,
			'sortField'      => sanitize_key( (string) ( $payload['sortField'] ?? 'id' ) ),
			'sortDirection'  => 'asc' === strtolower( (string) ( $payload['sortDirection'] ?? 'desc' ) ) ? 'asc' : 'desc',
			'filters'        => is_array( $payload['filters'] ?? null ) ? $payload['filters'] : [],
			'filterRelation' => 'OR' === strtoupper( (string) ( $payload['filterRelation'] ?? 'AND' ) ) ? 'OR' : 'AND',
			'roles'          => array_values( array_map( 'sanitize_key', (array) ( $payload['roles'] ?? [] ) ) ),
			'updatedAt'      => time(),
		];

		$all        = $this->load_all_db_views();
		$all[ $id ] = $view;
		update_option( self::OPTION_KEY, $all, false );

		$this->maybe_write_local_json( $view );

		return $view;
	}

	/**
	 * Delete a Saved View by ID.
	 */
	public function delete_view( string $view_id ): bool {
		$id  = sanitize_key( $view_id );
		$all = $this->load_all_db_views();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		unset( $all[ $id ] );
		update_option( self::OPTION_KEY, $all, false );

		$json_file = $this->get_save_path() . '/view-' . $id . '.json';
		if ( is_file( $json_file ) && wp_is_writable( $json_file ) ) {
			wp_delete_file( $json_file );
		}

		return true;
	}

	/**
	 * Inspect local datadeck-json/ directories and report syncable JSON view files.
	 *
	 * @return array{enabled: bool, savePath: string, syncAvailable: array<int, array<string, mixed>>}
	 */
	public function get_json_sync_status(): array {
		$save_path = $this->get_save_path();
		$enabled   = '' !== $save_path && is_dir( $save_path );
		$db_views  = $this->load_all_db_views();
		$syncable  = [];

		foreach ( $this->get_load_paths() as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = glob( trailingslashit( $dir ) . 'view-*.json' );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$raw  = file_get_contents( $file );
				$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
				if ( ! is_array( $data ) || empty( $data['id'] ) || empty( $data['entity'] ) ) {
					continue;
				}
				$id    = (string) $data['id'];
				$db_ts = (int) ( $db_views[ $id ]['updatedAt'] ?? 0 );
				$fs_ts = (int) ( $data['updatedAt'] ?? 0 );
				if ( ! isset( $db_views[ $id ] ) || $fs_ts > $db_ts ) {
					$syncable[] = $data;
				}
			}
		}

		return [
			'enabled'       => $enabled,
			'savePath'      => $enabled ? $save_path : '',
			'syncAvailable' => $syncable,
		];
	}

	/**
	 * Import all out-of-sync views from datadeck-json/ into the database.
	 *
	 * @return array{synced: int}
	 */
	public function sync_from_json(): array {
		$status = $this->get_json_sync_status();
		$all    = $this->load_all_db_views();
		$count  = 0;

		foreach ( $status['syncAvailable'] as $view ) {
			$id         = sanitize_key( (string) $view['id'] );
			$all[ $id ] = $view;
			++$count;
		}

		if ( $count > 0 ) {
			update_option( self::OPTION_KEY, $all, false );
		}

		return [ 'synced' => $count ];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function load_all_db_views(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		return is_array( $raw ) ? $raw : [];
	}

	private function get_save_path(): string {
		$default = trailingslashit( get_stylesheet_directory() ) . 'datadeck-json';
		$path    = (string) apply_filters( 'datadeck_json_save_path', $default );
		return untrailingslashit( $path );
	}

	/**
	 * @return array<int, string>
	 */
	private function get_load_paths(): array {
		$paths    = [ $this->get_save_path() ];
		$filtered = apply_filters( 'datadeck_json_load_paths', $paths );
		return is_array( $filtered ) ? array_values( array_unique( array_map( 'strval', $filtered ) ) ) : $paths;
	}

	/**
	 * Write a pretty-printed JSON view snapshot when datadeck-json/ exists and is writable.
	 *
	 * @param array<string, mixed> $view View array.
	 */
	private function maybe_write_local_json( array $view ): void {
		$dir = $this->get_save_path();
		if ( '' === $dir || ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return;
		}
		$file = $dir . '/view-' . sanitize_file_name( (string) $view['id'] ) . '.json';
		$json = wp_json_encode( $view, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_string( $json ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, $json . "\n" );
		}
	}
}
