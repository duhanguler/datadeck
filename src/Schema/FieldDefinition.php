<?php
/**
 * Immutable Field Definition value object shared between PHP engine and React DataViews UI.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldDefinition {

	/**
	 * Constructor.
	 *
	 * @param string                                      $id              Unique field identifier.
	 * @param string                                      $label           Human-readable column header.
	 * @param string                                      $type            Field type (text, textarea, number, currency, integer, boolean, select, multiselect, date, datetime, media, taxonomy, url, email, slug, readonly).
	 * @param string                                      $group           Grouping category (core, meta, acf, woocommerce, seo, taxonomy, media).
	 * @param string                                      $provider        Owning FieldProviderInterface ID.
	 * @param bool                                        $editable        Whether inline/bulk editing is allowed.
	 * @param bool                                        $sortable        Whether SQL/lookup sorting is supported.
	 * @param bool                                        $filterable      Whether filter clauses are supported.
	 * @param bool                                        $default_visible Visible in the default column preset.
	 * @param int                                         $width           Initial pixel width.
	 * @param string                                      $align           Text alignment ('left'|'right'|'center').
	 * @param bool                                        $monospace       Use monospace font (SKU, slug, meta key).
	 * @param bool                                        $tabular_nums    Enforce tabular-nums CSS alignment.
	 * @param string                                      $meta_key        Backing meta key (empty if native/CRUD).
	 * @param string                                      $taxonomy        Backing taxonomy slug (if type === 'taxonomy').
	 * @param array<int, array{value: string, label: string}> $options     Select/enum choices.
	 * @param array<string, mixed>                        $validation      Validation hints (min, max, maxLength, recommendedMax, pattern).
	 * @param array<string, mixed>                        $extra           Provider-specific metadata (e.g. acf_key, currency_symbol).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $type,
		public readonly string $group,
		public readonly string $provider,
		public readonly bool $editable = true,
		public readonly bool $sortable = true,
		public readonly bool $filterable = true,
		public readonly bool $default_visible = false,
		public readonly int $width = 160,
		public readonly string $align = 'left',
		public readonly bool $monospace = false,
		public readonly bool $tabular_nums = false,
		public readonly string $meta_key = '',
		public readonly string $taxonomy = '',
		public readonly array $options = [],
		public readonly array $validation = [],
		public readonly array $extra = [],
	) {}

	/**
	 * Serialize field definition for REST schema responses.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'             => $this->id,
			'label'          => $this->label,
			'type'           => $this->type,
			'group'          => $this->group,
			'provider'       => $this->provider,
			'editable'       => $this->editable,
			'sortable'       => $this->sortable,
			'filterable'     => $this->filterable,
			'defaultVisible' => $this->default_visible,
			'width'          => $this->width,
			'align'          => $this->align,
			'monospace'      => $this->monospace,
			'tabularNums'    => $this->tabular_nums,
			'metaKey'        => $this->meta_key,
			'taxonomy'       => $this->taxonomy,
			'options'        => $this->options,
			'validation'     => $this->validation,
			'extra'          => $this->extra,
		];
	}
}
