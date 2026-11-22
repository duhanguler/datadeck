<?php
/**
 * Lightweight PHPStan stubs for optional third-party integrations (WooCommerce, ACF, Meta Box, Action Scheduler, WP-CLI).
 *
 * @package DataDeck
 */

// phpcs:ignoreFile

if ( ! defined( 'DATADECK_VERSION' ) ) {
	define( 'DATADECK_VERSION', '1.1.0' );
}
if ( ! defined( 'DATADECK_FILE' ) ) {
	define( 'DATADECK_FILE', __FILE__ );
}
if ( ! defined( 'DATADECK_DIR' ) ) {
	define( 'DATADECK_DIR', __DIR__ . '/' );
}
if ( ! defined( 'DATADECK_URL' ) ) {
	define( 'DATADECK_URL', 'https://example.org/' );
}
if ( ! defined( 'DATADECK_DB_VERSION' ) ) {
	define( 'DATADECK_DB_VERSION', '1.0.0' );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function add_command( string $name, mixed $callable ): void {}
		public static function log( string $message ): void {}
		public static function success( string $message ): void {}
		public static function error( string $message ): void {}
	}
}

if ( ! class_exists( 'WC_Data_Exception' ) ) {
	class WC_Data_Exception extends \Exception {}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * @param mixed $the_product
	 * @return \WC_Product|false|null
	 */
	function wc_get_product( $the_product = false ) {}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param mixed $the_order
	 * @return \WC_Order|false
	 */
	function wc_get_order( $the_order = false ) {}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return array<int, mixed>|object
	 */
	function wc_get_orders( array $args ) {}
}

if ( ! function_exists( 'wc_get_order_statuses' ) ) {
	/**
	 * @return array<string, string>
	 */
	function wc_get_order_statuses(): array { return []; }
}

if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
	function get_woocommerce_currency_symbol( string $currency = '' ): string { return '$'; }
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		public function get_id(): int { return 0; }
		public function get_type(): string { return 'simple'; }
		public function is_type( string $type ): bool { return false; }
		public function get_sku( string $context = 'view' ): string { return ''; }
		public function get_regular_price( string $context = 'view' ): string { return ''; }
		public function get_sale_price( string $context = 'view' ): string { return ''; }
		public function get_stock_status( string $context = 'view' ): string { return 'instock'; }
		public function get_stock_quantity( string $context = 'view' ): ?int { return null; }
		public function get_manage_stock( string $context = 'view' ): bool { return false; }
		public function get_weight( string $context = 'view' ): string { return ''; }
		public function get_backorders( string $context = 'view' ): string { return 'no'; }
		public function get_tax_status( string $context = 'view' ): string { return 'taxable'; }
		public function set_sku( string $sku ): void {}
		public function set_regular_price( string $price ): void {}
		public function set_sale_price( string $price ): void {}
		public function set_stock_status( string $status = 'instock' ): void {}
		public function set_manage_stock( bool $manage_stock ): void {}
		public function set_stock_quantity( ?int $quantity ): void {}
		public function set_weight( string $weight ): void {}
		public function set_backorders( string $backorders ): void {}
		public function set_tax_status( string $status ): void {}
		public function save(): int { return 0; }
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public function get_id(): int { return 0; }
		public function get_order_number(): string { return ''; }
		public function get_status( string $context = 'view' ): string { return ''; }
		public function get_total( string $context = 'view' ): string { return '0'; }
		public function get_formatted_billing_full_name(): string { return ''; }
		public function get_billing_email( string $context = 'view' ): string { return ''; }
		public function get_payment_method( string $context = 'view' ): string { return ''; }
		public function get_payment_method_title( string $context = 'view' ): string { return ''; }
		public function get_item_count(): int { return 0; }
		public function get_customer_note( string $context = 'view' ): string { return ''; }
		public function get_date_created( string $context = 'view' ): mixed { return null; }
		public function get_edit_order_url(): string { return ''; }
		public function set_status( string $new_status, string $note = '', bool $manual_update = false ): void {}
		public function update_status( string $new_status, string $note = '', bool $manual = false ): bool { return true; }
		public function set_customer_note( string $note ): void {}
		public function set_billing_email( string $email ): void {}
		public function save(): int { return 0; }
		public function delete( bool $force_delete = false ): bool { return true; }
	}
}
