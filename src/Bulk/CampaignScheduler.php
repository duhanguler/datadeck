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
	 * Enqueue an auto-revert event for an already-applied bulk batch.
	 */
	public function schedule_revert( string $batch_uuid, int $revert_ts ): void {
		$this->enqueue_event( $revert_ts, self::HOOK_REVERT, [ $batch_uuid ] );
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
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_APPLY, [ $batch_uuid ], 'datadeck' );
			as_unschedule_all_actions( self::HOOK_REVERT, [ $batch_uuid ], 'datadeck' );
		}

		return true;
	}

	/**
	 * Execute a scheduled campaign in safe chunks under the scheduling user's capability context.
	 */
	public function run_campaign( string $batch_uuid ): void {
		global $wpdb;
		$table = Schema::batches_table();

		// Atomically claim the scheduled campaign so overlapping cron / Action Scheduler workers cannot run it twice.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->update(
			$table,
			[ 'status' => 'running' ],
			[
				'batch_uuid' => $batch_uuid,
				'status'     => 'scheduled',
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);

		if ( 1 !== $claimed ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$batch = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE batch_uuid = %s', $table, $batch_uuid )
		);

		if ( ! $batch ) {
			return;
		}

		$payload = json_decode( (string) $batch->schedule_payload, true );
		if ( ! is_array( $payload ) || empty( $payload['rule'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, [ 'status' => 'failed' ], [ 'batch_uuid' => $batch_uuid ], [ '%s' ], [ '%s' ] );
			return;
		}

		$prev_user_id  = get_current_user_id();
		$owner_user_id = (int) $batch->user_id;
		$is_system     = 0 === $owner_user_id;
		$has_owner     = $owner_user_id > 0 && (bool) get_userdata( $owner_user_id );

		// Never bypass capability checks when a human scheduling user was deleted after scheduling.
		if ( ! $is_system && ! $has_owner ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, [ 'status' => 'failed' ], [ 'batch_uuid' => $batch_uuid ], [ '%s' ], [ '%s' ] );
			return;
		}

		if ( $has_owner ) {
			wp_set_current_user( $owner_user_id );
		}

		$final_status = 'applied';
		try {
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
					$is_system,
					$batch->revert_at
				);

				if ( is_wp_error( $res ) ) {
					$final_status = 'failed';
					break;
				}
				if ( empty( $res['hasMore'] ) ) {
					break;
				}
				$after_id = (int) $res['lastId'];
			}
		} finally {
			if ( $has_owner ) {
				wp_set_current_user( $prev_user_id );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				[ 'status' => $final_status ],
				[ 'batch_uuid' => $batch_uuid ],
				[ '%s' ],
				[ '%s' ]
			);
		}
	}

	/**
	 * Automatically revert an applied campaign at its revert_at timestamp.
	 */
	public function revert_campaign( string $batch_uuid ): void {
		global $wpdb;
		$table = Schema::batches_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT status FROM %i WHERE batch_uuid = %s', $table, $batch_uuid )
		);
		if ( 'applied' !== $status ) {
			return;
		}

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
