<?php
/**
 * Media Library Field Provider — rapid inline & bulk editing of alt text, titles, captions, and audit metrics.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Fields;

use DataDeck\Contracts\FieldProviderInterface;
use DataDeck\Schema\FieldDefinition;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaFieldProvider implements FieldProviderInterface {

	public function get_id(): string {
		return 'media';
	}

	public function supports( string $entity_slug ): bool {
		return 'attachment' === $entity_slug;
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		return [
			new FieldDefinition(
				id: 'title',
				label: __( 'Title', 'datadeck' ),
				type: 'text',
				group: 'media',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 230,
			),
			new FieldDefinition(
				id: 'preview',
				label: __( 'Preview', 'datadeck' ),
				type: 'media',
				group: 'media',
				provider: $this->get_id(),
				editable: false,
				sortable: false,
				filterable: false,
				default_visible: true,
				width: 78,
				align: 'center',
				meta_key: '_wp_attached_file',
			),
			new FieldDefinition(
				id: 'id',
				label: __( 'ID', 'datadeck' ),
				type: 'integer',
				group: 'media',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 68,
				align: 'right',
				monospace: true,
				tabular_nums: true,
			),
			new FieldDefinition(
				id: 'alt_text',
				label: __( 'Alt Text', 'datadeck' ),
				type: 'text',
				group: 'media',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 250,
				meta_key: '_wp_attachment_image_alt',
				validation: [ 'recommendedMax' => 125 ],
			),
			new FieldDefinition(
				id: 'caption',
				label: __( 'Caption', 'datadeck' ),
				type: 'textarea',
				group: 'media',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: true,
				width: 210,
			),
			new FieldDefinition(
				id: 'mime',
				label: __( 'MIME Type', 'datadeck' ),
				type: 'text',
				group: 'media',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 120,
				monospace: true,
			),
			new FieldDefinition(
				id: 'dimensions',
				label: __( 'Dimensions', 'datadeck' ),
				type: 'text',
				group: 'media',
				provider: $this->get_id(),
				editable: false,
				sortable: false,
				filterable: false,
				default_visible: true,
				width: 112,
				align: 'right',
				monospace: true,
				tabular_nums: true,
				meta_key: '_wp_attachment_metadata',
			),
			new FieldDefinition(
				id: 'file_size_kb',
				label: __( 'Size (KB)', 'datadeck' ),
				type: 'number',
				group: 'media',
				provider: $this->get_id(),
				editable: false,
				sortable: false,
				filterable: false,
				default_visible: true,
				width: 96,
				align: 'right',
				tabular_nums: true,
				meta_key: '_wp_attachment_metadata',
			),
			new FieldDefinition(
				id: 'date',
				label: __( 'Uploaded', 'datadeck' ),
				type: 'datetime',
				group: 'media',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 155,
				tabular_nums: true,
			),
		];
	}

	public function get_required_meta_keys( array $fields ): array {
		$keys = [];
		foreach ( $fields as $f ) {
			if ( '' !== $f->meta_key ) {
				$keys[] = $f->meta_key;
			}
		}
		if ( isset( $fields['preview'] ) ) {
			$keys[] = '_wp_attachment_metadata';
		}
		return array_values( array_unique( $keys ) );
	}

	public function hydrate(
		string $entity_slug,
		array $ids,
		array $fields,
		array &$rows,
		array $meta_cache
	): void {
		$upload_dir = wp_get_upload_dir();
		$base_url   = trailingslashit( (string) ( $upload_dir['baseurl'] ?? '' ) );
		$base_dir   = trailingslashit( (string) ( $upload_dir['basedir'] ?? '' ) );

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$attached_file = (string) ( $meta_cache[ $id ]['_wp_attached_file'][0] ?? '' );
			$att_meta      = $meta_cache[ $id ]['_wp_attachment_metadata'][0] ?? [];
			$alt           = (string) ( $meta_cache[ $id ]['_wp_attachment_image_alt'][0] ?? '' );

			foreach ( $fields as $fid => $field ) {
				if ( 'preview' === $fid ) {
					$dir = dirname( $attached_file );
					$dir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );
					$url = '' !== $attached_file ? $base_url . $attached_file : '';
					if ( is_array( $att_meta ) && ! empty( $att_meta['sizes']['thumbnail']['file'] ) ) {
						$url = $base_url . $dir . $att_meta['sizes']['thumbnail']['file'];
					}
					$rows[ $id ][ $fid ] = [
						'id'       => $id,
						'url'      => $url,
						'alt'      => $alt,
						'filename' => '' !== $attached_file ? basename( $attached_file ) : '',
					];
					continue;
				}

				if ( 'dimensions' === $fid ) {
					if ( is_array( $att_meta ) && ! empty( $att_meta['width'] ) && ! empty( $att_meta['height'] ) ) {
						$rows[ $id ][ $fid ] = sprintf( '%d×%d', (int) $att_meta['width'], (int) $att_meta['height'] );
					} else {
						$rows[ $id ][ $fid ] = '—';
					}
					continue;
				}

				if ( 'file_size_kb' === $fid ) {
					$bytes = 0;
					if ( is_array( $att_meta ) && ! empty( $att_meta['filesize'] ) ) {
						$bytes = (int) $att_meta['filesize'];
					} elseif ( '' !== $attached_file && is_readable( $base_dir . $attached_file ) ) {
						$bytes = (int) filesize( $base_dir . $attached_file );
					}
					$rows[ $id ][ $fid ] = $bytes > 0 ? round( $bytes / 1024, 1 ) : null;
					continue;
				}

				$rows[ $id ][ $fid ] = match ( $fid ) {
					'id'       => $id,
					'title'    => $post->post_title,
					'alt_text' => $alt,
					'caption'  => $post->post_excerpt,
					'mime'     => $post->post_mime_type,
					'date'     => $post->post_date,
					default    => null,
				};
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		$post = get_post( $record_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		return match ( $field->id ) {
			'title'    => $post->post_title,
			'alt_text' => (string) get_post_meta( $record_id, '_wp_attachment_image_alt', true ),
			'caption'  => $post->post_excerpt,
			default    => null,
		};
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): bool|WP_Error {
		if ( 'alt_text' === $field->id ) {
			update_post_meta( $record_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $value ) );
			return true;
		}
		if ( 'title' === $field->id ) {
			$res = wp_update_post(
				[
					'ID'         => $record_id,
					'post_title' => sanitize_text_field( (string) $value ),
				],
				true
			);
			return is_wp_error( $res ) ? $res : true;
		}
		if ( 'caption' === $field->id ) {
			$res = wp_update_post(
				[
					'ID'           => $record_id,
					'post_excerpt' => sanitize_textarea_field( (string) $value ),
				],
				true
			);
			return is_wp_error( $res ) ? $res : true;
		}
		return new WP_Error( 'datadeck_unsupported_media_field', __( 'Unsupported media field.', 'datadeck' ), [ 'status' => 400 ] );
	}
}
