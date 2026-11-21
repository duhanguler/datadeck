<?php
/**
 * RowCraft Main Plugin Container & Lazy Service Bootstrapper.
 *
 * @package RowCraft
 */

declare(strict_types=1);

namespace RowCraft;

use RowCraft\Abilities\AbilityRegistrar;
use RowCraft\Admin\AdminIntegration;
use RowCraft\Bulk\BulkOperationEngine;
use RowCraft\Bulk\CampaignScheduler;
use RowCraft\Cli\RowCraftCommand;
use RowCraft\Entities\EntityRegistry;
use RowCraft\Fields\AcfFieldProvider;
use RowCraft\Fields\CorePostFieldProvider;
use RowCraft\Fields\FieldRegistry;
use RowCraft\Fields\MediaFieldProvider;
use RowCraft\Fields\MetaBoxFieldProvider;
use RowCraft\Fields\NativeMetaFieldProvider;
use RowCraft\Fields\SeoFieldProvider;
use RowCraft\Fields\UserFieldProvider;
use RowCraft\Fields\WooOrderFieldProvider;
use RowCraft\Fields\WooProductFieldProvider;
use RowCraft\Formula\ExpressionEngine;
use RowCraft\History\HistoryService;
use RowCraft\History\Schema;
use RowCraft\Lenses\LensRegistry;
use RowCraft\Query\KeysetStreamExporter;
use RowCraft\Query\SelectiveHydrator;
use RowCraft\Query\SmartQueryPlanner;
use RowCraft\Rest\RestApi;
use RowCraft\Sync\ViewSyncService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	private SelectiveHydrator $hydrator;
	private SmartQueryPlanner $planner;
	private FieldRegistry $fields;
	private EntityRegistry $entities;
	private ExpressionEngine $formula;
	private HistoryService $history;
	private BulkOperationEngine $bulk;
	private CampaignScheduler $scheduler;
	private LensRegistry $lenses;
	private ViewSyncService $views;
	private KeysetStreamExporter $exporter;

	private function __construct() {
		$this->hydrator  = new SelectiveHydrator();
		$this->planner   = new SmartQueryPlanner();
		$this->fields    = new FieldRegistry();
		$this->entities  = new EntityRegistry( $this->fields, $this->planner, $this->hydrator );
		$this->formula   = new ExpressionEngine();
		$this->history   = new HistoryService( $this->entities, $this->fields );
		$this->bulk      = new BulkOperationEngine( $this->entities, $this->fields, $this->history, $this->formula );
		$this->scheduler = new CampaignScheduler( $this->bulk, $this->history );
		$this->lenses    = new LensRegistry();
		$this->views     = new ViewSyncService();
		$this->exporter  = new KeysetStreamExporter();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire providers, REST routes, Admin UI, Cron, Abilities API, and WP-CLI.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Schema::maybe_upgrade();

		// Register built-in zero-paywall field providers.
		$this->fields->register( new CorePostFieldProvider( $this->hydrator ) );
		$this->fields->register( new WooProductFieldProvider() );
		$this->fields->register( new WooOrderFieldProvider() );
		$this->fields->register( new MediaFieldProvider() );
		$this->fields->register( new UserFieldProvider() );
		$this->fields->register( new SeoFieldProvider() );
		$this->fields->register( new AcfFieldProvider( $this->hydrator ) );
		$this->fields->register( new MetaBoxFieldProvider() );
		$this->fields->register( new NativeMetaFieldProvider() );

		// Scheduled & auto-revert campaigns + daily history TTL pruning.
		$this->scheduler->register_hooks();
		add_action( 'rowcraft_daily_prune', [ $this->history, 'prune_expired' ] );

		// REST API layer.
		$rest = new RestApi(
			$this->entities,
			$this->fields,
			$this->bulk,
			$this->scheduler,
			$this->history,
			$this->lenses,
			$this->views,
			$this->exporter,
			$this->formula
		);
		add_action( 'rest_api_init', [ $rest, 'register_routes' ] );

		// WordPress 6.9/7.0 Abilities API.
		( new AbilityRegistrar( $this->entities, $this->bulk, $this->history ) )->register_hooks();

		// Admin workspace & native list toggle.
		if ( is_admin() ) {
			( new AdminIntegration( $this->entities ) )->register_hooks();
		}

		// WP-CLI commands.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command(
				'rowcraft',
				new RowCraftCommand( $this->entities, $this->bulk, $this->history, $this->views )
			);
		}
	}

	public function entities(): EntityRegistry {
		return $this->entities;
	}

	public function fields(): FieldRegistry {
		return $this->fields;
	}

	public function formula(): ExpressionEngine {
		return $this->formula;
	}

	public function bulk(): BulkOperationEngine {
		return $this->bulk;
	}

	public function history(): HistoryService {
		return $this->history;
	}
}
