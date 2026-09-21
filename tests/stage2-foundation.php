<?php
/**
 * Dependency-free Stage 2 contract/unit checks.
 *
 * Run: php tests/stage2-foundation.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['affilio_test_filters'] = array();
$GLOBALS['affilio_test_options'] = array();
$GLOBALS['affilio_test_cron']    = array();
$GLOBALS['affilio_test_count']   = 0;
$GLOBALS['affilio_test_actions'] = array();
$GLOBALS['affilio_test_core']    = null;

function affilio_test_assert( $condition, $message ) {
	++$GLOBALS['affilio_test_count'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['affilio_test_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function do_action( $hook ) {
	$args = array_slice( func_get_args(), 1 );
	$GLOBALS['affilio_test_actions'][] = array( $hook, $args );
	if ( empty( $GLOBALS['affilio_test_filters'][ $hook ] ) ) {
		return;
	}
	ksort( $GLOBALS['affilio_test_filters'][ $hook ] );
	foreach ( $GLOBALS['affilio_test_filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
		}
	}
}

function apply_filters( $hook, $value ) {
	$args = array_slice( func_get_args(), 1 );
	if ( empty( $GLOBALS['affilio_test_filters'][ $hook ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['affilio_test_filters'][ $hook ] );
	foreach ( $GLOBALS['affilio_test_filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$call_args    = array_slice( $args, 0, $entry[1] );
			$call_args[0] = $value;
			$value        = call_user_func_array( $entry[0], $call_args );
			$args[0]      = $value;
		}
	}
	return $value;
}

function remove_all_filters( $hook ) {
	unset( $GLOBALS['affilio_test_filters'][ $hook ] );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', strip_tags( (string) $value ) ) );
}

function esc_url_raw( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	$scheme = parse_url( $value, PHP_URL_SCHEME );
	if ( null !== $scheme && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
		return '';
	}
	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}

function current_time( $type ) {
	return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}

function current_datetime() {
	return new DateTimeImmutable( 'now', wp_timezone() );
}

function __( $text ) {
	return $text;
}

function affilio() {
	return $GLOBALS['affilio_test_core'];
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['affilio_test_options'] ) ? $GLOBALS['affilio_test_options'][ $name ] : $default;
}

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['affilio_test_options'] ) ) {
		return false;
	}
	$GLOBALS['affilio_test_options'][ $name ] = $value;
	$GLOBALS['affilio_test_autoload'][ $name ] = $autoload;
	return true;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['affilio_test_options'][ $name ] = $value;
	if ( null !== $autoload ) {
		$GLOBALS['affilio_test_autoload'][ $name ] = $autoload;
	}
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['affilio_test_options'][ $name ], $GLOBALS['affilio_test_autoload'][ $name ] );
	return true;
}

function wp_next_scheduled( $hook ) {
	return $GLOBALS['affilio_test_cron'][ $hook ] ?? false;
}

function wp_schedule_single_event( $timestamp, $hook ) {
	$GLOBALS['affilio_test_cron'][ $hook ] = $timestamp;
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['affilio_test_cron'][ $hook ] );
}

class Affilio_Test_Atomic_Lock {
	public static $acquire = true;

	public static function acquire() {
		return self::$acquire;
	}

	public static function release() {
		return true;
	}
}

class_alias( 'Affilio_Test_Atomic_Lock', 'Affilio\\Infrastructure\\WordPress\\AtomicLock' );

require_once dirname( __DIR__ ) . '/src/Domain/Referral/ReferralStatus.php';
require_once dirname( __DIR__ ) . '/src/Contracts/Extension.php';
require_once dirname( __DIR__ ) . '/src/Core/Compatibility.php';
require_once dirname( __DIR__ ) . '/includes/class-affilio-commission-approval.php';
require_once dirname( __DIR__ ) . '/includes/class-affilio-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-affilio-privacy.php';

try {
	$default = Affilio_Commission_Approval::get_initial_state();
	affilio_test_assert( array( 'status' => 'unpaid', 'date_eligible' => null ) === $default, 'No-filter initial state changed.' );

	$future = gmdate( 'Y-m-d H:i:s', time() + 3600 );
	add_filter(
		'affilio_commission_initial_state',
		static function ( $state ) use ( $future ) {
			return array( 'status' => 'pending', 'date_eligible' => $future );
		}
	);
	affilio_test_assert( array( 'status' => 'pending', 'date_eligible' => $future ) === Affilio_Commission_Approval::get_initial_state(), 'Valid future hold was rejected.' );
	remove_all_filters( 'affilio_commission_initial_state' );

	$invalid_states = array(
		array( 'status' => 'paid', 'date_eligible' => null ),
		array( 'status' => 'pending', 'date_eligible' => 'not-a-date' ),
		array( 'status' => 'pending', 'date_eligible' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
		array( 'status' => 'unpaid', 'date_eligible' => $future ),
		'malformed',
	);
	foreach ( $invalid_states as $invalid ) {
		add_filter( 'affilio_commission_initial_state', static function () use ( $invalid ) { return $invalid; } );
		affilio_test_assert( $default === Affilio_Commission_Approval::get_initial_state(), 'Invalid state did not fall back safely.' );
		remove_all_filters( 'affilio_commission_initial_state' );
	}

	$order = array();
	add_filter( 'affilio_commission_initial_state', static function ( $state ) use ( &$order ) { $order[] = 10; return $state; }, 10, 2 );
	add_filter( 'affilio_commission_initial_state', static function ( $state ) use ( &$order ) { $order[] = 20; return $state; }, 20, 2 );
	Affilio_Commission_Approval::get_initial_state();
	affilio_test_assert( array( 10, 20 ) === $order, 'Initial-state callback order is not deterministic.' );
	remove_all_filters( 'affilio_commission_initial_state' );

	$captured = null;
	add_filter( 'affilio_commission_initial_state', static function ( $state, $context ) use ( &$captured ) { $captured = $context; return $state; }, 10, 2 );
	Affilio_Commission_Approval::get_initial_state(
		array(
			'event' => 'creation', 'order_id' => '12', 'affiliate_id' => 4, 'visit_id' => 8,
			'source' => 'coupon', 'qualified_at' => gmdate( 'Y-m-d H:i:s' ),
			'email' => 'secret@example.test', 'personal' => array( 'secret' ),
		)
	);
	affilio_test_assert( ! isset( $captured['email'], $captured['personal'] ) && 12 === $captured['order_id'], 'Initial-state context leaked unsupported data.' );
	remove_all_filters( 'affilio_commission_initial_state' );

	affilio_test_assert( Affilio_Commission_Approval::is_active_hold( array( 'status' => 'pending', 'date_eligible' => $future ) ), 'Future hold was not detected.' );
	affilio_test_assert( ! Affilio_Commission_Approval::is_active_hold( array( 'status' => 'pending', 'date_eligible' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ), 'Due hold remained active.' );
	affilio_test_assert( ! Affilio_Commission_Approval::is_active_hold( array( 'status' => 'unpaid', 'date_eligible' => $future ) ), 'Unsupported hold pair was accepted.' );

	affilio_test_assert( ! Affilio_Commission_Approval::is_handoff_requested(), 'Handoff was active by default.' );
	affilio_test_assert( Affilio_Commission_Approval::request_handoff(), 'Handoff activation failed.' );
	$scheduled = wp_next_scheduled( Affilio_Commission_Approval::LEGACY_CRON_HOOK );
	Affilio_Commission_Approval::request_handoff();
	affilio_test_assert( $scheduled === wp_next_scheduled( Affilio_Commission_Approval::LEGACY_CRON_HOOK ), 'Handoff activation was not idempotent.' );
	affilio_test_assert( false === $GLOBALS['affilio_test_autoload'][ Affilio_Commission_Approval::HANDOFF_OPTION ], 'Handoff option was autoloaded.' );
	Affilio_Commission_Approval::withdraw_handoff();
	affilio_test_assert( ! Affilio_Commission_Approval::is_handoff_requested() && ! wp_next_scheduled( Affilio_Commission_Approval::LEGACY_CRON_HOOK ), 'Handoff withdrawal failed.' );

	$referrals = new class() {
		public $limit;
		public $release_calls = 0;
		public function get_eligible_ids( $limit ) {
			$this->limit = $limit;
			return array( 1, 2 );
		}
		public function release_eligible_ids() {
			++$this->release_calls;
			return 1 === $this->release_calls ? array( 2 ) : array();
		}
	};
	$audit = new class() {
		public $records = array();
		public function record() {
			$this->records[] = func_get_args();
			return count( $this->records );
		}
	};
	$GLOBALS['affilio_test_core'] = (object) array( 'referrals_db' => $referrals, 'audit' => $audit );
	$approval = new Affilio_Commission_Approval();
	$released = $approval->release_eligible( 5000 );
	affilio_test_assert( array( 2 ) === $released && 2000 === $referrals->limit, 'Release was not bounded or did not return only changed rows.' );
	affilio_test_assert( 1 === count( $audit->records ) && 'status_changed' === $audit->records[0][2], 'Release audit was not written once.' );
	$status_actions = array_values( array_filter( $GLOBALS['affilio_test_actions'], static function ( $event ) { return 'affilio_referral_status_changed' === $event[0]; } ) );
	affilio_test_assert( 1 === count( $status_actions ) && array( 2, 'unpaid', 'pending', 'Commission holding period completed.' ) === $status_actions[0][1], 'Release status action was not emitted once for the changed row.' );
	$approval->release_eligible( 10 );
	affilio_test_assert( 1 === count( $audit->records ), 'A duplicate release emitted another audit record.' );

	$future_queue = gmdate( 'Y-m-d H:i:s', time() + 600 );
	$queue = new class( $future_queue ) {
		public $next;
		public function __construct( $next ) { $this->next = $next; }
		public function get_eligible_ids() { return array(); }
		public function release_eligible_ids() { return array(); }
		public function get_next_held_eligibility() { return $this->next; }
	};
	$GLOBALS['affilio_test_core'] = (object) array( 'referrals_db' => $queue, 'audit' => $audit );
	Affilio_Commission_Approval::request_handoff();
	wp_clear_scheduled_hook( Affilio_Commission_Approval::LEGACY_CRON_HOOK ); // Simulate WordPress consuming the single event.
	$approval->run_handoff_worker();
	$next_event = wp_next_scheduled( Affilio_Commission_Approval::LEGACY_CRON_HOOK );
	affilio_test_assert( $next_event >= time() + 590 && $next_event <= time() + 610, 'Future handoff date was not preserved in scheduling.' );

	wp_clear_scheduled_hook( Affilio_Commission_Approval::LEGACY_CRON_HOOK );
	Affilio_Test_Atomic_Lock::$acquire = false;
	$approval->run_handoff_worker();
	$retry_event = wp_next_scheduled( Affilio_Commission_Approval::LEGACY_CRON_HOOK );
	affilio_test_assert( $retry_event >= time() + Affilio_Commission_Approval::RETRY_DELAY - 1, 'Lock contention did not schedule a safe retry.' );
	Affilio_Test_Atomic_Lock::$acquire = true;

	$batch = new class() {
		public function get_eligible_ids( $limit ) { return range( 1, $limit ); }
		public function release_eligible_ids( $ids ) { return $ids; }
		public function get_next_held_eligibility() { return null; }
	};
	$batch_audit = new class() {
		public $count = 0;
		public function record() { return ++$this->count; }
	};
	$GLOBALS['affilio_test_core'] = (object) array( 'referrals_db' => $batch, 'audit' => $batch_audit );
	wp_clear_scheduled_hook( Affilio_Commission_Approval::LEGACY_CRON_HOOK );
	$batch_result = $approval->run_handoff_worker();
	affilio_test_assert( 500 === count( $batch_result ) && 500 === $batch_audit->count, 'Handoff worker did not stop at one bounded batch.' );
	affilio_test_assert( wp_next_scheduled( Affilio_Commission_Approval::LEGACY_CRON_HOOK ) <= time() + 2, 'A full batch did not schedule bounded continuation.' );
	Affilio_Commission_Approval::withdraw_handoff();

	$visits = new class() {
		public $result = false;
		public function anonymize_expired_ips() {
			return $this->result;
		}
	};
	$GLOBALS['affilio_test_core'] = (object) array( 'visits_db' => $visits );
	$privacy = new Affilio_Privacy();
	$GLOBALS['affilio_test_actions'] = array();
	$privacy->cleanup_expired_visit_ips();
	affilio_test_assert( empty( $GLOBALS['affilio_test_actions'] ), 'Failed retention mutation emitted a success signal.' );
	$visits->result = 0;
	$privacy->cleanup_expired_visit_ips();
	affilio_test_assert( empty( $GLOBALS['affilio_test_actions'] ), 'No-op retention mutation emitted a false success signal.' );
	$visits->result = 1;
	$privacy->cleanup_expired_visit_ips();
	affilio_test_assert( 1 === count( $GLOBALS['affilio_test_actions'] ) && 'retention' === $GLOBALS['affilio_test_actions'][0][1][0]['scope'], 'Successful retention mutation did not emit its minimal signal.' );

	$diagnostics = new Affilio_Diagnostics();
	$method      = new ReflectionMethod( $diagnostics, 'normalize_checks' );
	$method->setAccessible( true );
	$checks = $method->invoke(
		$diagnostics,
		array(
			array( 'status' => 'good', 'label' => '<b>First</b>', 'detail' => 'Safe', 'action_url' => 'https://example.test', 'action_label' => 'Open' ),
			'malformed',
			array( 'status' => 'unknown', 'label' => 'Second', 'detail' => '<script>detail</script>', 'action_url' => 'javascript:alert(1)' ),
			array( 'status' => array(), 'label' => 'Discard', 'detail' => 'Discard' ),
		)
	);
	affilio_test_assert( 2 === count( $checks ), 'Malformed diagnostics entries were not discarded.' );
	affilio_test_assert( 'First' === $checks[0]['label'] && 'Second' === $checks[1]['label'], 'Diagnostics order or text normalization changed.' );
	affilio_test_assert( 'warning' === $checks[1]['status'] && '' === $checks[1]['action_url'], 'Diagnostics status/URL normalization failed.' );

	$root        = dirname( __DIR__ );
	$diagnostic_source = file_get_contents( $root . '/includes/class-affilio-diagnostics.php' );
	$admin_source      = file_get_contents( $root . '/includes/class-affilio-admin-menu.php' );
	$admin_css         = file_get_contents( $root . '/assets/css/affilio-admin.css' );
	$frontend_css      = file_get_contents( $root . '/assets/css/affilio-frontend.css' );
	$registration_css  = file_get_contents( $root . '/assets/css/affilio-registration.css' );
	$login_css         = file_get_contents( $root . '/assets/css/affilio-login.css' );
	$login_source      = file_get_contents( $root . '/includes/class-affilio-login-branding.php' );
	$dashboard_source  = file_get_contents( $root . '/includes/class-affilio-dashboard.php' );
	$workflow_source   = file_get_contents( $root . '/includes/class-affilio-admin-workflows.php' );
	$coupon_source     = file_get_contents( $root . '/includes/class-affilio-coupons.php' );
	$affiliates_css    = file_get_contents( $root . '/assets/css/affilio-affiliates-admin.css' );
	$referrals_css     = file_get_contents( $root . '/assets/css/affilio-referrals-admin.css' );
	$coupons_css       = file_get_contents( $root . '/assets/css/affilio-coupons-admin.css' );
	$payout_requests_css = file_get_contents( $root . '/assets/css/affilio-payout-requests-admin.css' );
	$payouts_css       = file_get_contents( $root . '/assets/css/affilio-payouts-admin.css' );
	$creatives_css     = file_get_contents( $root . '/assets/css/affilio-creatives-admin.css' );
	$creatives_js      = file_get_contents( $root . '/assets/js/affilio-creatives-admin.js' );
	$creatives_source  = file_get_contents( $root . '/includes/class-affilio-creatives.php' );
	$overview_css      = file_get_contents( $root . '/assets/css/affilio-overview-admin.css' );
	$settings_css      = file_get_contents( $root . '/assets/css/affilio-settings-admin.css' );
	$woo_source        = file_get_contents( $root . '/includes/integrations/class-affilio-integration-woocommerce.php' );
	$referral_source   = file_get_contents( $root . '/includes/database/class-affilio-db-referrals.php' );
	$privacy_source    = file_get_contents( $root . '/includes/class-affilio-privacy.php' );
	$approval_source   = file_get_contents( $root . '/includes/class-affilio-commission-approval.php' );
	affilio_test_assert( false !== strpos( $diagnostic_source, 'Affilio_Capabilities::MANAGE_AFFILIATES' ), 'Diagnostics capability boundary changed.' );
	affilio_test_assert( false !== strpos( $diagnostic_source, 'esc_html( $check[' ) && false !== strpos( $diagnostic_source, 'esc_url( $check[' ), 'Diagnostics rendering escaping changed.' );
	affilio_test_assert( false !== strpos( $admin_source, 'action="options.php"' ) && false !== strpos( $admin_source, 'settings_fields( Affilio_Settings::OPTION_GROUP )' ), 'Settings API form boundary changed.' );
	affilio_test_assert( false !== strpos( $admin_css, '.wp-core-ui select:not([multiple]):not([size])' ) && false !== strpos( $admin_css, "stroke='%234f46e5'" ) && false !== strpos( $admin_css, '@media (forced-colors: active)' ), 'Shared admin dropdown cue or high-contrast fallback is missing.' );
	affilio_test_assert( false !== strpos( $frontend_css, '.affilio-dashboard select:not([multiple]):not([size])' ) && false !== strpos( $frontend_css, '.affilio-registration-form select:not([multiple]):not([size])' ) && false !== strpos( $frontend_css, '[dir="rtl"]' ), 'Frontend dropdown cue or RTL handling is missing.' );
	affilio_test_assert( false !== strpos( $registration_css, '.affilio-registration-form.affilio-registration-form--single-step.is-wizard-enhanced .affilio-registration-section-heading' ) && false !== strpos( $registration_css, '.is-registration-complete .affilio-registration-progress' ), 'Logged-in registration layout or completed-state focus styling is missing.' );
	affilio_test_assert( false !== strpos( $login_source, "const QUERY_ARG   = 'affilio_access'" ) && false !== strpos( $login_source, 'wp_new_user_notification_email' ) && false !== strpos( $login_source, 'retrieve_password_message' ) && false !== strpos( $login_css, 'body.affilio-login' ), 'Affiliate-scoped login and password branding is incomplete.' );
	affilio_test_assert( false !== strpos( $login_source, 'redirect_targets_affiliate_area' ) && false !== strpos( $dashboard_source, 'Affilio_Login_Branding::login_url' ), 'Affiliate dashboard login redirect does not preserve the branded account context.' );
	affilio_test_assert( false !== strpos( $dashboard_source, 'affilio-dashboard-access-primary' ) && false !== strpos( $frontend_css, '.affilio-dashboard-access-header' ), 'Premium signed-out affiliate dashboard entry is incomplete.' );
	affilio_test_assert( false !== strpos( $dashboard_source, 'affilio-application-review-steps' ) && false !== strpos( $dashboard_source, 'Affilio_Login_Branding::password_reset_url' ) && false !== strpos( $frontend_css, '.affilio-application-state-action-primary' ), 'Premium pending affiliate status experience is incomplete.' );
	affilio_test_assert( false !== strpos( $admin_source, 'do_settings_fields( $page, $section_id )' ) && false !== strpos( $admin_source, "wp_enqueue_style( 'affilio-settings-admin'" ), 'Settings fields or page-scoped asset registration changed.' );
	affilio_test_assert( false !== strpos( $settings_css, '.affilio-settings-wrap' ) && false !== strpos( $settings_css, '.affilio-settings-card.is-danger' ), 'Premium Settings layout or destructive-action treatment is missing.' );
	affilio_test_assert( false !== strpos( $admin_source, 'affilio-overview-progress' ) && false !== strpos( $admin_source, "wp_enqueue_style( 'affilio-overview-admin'" ), 'Overview readiness UI or page-scoped asset registration changed.' );
	affilio_test_assert( false !== strpos( $overview_css, '.affilio-overview-hero' ) && false !== strpos( $overview_css, '.affilio-quick-link__icon' ), 'Premium Overview workspace styling is missing.' );
	affilio_test_assert( false !== strpos( $admin_source, "wp_enqueue_style( 'affilio-affiliates-admin'" ) && false !== strpos( $workflow_source, 'affilio-affiliate-directory__form' ), 'Affiliates directory structure or page-scoped asset registration changed.' );
	affilio_test_assert( false !== strpos( $affiliates_css, '.affilio-affiliates-hero' ) && false !== strpos( $affiliates_css, '.affilio-affiliate-directory__table' ), 'Premium Affiliates directory styling is missing.' );
	affilio_test_assert( false !== strpos( $workflow_source, '$table->has_items()' ) && false !== strpos( $workflow_source, "'has-items' : 'is-empty'" ), 'Affiliates empty-state control boundary changed.' );
	affilio_test_assert( false !== strpos( $affiliates_css, '.affilio-affiliate-directory.is-empty' ) && false !== strpos( $affiliates_css, 'font-size: 0;' ), 'Affiliates filter separator or empty-state styling is missing.' );
	affilio_test_assert( false !== strpos( $admin_source, "wp_enqueue_style( 'affilio-referrals-admin'" ) && false !== strpos( $workflow_source, 'affilio-referral-ledger__form' ), 'Referrals workspace structure or page-scoped asset registration changed.' );
	affilio_test_assert( false !== strpos( $workflow_source, 'affilio-referral-advanced-filters' ) && false !== strpos( $workflow_source, 'name="source"' ) && false !== strpos( $workflow_source, 'name="campaign"' ), 'Referrals source or advanced-filter controls are missing.' );
	affilio_test_assert( false !== strpos( $referrals_css, '.affilio-referrals-hero' ) && false !== strpos( $referrals_css, '.affilio-referral-ledger.is-empty' ) && false !== strpos( $referrals_css, '@media screen and (max-width: 600px)' ), 'Premium responsive Referrals workspace styling is missing.' );
	affilio_test_assert( 3 <= substr_count( $workflow_source, 'class="affilio-referral-button-icon"' ) && false !== strpos( $referrals_css, '.affilio-referrals-wrap .affilio-referral-filter-actions .button' ) && false !== strpos( $referrals_css, 'white-space: nowrap;' ), 'Referrals primary action icon alignment is missing.' );
	affilio_test_assert( false !== strpos( $admin_source, "wp_enqueue_style( 'affilio-coupons-admin'" ) && false !== strpos( $coupon_source, 'affilio-coupon-workspace' ) && false !== strpos( $coupon_source, 'affilio-coupon-directory' ), 'Coupons workspace structure or page-scoped asset registration changed.' );
	affilio_test_assert( 4 <= substr_count( $coupon_source, 'class="affilio-coupon-button-icon"' ) && false !== strpos( $coupons_css, '.affilio-coupons-page .affilio-coupon-form__actions .button' ) && false !== strpos( $coupons_css, 'white-space: nowrap;' ), 'Coupons action icon alignment is missing.' );
	affilio_test_assert( false !== strpos( $coupon_source, "disabled( ! \$has_affiliates )" ) && false !== strpos( $coupon_source, 'Coupons assigned to different affiliates do not produce ambiguous attribution.' ), 'Coupons readiness or conflict guidance is missing.' );
	affilio_test_assert( false !== strpos( $coupon_source, '$empty_state_text' ) && false !== strpos( $coupon_source, 'affilio-coupon-empty-state__action' ) && false !== strpos( $coupons_css, '.affilio-coupons-page .affilio-coupon-empty-state__action' ), 'Coupons empty state does not follow assignment readiness.' );
	affilio_test_assert( false !== strpos( $coupons_css, '@media screen and (max-width: 600px)' ) && false !== strpos( $coupons_css, 'content: attr(data-label);' ), 'Premium responsive Coupons table styling is missing.' );
	affilio_test_assert( false !== strpos( $admin_source, "wp_enqueue_style( 'affilio-payout-requests-admin'" ) && false !== strpos( $workflow_source, 'affilio-payout-request-summary' ) && false !== strpos( $workflow_source, 'affilio-payout-request-queue' ), 'Payout Requests workspace structure or page-scoped asset registration changed.' );
	affilio_test_assert( 4 <= substr_count( $workflow_source, 'class="affilio-payout-request-button-icon"' ) && false !== strpos( $payout_requests_css, '.affilio-payout-requests-page .affilio-payout-request-actions .button' ) && false !== strpos( $payout_requests_css, 'white-space: nowrap;' ), 'Payout Requests action icon alignment is missing.' );
	affilio_test_assert( false !== strpos( $workflow_source, '$status_counts' ) && false !== strpos( $workflow_source, "in_array( \$status, \$statuses, true )" ) && false !== strpos( $workflow_source, 'min( $page, $total_pages )' ) && false !== strpos( $workflow_source, 'Approval revalidates the live balance.' ), 'Payout Requests lifecycle summary, pagination, or filter safety is missing.' );
	affilio_test_assert( false !== strpos( $payout_requests_css, '@media screen and (max-width: 900px)' ) && false !== strpos( $payout_requests_css, 'content: attr(data-label);' ), 'Premium responsive Payout Requests table styling is missing.' );
	affilio_test_assert( false !== strpos( $admin_source, "wp_enqueue_style( 'affilio-payouts-admin'" ) && false !== strpos( $admin_source, 'affilio-payout-directory' ) && false !== strpos( $admin_source, 'affilio-payout-actions__grid' ), 'Payouts workspace structure or page-scoped asset registration changed.' );
	affilio_test_assert( 6 <= substr_count( $admin_source, 'class="affilio-payout-button-icon"' ) && false !== strpos( $payouts_css, '.affilio-payout-action-button' ) && false !== strpos( $payouts_css, 'white-space: nowrap;' ), 'Payouts action icon alignment is missing.' );
	affilio_test_assert( false !== strpos( $payouts_css, '.affilio-payouts-hero' ) && false !== strpos( $payouts_css, '.affilio-payout-directory.is-empty' ) && false !== strpos( $payouts_css, 'content: attr(data-label);' ), 'Premium responsive Payouts workspace styling is missing.' );
	affilio_test_assert( false !== strpos( $admin_source, 'in_array( $status, $statuses, true )' ) && false !== strpos( $admin_source, 'Controlled payout ledger.' ) && false !== strpos( $admin_source, 'Payment changes remain auditable.' ), 'Payouts status safety or operational guidance is missing.' );
	$payout_list_source = file_get_contents( $root . '/includes/admin/class-affilio-payouts-list-table.php' );
	affilio_test_assert( false !== strpos( $payout_list_source, 'min( $current_page, $total_pages )' ) && false !== strpos( $payout_list_source, 'affilio-payout-empty-state__action' ), 'Payout list pagination or actionable empty-state safety is missing.' );
	affilio_test_assert( false !== strpos( $payouts_css, '.affilio-payout-views .subsubsub .count' ) && false !== strpos( $payouts_css, '.affilio-payout-directory.is-empty .affilio-payout-list-form .tablenav' ), 'Payout filter spacing or empty toolbar suppression is missing.' );
	affilio_test_assert( false !== strpos( $admin_source, "wp_enqueue_style( 'affilio-creatives-admin'" ) && false !== strpos( $admin_source, "wp_enqueue_script( 'affilio-creatives-admin'" ), 'Creative page-scoped assets are not registered.' );
	affilio_test_assert( false !== strpos( $creatives_source, 'affilio-creatives-hero' ) && false !== strpos( $creatives_source, 'affilio-creative-summary' ) && false !== strpos( $creatives_source, 'affilio-creative-workspace__grid' ), 'Premium Creative library or editor structure is missing.' );
	affilio_test_assert( 3 <= substr_count( $creatives_source, 'class="affilio-creative-button-icon"' ) && false !== strpos( $creatives_css, '.affilio-creative-button-icon' ) && false !== strpos( $creatives_css, 'white-space: nowrap;' ), 'Creative action icon alignment is missing.' );
	affilio_test_assert( false !== strpos( $creatives_source, 'is_same_site_url' ) && false !== strpos( $creatives_source, 'External images are not loaded in this admin preview for privacy.' ) && false !== strpos( $creatives_js, 'isSameSiteImage' ), 'Creative preview privacy boundary is missing.' );
	affilio_test_assert( false !== strpos( $referral_source, "'status_counts'" ) && false !== strpos( $referral_source, "'referral-summary-v3'" ), 'Referral lifecycle summary contract is missing or stale.' );
	affilio_test_assert( false !== strpos( $woo_source, 'Affilio_Commission_Approval::is_active_hold( $referral )' ), 'WooCommerce hold bypass guard is missing.' );
	affilio_test_assert( substr_count( $referral_source, "status = 'pending' AND payout_id IS NULL AND date_eligible IS NOT NULL" ) >= 3, 'Release repository payout safety is incomplete.' );
	affilio_test_assert( false !== strpos( $approval_source, "ReferralStatus::can_transition( 'pending', 'unpaid' )" ), 'Domain transition validation is missing.' );
	affilio_test_assert( false !== strpos( $approval_source, "'status_changed'" ) && false !== strpos( $approval_source, "do_action( 'affilio_referral_status_changed'" ), 'Release audit/event ownership is incomplete.' );
	affilio_test_assert( 2 === substr_count( $privacy_source, "'affilio_visit_identifiers_anonymized'" ) && false === strpos( $privacy_source, "'ip_address' =>" ) && false === strpos( $privacy_source, "'visitor_hash' =>" ), 'Privacy notification contract is incomplete or sensitive.' );

	$compatibility = new Affilio\Core\Compatibility( '2.1.2' );
	affilio_test_assert( $compatibility->supports_api( '1.2.0' ) && $compatibility->supports_api( '1.3.0' ) && ! $compatibility->supports_api( '2.0.0' ), 'Core API compatibility regression.' );

	$contract_hashes = array(
		'AnalyticsProvider.php'      => '1FF91D959C4C6D9A300EFFB0DBB346C5163DEE61762BBFE7FFF6A603EACF3B42',
		'CommissionRuleProvider.php' => '8DF90537945A344D4BC40B10B680E7EA52DA42CF4D834EBF861424240651C9DB',
		'EmailTemplateProvider.php'  => '1295123F1F42E3EBDC12AC0836E16A9CF767ED1AC936207CA402915716153094',
		'Extension.php'              => '09AC7AAE6C2C00DF1054270640238603F91BD14F1EEAFD46D78905AF6155324A',
		'FeatureProvider.php'        => 'C3BCEB969C8C22B6A7BE427C99AFFB5782AAE25A0341C250FD89B6102058208E',
		'FraudPolicyProvider.php'    => 'F6C7E9911577974DA412865B5337529C26FB77BB7F109B122611B8DA743CBA3D',
	);
	foreach ( $contract_hashes as $file => $hash ) {
		affilio_test_assert( $hash === strtoupper( hash_file( 'sha256', $root . '/src/Contracts/' . $file ) ), $file . ' changed unexpectedly.' );
	}

	affilio_test_assert( false !== strpos( file_get_contents( $root . '/dreamax-affiliates.php' ), "AFFILIO_DB_VERSION', '1.8'" ), 'Database version changed.' );

	echo 'PASS: ' . $GLOBALS['affilio_test_count'] . " Stage 2 checks.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
