<?php
/**
 * Selective Meta & Term Hydrator — eliminates N+1 queries and skips megabyte page-builder blobs.
 *
 * Unlike WordPress core's update_meta_cache() which loads EVERY meta key (including multi-MB
 * _elementor_data / _bricks_page_content blobs), this fetches ONLY the visible columns' keys
 * in a single indexed SQL query.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SelectiveHydrator {

	/**
	 * Fetch ONLY the requested postmeta keys for the given post IDs in 1 single SQL query.
	 *
	 * @param array<int, int>    $post_ids  Post IDs.
	 * @param array<int, string> $meta_keys Meta keys strictly required by visible columns.
	 * @return array<int, array<string, array<int, mixed>>> Map: [post_id][meta_key] => list of unserialized values.
	 */
	public function hydrate_post_meta( array $post_ids, array $meta_keys ): array {
		return $this->hydrate_meta_table( 'post', $post_ids, $meta_keys );
	}

	/**
	 * Fetch ONLY the requested usermeta keys for the given user IDs in 1 single SQL query.
	 *
	 * @param array<int, int>    $user_ids  User IDs.
	 * @param array<int, string> $meta_keys Meta keys strictly required by visible columns.
	 * @return array<int, array<string, array<int, mixed>>>
	 */
	public function hydrate_user_meta( array $user_ids, array $meta_keys ): array {
		return $this->hydrate_meta_table( 'user', $user_ids, $meta_keys );
	}

	/**
	 * Fetch ONLY the requested commentmeta keys for the given comment IDs in 1 single SQL query.
	 *
	 * @param array<int, int>    $comment_ids Comment IDs.
	 * @param array<int, string> $meta_keys   Meta keys strictly required by visible columns.
	 * @return array<int, array<string, array<int, mixed>>>
	 */
	public function hydrate_comment_meta( array $comment_ids, array $meta_keys ): array {
		return $this->hydrate_meta_table( 'comment', $comment_ids, $meta_keys );
	}

	/**
	 * Fetch terms for ONLY the visible taxonomies across all object IDs in 1 single SQL query.
	 * Completely eliminates N+1 get_the_terms() queries regardless of row count (50 or 500 rows = 1 SQL).
	 *
	 * @param array<int, int>    $post_ids   Post IDs.
	 * @param array<int, string> $taxonomies Taxonomy slugs to hydrate.
	 * @return array<int, array<string, array<int, array{id: int, label: string, slug: string}>>>
	 */
	public function hydrate_terms( array $post_ids, array $taxonomies ): array {
		global $wpdb;

		$post_ids   = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$taxonomies = array_values( array_unique( array_filter( $taxonomies ) ) );

		if ( empty( $post_ids ) || empty( $taxonomies ) ) {
			return [];
		}

		$id_placeholders  = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$tax_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT tr.object_id, tt.taxonomy, t.term_id, t.name, t.slug
			FROM {$wpdb->term_relationships} AS tr
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id
			WHERE tr.object_id IN ({$id_placeholders})
			AND tt.taxonomy IN ({$tax_placeholders})
			ORDER BY t.name ASC",
			...array_merge( $post_ids, $taxonomies )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );
		$map  = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row->object_id ][ (string) $row->taxonomy ][] = [
					'id'    => (int) $row->term_id,
					'label' => (string) $row->name,
					'slug'  => (string) $row->slug,
				];
			}
		}

		return $map;
	}

	/**
	 * Hydrate attachment thumbnails (URL + alt) in 1 single query pair for media columns.
	 *
	 * @param array<int, int> $attachment_ids Attachment IDs.
	 * @return array<int, array{id: int, url: string, alt: string, filename: string}>
	 */
	public function hydrate_thumbnails( array $attachment_ids ): array {
		$attachment_ids = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) );
		if ( empty( $attachment_ids ) ) {
			return [];
		}

		$meta       = $this->hydrate_post_meta( $attachment_ids, [ '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' ] );
		$upload_dir = wp_get_upload_dir();
		$base_url   = trailingslashit( (string) ( $upload_dir['baseurl'] ?? '' ) );
		$result     = [];

		foreach ( $attachment_ids as $att_id ) {
			$attached_file = (string) ( $meta[ $att_id ]['_wp_attached_file'][0] ?? '' );
			$att_meta      = $meta[ $att_id ]['_wp_attachment_metadata'][0] ?? [];
			$alt           = (string) ( $meta[ $att_id ]['_wp_attachment_image_alt'][0] ?? '' );
			$thumb_url     = '';

			if ( '' !== $attached_file ) {
				$dir = dirname( $attached_file );
				$dir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );

				if ( is_array( $att_meta ) && ! empty( $att_meta['sizes']['thumbnail']['file'] ) ) {
					$thumb_url = $base_url . $dir . $att_meta['sizes']['thumbnail']['file'];
				} else {
					$thumb_url = $base_url . $attached_file;
				}
			}

			$result[ $att_id ] = [
				'id'       => $att_id,
				'url'      => $thumb_url,
				'alt'      => $alt,
				'filename' => '' !== $attached_file ? basename( $attached_file ) : '',
			];
		}

		return $result;
	}

	/**
	 * Shared selective meta fetcher using strict IN(...) prepared placeholders.
	 *
	 * @param string             $object_type 'post' or 'user'.
	 * @param array<int, int>    $object_ids  Object IDs.
	 * @param array<int, string> $meta_keys   Allowed meta keys.
	 * @return array<int, array<string, array<int, mixed>>>
	 */
	private function hydrate_meta_table( string $object_type, array $object_ids, array $meta_keys ): array {
		global $wpdb;

		$object_ids = array_values( array_unique( array_filter( array_map( 'intval', $object_ids ) ) ) );
		$meta_keys  = array_values( array_unique( array_filter( $meta_keys, static fn( string $k ): bool => '' !== $k ) ) );

		if ( empty( $object_ids ) || empty( $meta_keys ) ) {
			return [];
		}

		$table = match ( $object_type ) {
			'user'    => $wpdb->usermeta,
			'comment' => $wpdb->commentmeta,
			default   => $wpdb->postmeta,
		};
		$id_column = match ( $object_type ) {
			'user'    => 'user_id',
			'comment' => 'comment_id',
			default   => 'post_id',
		};

		$id_placeholders  = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT {$id_column} AS object_id, meta_key, meta_value FROM {$table} WHERE {$id_column} IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders})",
			...array_merge( $object_ids, $meta_keys )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );
		$map  = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$oid                   = (int) $row->object_id;
				$key                   = (string) $row->meta_key;
				$map[ $oid ][ $key ][] = maybe_unserialize( $row->meta_value );
			}
		}

		return $map;
	}
}
