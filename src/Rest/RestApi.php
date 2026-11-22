<?php
/**
 * REST API Controller for DataDeck (/wp-json/datadeck/v1/).
 *
 * @package DataDeck
 */

declare(strict_types=1);

namespace DataDeck\Rest;

use DataDeck\Bulk\BulkOperationEngine;
use DataDeck\Bulk\CampaignScheduler;
use DataDeck\Entities\EntityRegistry;
use DataDeck\Fields\ComputedFieldProvider;
use DataDeck\Fields\FieldRegistry;
use DataDeck\Formula\ExpressionEngine;
use DataDeck\History\HistoryService;
use DataDeck\Import\CsvImportEngine;
use DataDeck\Lenses\LensRegistry;
use DataDeck\Query\KeysetStreamExporter;
use DataDeck\Query\QueryRequest;
use DataDeck\Sync\ViewSyncService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RestApi {

	public const NAMESPACE = 'datadeck/v1';

	public function __construct(
		private readonly EntityRegistry $entities,
		private readonly FieldRegistry $fields,
		private readonly BulkOperationEngine $bulk,
		private readonly CampaignScheduler $scheduler,
		private readonly HistoryService $history,
		private readonly LensRegistry $lenses,
		private readonly ViewSyncService $views,
		private readonly KeysetStreamExporter $exporter,
		private readonly ExpressionEngine $formula,
		private readonly ComputedFieldProvider $computed_fields,
		private readonly CsvImportEngine $importer,
	) {}

	/**
	 * Register all REST routes on rest_api_init.
	 */
	public function register_routes(): void {
		// Entities overview.
		register_rest_route(
			self::NAMESPACE,
			'/entities',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_entities' ],
				'permission_callback' => [ $this, 'can_access_admin' ],
			]
		);

		// Entity schema, lenses, saved views & JSON sync status.
		register_rest_route(
			self::NAMESPACE,
			'/schema/(?P<entity>[a-z0-9_\-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_schema' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		// Paginated records with field projection.
		register_rest_route(
			self::NAMESPACE,
			'/records/(?P<entity>[a-z0-9_\-]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_records' ],
					'permission_callback' => [ $this, 'can_read_entity' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_record' ],
					'permission_callback' => [ $this, 'can_read_entity' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_records' ],
					'permission_callback' => [ $this, 'can_read_entity' ],
				],
			]
		);

		// Duplicate a single record.
		register_rest_route(
			self::NAMESPACE,
			'/records/(?P<entity>[a-z0-9_\-]+)/(?P<id>\d+)/duplicate',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'duplicate_record' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		// Batch inline edits, Excel paste, bulk dry-run, and chunked bulk apply.
		register_rest_route(
			self::NAMESPACE,
			'/batch/(?P<entity>[a-z0-9_\-]+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_batch' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		// Formula syntax validator.
		register_rest_route(
			self::NAMESPACE,
			'/formula/validate',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'validate_formula' ],
				'permission_callback' => [ $this, 'can_access_admin' ],
			]
		);

		// History & one-click rollback.
		register_rest_route(
			self::NAMESPACE,
			'/history',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_history' ],
				'permission_callback' => [ $this, 'can_access_admin' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/history/(?P<uuid>[a-zA-Z0-9\-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_history_detail' ],
				'permission_callback' => [ $this, 'can_access_admin' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/history/(?P<uuid>[a-zA-Z0-9\-]+)/rollback',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rollback_batch' ],
				'permission_callback' => [ $this, 'can_access_admin' ],
			]
		);

		// Scheduled & auto-reverting campaigns.
		register_rest_route(
			self::NAMESPACE,
			'/schedules/(?P<entity>[a-z0-9_\-]+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_schedule' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/schedules/(?P<uuid>[a-zA-Z0-9\-]+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'cancel_schedule' ],
				'permission_callback' => [ $this, 'can_access_admin' ],
			]
		);

		// Saved views & datadeck-json sync.
		register_rest_route(
			self::NAMESPACE,
			'/views/(?P<entity>[a-z0-9_\-]+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_view' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/views/(?P<id>[a-z0-9_\-]+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_view' ],
				'permission_callback' => [ $this, 'can_access_admin' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/views/sync-json',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'sync_views_from_json' ],
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
			]
		);

		// User UI preferences (default view toggle, density, save mode).
		register_rest_route(
			self::NAMESPACE,
			'/preferences',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_preferences' ],
				'permission_callback' => [ $this, 'can_access_admin' ],
			]
		);

		// O(1) Memory Streaming CSV / JSON export.
		register_rest_route(
			self::NAMESPACE,
			'/export/(?P<entity>[a-z0-9_\-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export_records' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		// Cell-level record history & single-cell rollback.
		register_rest_route(
			self::NAMESPACE,
			'/history/record/(?P<entity>[a-z0-9_\-]+)/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_record_history' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/history/change/(?P<change_id>\d+)/rollback',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rollback_change' ],
				'permission_callback' => [ $this, 'can_access_admin' ],
			]
		);

		// Custom virtual AST computed columns.
		register_rest_route(
			self::NAMESPACE,
			'/computed-fields/(?P<entity>[a-z0-9_\-]+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_computed_field' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/computed-fields/(?P<entity>[a-z0-9_\-]+)/(?P<field_id>[a-z0-9_\-]+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_computed_field' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		// Smart CSV / JSON Import (analyze, dry-run diff, chunked apply).
		register_rest_route(
			self::NAMESPACE,
			'/import/(?P<entity>[a-z0-9_\-]+)/analyze',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_analyze' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/(?P<entity>[a-z0-9_\-]+)/dry-run',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_dry_run' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/(?P<entity>[a-z0-9_\-]+)/apply',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_apply' ],
				'permission_callback' => [ $this, 'can_read_entity' ],
			]
		);
	}

	public function can_access_admin(): bool {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function can_read_entity( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$entity  = sanitize_key( (string) $request->get_param( 'entity' ) );
		$adapter = $this->entities->get( $entity );
		return $adapter ? $adapter->can_read() : false;
	}

	public function get_entities(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'entities' => $this->entities->list_readable(),
			],
			200
		);
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function get_schema( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity  = sanitize_key( (string) $request->get_param( 'entity' ) );
		$adapter = $this->entities->get( $entity );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_not_found', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$field_map = $this->fields->get_fields_for_entity( $entity );
		$fields    = array_map( static fn( $f ): array => $f->to_array(), array_values( $field_map ) );

		return new WP_REST_Response(
			[
				'entity'        => [
					'slug'        => $adapter->get_slug(),
					'label'       => $adapter->get_label(),
					'pluralLabel' => $adapter->get_plural_label(),
					'group'       => $adapter->get_group(),
					'canCreate'   => $adapter->can_create(),
				],
				'fields'        => $fields,
				'defaultFields' => $this->fields->get_default_field_ids( $entity ),
				'lenses'        => $this->lenses->get_lenses_for_entity( $entity ),
				'views'         => $this->views->get_views( $entity ),
				'jsonSync'      => $this->views->get_json_sync_status(),
			],
			200
		);
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function get_records( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity  = sanitize_key( (string) $request->get_param( 'entity' ) );
		$adapter = $this->entities->get( $entity );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_not_found', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$query_req = QueryRequest::from_array( $entity, $request->get_params() );
		$result    = $adapter->query( $query_req );

		return new WP_REST_Response( $result->to_array(), 200 );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function create_record( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity  = sanitize_key( (string) $request->get_param( 'entity' ) );
		$adapter = $this->entities->get( $entity );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_not_found', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$created = $adapter->create_record( $data );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$projected = $this->fields->get_default_field_ids( $entity );
		$hydrated  = $adapter->hydrate_records( [ $created ], $projected );

		return new WP_REST_Response(
			[
				'id'     => $created,
				'record' => $hydrated[0] ?? [ 'id' => $created ],
			],
			201
		);
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function duplicate_record( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity  = sanitize_key( (string) $request->get_param( 'entity' ) );
		$id      = (int) $request->get_param( 'id' );
		$adapter = $this->entities->get( $entity );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_not_found', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$new_id = $adapter->duplicate_record( $id );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$projected = $this->fields->get_default_field_ids( $entity );
		$hydrated  = $adapter->hydrate_records( [ $new_id ], $projected );

		return new WP_REST_Response(
			[
				'id'     => $new_id,
				'record' => $hydrated[0] ?? [ 'id' => $new_id ],
			],
			201
		);
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function delete_records( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity  = sanitize_key( (string) $request->get_param( 'entity' ) );
		$adapter = $this->entities->get( $entity );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_not_found', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$body = $request->get_json_params();
		$ids  = is_array( $body ) && is_array( $body['ids'] ?? null ) ? array_map( 'intval', $body['ids'] ) : [];

		return new WP_REST_Response( $adapter->delete_records( $ids ), 200 );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function handle_batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity = sanitize_key( (string) $request->get_param( 'entity' ) );
		$body   = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'datadeck_bad_payload', __( 'Invalid JSON body.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$mode = sanitize_key( (string) ( $body['mode'] ?? 'inline' ) );

		if ( 'inline' === $mode || 'paste' === $mode ) {
			$edits = is_array( $body['edits'] ?? null ) ? $body['edits'] : [];
			$force = ! empty( $body['force'] );
			$res   = $this->bulk->commit_cell_edits( $entity, $edits, $mode, $force );
			return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
		}

		$rule         = is_array( $body['rule'] ?? null ) ? $body['rule'] : [];
		$target_ids   = is_array( $body['ids'] ?? null ) ? array_map( 'intval', $body['ids'] ) : [];
		$query_params = is_array( $body['query'] ?? null ) ? $body['query'] : [];

		if ( 'dry_run' === $mode ) {
			$res = $this->bulk->dry_run( $entity, $rule, $target_ids, $query_params );
			return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
		}

		if ( 'bulk' === $mode ) {
			$res = $this->bulk->execute_chunk(
				$entity,
				$rule,
				$target_ids,
				$query_params,
				max( 0, (int) ( $body['afterId'] ?? 0 ) ),
				max( 10, min( 250, (int) ( $body['chunkSize'] ?? 50 ) ) ),
				isset( $body['batchUuid'] ) ? sanitize_text_field( (string) $body['batchUuid'] ) : null,
				false,
				! empty( $body['revertAt'] ) ? sanitize_text_field( (string) $body['revertAt'] ) : null
			);
			return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
		}

		return new WP_Error( 'datadeck_invalid_mode', __( 'Unsupported batch mode.', 'datadeck' ), [ 'status' => 400 ] );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function validate_formula( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$expr = is_array( $body ) ? (string) ( $body['expression'] ?? '' ) : '';
		$res  = $this->formula->validate( $expr );

		return new WP_REST_Response(
			[
				'valid' => true === $res,
				'error' => true === $res ? null : $res,
			],
			200
		);
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function get_history( WP_REST_Request $request ): WP_REST_Response {
		$entity = sanitize_key( (string) ( $request->get_param( 'entity' ) ?? '' ) );
		$limit  = (int) ( $request->get_param( 'limit' ) ?? 30 );

		return new WP_REST_Response(
			[
				'batches' => $this->history->list_batches( $entity, $limit ),
			],
			200
		);
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function get_history_detail( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$uuid = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
		$res  = $this->history->get_batch_detail( $uuid );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function rollback_batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$uuid = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
		$res  = $this->history->rollback( $uuid, false );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function create_schedule( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity = sanitize_key( (string) $request->get_param( 'entity' ) );
		$body   = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'datadeck_bad_payload', __( 'Invalid JSON body.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$res = $this->scheduler->schedule(
			$entity,
			sanitize_text_field( (string) ( $body['name'] ?? '' ) ),
			is_array( $body['rule'] ?? null ) ? $body['rule'] : [],
			is_array( $body['ids'] ?? null ) ? array_map( 'intval', $body['ids'] ) : [],
			is_array( $body['query'] ?? null ) ? $body['query'] : [],
			sanitize_text_field( (string) ( $body['scheduledAt'] ?? '' ) ),
			! empty( $body['revertAt'] ) ? sanitize_text_field( (string) $body['revertAt'] ) : null
		);

		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 201 );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function cancel_schedule( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$uuid = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
		$res  = $this->scheduler->cancel( $uuid );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( [ 'cancelled' => true ], 200 );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function save_view( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity = sanitize_key( (string) $request->get_param( 'entity' ) );
		$body   = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'datadeck_bad_payload', __( 'Invalid view payload.', 'datadeck' ), [ 'status' => 400 ] );
		}
		$res = $this->views->save_view( $entity, $body );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( [ 'view' => $res ], 200 );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function delete_view( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_key( (string) $request->get_param( 'id' ) );
		return new WP_REST_Response( [ 'deleted' => $this->views->delete_view( $id ) ], 200 );
	}

	public function sync_views_from_json(): WP_REST_Response {
		return new WP_REST_Response( $this->views->sync_from_json(), 200 );
	}

	/**
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function save_preferences( WP_REST_Request $request ): WP_REST_Response {
		$body    = $request->get_json_params();
		$uid     = get_current_user_id();
		$current = get_user_meta( $uid, 'datadeck_preferences', true );
		$prefs   = is_array( $current ) ? $current : [];

		if ( is_array( $body ) ) {
			if ( isset( $body['density'] ) && in_array( $body['density'], [ 'compact', 'default', 'comfortable' ], true ) ) {
				$prefs['density'] = $body['density'];
			}
			if ( isset( $body['saveMode'] ) && in_array( $body['saveMode'], [ 'staged', 'instant' ], true ) ) {
				$prefs['saveMode'] = $body['saveMode'];
			}
			if ( isset( $body['defaultForEntity'] ) && is_array( $body['defaultForEntity'] ) ) {
				$existing = is_array( $prefs['defaultForEntity'] ?? null ) ? $prefs['defaultForEntity'] : [];
				foreach ( $body['defaultForEntity'] as $ent => $flag ) {
					$existing[ sanitize_key( (string) $ent ) ] = (bool) $flag;
				}
				$prefs['defaultForEntity'] = $existing;
			}
		}

		update_user_meta( $uid, 'datadeck_preferences', $prefs );

		return new WP_REST_Response( [ 'preferences' => $prefs ], 200 );
	}

	/**
	 * Stream filtered records as CSV or JSON with O(1) PHP memory usage.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function export_records( WP_REST_Request $request ): WP_Error {
		$entity  = sanitize_key( (string) $request->get_param( 'entity' ) );
		$adapter = $this->entities->get( $entity );
		if ( ! $adapter ) {
			return new WP_Error( 'datadeck_not_found', __( 'Entity not found.', 'datadeck' ), [ 'status' => 404 ] );
		}

		$query_req = QueryRequest::from_array( $entity, $request->get_params() );
		$all       = $this->fields->get_fields_for_entity( $entity );
		$want      = ! empty( $query_req->fields ) ? $query_req->fields : $this->fields->get_default_field_ids( $entity );
		$ordered   = [];

		foreach ( $want as $fid ) {
			if ( isset( $all[ $fid ] ) ) {
				$ordered[ $fid ] = $all[ $fid ];
			}
		}

		$format = sanitize_key( (string) ( $request->get_param( 'format' ) ?? 'csv' ) );
		$this->exporter->stream( $adapter, $query_req, $ordered, $format );
		exit;
	}

	/**
	 * Cell-level chronological history for a single record.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function get_record_history( WP_REST_Request $request ): WP_REST_Response {
		$entity = sanitize_key( (string) $request->get_param( 'entity' ) );
		$id     = (int) $request->get_param( 'id' );
		$field  = sanitize_key( (string) ( $request->get_param( 'field' ) ?? '' ) );
		$limit  = (int) ( $request->get_param( 'limit' ) ?? 50 );

		return new WP_REST_Response(
			[
				'changes' => $this->history->list_record_changes( $entity, $id, $field, $limit ),
			],
			200
		);
	}

	/**
	 * Restore a single historical cell change.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function rollback_change( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$change_id = (int) $request->get_param( 'change_id' );
		$res       = $this->history->rollback_change( $change_id );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
	}

	/**
	 * Create or update a custom computed formula column.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function save_computed_field( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity = sanitize_key( (string) $request->get_param( 'entity' ) );
		$body   = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'datadeck_bad_payload', __( 'Invalid JSON body.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$saved = $this->computed_fields->save_custom( $entity, $body );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->fields->clear_cache();
		$field_map = $this->fields->get_fields_for_entity( $entity );
		$fields    = array_map( static fn( $f ): array => $f->to_array(), array_values( $field_map ) );

		return new WP_REST_Response(
			[
				'computedField' => $saved,
				'fields'        => $fields,
			],
			201
		);
	}

	/**
	 * Delete a custom computed formula column.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function delete_computed_field( WP_REST_Request $request ): WP_REST_Response {
		$entity   = sanitize_key( (string) $request->get_param( 'entity' ) );
		$field_id = sanitize_key( (string) $request->get_param( 'field_id' ) );
		$deleted  = $this->computed_fields->delete_custom( $entity, $field_id );
		$this->fields->clear_cache();

		$field_map = $this->fields->get_fields_for_entity( $entity );
		$fields    = array_map( static fn( $f ): array => $f->to_array(), array_values( $field_map ) );

		return new WP_REST_Response(
			[
				'deleted' => $deleted,
				'fields'  => $fields,
			],
			200
		);
	}

	/**
	 * Analyze raw CSV/JSON import payload and return suggested column mappings.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function import_analyze( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity = sanitize_key( (string) $request->get_param( 'entity' ) );
		$body   = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'datadeck_bad_payload', __( 'Invalid JSON body.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$content = (string) ( $body['content'] ?? '' );
		$format  = sanitize_key( (string) ( $body['format'] ?? 'csv' ) );
		$res     = $this->importer->analyze( $entity, $content, $format );

		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
	}

	/**
	 * Preview exact row-by-row diffs before applying a CSV/JSON import.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function import_dry_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity = sanitize_key( (string) $request->get_param( 'entity' ) );
		$body   = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'datadeck_bad_payload', __( 'Invalid JSON body.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$rows           = is_array( $body['rows'] ?? null ) ? $body['rows'] : [];
		$mapping        = is_array( $body['mapping'] ?? null ) ? $body['mapping'] : [];
		$match_by       = sanitize_key( (string) ( $body['matchBy'] ?? 'id' ) );
		$create_missing = ! empty( $body['createMissing'] );

		$res = $this->importer->dry_run( $entity, $rows, $mapping, $match_by, $create_missing );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
	}

	/**
	 * Apply one chunk of CSV/JSON import rows with full HistoryService delta rollback tracking.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 */
	public function import_apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity = sanitize_key( (string) $request->get_param( 'entity' ) );
		$body   = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'datadeck_bad_payload', __( 'Invalid JSON body.', 'datadeck' ), [ 'status' => 400 ] );
		}

		$rows           = is_array( $body['rows'] ?? null ) ? $body['rows'] : [];
		$mapping        = is_array( $body['mapping'] ?? null ) ? $body['mapping'] : [];
		$match_by       = sanitize_key( (string) ( $body['matchBy'] ?? 'id' ) );
		$create_missing = ! empty( $body['createMissing'] );
		$batch_uuid     = ! empty( $body['batchUuid'] ) ? sanitize_text_field( (string) $body['batchUuid'] ) : null;

		$res = $this->importer->apply_chunk( $entity, $rows, $mapping, $match_by, $create_missing, $batch_uuid );
		return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 200 );
	}
}
