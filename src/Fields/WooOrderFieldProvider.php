<?php
/**
 * WooCommerce HPOS-Native Order Field Provider.
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft\Fields;

use RowCraft\Contracts\FieldProviderInterface;
use RowCraft\Schema\FieldDefinition;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooOrderFieldProvider implements FieldProviderInterface {

	public function get_id(): string {
		return 'woo_order';
	}

	public function supports( string $entity_slug ): bool {
		return 'shop_order' === $entity_slug && class_exists( 'WooCommerce' );
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public function get_fields( string $entity_slug ): array {
		if ( ! $this->supports( $entity_slug ) ) {
			return [];
		}

		$status_options = [];
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $slug => $label ) {
				$status_options[] = [
					'value' => str_replace( 'wc-', '', (string) $slug ),
					'label' => (string) $label,
				];
			}
		}

		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '$';

		return [
			new FieldDefinition(
				id: 'id',
				label: __( 'Order #', 'rowcraft' ),
				type: 'integer',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 90,
				align: 'right',
				monospace: true,
				tabular_nums: true,
			),
			new FieldDefinition(
				id: 'customer_name',
				label: __( 'Customer', 'rowcraft' ),
				type: 'text',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: false,
				sortable: false,
				filterable: true,
				default_visible: true,
				width: 190,
			),
			new FieldDefinition(
				id: 'billing_email',
				label: __( 'Billing Email', 'rowcraft' ),
				type: 'email',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: true,
				default_visible: true,
				width: 210,
			),
			new FieldDefinition(
				id: 'status',
				label: __( 'Order Status', 'rowcraft' ),
				type: 'select',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 145,
				options: $status_options,
			),
			new FieldDefinition(
				id: 'total',
				label: __( 'Total', 'rowcraft' ),
				type: 'currency',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 112,
				align: 'right',
				tabular_nums: true,
				extra: [ 'currency' => $currency ],
			),
			new FieldDefinition(
				id: 'payment_method',
				label: __( 'Payment', 'rowcraft' ),
				type: 'text',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: false,
				sortable: false,
				filterable: false,
				default_visible: true,
				width: 140,
			),
			new FieldDefinition(
				id: 'customer_note',
				label: __( 'Customer Note', 'rowcraft' ),
				type: 'textarea',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: true,
				sortable: false,
				filterable: false,
				default_visible: true,
				width: 230,
			),
			new FieldDefinition(
				id: 'date',
				label: __( 'Created', 'rowcraft' ),
				type: 'datetime',
				group: 'woocommerce',
				provider: $this->get_id(),
				editable: false,
				sortable: true,
				filterable: true,
				default_visible: true,
				width: 162,
				tabular_nums: true,
			),
		];
	}

	public function get_required_meta_keys( array $fields ): array {
		return [];
	}

	public function hydrate(
		string $entity_slug,
		array $ids,
		array $fields,
		array &$rows,
		array $meta_cache
	): void {
		if ( ! function_exists( 'wc_get_orders' ) || empty( $ids ) ) {
			return;
		}

		$orders = wc_get_orders(
			[
				'post__in' => $ids,
				'include'  => $ids,
				'limit'    => count( $ids ),
			]
		);

		if ( ! is_array( $orders ) ) {
			return;
		}

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$oid = $order->get_id();
			foreach ( $fields as $fid => $field ) {
				$created              = $order->get_date_created();
				$billing_name         = trim( $order->get_formatted_billing_full_name() );
				$payment_title        = $order->get_payment_method_title();
				$rows[ $oid ][ $fid ] = match ( $fid ) {
					'id'             => $oid,
					'customer_name'  => '' !== $billing_name ? $billing_name : __( 'Guest', 'rowcraft' ),
					'billing_email'  => $order->get_billing_email(),
					'status'         => $order->get_status(),
					'total'          => (float) $order->get_total(),
					'payment_method' => '' !== $payment_title ? $payment_title : $order->get_payment_method(),
					'customer_note'  => $order->get_customer_note(),
					'date'           => $created ? $created->date( 'Y-m-d H:i:s' ) : '',
					default          => null,
				};
			}
		}
	}

	public function read_value( string $entity_slug, int $record_id, FieldDefinition $field ): mixed {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $record_id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		return match ( $field->id ) {
			'status'        => $order->get_status(),
			'billing_email' => $order->get_billing_email(),
			'customer_note' => $order->get_customer_note(),
			default         => null,
		};
	}

	public function write_value( string $entity_slug, int $record_id, FieldDefinition $field, mixed $value ): true|WP_Error {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'rowcraft_woo_inactive', __( 'WooCommerce is not active.', 'rowcraft' ), [ 'status' => 400 ] );
		}
		$order = wc_get_order( $record_id );
		if ( ! $order instanceof \WC_Order ) {
			return new WP_Error( 'rowcraft_order_missing', __( 'Order not found.', 'rowcraft' ), [ 'status' => 404 ] );
		}

		switch ( $field->id ) {
			case 'status':
				$order->update_status( sanitize_key( (string) $value ), __( 'Updated via RowCraft.', 'rowcraft' ), true );
				return true;
			case 'billing_email':
				$email = sanitize_email( (string) $value );
				if ( '' !== $email && ! is_email( $email ) ) {
					return new WP_Error( 'rowcraft_invalid_email', __( 'Invalid billing email.', 'rowcraft' ), [ 'status' => 400 ] );
				}
				$order->set_billing_email( $email );
				$order->save();
				return true;
			case 'customer_note':
				$order->set_customer_note( sanitize_textarea_field( (string) $value ) );
				$order->save();
				return true;
			default:
				return new WP_Error( 'rowcraft_unsupported_order_field', __( 'Unsupported order field.', 'rowcraft' ), [ 'status' => 400 ] );
		}
	}
}
