<?php
/**
 * Scheduled & Auto-Reverting Bulk Campaign Engine (Action Scheduler + WP-Cron fallback).
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Bulk;

use DataDeck\History\HistoryService;
use DataDeck\History\Schema;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CampaignScheduler {

	public const HOOK_APPLY  = 'datadeck_run_scheduled_campaign';
	public const HOOK_REVERT = 'datadeck_revert_scheduled_campaign';

	public function __construct(
		private readonly BulkOperationEngine $bulk,
		private readonly HistoryService $history,
	) {}

	/**
	 * Register cron / Action Scheduler callbacks.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_APPLY, [ $this, 'run_campaign' ], 10, 1 );
		add_action( self::HOOK_REVERT, [ $this, 'revert_campaign' ], 10, 1 );
		add_action( 'datadeck_process_schedules', [ $this, 'poll_due_campaigns' ] );
	}

	/**
	 * Schedule a future bulk edit campaign with optional automatic revert timestamp.
	 *
	 * @param string               $entity_slug  Entity slug.
	 * @param string               $name         Campaign title.
	 * @param array<string, mixed> $rule         Bulk rule definition.
	 * @param array<int, int>      $target_ids   Selected IDs (empty = query_params).
	 * @param array<string, mixed> $query_params Filter parameters.
	 * @param string               $scheduled_at ISO/UTC start datetime.
	 * @param string|null          $revert_at    Optional ISO/UTC auto-revert datetime.
	 * @return array<string, mixed>|WP_Error
	 */
	public function schedule(
		string $entity_slug,
		string $name,
		array $rule,
		array $target_ids,
		array $query_params,
		string $scheduled_at,
		?string $revert_at = null
	): array|WP_Error {
		$start_ts = strtotime( $scheduled_at );
		if ( false === $start_ts ) {
			return new WP_Error( 'datadeck_invalid_schedule', __( 'Invalid start date/time.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$revert_ts = null;
		if ( null !== $revert_at && '' !== $revert_at ) {
			$parsed = strtotime( $revert_at );
			if ( false === $parsed || $parsed <= $start_ts ) {
				return new WP_Error( 'datadeck_invalid_revert', __( 'Auto-revert time must be after start time.', 'datadeck' ), [ 'status' => 400 ] );
			}
			$revert_ts = $parsed;
		}

		$payload = [
			'rule'         => $rule,
			'target_ids'   => array_map( 'intval', $target_ids ),
			'query_params' => $query_params,
		];

		$batch = $this->history->open_batch(
			$entity_slug,
			'scheduled',
			'' !== $name ? $name : sprintf( 'Scheduled %s campaign', $entity_slug ),
			null,
			'scheduled',
			$payload,
			gmdate( 'Y-m-d H:i:s', $start_ts ),
			$revert_ts ? gmdate( 'Y-m-d H:i:s', $revert_ts ) : null
		);

		$this->enqueue_event( $start_ts, self::HOOK_APPLY, [ $batch['uuid'] ] );
		if ( $revert_ts ) {
			$this->enqueue_event( $revert_ts, self::HOOK_REVERT, [ $batch['uuid'] ] );
		}

		return [
			'uuid'        => $batch['uuid'],
			'status'      => 'scheduled',
			'scheduledAt' => gmdate( 'Y-m-d H:i:s', $start_ts ),
			'revertAt'    => $revert_ts ? gmdate( 'Y-m-d H:i:s', $revert_ts ) : null,
		];
	}

	/**
	 * Cancel a pending scheduled campaign.
	 */
	public function cancel( string $batch_uuid ): bool|WP_Error {
		global $wpdb;
		$table = Schema::batches_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			[ 'status' => 'cancelled' ],
			[
				'batch_uuid' => $batch_uuid,
				'status'     => 'scheduled',
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);

		if ( ! $updated ) {
			return new WP_Error( 'datadeck_cancel_failed', __( 'Scheduled campaign not found or already executed.', 'datadeck' ), [ 'status' => 404 ] );
		}

		wp_clear_scheduled_hook( self::HOOK_APPLY, [ $batch_uuid ] );
		wp_clear_scheduled_hook( self::HOOK_REVERT, [ $batch_uuid ] );

		return true;
	}

	/**
	 * Execute a scheduled campaign in safe chunks.
	 */
	public function run_campaign( string $batch_uuid ): void {
		global $wpdb;
		$table = Schema::batches_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$batch = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE batch_uuid = %s AND status = 'scheduled'", $table, $batch_uuid )
		);

		if ( ! $batch ) {
			return;
		}

		$payload = json_decode( (string) $batch->schedule_payload, true );
		if ( ! is_array( $payload ) || empty( $payload['rule'] ) ) {
			return;
		}

		$after_id = 0;
		while ( true ) {
			$res = $this->bulk->execute_chunk(
				(string) $batch->entity_type,
				(array) $payload['rule'],
				(array) ( $payload['target_ids'] ?? [] ),
				(array) ( $payload['query_params'] ?? [] ),
				$after_id,
				100,
				$batch_uuid,
				true,
				$batch->revert_at
			);

			if ( is_wp_error( $res ) || empty( $res['hasMore'] ) ) {
				break;
			}
			$after_id = (int) $res['lastId'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			[ 'status' => 'applied' ],
			[ 'batch_uuid' => $batch_uuid ],
			[ '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Automatically revert a campaign at its revert_at timestamp.
	 */
	public function revert_campaign( string $batch_uuid ): void {
		$this->history->rollback( $batch_uuid, true );
	}

	/**
	 * Safety-net hourly sweep for any due start or auto-revert campaigns.
	 */
	public function poll_due_campaigns(): void {
		global $wpdb;
		$table = Schema::batches_table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$due_start = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT batch_uuid FROM %i WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= %s",
				$table,
				$now
			)
		);
		if ( is_array( $due_start ) ) {
			foreach ( $due_start as $uuid ) {
				$this->run_campaign( (string) $uuid );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$due_revert = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT batch_uuid FROM %i WHERE status = 'applied' AND revert_at IS NOT NULL AND revert_at <= %s",
				$table,
				$now
			)
		);
		if ( is_array( $due_revert ) ) {
			foreach ( $due_revert as $uuid ) {
				$this->revert_campaign( (string) $uuid );
			}
		}
	}

	/**
	 * Prefer WooCommerce Action Scheduler when installed, fallback to WP-Cron.
	 *
	 * @param int               $timestamp UTC Unix timestamp.
	 * @param string            $hook      Action hook name.
	 * @param array<int, mixed> $args      Hook arguments.
	 */
	private function enqueue_event( int $timestamp, string $hook, array $args ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, $hook, $args, 'datadeck' );
			return;
		}
		wp_schedule_single_event( $timestamp, $hook, $args );
	}
}
