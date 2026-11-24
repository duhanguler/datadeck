<?php
/**
 * WordPress Comments & WooCommerce Product Reviews Field Provider.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Fields;

use DataDeck\Contracts\FieldProviderInterface;
use DataDeck\Schema\FieldDefinition;
use WP_Comment;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CommentFieldProvider implements FieldProviderInterface {

	public function get_id(): string {
		return 'comment';
	}

	public function supports( string $entity_slug ): bool {
		return 'comment' === $entity_slug;
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		return [
			new FieldDefinition(
				id: 'content',
				label: __( 'Comment', 'datadeck' ),
				type: 'textarea',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: false,
				default_visible: true,
				width: 320,
			),
			new FieldDefinition(
				id: 'id',
				label: __( 'ID', 'datadeck' ),
				type: 'integer',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: false,
				default_visible: true,
				width: 72,
				align: 'right',
				monospace: true,
				tabular_nums: true,
			),
			new FieldDefinition(
				id: 'author_name',
				label: __( 'Author', 'datadeck' ),
				type: 'text',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: false,
				default_visible: true,
				width: 155,
			),
			new FieldDefinition(
				id: 'author_email',
				label: __( 'Author Email', 'datadeck' ),
				type: 'email',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: false,
				default_visible: true,
				width: 195,
				monospace: true,
			),
			new FieldDefinition(
				id: 'status',
				label: __( 'Status', 'datadeck' ),
				type: 'select',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 120,
				options: [
					[
						'value' => 'approve',
						'label' => __( 'Approved', 'datadeck' ),
					],
					[
						'value' => 'hold',
						'label' => __( 'Pending', 'datadeck' ),
					],
					[
						'value' => 'spam',
						'label' => __( 'Spam', 'datadeck' ),
					],
					[
						'value' => 'trash',
						'label' => __( 'Trash', 'datadeck' ),
					],
				],
			),
			new FieldDefinition(
				id: 'post_title',
				label: __( 'In Response To', 'datadeck' ),
				type: 'readonly',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: false,
				filterable: false,
				default_visible: true,
				width: 200,
			),
			new FieldDefinition(
				id: 'rating',
				label: __( 'Rating', 'datadeck' ),
				type: 'integer',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: class_exists( 'WooCommerce' ),
				width: 86,
				align: 'right',
				tabular_nums: true,
				meta_key: 'rating',
				validation: [
					'min' => 1,
					'max' => 5,
				],
			),
			new FieldDefinition(
				id: 'comment_type',
				label: __( 'Type', 'datadeck' ),
				type: 'select',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 110,
				options: [
					[
						'value' => 'comment',
						'label' => __( 'Comment', 'datadeck' ),
					],
					[
						'value' => 'review',
						'label' => __( 'Product Review', 'datadeck' ),
					],
					[
						'value' => 'pingback',
						'label' => __( 'Pingback', 'datadeck' ),
					],
				],
			),
			new FieldDefinition(
				id: 'post_id',
				label: __( 'Post ID', 'datadeck' ),
				type: 'integer',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: false,
				width: 84,
				align: 'right',
				monospace: true,
				tabular_nums: true,
			),
			new FieldDefinition(
				id: 'date',
				label: __( 'Submitted', 'datadeck' ),
				type: 'datetime',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: false,
				default_visible: true,
				width: 145,
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
		return $keys;
	}

	public function hydrate(
		string $entity_slug,
		array $ids,
		array $fields,
		array &$rows,
		array $meta_cache
	): void {
		foreach ( $ids as $id ) {
			$comment = $rows[ $id ]['_comment'] ?? get_comment( $id );
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}

			foreach ( $fields as $fid => $field ) {
				if ( 'rating' === $fid ) {
					$raw                 = $meta_cache[ $id ]['rating'][0] ?? null;
					$rows[ $id ][ $fid ] = ( null !== $raw && '' !== $raw ) ? (int) $raw : null;
					continue;
				}

				$rows[ $id ][ $fid ] = match ( $fid ) {
					'id'           => $id,
					'content'      => (string) $comment->comment_content,
					'author_name'  => (string) $comment->comment_author,
					'author_email' => (string) $comment->comment_author_email,
					'status'       => $this->normalize_status( (string) $comment->comment_approved ),
					'post_id'      => (int) $comment->comment_post_ID,
					'post_title'   => (int) $comment->comment_post_ID > 0 ? html_entity_decode( get_the_title( (int) $comment->comment_post_ID ), ENT_QUOTES, 'UTF-8' ) : '',
					'comment_type' => '' !== (string) $comment->comment_type ? (string) $comment->comment_type : 'comment',
					'date'         => (string) $comment->comment_date,
					default        => null,
				};
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		$comment = get_comment( $record_id );
		if ( ! $comment instanceof WP_Comment ) {
			return null;
		}

		if ( 'rating' === $field->id ) {
			$raw = get_comment_meta( $record_id, 'rating', true );
			return '' !== (string) $raw ? (int) $raw : null;
		}

		return match ( $field->id ) {
			'content'      => (string) $comment->comment_content,
			'author_name'  => (string) $comment->comment_author,
			'author_email' => (string) $comment->comment_author_email,
			'status'       => $this->normalize_status( (string) $comment->comment_approved ),
			'post_id'      => (int) $comment->comment_post_ID,
			'comment_type' => (string) $comment->comment_type,
			'date'         => (string) $comment->comment_date,
			default        => null,
		};
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): bool|WP_Error {
		if ( 'rating' === $field->id ) {
			if ( null === $value || '' === $value ) {
				delete_comment_meta( $record_id, 'rating' );
			} else {
				$num = max( 1, min( 5, (int) $value ) );
				update_comment_meta( $record_id, 'rating', $num );
			}
			$comment = get_comment( $record_id );
			if ( $comment instanceof WP_Comment && (int) $comment->comment_post_ID > 0 && class_exists( 'WC_Comments' ) ) {
				\WC_Comments::clear_transients( (int) $comment->comment_post_ID );
			}
			return true;
		}

		if ( 'status' === $field->id ) {
			$status = sanitize_key( (string) $value );
			if ( ! in_array( $status, [ 'approve', 'hold', 'spam', 'trash' ], true ) ) {
				return new WP_Error( 'datadeck_invalid_status', __( 'Invalid comment status.', 'datadeck' ), [ 'status' => 400 ] );
			}
			$ok = wp_set_comment_status( $record_id, $status, true );
			return is_wp_error( $ok ) ? $ok : true;
		}

		if ( 'author_email' === $field->id ) {
			$raw_email = trim( (string) $value );
			$clean     = sanitize_email( $raw_email );
			if ( '' !== $raw_email && ( '' === $clean || ! is_email( $clean ) ) ) {
				return new WP_Error( 'datadeck_invalid_email', __( 'Invalid author email address.', 'datadeck' ), [ 'status' => 400 ] );
			}
			$value = $clean;
		}

		$update = [ 'comment_ID' => $record_id ];
		match ( $field->id ) {
			'content'      => $update['comment_content']      = wp_kses_post( (string) $value ),
			'author_name'  => $update['comment_author']       = sanitize_text_field( (string) $value ),
			'author_email' => $update['comment_author_email'] = (string) $value,
			default        => null,
		};

		if ( count( $update ) <= 1 ) {
			return true;
		}

		$res = wp_update_comment( $update, true );
		return is_wp_error( $res ) ? $res : true;
	}

	private function normalize_status( string $approved ): string {
		return match ( $approved ) {
			'1', 'approve' => 'approve',
			'0', 'hold'    => 'hold',
			'spam'         => 'spam',
			'trash'        => 'trash',
			default        => $approved,
		};
	}
}
