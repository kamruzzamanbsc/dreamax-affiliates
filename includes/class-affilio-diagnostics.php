<?php
/**
 * Privacy-safe diagnostics and attribution test workflow.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides privacy-safe system checks and an attribution test workflow.
 */
class Affilio_Diagnostics {

	const PAGE_SLUG             = 'affilio-diagnostics';
	const TEST_TRANSIENT_PREFIX = 'affilio_attribution_test_';
	const TEST_CAMPAIGN_PREFIX  = 'affilio-diagnostic-';
	const TEST_EXPIRATION       = HOUR_IN_SECONDS;
	const ACTION_PREPARE_TEST   = 'affilio_prepare_attribution_test';
	const ACTION_VERIFY_TEST    = 'affilio_verify_attribution_test';
	const ACTION_CLEAR_TEST     = 'affilio_clear_attribution_test';

	/**
	 * Registers diagnostics request handlers.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION_PREPARE_TEST, array( $this, 'handle_prepare_test' ) );
		add_action( 'admin_post_' . self::ACTION_VERIFY_TEST, array( $this, 'handle_verify_test' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_TEST, array( $this, 'handle_clear_test' ) );
	}

	/**
	 * Renders the diagnostics screen.
	 *
	 * @return void
	 */
	public function render_page() {
		$this->assert_access();

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing.
		$tab = in_array( $tab, array( 'status', 'attribution' ), true ) ? $tab : 'status';
		?>
		<div class="wrap affilio-diagnostics-wrap">
			<hr class="wp-header-end">
			<header class="affilio-diagnostics-hero">
				<div class="affilio-diagnostics-hero__content"><span class="affilio-diagnostics-eyebrow"><?php esc_html_e( 'Operational assurance', 'dreamax-affiliates' ); ?></span><h1><?php esc_html_e( 'Diagnostics', 'dreamax-affiliates' ); ?></h1><p><?php esc_html_e( 'Review local system readiness and verify the real referral-attribution path from one privacy-safe workspace.', 'dreamax-affiliates' ); ?></p></div>
				<span class="affilio-diagnostics-hero__badge"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Local and private', 'dreamax-affiliates' ); ?></span>
			</header>
			<nav class="affilio-diagnostics-tabs" aria-label="<?php echo esc_attr__( 'Diagnostics sections', 'dreamax-affiliates' ); ?>">
				<a class="<?php echo 'status' === $tab ? 'is-active' : ''; ?>" <?php echo 'status' === $tab ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=status' ) ); ?>"><span class="dashicons dashicons-heart" aria-hidden="true"></span><span><strong><?php esc_html_e( 'System Status', 'dreamax-affiliates' ); ?></strong><small><?php esc_html_e( 'Readiness and integrations', 'dreamax-affiliates' ); ?></small></span></a>
				<a class="<?php echo 'attribution' === $tab ? 'is-active' : ''; ?>" <?php echo 'attribution' === $tab ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=attribution' ) ); ?>"><span class="dashicons dashicons-randomize" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Test Attribution', 'dreamax-affiliates' ); ?></strong><small><?php esc_html_e( 'Guided referral-path check', 'dreamax-affiliates' ); ?></small></span></a>
			</nav>
			<?php $this->render_notice(); ?>
			<?php 'attribution' === $tab ? $this->render_attribution_tab() : $this->render_status_tab(); ?>
		</div>
		<?php
	}

	/**
	 * Prepares a user-scoped attribution test.
	 *
	 * @return void
	 */
	public function handle_prepare_test() {
		$this->assert_access();
		check_admin_referer( self::ACTION_PREPARE_TEST );

		$affiliate_id = isset( $_POST['affiliate_id'] ) ? absint( wp_unslash( $_POST['affiliate_id'] ) ) : 0;
		$affiliate    = $affiliate_id ? affilio()->affiliates_db->get( $affiliate_id ) : null;

		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			$this->redirect( 'invalid_affiliate' );
		}

		$this->remove_test_data( $this->get_test_state(), false );
		$current_cookie = isset( $_COOKIE[ Affilio_Tracking::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Affilio_Tracking::COOKIE_NAME ] ) ) : '';
		if ( '' !== $current_cookie ) {
			$this->clear_tracking_cookie();
		}

		$campaign = self::TEST_CAMPAIGN_PREFIX . get_current_user_id() . '-' . strtolower( wp_generate_password( 12, false, false ) );
		$state    = array(
			'affiliate_id'  => (int) $affiliate->id,
			'referral_code' => (string) $affiliate->referral_code,
			'campaign'      => substr( sanitize_key( $campaign ), 0, 100 ),
			'created_at'    => time(),
			'verified_at'   => 0,
			'visit_id'      => 0,
			'status'        => 'prepared',
		);

		set_transient( $this->get_transient_key(), $state, self::TEST_EXPIRATION );
		affilio()->audit->record( 'diagnostic', (int) $affiliate->id, 'attribution_test_prepared', '', array( 'campaign' => $state['campaign'] ) );
		$this->redirect( 'prepared' );
	}

	/**
	 * Verifies that the current browser cookie resolves to the prepared visit.
	 *
	 * @return void
	 */
	public function handle_verify_test() {
		$this->assert_access();
		check_admin_referer( self::ACTION_VERIFY_TEST );

		$state = $this->get_test_state();
		if ( empty( $state ) ) {
			$this->redirect( 'expired' );
		}

		$visit = Affilio_Tracking::resolve_tracked_visit();
		if ( ! $visit || ! $this->visit_matches_state( $visit, $state ) ) {
			$this->redirect( 'not_detected' );
		}

		$state['status']      = 'verified';
		$state['visit_id']    = (int) $visit->id;
		$state['verified_at'] = time();
		set_transient( $this->get_transient_key(), $state, self::TEST_EXPIRATION );
		affilio()->audit->record( 'diagnostic', (int) $state['affiliate_id'], 'attribution_test_verified', '', array( 'visit_id' => (int) $visit->id ) );
		$this->redirect( 'verified' );
	}

	/**
	 * Removes test rows, state, and only a matching diagnostic cookie.
	 *
	 * @return void
	 */
	public function handle_clear_test() {
		$this->assert_access();
		check_admin_referer( self::ACTION_CLEAR_TEST );

		$state = $this->get_test_state();
		$this->remove_test_data( $state, true );
		delete_transient( $this->get_transient_key() );
		$this->redirect( 'cleared' );
	}

	/**
	 * Renders environment and configuration checks.
	 *
	 * @return void
	 */
	private function render_status_tab() {
		$checks  = $this->get_system_checks();
		$summary = array_count_values( array_column( $checks, 'status' ) );
		$total   = count( $checks );
		?>
		<section class="affilio-diagnostics-section" aria-labelledby="affilio-status-title">
			<div class="affilio-diagnostics-heading">
				<span class="affilio-diagnostics-heading__icon dashicons dashicons-shield-alt" aria-hidden="true"></span>
				<div><span class="affilio-diagnostics-eyebrow"><?php esc_html_e( 'Readiness overview', 'dreamax-affiliates' ); ?></span>
					<h2 id="affilio-status-title"><?php esc_html_e( 'System health checks', 'dreamax-affiliates' ); ?></h2>
					<p><?php esc_html_e( 'Checks are performed locally on this WordPress site. No report is transmitted automatically.', 'dreamax-affiliates' ); ?></p>
				</div>
			</div>

			<div class="affilio-status-summary" aria-label="<?php echo esc_attr__( 'Status summary', 'dreamax-affiliates' ); ?>">
				<article class="is-total"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><div><small><?php esc_html_e( 'Total checks', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $total ) ); ?></strong></div></article>
				<article class="is-good"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><small><?php esc_html_e( 'Healthy', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( (int) ( $summary['good'] ?? 0 ) ) ); ?></strong></div></article>
				<article class="is-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><small><?php esc_html_e( 'Warnings', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( (int) ( $summary['warning'] ?? 0 ) ) ); ?></strong></div></article>
				<article class="is-critical"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span><div><small><?php esc_html_e( 'Critical', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( (int) ( $summary['critical'] ?? 0 ) ) ); ?></strong></div></article>
			</div>

			<div class="affilio-diagnostics-boundary"><span class="dashicons dashicons-privacy" aria-hidden="true"></span><p><?php esc_html_e( 'Results are generated on this site and include extension-provided checks only through the normalized, append-only diagnostics boundary.', 'dreamax-affiliates' ); ?></p></div>

			<div class="affilio-status-list">
				<?php foreach ( $checks as $check ) : ?>
					<article class="affilio-status-row is-<?php echo esc_attr( $check['status'] ); ?>">
						<span class="affilio-status-row__icon dashicons <?php echo esc_attr( $this->status_icon( $check['status'] ) ); ?>" aria-hidden="true"></span>
						<div class="affilio-status-row__content"><div class="affilio-status-row__title"><h3><?php echo esc_html( $check['label'] ); ?></h3></div><p><?php echo esc_html( $check['detail'] ); ?></p></div>
						<span class="affilio-status-row__state"><?php echo esc_html( $this->status_label( $check['status'] ) ); ?></span>
						<?php if ( ! empty( $check['action_url'] ) && ! empty( $check['action_label'] ) ) : ?>
							<a class="button button-small affilio-status-row__action" href="<?php echo esc_url( $check['action_url'] ); ?>"><?php echo esc_html( $check['action_label'] ); ?><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="affilio-admin-card affilio-support-report" aria-labelledby="affilio-support-report-title">
			<div class="affilio-diagnostics-heading"><span class="affilio-diagnostics-heading__icon dashicons dashicons-media-text" aria-hidden="true"></span><div><span class="affilio-diagnostics-eyebrow"><?php esc_html_e( 'Support handoff', 'dreamax-affiliates' ); ?></span><h2 id="affilio-support-report-title"><?php esc_html_e( 'Privacy-safe support report', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Copy this report when requesting support. It excludes administrator email addresses, filesystem paths, database credentials, table prefixes, cookies, and visitor data.', 'dreamax-affiliates' ); ?></p></div></div>
			<div class="affilio-support-report__editor"><div class="affilio-support-report__label"><strong><?php esc_html_e( 'Redacted diagnostic summary', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Click inside to select the report.', 'dreamax-affiliates' ); ?></span></div><textarea class="large-text code" rows="13" readonly onclick="this.select();" aria-label="<?php echo esc_attr__( 'Dreamax Affiliates support report', 'dreamax-affiliates' ); ?>"><?php echo esc_textarea( $this->build_support_report( $checks ) ); ?></textarea></div>
		</section>
		<?php
	}

	/**
	 * Renders the real referral-path test.
	 *
	 * @return void
	 */
	private function render_attribution_tab() {
		$state      = $this->get_test_state();
		$affiliates = affilio()->affiliates_db->query_for_selector( 'active', 200 );
		?>
		<section class="affilio-diagnostics-section" aria-labelledby="affilio-attribution-title">
			<div class="affilio-diagnostics-heading">
				<span class="affilio-diagnostics-heading__icon dashicons dashicons-randomize" aria-hidden="true"></span>
				<div><span class="affilio-diagnostics-eyebrow"><?php esc_html_e( 'Guided verification', 'dreamax-affiliates' ); ?></span>
					<h2 id="affilio-attribution-title"><?php esc_html_e( 'Test the attribution path', 'dreamax-affiliates' ); ?></h2>
					<p><?php esc_html_e( 'Open a real referral link, write the normal secure attribution cookie, and confirm that it resolves to the expected visit.', 'dreamax-affiliates' ); ?></p>
				</div>
			</div>
			<p class="description affilio-diagnostics-attribution-note"><?php esc_html_e( 'Test visits use a dedicated diagnostic campaign and can be removed here. Preparing a test clears any existing Dreamax Affiliates attribution cookie in this administrator browser; it does not delete the earlier visit row.', 'dreamax-affiliates' ); ?></p>

			<?php if ( empty( $state ) ) : ?>
				<div class="affilio-admin-card">
					<h3><?php esc_html_e( '1. Choose an active affiliate', 'dreamax-affiliates' ); ?></h3>
					<?php if ( empty( $affiliates ) ) : ?>
						<p class="affilio-empty-state"><?php esc_html_e( 'Create or approve at least one affiliate before running the attribution test.', 'dreamax-affiliates' ); ?></p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Affilio_Admin_Menu::AFFILIATES_PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Manage Affiliates', 'dreamax-affiliates' ); ?></a>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_PREPARE_TEST ); ?>">
							<?php wp_nonce_field( self::ACTION_PREPARE_TEST ); ?>
							<label for="affilio-test-affiliate"><strong><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></strong></label>
							<select id="affilio-test-affiliate" name="affiliate_id" required>
								<option value=""><?php esc_html_e( 'Select an affiliate', 'dreamax-affiliates' ); ?></option>
								<?php foreach ( $affiliates as $affiliate ) : ?>
									<option value="<?php echo esc_attr( (int) $affiliate->id ); ?>"><?php echo esc_html( sprintf( '%1$s — %2$s', $affiliate->display_name ? $affiliate->display_name : $affiliate->user_email, $affiliate->referral_code ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php submit_button( __( 'Prepare Test', 'dreamax-affiliates' ), 'primary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php
				$test_url = add_query_arg(
					array(
						Affilio_Tracking::QUERY_VAR => $state['referral_code'],
						Affilio_Tracking::CAMPAIGN_QUERY_VAR => $state['campaign'],
					),
					home_url( '/' )
				);
				?>
				<div class="affilio-diagnostics-steps">
					<section class="affilio-admin-card">
						<h3><?php esc_html_e( '1. Open the test link', 'dreamax-affiliates' ); ?></h3>
						<?php /* translators: %s: the referral code generated for this diagnostic test. */ ?>
						<p><?php echo esc_html( sprintf( __( 'Expected referral code: %s', 'dreamax-affiliates' ), $state['referral_code'] ) ); ?></p>
						<?php /* translators: %s: the campaign label generated for this diagnostic test. */ ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Diagnostic campaign: %s', 'dreamax-affiliates' ), $state['campaign'] ) ); ?></p>
						<a class="button button-primary" href="<?php echo esc_url( $test_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Test Link', 'dreamax-affiliates' ); ?></a>
					</section>
					<section class="affilio-admin-card">
						<h3><?php esc_html_e( '2. Verify this browser', 'dreamax-affiliates' ); ?></h3>
						<p><?php esc_html_e( 'After the front-end page loads, return here and verify the secure cookie and visit record.', 'dreamax-affiliates' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_VERIFY_TEST ); ?>">
							<?php wp_nonce_field( self::ACTION_VERIFY_TEST ); ?>
							<?php submit_button( __( 'Verify Attribution', 'dreamax-affiliates' ), 'secondary', 'submit', false ); ?>
						</form>
						<?php if ( 'verified' === ( $state['status'] ?? '' ) ) : ?>
							<p class="affilio-test-success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Attribution verified for the expected affiliate and diagnostic campaign.', 'dreamax-affiliates' ); ?></p>
						<?php endif; ?>
					</section>
					<section class="affilio-admin-card">
						<h3><?php esc_html_e( '3. Remove test data', 'dreamax-affiliates' ); ?></h3>
						<p><?php esc_html_e( 'Clear the diagnostic visit, temporary test state, and matching test cookie after verification.', 'dreamax-affiliates' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEAR_TEST ); ?>">
							<?php wp_nonce_field( self::ACTION_CLEAR_TEST ); ?>
							<?php submit_button( __( 'Remove Test Data', 'dreamax-affiliates' ), 'delete', 'submit', false ); ?>
						</form>
					</section>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Returns local readiness checks.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_system_checks() {
		$checks = array();

		$checks[] = $this->check(
			version_compare( get_bloginfo( 'version' ), AFFILIO_MINIMUM_WORDPRESS_VERSION, '>=' ) ? 'good' : 'critical',
			__( 'WordPress version', 'dreamax-affiliates' ),
			/* translators: %1$s: installed WordPress version; %2$s: minimum WordPress version Dreamax Affiliates requires. */
			sprintf( __( 'Installed: %1$s. Required: %2$s or later.', 'dreamax-affiliates' ), get_bloginfo( 'version' ), AFFILIO_MINIMUM_WORDPRESS_VERSION ),
			admin_url( 'update-core.php' ),
			__( 'Open Updates', 'dreamax-affiliates' )
		);
		$checks[] = $this->check(
			version_compare( PHP_VERSION, AFFILIO_MINIMUM_PHP_VERSION, '>=' ) ? 'good' : 'critical',
			__( 'PHP version', 'dreamax-affiliates' ),
			/* translators: %1$s: installed PHP version; %2$s: minimum PHP version Dreamax Affiliates requires. */
			sprintf( __( 'Installed: %1$s. Required: %2$s or later.', 'dreamax-affiliates' ), PHP_VERSION, AFFILIO_MINIMUM_PHP_VERSION )
		);
		$checks[] = $this->check(
			affilio()->is_woocommerce_active() ? 'good' : 'warning',
			__( 'WooCommerce integration', 'dreamax-affiliates' ),
			affilio()->is_woocommerce_active() ? __( 'WooCommerce is active and the Dreamax Affiliates integration is loaded.', 'dreamax-affiliates' ) : __( 'WooCommerce is not active, so order attribution and commission creation cannot run.', 'dreamax-affiliates' ),
			admin_url( 'plugins.php' ),
			__( 'Open Plugins', 'dreamax-affiliates' )
		);

		$installed_version = (string) get_option( 'affilio_version', '' );
		$checks[]          = $this->check(
			AFFILIO_VERSION === $installed_version ? 'good' : 'warning',
			__( 'Plugin migration state', 'dreamax-affiliates' ),
			AFFILIO_VERSION === $installed_version
				/* translators: %s: currently installed plugin version. */
				? sprintf( __( 'Installed migration version matches %s.', 'dreamax-affiliates' ), AFFILIO_VERSION )
				/* translators: %1$s: version recorded in the database; %2$s: version of the currently loaded plugin code. */
				: sprintf( __( 'Stored version is %1$s while the loaded code is %2$s. Reload a Dreamax Affiliates admin page to allow bounded upgrades to continue.', 'dreamax-affiliates' ), $installed_version ? $installed_version : __( 'not recorded', 'dreamax-affiliates' ), AFFILIO_VERSION )
		);

		$db_version = (string) get_option( 'affilio_db_version', '' );
		$checks[]   = $this->check(
			AFFILIO_DB_VERSION === $db_version ? 'good' : 'critical',
			__( 'Database schema version', 'dreamax-affiliates' ),
			/* translators: %1$s: database schema version stored in the site's options; %2$s: schema version the loaded plugin code requires. */
			sprintf( __( 'Stored schema: %1$s. Required schema: %2$s.', 'dreamax-affiliates' ), $db_version ? $db_version : __( 'not recorded', 'dreamax-affiliates' ), AFFILIO_DB_VERSION )
		);

		$missing_tables = $this->get_missing_tables();
		$checks[]       = $this->check(
			empty( $missing_tables ) ? 'good' : 'critical',
			__( 'Dreamax Affiliates database tables', 'dreamax-affiliates' ),
			empty( $missing_tables )
				? __( 'All six required Dreamax Affiliates tables are present.', 'dreamax-affiliates' )
				/* translators: %s: number of required Dreamax Affiliates database tables that are missing. */
				: sprintf( __( '%s required table(s) could not be found.', 'dreamax-affiliates' ), Affilio_I18n::number( count( $missing_tables ) ) )
		);

		$registration = $this->get_page_check( Affilio_Onboarding::OPTION_REGISTRATION_PAGE, 'dreamax_affiliates_registration', __( 'Registration page', 'dreamax-affiliates' ) );
		$dashboard    = $this->get_page_check( Affilio_Onboarding::OPTION_DASHBOARD_PAGE, 'dreamax_affiliates_dashboard', __( 'Dashboard page', 'dreamax-affiliates' ) );
		$checks[]     = $registration;
		$checks[]     = $dashboard;

		$cron_disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$cron_scheduled = (bool) wp_next_scheduled( Affilio_Privacy::CLEANUP_HOOK );
		$cron_status    = $cron_scheduled && ! $cron_disabled ? 'good' : 'warning';
		$cron_detail    = $cron_scheduled ? __( 'The daily privacy-retention cleanup event is scheduled.', 'dreamax-affiliates' ) : __( 'The daily privacy-retention cleanup event is not currently scheduled.', 'dreamax-affiliates' );
		if ( $cron_disabled ) {
			$cron_detail .= ' ' . __( 'WordPress cron is disabled; confirm that the server runs wp-cron.php externally.', 'dreamax-affiliates' );
		}
		$checks[] = $this->check( $cron_status, __( 'Scheduled privacy cleanup', 'dreamax-affiliates' ), $cron_detail );

		$checks[] = $this->check(
			is_ssl() ? 'good' : 'warning',
			__( 'HTTPS', 'dreamax-affiliates' ),
			is_ssl() ? __( 'The current administrator request uses HTTPS.', 'dreamax-affiliates' ) : __( 'HTTPS is not detected on this request. Secure cookies are strongly recommended for production stores.', 'dreamax-affiliates' )
		);
		$checks[] = $this->check(
			current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ? 'good' : 'critical',
			__( 'Dreamax Affiliates administrator capability', 'dreamax-affiliates' ),
			current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ? __( 'The current administrator can manage the affiliate program.', 'dreamax-affiliates' ) : __( 'The current user is missing the required Dreamax Affiliates management capability.', 'dreamax-affiliates' )
		);

		$catalog_errors = affilio()->feature_catalog->validate();
		$checks[]       = $this->check(
			empty( $catalog_errors ) ? 'good' : 'critical',
			__( 'Free/Pro feature boundary', 'dreamax-affiliates' ),
			empty( $catalog_errors )
				? __( 'The local feature catalog is valid and the Free/Pro boundary can be read.', 'dreamax-affiliates' )
				/* translators: %s: number of feature-catalog validation errors detected. */
				: sprintf( __( '%s feature-catalog validation error(s) were detected.', 'dreamax-affiliates' ), Affilio_I18n::number( count( $catalog_errors ) ) )
		);

		/**
		 * Filters the final diagnostics collection so extensions may append checks.
		 *
		 * @since 2.1.3
		 *
		 * @param array $checks Free-owned normalized system checks.
		 */
		$filtered = apply_filters( 'affilio_diagnostics_system_checks', $checks );
		if ( ! is_array( $filtered ) ) {
			return $this->normalize_checks( $checks );
		}

		// This boundary is append-only: extensions cannot remove or rewrite
		// Free's own health checks by returning a shorter/modified collection.
		$contributions = array_slice( array_values( $filtered ), count( $checks ) );
		return $this->normalize_checks( array_merge( $checks, $contributions ) );
	}

	/**
	 * Returns a page/shortcode check.
	 *
	 * @param string $option_name Page option.
	 * @param string $shortcode   Required shortcode.
	 * @param string $label       Check label.
	 * @return array<string,string>
	 */
	private function get_page_check( $option_name, $shortcode, $label ) {
		$page_id = absint( get_option( $option_name, 0 ) );
		$post    = $page_id ? get_post( $page_id ) : null;
		$url     = admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG . '&step=2' );

		if ( ! $post ) {
			return $this->check( 'critical', $label, __( 'No valid page is selected.', 'dreamax-affiliates' ), $url, __( 'Review Pages', 'dreamax-affiliates' ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return $this->check( 'warning', $label, __( 'The selected page is not published.', 'dreamax-affiliates' ), get_edit_post_link( $page_id, 'raw' ), __( 'Edit Page', 'dreamax-affiliates' ) );
		}
		if ( ! has_shortcode( $post->post_content, $shortcode ) ) {
			/* translators: %s: required shortcode tag, e.g. "dreamax_affiliates_registration". */
			return $this->check( 'critical', $label, sprintf( __( 'The selected page is missing the [%s] shortcode.', 'dreamax-affiliates' ), $shortcode ), get_edit_post_link( $page_id, 'raw' ), __( 'Edit Page', 'dreamax-affiliates' ) );
		}

		/* translators: %s: required shortcode tag, e.g. "dreamax_affiliates_registration". */
		return $this->check( 'good', $label, sprintf( __( 'Published page contains the [%s] shortcode.', 'dreamax-affiliates' ), $shortcode ), get_permalink( $page_id ), __( 'View Page', 'dreamax-affiliates' ) );
	}

	/**
	 * Returns missing required custom tables without exposing their names.
	 *
	 * @return array<int,string>
	 */
	private function get_missing_tables() {
		global $wpdb;

		$services = array(
			affilio()->affiliates_db,
			affilio()->referrals_db,
			affilio()->visits_db,
			affilio()->payouts_db,
			affilio()->events_db,
			affilio()->payout_requests_db,
		);
		$missing  = array();
		foreach ( $services as $service ) {
			$table = $service->get_table_name();
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- local health check.
			if ( $table !== $found ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/**
	 * Builds a redacted text report.
	 *
	 * @param array<int,array<string,string>> $checks Status checks.
	 * @return string
	 */
	private function build_support_report( array $checks ) {
		$lines = array(
			'Dreamax Affiliates Support Report',
			'Generated: ' . current_time( 'mysql' ),
			'Dreamax Affiliates version: ' . AFFILIO_VERSION,
			'Dreamax Affiliates database version: ' . AFFILIO_DB_VERSION,
			'Dreamax Affiliates Core API: ' . AFFILIO_CORE_API_VERSION,
			'WordPress version: ' . get_bloginfo( 'version' ),
			'PHP version: ' . PHP_VERSION,
			'WooCommerce active: ' . ( affilio()->is_woocommerce_active() ? 'yes' : 'no' ),
			'Multisite: ' . ( is_multisite() ? 'yes' : 'no' ),
			'HTTPS detected: ' . ( is_ssl() ? 'yes' : 'no' ),
			'WP-Cron disabled: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'yes' : 'no' ),
			'',
			'Checks:',
		);
		foreach ( $checks as $check ) {
			$lines[] = sprintf( '- [%1$s] %2$s: %3$s', strtoupper( $check['status'] ), $check['label'], $check['detail'] );
		}
		$lines[] = '';
		$lines[] = 'Privacy: This report intentionally excludes URLs, emails, filesystem paths, database credentials, table prefixes, cookies, and visitor records.';
		return implode( "\n", $lines );
	}

	/**
	 * Creates a normalized status item.
	 *
	 * @param string $status       Status key.
	 * @param string $label        Human-readable check label.
	 * @param string $detail       Human-readable check result.
	 * @param string $action_url   Optional remediation URL.
	 * @param string $action_label Optional remediation link label.
	 * @return array<string,string>
	 */
	private function check( $status, $label, $detail, $action_url = '', $action_label = '' ) {
		return array(
			'status'       => in_array( $status, array( 'good', 'warning', 'critical' ), true ) ? $status : 'warning',
			'label'        => (string) $label,
			'detail'       => (string) $detail,
			'action_url'   => (string) $action_url,
			'action_label' => (string) $action_label,
		);
	}

	/**
	 * Normalizes all built-in and extension-contributed status checks.
	 *
	 * Array iteration order is preserved so appended checks render
	 * deterministically after the built-in collection.
	 *
	 * @param array $checks Untrusted filtered checks.
	 * @return array<int,array<string,string>>
	 */
	private function normalize_checks( array $checks ) {
		$normalized = array();
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['label'], $check['detail'] ) ) {
				continue;
			}

			$fields = array( 'status', 'label', 'detail', 'action_url', 'action_label' );
			foreach ( $fields as $field ) {
				if ( isset( $check[ $field ] ) && ! is_scalar( $check[ $field ] ) ) {
					continue 2;
				}
			}

			$status       = isset( $check['status'] ) ? sanitize_key( (string) $check['status'] ) : 'warning';
			$normalized[] = array(
				'status'       => in_array( $status, array( 'good', 'warning', 'critical' ), true ) ? $status : 'warning',
				'label'        => substr( sanitize_text_field( (string) $check['label'] ), 0, 200 ),
				'detail'       => substr( sanitize_text_field( (string) $check['detail'] ), 0, 1000 ),
				'action_url'   => isset( $check['action_url'] ) ? substr( esc_url_raw( (string) $check['action_url'] ), 0, 2000 ) : '',
				'action_label' => isset( $check['action_label'] ) ? substr( sanitize_text_field( (string) $check['action_label'] ), 0, 200 ) : '',
			);
		}

		return $normalized;
	}

	/**
	 * Returns the icon class for a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_icon( $status ) {
		if ( 'good' === $status ) {
			return 'dashicons-yes-alt';
		}
		if ( 'critical' === $status ) {
			return 'dashicons-dismiss';
		}
		return 'dashicons-warning';
	}

	/**
	 * Returns a human-readable status label.
	 *
	 * @param string $status Normalized status.
	 * @return string
	 */
	private function status_label( $status ) {
		if ( 'good' === $status ) {
			return __( 'Healthy', 'dreamax-affiliates' );
		}
		if ( 'critical' === $status ) {
			return __( 'Critical', 'dreamax-affiliates' );
		}
		return __( 'Warning', 'dreamax-affiliates' );
	}

	/**
	 * Removes visits for the exact diagnostic campaign and a matching cookie.
	 *
	 * @param array<string,mixed> $state       Current state.
	 * @param bool                $clear_cookie Whether to clear a matching cookie.
	 * @return void
	 */
	private function remove_test_data( $state, $clear_cookie ) {
		if ( empty( $state['campaign'] ) || 0 !== strpos( (string) $state['campaign'], self::TEST_CAMPAIGN_PREFIX ) ) {
			return;
		}

		$current_visit = $clear_cookie ? Affilio_Tracking::resolve_tracked_visit() : null;
		affilio()->visits_db->delete_by_campaign( (string) $state['campaign'] );
		if ( $clear_cookie && $current_visit && $this->visit_matches_state( $current_visit, $state ) ) {
			$this->clear_tracking_cookie();
		}
	}

	/**
	 * Checks a visit against the user-scoped test definition.
	 *
	 * @param object              $visit Visit row.
	 * @param array<string,mixed> $state Test state.
	 * @return bool
	 */
	private function visit_matches_state( $visit, array $state ) {
		return (int) ( $visit->affiliate_id ?? 0 ) === (int) ( $state['affiliate_id'] ?? 0 )
			&& hash_equals( (string) ( $state['campaign'] ?? '' ), (string) ( $visit->campaign ?? '' ) )
			&& hash_equals( (string) ( $state['referral_code'] ?? '' ), (string) ( $visit->referral_code ?? '' ) );
	}

	/**
	 * Expires only the Dreamax Affiliates tracking cookie.
	 *
	 * @return void
	 */
	private function clear_tracking_cookie() {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			Affilio_Tracking::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ Affilio_Tracking::COOKIE_NAME ] );
	}

	/**
	 * Shows a bounded notice selected from known keys.
	 *
	 * @return void
	 */
	private function render_notice() {
		$key     = isset( $_GET['affilio_notice'] ) ? sanitize_key( wp_unslash( $_GET['affilio_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect notice only.
		$notices = array(
			'prepared'          => array( 'info', __( 'The attribution test is ready. Open the test link, then return and verify it.', 'dreamax-affiliates' ) ),
			'verified'          => array( 'success', __( 'Attribution passed: the secure cookie resolved to the expected affiliate and diagnostic visit.', 'dreamax-affiliates' ) ),
			'cleared'           => array( 'success', __( 'Diagnostic visit data and temporary test state were removed.', 'dreamax-affiliates' ) ),
			'invalid_affiliate' => array( 'error', __( 'Choose an active affiliate before preparing the test.', 'dreamax-affiliates' ) ),
			'expired'           => array( 'warning', __( 'The attribution test expired. Prepare a new test and try again.', 'dreamax-affiliates' ) ),
			'not_detected'      => array( 'warning', __( 'The expected attribution was not detected. Open the prepared link in this browser, allow cookies, and verify again.', 'dreamax-affiliates' ) ),
		);
		if ( ! isset( $notices[ $key ] ) ) {
			return;
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $key ][0] ), esc_html( $notices[ $key ][1] ) );
	}

	/**
	 * Returns the current user's test state.
	 *
	 * @return array<string,mixed>
	 */
	private function get_test_state() {
		$state = get_transient( $this->get_transient_key() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Returns the user-scoped transient key.
	 *
	 * @return string
	 */
	private function get_transient_key() {
		return self::TEST_TRANSIENT_PREFIX . get_current_user_id();
	}

	/**
	 * Redirects back to the attribution tab.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect( $notice ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=attribution&affilio_notice=' . sanitize_key( $notice ) ) );
		exit;
	}

	/**
	 * Enforces the dedicated program-management capability.
	 *
	 * @return void
	 */
	private function assert_access() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to access Dreamax Affiliates diagnostics.', 'dreamax-affiliates' ) );
		}
	}
}
