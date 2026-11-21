<?php
/**
 * Core WordPress Post, Page, CPT, and Taxonomy Field Provider.
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Fields;

use RowCraft\Contracts\FieldProviderInterface;
use RowCraft\Query\SelectiveHydrator;
use RowCraft\Schema\FieldDefinition;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CorePostFieldProvider implements FieldProviderInterface {

	private SelectiveHydrator $hydrator;

	public function __construct( SelectiveHydrator $hydrator ) {
		$this->hydrator = $hydrator;
	}

	public function get_id(): string {
		return 'core_post';
	}

	public function supports( string $entity_slug ): bool {
		if ( in_array( $entity_slug, [ 'user', 'attachment', 'shop_order' ], true ) ) {
			return false;
		}
		return post_type_exists( $entity_slug );
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		$is_product = 'product' === $entity_slug;

		$status_options = [
			[
				'value' => 'publish',
				'label' => __( 'Published', 'rowcraft' ),
			],
			[
				'value' => 'draft',
				'label' => __( 'Draft', 'rowcraft' ),
			],
			[
				'value' => 'pending',
				'label' => __( 'Pending Review', 'rowcraft' ),
			],
			[
				'value' => 'private',
				'label' => __( 'Private', 'rowcraft' ),
			],
			[
				'value' => 'future',
				'label' => __( 'Scheduled', 'rowcraft' ),
			],
		];

		$include_opts   = \RowCraft\Plugin::instance()->fields()->should_include_options();
		$author_options = $include_opts ? $this->get_author_options() : [];

		$fields = [
			new FieldDefinition(
				id: 'title',
				label: __( 'Title', 'rowcraft' ),
				type: 'text',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 280,
				validation: $is_product ? [ 'required' => true ] : [
					'required'       => true,
					'recommendedMax' => 60,
				],
			),
			new FieldDefinition(
				id: 'featured_image',
				label: __( 'Image', 'rowcraft' ),
				type: 'media',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: true,
				width: 60,
				align: 'center',
				meta_key: '_thumbnail_id',
			),
			new FieldDefinition(
				id: 'id',
				label: __( 'ID', 'rowcraft' ),
				type: 'integer',
				group: 'core',
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
				id: 'slug',
				label: __( 'Slug', 'rowcraft' ),
				type: 'slug',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: ! $is_product,
				width: 190,
				monospace: true,
			),
			new FieldDefinition(
				id: 'status',
				label: __( 'Status', 'rowcraft' ),
				type: 'select',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 124,
				options: $status_options,
			),
			new FieldDefinition(
				id: 'author',
				label: __( 'Author', 'rowcraft' ),
				type: 'select',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: ! $is_product,
				width: 150,
				options: $author_options,
			),
			new FieldDefinition(
				id: 'date',
				label: __( 'Published Date', 'rowcraft' ),
				type: 'datetime',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: ! $is_product,
				width: 162,
				tabular_nums: true,
			),
			new FieldDefinition(
				id: 'modified',
				label: __( 'Last Modified', 'rowcraft' ),
				type: 'datetime',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: false,
				width: 162,
				tabular_nums: true,
			),
			new FieldDefinition(
				id: 'excerpt',
				label: __( 'Excerpt', 'rowcraft' ),
				type: 'textarea',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 240,
			),
			new FieldDefinition(
				id: 'word_count',
				label: __( 'Words', 'rowcraft' ),
				type: 'integer',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: false,
				filterable: false,
				default_visible: 'post' === $entity_slug,
				width: 82,
				align: 'right',
				tabular_nums: true,
			),
			new FieldDefinition(
				id: 'order',
				label: __( 'Menu Order', 'rowcraft' ),
				type: 'integer',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: 'page' === $entity_slug,
				width: 96,
				align: 'right',
				tabular_nums: true,
			),
			new FieldDefinition(
				id: 'comment_status',
				label: __( 'Comments', 'rowcraft' ),
				type: 'select',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: false,
				default_visible: false,
				width: 110,
				options: [
					[
						'value' => 'open',
						'label' => __( 'Open', 'rowcraft' ),
					],
					[
						'value' => 'closed',
						'label' => __( 'Closed', 'rowcraft' ),
					],
				],
			),
		];

		// Dynamically expose registered taxonomies for this post type.
		$taxonomies = get_object_taxonomies( $entity_slug, 'objects' );
		foreach ( $taxonomies as $tax_slug => $tax_obj ) {
			if ( ! $tax_obj->show_ui && ! in_array( $tax_slug, [ 'category', 'post_tag', 'product_cat', 'product_tag' ], true ) ) {
				continue;
			}
			// WooCommerce provider handles product_cat/product_tag cleanly in commerce group.
			if ( $is_product && in_array( $tax_slug, [ 'product_type', 'product_visibility', 'product_shipping_class', 'product_cat', 'product_tag' ], true ) ) {
				continue;
			}

			$options = [];
			if ( $include_opts ) {
				$terms = get_terms(
					[
						'taxonomy'   => $tax_slug,
						'hide_empty' => false,
						'number'     => 250,
					]
				);
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$options[] = [
							'value' => (string) $term->term_id,
							'label' => $term->name,
						];
					}
				}
			}

			$fields[] = new FieldDefinition(
				id: 'tax:' . $tax_slug,
				label: (string) $tax_obj->labels->name,
				type: 'taxonomy',
				group: 'taxonomy',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: in_array( $tax_slug, [ 'category', 'post_tag' ], true ),
				width: 190,
				taxonomy: $tax_slug,
				options: $options,
				extra: [ 'hierarchical' => (bool) $tax_obj->hierarchical ],
			);
		}

		return $fields;
	}

	public function get_required_meta_keys( array $fields ): array {
		$keys = [];
		if ( isset( $fields['featured_image'] ) ) {
			$keys[] = '_thumbnail_id';
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
		if ( empty( $ids ) ) {
			return;
		}

		// Pre-hydrate featured images in 1 single query if visible.
		$thumb_map = [];
		if ( isset( $fields['featured_image'] ) ) {
			$att_ids = [];
			foreach ( $ids as $pid ) {
				$tid = (int) ( $meta_cache[ $pid ]['_thumbnail_id'][0] ?? 0 );
				if ( $tid > 0 ) {
					$att_ids[] = $tid;
				}
			}
			$thumb_map = $this->hydrator->hydrate_thumbnails( $att_ids );
		}

		foreach ( $ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			foreach ( $fields as $fid => $field ) {
				if ( 'taxonomy' === $field->type && '' !== $field->taxonomy ) {
					$preloaded = $meta_cache[ $pid ]['__rc_terms'][0][ $field->taxonomy ] ?? null;
					if ( is_array( $preloaded ) ) {
						$rows[ $pid ][ $fid ] = $preloaded;
					} else {
						$rows[ $pid ][ $fid ] = [];
					}
					continue;
				}

				$rows[ $pid ][ $fid ] = match ( $fid ) {
					'id'             => $pid,
					'featured_image' => $this->resolve_thumb( $pid, $meta_cache, $thumb_map ),
					'title'          => $post->post_title,
					'slug'           => $post->post_name,
					'status'         => $post->post_status,
					'author'         => (string) $post->post_author,
					'date'           => $post->post_date,
					'modified'       => $post->post_modified,
					'excerpt'        => $post->post_excerpt,
					'word_count'     => str_word_count( wp_strip_all_tags( $post->post_content ) ),
					'order'          => (int) $post->menu_order,
					'comment_status' => $post->comment_status,
					default          => null,
				};
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		$post = get_post( $record_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( 'taxonomy' === $field->type && '' !== $field->taxonomy ) {
			$terms = wp_get_object_terms( $record_id, $field->taxonomy, [ 'fields' => 'ids' ] );
			return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
		}

		return match ( $field->id ) {
			'featured_image' => (int) get_post_thumbnail_id( $record_id ),
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'author'         => (string) $post->post_author,
			'date'           => $post->post_date,
			'excerpt'        => $post->post_excerpt,
			'order'          => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			default          => null,
		};
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): true|WP_Error {
		if ( 'taxonomy' === $field->type && '' !== $field->taxonomy ) {
			$term_ids = $this->normalize_term_ids( $value, $field->taxonomy );
			$res      = wp_set_object_terms( $record_id, $term_ids, $field->taxonomy, false );
			return is_wp_error( $res ) ? $res : true;
		}

		if ( 'featured_image' === $field->id ) {
			$att_id = is_array( $value ) ? (int) ( $value['id'] ?? 0 ) : (int) $value;
			if ( $att_id <= 0 ) {
				delete_post_thumbnail( $record_id );
			} else {
				set_post_thumbnail( $record_id, $att_id );
			}
			return true;
		}

		$update = [ 'ID' => $record_id ];

		switch ( $field->id ) {
			case 'title':
				$update['post_title'] = sanitize_text_field( (string) $value );
				break;
			case 'slug':
				$update['post_name'] = sanitize_title( (string) $value );
				break;
			case 'status':
				$allowed = [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ];
				$status  = sanitize_key( (string) $value );
				if ( ! in_array( $status, $allowed, true ) ) {
					return new WP_Error( 'rowcraft_invalid_status', __( 'Invalid post status.', 'rowcraft' ), [ 'status' => 400 ] );
				}
				$update['post_status'] = $status;
				break;
			case 'author':
				$author_id = (int) $value;
				if ( $author_id <= 0 || ! get_userdata( $author_id ) ) {
					return new WP_Error( 'rowcraft_invalid_author', __( 'Invalid author ID.', 'rowcraft' ), [ 'status' => 400 ] );
				}
				$update['post_author'] = $author_id;
				break;
			case 'date':
				$ts = strtotime( (string) $value );
				if ( false === $ts ) {
					return new WP_Error( 'rowcraft_invalid_date', __( 'Invalid date format.', 'rowcraft' ), [ 'status' => 400 ] );
				}
				$formatted               = gmdate( 'Y-m-d H:i:s', $ts );
				$update['post_date']     = $formatted;
				$update['post_date_gmt'] = get_gmt_from_date( $formatted );
				$update['edit_date']     = true;
				break;
			case 'excerpt':
				$update['post_excerpt'] = wp_kses_post( (string) $value );
				break;
			case 'order':
				$update['menu_order'] = (int) $value;
				break;
			case 'comment_status':
				$update['comment_status'] = 'open' === $value ? 'open' : 'closed';
				break;
			default:
				return new WP_Error( 'rowcraft_unsupported_field', __( 'Unsupported core field.', 'rowcraft' ), [ 'status' => 400 ] );
		}

		$result = wp_update_post( $update, true );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @param array<int, array<string, array<int, mixed>>>                               $meta_cache Meta cache.
	 * @param array<int, array{id: int, url: string, alt: string, filename: string}>     $thumb_map  Preloaded thumbnails.
	 * @return array{id: int, url: string, alt: string, filename: string}|null
	 */
	private function resolve_thumb( int $pid, array $meta_cache, array $thumb_map ): ?array {
		$tid = (int) ( $meta_cache[ $pid ]['_thumbnail_id'][0] ?? 0 );
		if ( $tid <= 0 ) {
			return null;
		}
		return $thumb_map[ $tid ] ?? [
			'id'       => $tid,
			'url'      => (string) wp_get_attachment_image_url( $tid, 'thumbnail' ),
			'alt'      => '',
			'filename' => '',
		];
	}

	/**
	 * Normalize incoming term IDs, term objects, or comma-separated names into term IDs.
	 *
	 * @return array<int, int>
	 */
	private function normalize_term_ids( mixed $value, string $taxonomy ): array {
		if ( is_string( $value ) ) {
			$parts = array_filter( array_map( 'trim', explode( ',', $value ) ) );
			$value = $parts;
		}
		if ( ! is_array( $value ) ) {
			return [];
		}

		$term_ids = [];
		foreach ( $value as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$term_ids[] = (int) $item['id'];
			} elseif ( is_numeric( $item ) ) {
				$term_ids[] = (int) $item;
			} elseif ( is_string( $item ) && '' !== $item ) {
				$term = get_term_by( 'name', $item, $taxonomy );
				if ( ! $term ) {
					$term = get_term_by( 'slug', sanitize_title( $item ), $taxonomy );
				}
				if ( $term instanceof \WP_Term ) {
					$term_ids[] = $term->term_id;
				} else {
					$created = wp_insert_term( sanitize_text_field( $item ), $taxonomy );
					if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
						$term_ids[] = (int) $created['term_id'];
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $term_ids ) ) );
	}

	/**
	 * Load authors once per schema build.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private function get_author_options(): array {
		$users   = get_users(
			[
				'fields' => [ 'ID', 'display_name' ],
				'number' => 200,
			]
		);
		$options = [];
		foreach ( $users as $user ) {
			$options[] = [
				'value' => (string) $user->ID,
				'label' => (string) $user->display_name,
			];
		}
		return $options;
	}
}
