<?php
/**
 * Paginated Query Result DTO with performance telemetry.
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueryResult {

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $items        Hydrated row items.
	 * @param int                              $total        Total matching records.
	 * @param int                              $total_pages  Total pages.
	 * @param int                              $page         Current page.
	 * @param int                              $per_page     Page size.
	 * @param int                              $query_count  SQL queries executed during this request.
	 * @param float                            $duration_ms  Execution time in milliseconds.
	 * @param string                           $query_plan   Query execution strategy identifier.
	 */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $total_pages,
		public readonly int $page,
		public readonly int $per_page,
		public readonly int $query_count = 0,
		public readonly float $duration_ms = 0.0,
		public readonly string $query_plan = 'standard',
	) {}

	/**
	 * Convert result to REST array payload.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'items'      => $this->items,
			'total'      => $this->total,
			'totalPages' => $this->total_pages,
			'page'       => $this->page,
			'perPage'    => $this->per_page,
			'perf'       => [
				'queries'    => $this->query_count,
				'durationMs' => round( $this->duration_ms, 2 ),
				'plan'       => $this->query_plan,
			],
		];
	}
}
