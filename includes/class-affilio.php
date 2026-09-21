<?php
/**
 * Main plugin singleton and incremental service bootstrap.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Affilio {

	/**
	 * The single instance of this class.
	 *
	 * @var Affilio|null
	 */
	private static $instance = null;

	/**
	 * Whether service-discovery hooks have been emitted.
	 *
	 * @var bool
	 */
	private $ready_announced = false;

	/**
	 * Versioned compatibility service for Pro and third-party extensions.
	 *
	 * @var \Affilio\Core\Compatibility
	 */
	public $compatibility;

	/**
	 * Lightweight registry for incrementally decoupled services.
	 *
	 * @var \Affilio\Core\ServiceRegistry
	 */
	public $services;

	/**
	 * Developer-facing Free/Pro feature productization catalog.
	 *
	 * @var \Affilio\Core\FeatureCatalog
	 */
	public $feature_catalog;

	/**
	 * Fixed Free/Shared runtime feature gate.
	 *
	 * @var \Affilio\Core\FeatureGate
	 */
	public $feature_gate;

	/** @var \Affilio\Core\ProviderRegistry */
	public $commission_rule_providers;

	/** @var \Affilio\Core\ProviderRegistry */
	public $fraud_policy_providers;

	/** @var \Affilio\Core\ProviderRegistry */
	public $analytics_providers;

	/** @var \Affilio\Core\ProviderRegistry */
	public $email_template_providers;

	/**
	 * Execution-context service.
	 *
	 * @var \Affilio\Infrastructure\WordPress\RequestContext
	 */
	public $request_context;

	/**
	 * Bounded database-upgrade coordinator.
	 *
	 * @var \Affilio\Infrastructure\WordPress\UpgradeCoordinator
	 */
	public $upgrade_coordinator;

	/**
	 * Versioned and restartable migration runner.
	 *
	 * @var \Affilio\Infrastructure\WordPress\MigrationRunner
	 */
	public $migration_runner;

	/**
	 * Bounded multisite/network lifecycle coordinator.
	 *
	 * @var \Affilio\Infrastructure\WordPress\MultisiteManager
	 */
	public $multisite_manager;

	/**
	 * Site-scoped report and analytics cache.
	 *
	 * @var \Affilio\Infrastructure\WordPress\PerformanceCache
	 */
	public $performance_cache;

	/**
	 * Non-destructive Free/Pro data-preservation coordinator.
	 *
	 * @var \Affilio\Infrastructure\WordPress\FreeTierDataPreserver
	 */
	public $free_tier_data_preserver;

	/**
	 * @var Affilio_DB_Affiliates
	 */
	public $affiliates_db;

	/**
	 * @var Affilio_DB_Referrals
	 */
	public $referrals_db;

	/**
	 * @var Affilio_DB_Visits
	 */
	public $visits_db;

	/**
	 * @var Affilio_DB_Payouts
	 */
	public $payouts_db;


	/** @var Affilio_DB_Events */
	public $events_db;

	/** @var Affilio_DB_Payout_Requests */
	public $payout_requests_db;

	/** @var Affilio_Audit */
	public $audit;

	/** @var Affilio_Commission_Approval */
	public $commission_approval;

	/** @var Affilio_Payout_Requests */
	public $payout_requests;

	/**
	 * @var Affilio_Payouts
	 */
	public $payouts;

	/**
	 * @var Affilio_Coupons
	 */
	public $coupons;

	/**
	 * @var Affilio_Creatives
	 */
	public $creatives;

	/**
	 * Backward-compatible basic report summary service.
	 *
	 * @var Affilio_Report_Summary
	 */
	public $analytics;


	/**
	 * Active platform integrations, keyed by slug (for example, woocommerce).
	 *
	 * @var Affilio_Integration[]
	 */
	public $integrations = array();

	/**
	 * Gets and creates the single instance.
	 *
	 * @return Affilio
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			$instance       = new self();
			self::$instance = $instance;
			$instance->announce_ready();
		}

		return self::$instance;
	}

	/**
	 * Creates the compatibility, context, registry, and legacy services.
	 */
	private function __construct() {
		$this->services                  = new \Affilio\Core\ServiceRegistry();
		$this->compatibility             = new \Affilio\Core\Compatibility( AFFILIO_VERSION );
		$this->feature_catalog           = new \Affilio\Core\FeatureCatalog();
		$this->feature_gate              = new \Affilio\Core\FeatureGate( $this->feature_catalog );
		$this->commission_rule_providers = new \Affilio\Core\ProviderRegistry( \Affilio\Contracts\CommissionRuleProvider::class );
		$this->fraud_policy_providers    = new \Affilio\Core\ProviderRegistry( \Affilio\Contracts\FraudPolicyProvider::class );
		$this->analytics_providers       = new \Affilio\Core\ProviderRegistry( \Affilio\Contracts\AnalyticsProvider::class );
		$this->email_template_providers  = new \Affilio\Core\ProviderRegistry( \Affilio\Contracts\EmailTemplateProvider::class );
		$this->request_context           = new \Affilio\Infrastructure\WordPress\RequestContext();
		$this->performance_cache         = new \Affilio\Infrastructure\WordPress\PerformanceCache();
		$this->free_tier_data_preserver  = new \Affilio\Infrastructure\WordPress\FreeTierDataPreserver();
		$this->migration_runner = new \Affilio\Infrastructure\WordPress\MigrationRunner(
			AFFILIO_VERSION,
			AFFILIO_DB_VERSION,
			2
		);
		$this->upgrade_coordinator = new \Affilio\Infrastructure\WordPress\UpgradeCoordinator(
			array( $this, 'upgrade_required' ),
			array( $this, 'maybe_upgrade_db' )
		);
		$this->multisite_manager = new \Affilio\Infrastructure\WordPress\MultisiteManager(
			array( $this, 'synchronize_current_site_for_network' ),
			array( $this, 'cleanup_current_site_for_deleted_site' )
		);

		$this->register_service( 'core.compatibility', $this->compatibility );
		$this->register_service( 'core.feature_catalog', $this->feature_catalog );
		$this->register_service( 'core.feature_gate', $this->feature_gate );
		$this->register_service( 'core.commission_rule_providers', $this->commission_rule_providers );
		$this->register_service( 'core.fraud_policy_providers', $this->fraud_policy_providers );
		$this->register_service( 'core.analytics_providers', $this->analytics_providers );
		$this->register_service( 'core.email_template_providers', $this->email_template_providers );
		$this->register_service( 'core.request_context', $this->request_context );
		$this->register_service( 'core.performance_cache', $this->performance_cache );
		$this->register_service( 'core.free_tier_data_preserver', $this->free_tier_data_preserver );
		$this->register_service( 'core.migration_runner', $this->migration_runner );
		$this->register_service( 'core.upgrade_coordinator', $this->upgrade_coordinator );
		$this->register_service( 'core.multisite_manager', $this->multisite_manager );

		$this->includes();
		$this->init_hooks();

	}

	/**
	 * Cloning is disabled because there must be one core bootstrap instance.
	 *
	 * @return void
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Dreamax Affiliates is a singleton and cannot be cloned.', 'dreamax-affiliates' ), esc_html( AFFILIO_VERSION ) );
	}

	/**
	 * Unserializing is disabled for the same reason as cloning.
	 *
	 * @return void
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Dreamax Affiliates is a singleton and cannot be unserialized.', 'dreamax-affiliates' ), esc_html( AFFILIO_VERSION ) );
	}

	/**
	 * Returns one registered service without breaking legacy public properties.
	 *
	 * @param string $identifier Service identifier.
	 * @return object|null
	 */
	public function service( $identifier ) {
		return $this->services->get( (string) $identifier );
	}

	/**
	 * Loads legacy classes and registers their already-created service objects.
	 *
	 * The legacy classes remain available to preserve backward compatibility.
	 * New foundation classes are loaded through the Dreamax Affiliates namespace autoloader.
	 *
	 * @return void
	 */
	private function includes() {
		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-i18n.php';
		require_once AFFILIO_PLUGIN_DIR . 'includes/abstracts/class-affilio-db.php';
		require_once AFFILIO_PLUGIN_DIR . 'includes/database/class-affilio-db-affiliates.php';
		require_once AFFILIO_PLUGIN_DIR . 'includes/database/class-affilio-db-referrals.php';
		require_once AFFILIO_PLUGIN_DIR . 'includes/database/class-affilio-db-visits.php';
		require_once AFFILIO_PLUGIN_DIR . 'includes/database/class-affilio-db-payouts.php';
		require_once AFFILIO_PLUGIN_DIR . 'includes/database/class-affilio-db-events.php';
		require_once AFFILIO_PLUGIN_DIR . 'includes/database/class-affilio-db-payout-requests.php';

		$this->affiliates_db      = new Affilio_DB_Affiliates();
		$this->referrals_db       = new Affilio_DB_Referrals();
		$this->visits_db          = new Affilio_DB_Visits();
		$this->payouts_db         = new Affilio_DB_Payouts();
		$this->events_db          = new Affilio_DB_Events();
		$this->payout_requests_db = new Affilio_DB_Payout_Requests();

		$this->register_service( 'database.affiliates', $this->affiliates_db );
		$this->register_service( 'database.referrals', $this->referrals_db );
		$this->register_service( 'database.visits', $this->visits_db );
		$this->register_service( 'database.payouts', $this->payouts_db );
		$this->register_service( 'database.events', $this->events_db );
		$this->register_service( 'database.payout_requests', $this->payout_requests_db );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-audit.php';
		$this->audit = new Affilio_Audit();
		$this->register_service( 'support.audit', $this->audit );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-commission-approval.php';
		$this->commission_approval = new Affilio_Commission_Approval();
		$this->register_service( 'application.commission_approval', $this->commission_approval );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-commission.php';

		require_once AFFILIO_PLUGIN_DIR . 'includes/abstracts/class-affilio-integration.php';
		$this->load_integrations();

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-capabilities.php';

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-payouts.php';
		$this->payouts = new Affilio_Payouts();
		$this->register_service( 'application.payouts', $this->payouts );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-payout-requests.php';
		$this->payout_requests = new Affilio_Payout_Requests();
		$this->register_service( 'application.payout_requests', $this->payout_requests );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-login-branding.php';
		$this->register_service( 'frontend.login_branding', new Affilio_Login_Branding() );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-registration.php';
		$this->register_service( 'frontend.registration', new Affilio_Registration() );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-fraud-prevention.php';

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-tracking.php';
		$this->register_service( 'frontend.tracking', new Affilio_Tracking() );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-reports.php';
		$this->register_service( 'application.reports', new Affilio_Reports() );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-coupons.php';
		$this->coupons = new Affilio_Coupons();
		$this->register_service( 'application.coupons', $this->coupons );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-creatives.php';
		$this->creatives = new Affilio_Creatives();
		$this->register_service( 'application.creatives', $this->creatives );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-report-summary.php';
		$this->analytics = new Affilio_Report_Summary();
		$this->register_service( 'application.analytics', $this->analytics );
		$this->register_service( 'application.report_summary', $this->analytics );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-dashboard.php';
		$this->register_service( 'frontend.dashboard', new Affilio_Dashboard() );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-my-account.php';
		$this->register_service( 'frontend.my_account', new Affilio_My_Account() );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-assets.php';
		$this->register_service( 'support.assets', new Affilio_Assets() );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-email-templates.php';
		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-emails.php';
		$this->register_service( 'support.emails', new Affilio_Emails() );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-privacy.php';
		$this->register_service( 'support.privacy', new Affilio_Privacy() );

		// Exclude AJAX, REST, cron, and CLI from human admin-screen services.
		if ( $this->request_context->is_admin_screen() ) {
			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-settings.php';
			$this->register_service( 'admin.settings', new Affilio_Settings() );


			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-affiliate-admin.php';
			$this->register_service( 'admin.affiliate_admin', new Affilio_Affiliate_Admin() );

			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-manual-referrals.php';
			$this->register_service( 'admin.manual_referrals', new Affilio_Manual_Referrals() );

			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-admin-workflows.php';
			$this->register_service( 'admin.workflows', new Affilio_Admin_Workflows() );

			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-diagnostics.php';
			$this->register_service( 'admin.diagnostics', new Affilio_Diagnostics() );

			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-upgrade-page.php';
			$this->register_service( 'admin.upgrade_page', new Affilio_Upgrade_Page() );

			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-admin-menu.php';
			$this->register_service( 'admin.menu', new Affilio_Admin_Menu() );

			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-onboarding.php';
			$this->register_service( 'admin.onboarding', new Affilio_Onboarding() );
		}
	}

	/**
	 * Detects supported commerce platforms and instantiates matching modules.
	 *
	 * @return void
	 */
	private function load_integrations() {
		if ( $this->is_woocommerce_active() ) {
			require_once AFFILIO_PLUGIN_DIR . 'includes/integrations/class-affilio-integration-woocommerce.php';

			$this->integrations['woocommerce'] = new Affilio_Integration_WooCommerce();
			$this->register_service( 'integration.woocommerce', $this->integrations['woocommerce'] );
		}

		if ( empty( $this->integrations ) ) {
			add_action( 'admin_notices', array( $this, 'no_integration_notice' ) );
		}
	}

	/**
	 * Whether WooCommerce is active on this site or network.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		// 'active_plugins' here is WordPress core's own established filter name (see
		// wp-includes/plugin.php usage across core and other plugins), applied so that any
		// site-level filtering of the active-plugins list (staging tools, security plugins,
		// etc.) is respected. It is intentionally not Dreamax Affiliates-prefixed: renaming it would stop
		// tapping into that existing, shared filter and could hide an actually-active WooCommerce.
		$active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			$network_id     = function_exists( 'get_current_network_id' ) ? absint( get_current_network_id() ) : 1;
			$network_active = (array) get_network_option( $network_id, 'active_sitewide_plugins', array() );
			return array_key_exists( 'woocommerce/woocommerce.php', $network_active );
		}

		return false;
	}

	/**
	 * Shows an informational notice when no supported integration is active.
	 *
	 * @return void
	 */
	public function no_integration_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || ! affilio_is_plugin_admin_notice_screen() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Dreamax Affiliates is active, but no supported e-commerce plugin (currently: WooCommerce) was detected, so order tracking is disabled until one is installed and active.', 'dreamax-affiliates' );
		echo '</p></div>';
	}

	/**
	 * Registers core hooks and the bounded upgrade coordinator.
	 *
	 * @return void
	 */
	private function init_hooks() {
		$this->upgrade_coordinator->register_hooks();
		$this->multisite_manager->register_hooks();
		$this->performance_cache->register_hooks();

		if ( $this->upgrade_required() || $this->request_context->is_admin_screen() ) {
			$this->upgrade_coordinator->refresh_pending_state();
		}
	}

	/**
	 * Creates or upgrades all current custom tables.
	 *
	 * @return void
	 */
	public function install_tables() {
		$this->affiliates_db->create_table();
		$this->referrals_db->create_table();
		$this->visits_db->create_table();
		$this->payouts_db->create_table();
		$this->events_db->create_table();
		$this->payout_requests_db->create_table();

		update_option( 'affilio_db_version', AFFILIO_DB_VERSION, false );
	}

	/**
	 * Whether plugin or database migration work remains.
	 *
	 * @return bool
	 */
	public function upgrade_required() {
		$installed_version = (string) get_option( 'affilio_version', '' );
		$db_version        = (string) get_option( 'affilio_db_version', '' );

		return AFFILIO_VERSION !== $installed_version
			|| AFFILIO_DB_VERSION !== $db_version
			|| \Affilio\Infrastructure\WordPress\MigrationRunner::has_state();
	}

	/**
	 * Executes one bounded, idempotent upgrade batch.
	 *
	 * Progress is saved after every completed step. The plugin version is moved
	 * to the target only after all steps finish, so an interrupted request can
	 * safely resume without advertising a partially completed upgrade.
	 *
	 * @return void
	 */
	public function maybe_upgrade_db() {
		$installed_version = (string) get_option( 'affilio_version', '' );

		$complete = $this->migration_runner->run(
			array(
				array(
					'id'       => 'schema-' . str_replace( '.', '-', AFFILIO_DB_VERSION ),
					'required' => static function () {
						return AFFILIO_DB_VERSION !== (string) get_option( 'affilio_db_version', '' );
					},
					'callback' => array( $this, 'install_tables' ),
				),
				array(
					'id'       => 'onboarding-1-4-0',
					'required' => '' === $installed_version || version_compare( $installed_version, '1.4.0', '<' ),
					'callback' => static function () {
						require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-onboarding.php';
						Affilio_Onboarding::upgrade_to_140();
					},
				),
				array(
					'id'       => 'lifecycle-1-9-4',
					'required' => '' === $installed_version || version_compare( $installed_version, '1.9.4', '<' ),
					'callback' => array( $this, 'upgrade_lifecycle_defaults' ),
				),
				array(
					'id'       => 'multisite-1-9-5',
					'required' => '' === $installed_version || version_compare( $installed_version, '1.9.5', '<' ),
					'callback' => array( $this, 'upgrade_lifecycle_defaults' ),
				),
				array(
					'id'       => 'performance-1-9-6',
					'required' => '' === $installed_version || version_compare( $installed_version, '1.9.6', '<' ),
					'callback' => array( $this, 'upgrade_performance_defaults' ),
				),
				array(
					'id'       => 'free-commission-2-0-2',
					'required' => '' !== $installed_version && version_compare( $installed_version, '2.0.2', '<' ),
					'callback' => array( $this, 'upgrade_free_tier_commission_workflows' ),
				),
				array(
					'id'       => 'free-fraud-email-2-0-4',
					'required' => '' !== $installed_version && version_compare( $installed_version, '2.0.4', '<' ),
					'callback' => array( $this, 'upgrade_free_tier_fraud_email_workflows' ),
				),
				array(
					'id'       => 'free-data-preservation-2-0-5',
					'required' => '' !== $installed_version && version_compare( $installed_version, '2.0.5', '<' ),
					'callback' => array( $this, 'upgrade_free_tier_data_preservation' ),
				),
			)
		);

		if ( ! $complete ) {
			return;
		}

		update_option( 'affilio_version', AFFILIO_VERSION, false );
		delete_option( 'affilio_upgrade_pending' );
	}

	/**
	 * Restores lifecycle defaults and scheduled workers for older releases.
	 *
	 * This step is intentionally idempotent and can safely run again after a
	 * failed or interrupted request.
	 *
	 * @return void
	 */
	public function upgrade_lifecycle_defaults() {
		\Affilio\Infrastructure\WordPress\PluginLifecycle::ensure_defaults();

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-capabilities.php';
		Affilio_Capabilities::add_capabilities();

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-privacy.php';
		Affilio_Privacy::schedule_cleanup();

	}

	/**
	 * Separates legacy holding automation from the Free runtime.
	 *
	 * Existing held referrals are made payout-eligible without deleting their
	 * original eligibility date or advanced settings. Product/category/variation
	 * metadata remains untouched for a separately distributed provider.
	 *
	 * @return void
	 */
	public function upgrade_free_tier_commission_workflows() {
		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-commission-approval.php';
		Affilio_Commission_Approval::clear_legacy_schedule();

		$this->referrals_db->release_legacy_held_referrals_for_free_tier();
		$this->performance_cache->invalidate();
	}

	/**
	 * Preserves advanced fraud/template data while initializing Free controls.
	 *
	 * Existing blocked domains, velocity settings, and editable template content
	 * are retained untouched for a separately distributed provider. Free copies
	 * only the previous per-notification enabled flags into its dedicated option.
	 *
	 * @return void
	 */
	public function upgrade_free_tier_fraud_email_workflows() {
		if ( false === get_option( 'affilio_email_notifications', false ) ) {
			$legacy   = get_option( 'affilio_email_templates', array() );
			$settings = array();

			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-email-templates.php';
			foreach ( Affilio_Email_Templates::definitions() as $key => $definition ) {
				$settings[ $key ] = isset( $legacy[ $key ] ) && is_array( $legacy[ $key ] )
					? ! empty( $legacy[ $key ]['enabled'] )
					: ! empty( $definition['enabled'] );
			}

			add_option( 'affilio_email_notifications', $settings, '', false );
		}

		$this->performance_cache->invalidate();
	}

	/**
	 * Records the completed Free-tier split without changing preserved data.
	 *
	 * The marker contains only option-presence booleans and known metadata-key
	 * names. Legacy rule values, templates, affiliate records, orders, and
	 * financial history remain untouched.
	 *
	 * @return void
	 */
	public function upgrade_free_tier_data_preservation() {
		\Affilio\Infrastructure\WordPress\PluginLifecycle::normalize_large_option_autoloading();
		\Affilio\Infrastructure\WordPress\FreeTierDataPreserver::mark_complete( AFFILIO_VERSION );
		$this->performance_cache->invalidate();
	}


	/**
	 * Applies non-destructive performance defaults for upgraded sites.
	 *
	 * @return void
	 */
	public function upgrade_performance_defaults() {
		\Affilio\Infrastructure\WordPress\PluginLifecycle::ensure_defaults();
		\Affilio\Infrastructure\WordPress\PluginLifecycle::normalize_large_option_autoloading();
		$this->performance_cache->invalidate();
	}

	/**
	 * Synchronizes one current-site installation during network activation,
	 * plugin updates, and new-site creation.
	 *
	 * Fresh sites receive the complete schema and defaults without an onboarding
	 * redirect. Existing sites run the versioned migration runner until the
	 * bounded step set completes.
	 *
	 * @return void
	 */
	public function synchronize_current_site_for_network() {
		$installed_version = (string) get_option( 'affilio_version', '' );

		if ( '' === $installed_version ) {
			$this->initialize_current_site( false );
			return;
		}

		$attempts = 0;
		while ( $this->upgrade_required() && $attempts < 10 ) {
			$this->maybe_upgrade_db();
			++$attempts;
		}

		if ( $this->upgrade_required() ) {
			throw new \RuntimeException( 'Dreamax Affiliates site synchronization did not complete within the bounded migration limit.' );
		}

		$this->upgrade_lifecycle_defaults();
	}

	/**
	 * Performs destructive cleanup for a site WordPress is about to delete.
	 *
	 * @return void
	 */
	public function cleanup_current_site_for_deleted_site() {
		\Affilio\Infrastructure\WordPress\PluginLifecycle::uninstall_current_site( true );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-capabilities.php';
		Affilio_Capabilities::remove_capabilities();
	}

	/**
	 * Installs or refreshes one site's schema, defaults, capabilities, and jobs.
	 *
	 * @param bool $interactive Whether this is a direct site activation that may show onboarding.
	 * @return void
	 */
	private function initialize_current_site( $interactive = true ) {
		$this->install_tables();
		\Affilio\Infrastructure\WordPress\PluginLifecycle::ensure_defaults();
		\Affilio\Infrastructure\WordPress\FreeTierDataPreserver::mark_complete( AFFILIO_VERSION );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-capabilities.php';
		Affilio_Capabilities::add_capabilities();

		update_option( 'affilio_version', AFFILIO_VERSION, false );
		delete_option( 'affilio_upgrade_pending' );
		\Affilio\Infrastructure\WordPress\MigrationRunner::clear_state();
		\Affilio\Infrastructure\WordPress\UpgradeCoordinator::clear_lock();

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-privacy.php';
		Affilio_Privacy::schedule_cleanup();


		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-onboarding.php';
		Affilio_Onboarding::activate( (bool) $interactive );

		require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-my-account.php';
		Affilio_My_Account::register_endpoint();

		if ( $interactive ) {
			flush_rewrite_rules();
			delete_option( 'affilio_rewrite_flush_required' );
		}
	}

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether WordPress is activating the plugin for the network.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		$instance = self::instance();

		if ( is_multisite() && $network_wide ) {
			$instance->multisite_manager->activate_network();
			return;
		}

		$installed_version = (string) get_option( 'affilio_version', '' );
		if ( '' !== $installed_version ) {
			$instance->synchronize_current_site_for_network();

			require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio-my-account.php';
			Affilio_My_Account::register_endpoint();
			flush_rewrite_rules();
			delete_option( 'affilio_rewrite_flush_required' );
			return;
		}

		$instance->initialize_current_site( true );
	}

	/**
	 * Deactivation callback. Permanent data and resumable migration progress
	 * remain intact; cron, notices, caches, and stale mutexes are removed for
	 * the current site. Network synchronization state is removed on a network
	 * deactivation without traversing every site synchronously.
	 *
	 * @param bool $network_wide Whether WordPress is deactivating the plugin for the network.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		$instance = self::instance();

		\Affilio\Infrastructure\WordPress\PluginLifecycle::clear_scheduled_events();
		\Affilio\Infrastructure\WordPress\PluginLifecycle::clear_runtime_state( false );
		\Affilio\Infrastructure\WordPress\UpgradeCoordinator::clear_lock();

		if ( is_multisite() && $network_wide ) {
			$instance->multisite_manager->deactivate_network();
		}

		flush_rewrite_rules();
	}

	/**
	 * Emits service-discovery hooks only after the singleton assignment is
	 * complete, preventing recursive `affilio()` calls during construction.
	 *
	 * @return void
	 */
	private function announce_ready() {
		if ( $this->ready_announced ) {
			return;
		}

		$this->ready_announced = true;

		foreach ( $this->services->identifiers() as $identifier ) {
			$service = $this->services->get( $identifier );

			if ( $service ) {
				do_action( 'affilio_service_registered', $identifier, $service );
			}
		}

		/**
		 * Fires after the Free core has registered its stable services.
		 *
		 * @param \Affilio\Core\Compatibility $compatibility Compatibility service.
		 * @param \Affilio\Core\ServiceRegistry $services      Core service registry.
		 * @param Dreamax Affiliates $core Core plugin instance.
		 */
		do_action( 'affilio_core_ready', $this->compatibility, $this->services, $this );
	}

	/**
	 * Registers a service without emitting hooks during object construction.
	 *
	 * @param string $identifier Stable service identifier.
	 * @param object $service    Service instance.
	 * @return void
	 */
	private function register_service( $identifier, $service ) {
		$this->services->set( (string) $identifier, $service );
	}
}
