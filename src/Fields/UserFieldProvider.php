<?php
/**
 * WordPress User Field Provider.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Fields;

use DataDeck\Contracts\FieldProviderInterface;
use DataDeck\Schema\FieldDefinition;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UserFieldProvider implements FieldProviderInterface {

	public function get_id(): string {
		return 'user';
	}

	public function supports( string $entity_slug ): bool {
		return 'user' === $entity_slug;
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		$role_options = [];
		foreach ( wp_roles()->get_names() as $slug => $label ) {
			$role_options[] = [
				'value' => (string) $slug,
				'label' => translate_user_role( (string) $label ),
			];
		}

		return [
			new FieldDefinition(
				id: 'id',
				label: __( 'ID', 'datadeck' ),
				type: 'integer',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 72,
				align: 'right',
				monospace: true,
				tabular_nums: true,
			),
			new FieldDefinition(
				id: 'user_login',
				label: __( 'Username', 'datadeck' ),
				type: 'text',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 150,
				monospace: true,
			),
			new FieldDefinition(
				id: 'display_name',
				label: __( 'Display Name', 'datadeck' ),
				type: 'text',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 190,
			),
			new FieldDefinition(
				id: 'user_email',
				label: __( 'Email', 'datadeck' ),
				type: 'email',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 220,
			),
			new FieldDefinition(
				id: 'role',
				label: __( 'Role', 'datadeck' ),
				type: 'select',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: true,
				width: 145,
				options: $role_options,
			),
			new FieldDefinition(
				id: 'first_name',
				label: __( 'First Name', 'datadeck' ),
				type: 'text',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 140,
				meta_key: 'first_name',
			),
			new FieldDefinition(
				id: 'last_name',
				label: __( 'Last Name', 'datadeck' ),
				type: 'text',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 140,
				meta_key: 'last_name',
			),
			new FieldDefinition(
				id: 'user_url',
				label: __( 'Website', 'datadeck' ),
				type: 'url',
				group: 'core',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: false,
				width: 200,
				monospace: true,
			),
			new FieldDefinition(
				id: 'registered',
				label: __( 'Registered', 'datadeck' ),
				type: 'datetime',
				group: 'core',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: false,
				default_visible: true,
				width: 162,
				tabular_nums: true,
			),
		];
	}

	public function get_required_meta_keys( array $fields ): array {
		global $wpdb;
		$keys = [];
		if ( isset( $fields['role'] ) ) {
			$keys[] = $wpdb->get_blog_prefix() . 'capabilities';
		}
		foreach ( [ 'first_name', 'last_name' ] as $mk ) {
			if ( isset( $fields[ $mk ] ) ) {
				$keys[] = $mk;
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
		global $wpdb;
		$cap_key = $wpdb->get_blog_prefix() . 'capabilities';

		foreach ( $ids as $uid ) {
			$user = get_userdata( $uid );
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			foreach ( $fields as $fid => $field ) {
				if ( 'role' === $fid ) {
					$caps = $meta_cache[ $uid ][ $cap_key ][0] ?? $user->caps;
					$role = '';
					if ( is_array( $caps ) ) {
						foreach ( $caps as $r => $enabled ) {
							if ( $enabled ) {
								$role = (string) $r;
								break;
							}
						}
					}
					$rows[ $uid ][ $fid ] = $role;
					continue;
				}

				$rows[ $uid ][ $fid ] = match ( $fid ) {
					'id'           => $uid,
					'user_login'   => $user->user_login,
					'display_name' => $user->display_name,
					'user_email'   => $user->user_email,
					'first_name'   => (string) ( $meta_cache[ $uid ]['first_name'][0] ?? '' ),
					'last_name'    => (string) ( $meta_cache[ $uid ]['last_name'][0] ?? '' ),
					'user_url'     => $user->user_url,
					'registered'   => $user->user_registered,
					default        => null,
				};
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		$user = get_userdata( $record_id );
		if ( ! $user instanceof WP_User ) {
			return null;
		}
		return match ( $field->id ) {
			'display_name' => $user->display_name,
			'user_email'   => $user->user_email,
			'role'         => (string) ( $user->roles[0] ?? '' ),
			'first_name'   => (string) get_user_meta( $record_id, 'first_name', true ),
			'last_name'    => (string) get_user_meta( $record_id, 'last_name', true ),
			'user_url'     => $user->user_url,
			default        => null,
		};
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): true|WP_Error {
		if ( 'role' === $field->id ) {
			if ( ! current_user_can( 'promote_users' ) || get_current_user_id() === $record_id ) {
				return new WP_Error( 'datadeck_role_forbidden', __( 'Cannot change this user role.', 'datadeck' ), [ 'status' => 403 ] );
			}
			$role = sanitize_key( (string) $value );
			if ( ! wp_roles()->is_role( $role ) ) {
				return new WP_Error( 'datadeck_invalid_role', __( 'Invalid role.', 'datadeck' ), [ 'status' => 400 ] );
			}
			$user = get_userdata( $record_id );
			if ( ! $user instanceof WP_User ) {
				return new WP_Error( 'datadeck_user_missing', __( 'User not found.', 'datadeck' ), [ 'status' => 404 ] );
			}
			$user->set_role( $role );
			return true;
		}

		if ( 'first_name' === $field->id || 'last_name' === $field->id ) {
			update_user_meta( $record_id, $field->id, sanitize_text_field( (string) $value ) );
			return true;
		}

		$update = [ 'ID' => $record_id ];
		switch ( $field->id ) {
			case 'display_name':
				$update['display_name'] = sanitize_text_field( (string) $value );
				break;
			case 'user_email':
				$email = sanitize_email( (string) $value );
				if ( ! is_email( $email ) ) {
					return new WP_Error( 'datadeck_invalid_email', __( 'Invalid email address.', 'datadeck' ), [ 'status' => 400 ] );
				}
				$update['user_email'] = $email;
				break;
			case 'user_url':
				$update['user_url'] = esc_url_raw( (string) $value );
				break;
			default:
				return new WP_Error( 'datadeck_unsupported_user_field', __( 'Unsupported user field.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$res = wp_update_user( $update );
		return is_wp_error( $res ) ? $res : true;
	}
}
