<?php
/**
 * Registers and renders Dreamax Affiliates admin screens.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers menu entries and renders the core administration screens.
 */
class Affilio_Admin_Menu {

	const AFFILIATES_PAGE_SLUG = 'affilio-affiliates';
	const REFERRALS_PAGE_SLUG  = 'affilio-referrals';

	/**
	 * Registers the administration hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_admin_assets' ) );
	}

	/**
	 * Registers the top-level menu and submenus.
	 *
	 * @return void
	 */
	public function register_menu() {
		$capability = Affilio_Capabilities::MANAGE_AFFILIATES;
		$onboarding = affilio()->service( 'admin.onboarding' );

		add_menu_page(
			__( 'Dreamax Affiliates', 'dreamax-affiliates' ),
			__( 'Dreamax Affiliates', 'dreamax-affiliates' ),
			$capability,
			'affilio',
			array( $this, 'render_overview_page' ),
			'dashicons-groups',
			56
		);

		add_submenu_page( 'affilio', __( 'Dreamax Affiliates Overview', 'dreamax-affiliates' ), __( 'Overview', 'dreamax-affiliates' ), $capability, 'affilio', array( $this, 'render_overview_page' ) );
		if ( $onboarding instanceof Affilio_Onboarding ) {
			add_submenu_page( 'affilio', __( 'Dreamax Affiliates Setup', 'dreamax-affiliates' ), __( 'Setup', 'dreamax-affiliates' ), $capability, Affilio_Onboarding::PAGE_SLUG, array( $onboarding, 'render_setup_page' ) );
		}
		add_submenu_page( 'affilio', __( 'Affiliates', 'dreamax-affiliates' ), __( 'Affiliates', 'dreamax-affiliates' ), $capability, self::AFFILIATES_PAGE_SLUG, array( affilio()->service( 'admin.workflows' ), 'render_affiliates' ) );
		add_submenu_page( 'affilio', __( 'Referrals', 'dreamax-affiliates' ), __( 'Referrals', 'dreamax-affiliates' ), $capability, self::REFERRALS_PAGE_SLUG, array( affilio()->service( 'admin.workflows' ), 'render_referrals' ) );
		add_submenu_page( 'affilio', __( 'Reports', 'dreamax-affiliates' ), __( 'Reports', 'dreamax-affiliates' ), $capability, 'affilio-reports', array( $this, 'render_reports_page' ) );
		add_submenu_page( 'affilio', __( 'Affiliate Coupons', 'dreamax-affiliates' ), __( 'Coupons', 'dreamax-affiliates' ), $capability, 'affilio-coupons', array( affilio()->coupons, 'render_admin_page' ) );
		add_submenu_page( 'affilio', __( 'Payout Requests', 'dreamax-affiliates' ), __( 'Payout Requests', 'dreamax-affiliates' ), $capability, 'affilio-payout-requests', array( affilio()->service( 'admin.workflows' ), 'render_payout_requests' ) );
		add_submenu_page( 'affilio', __( 'Payouts', 'dreamax-affiliates' ), __( 'Payouts', 'dreamax-affiliates' ), $capability, 'affilio-payouts', array( $this, 'render_payouts_page' ) );

		$diagnostics = affilio()->service( 'admin.diagnostics' );
		if ( $diagnostics instanceof Affilio_Diagnostics ) {
			add_submenu_page( 'affilio', __( 'Dreamax Affiliates Diagnostics', 'dreamax-affiliates' ), __( 'Diagnostics', 'dreamax-affiliates' ), $capability, Affilio_Diagnostics::PAGE_SLUG, array( $diagnostics, 'render_page' ) );
		}

		$upgrade_page = affilio()->service( 'admin.upgrade_page' );
		if ( $upgrade_page instanceof Affilio_Upgrade_Page ) {
			add_submenu_page( 'affilio', __( 'Dreamax Affiliates Pro', 'dreamax-affiliates' ), __( 'Dreamax Affiliates Pro', 'dreamax-affiliates' ), $capability, Affilio_Upgrade_Page::PAGE_SLUG, array( $upgrade_page, 'render_page' ) );
		}

		add_submenu_page( 'affilio', __( 'Settings', 'dreamax-affiliates' ), __( 'Settings', 'dreamax-affiliates' ), $capability, Affilio_Settings::PAGE_SLUG, array( $this, 'render_settings_page' ) );
	}

	/**
	 * Renders the beginner-friendly program overview and next actions.
	 *
	 * Legacy affiliate edit links that used the former top-level route are
	 * still handed to the affiliate workflow instead of becoming dead links.
	 *
	 * @return void
	 */
	public function render_overview_page() {
		$legacy_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $legacy_view, array( 'add', 'edit' ), true ) || isset( $_GET['affiliate_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			affilio()->service( 'admin.workflows' )->render_affiliates();
			return;
		}

		$affiliate_counts = affilio()->affiliates_db->get_status_counts();
		$active_count     = (int) ( $affiliate_counts['active'] ?? 0 );
		$pending_count    = (int) ( $affiliate_counts['pending'] ?? 0 );
		$affiliate_total  = array_sum( array_map( 'intval', $affiliate_counts ) );
		$referral_count   = affilio()->referrals_db->count();
		$click_count      = affilio()->visits_db->count();
		$request_count    = affilio()->payout_requests_db->count( array( 'status' => 'requested' ) );
		$registration     = $this->get_program_page_state( Affilio_Onboarding::OPTION_REGISTRATION_PAGE, 'dreamax_affiliates_registration' );
		$dashboard        = $this->get_program_page_state( Affilio_Onboarding::OPTION_DASHBOARD_PAGE, 'dreamax_affiliates_dashboard' );
		$setup_complete   = (bool) get_option( Affilio_Onboarding::OPTION_COMPLETED, false );
		$woocommerce      = affilio()->is_woocommerce_active();
		$commission_type  = get_option( 'affilio_default_commission_type', 'percentage' );
		$commission_rate  = (float) get_option( 'affilio_default_commission_rate', 20 );
		$commission_label = 'flat' === $commission_type
			? Affilio_I18n::amount( $commission_rate, function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' )
			: Affilio_I18n::percentage( $commission_rate, 2 );
		$readiness_total  = 4;
		$readiness_count  = (int) $setup_complete + (int) $registration['ready'] + (int) $dashboard['ready'] + (int) $woocommerce;
		$attention_total  = $pending_count + $request_count;
		$program_ready    = $readiness_total === $readiness_count;
		?>
		<div class="wrap affilio-overview-wrap">
			<hr class="wp-header-end">
			<header class="affilio-overview-hero">
				<div class="affilio-overview-hero__content"><span class="affilio-overview-eyebrow"><?php esc_html_e( 'Affiliate operations', 'dreamax-affiliates' ); ?></span><h1><?php esc_html_e( 'Program Overview', 'dreamax-affiliates' ); ?></h1><p><?php esc_html_e( 'Monitor readiness, review activity, and move directly to the next operational task from one trusted workspace.', 'dreamax-affiliates' ); ?></p>
					<div class="affilio-overview-actions">
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG ) ); ?>"><span><?php echo $setup_complete ? esc_html__( 'Review Setup', 'dreamax-affiliates' ) : esc_html__( 'Continue Setup', 'dreamax-affiliates' ); ?></span><svg class="affilio-button-arrow" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M5.75 3.5 10.25 8l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Manage Affiliates', 'dreamax-affiliates' ); ?></a>
				<?php
				if ( $registration['url'] ) :
					?>
						<a class="button" href="<?php echo esc_url( $registration['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Registration Page', 'dreamax-affiliates' ); ?></a><?php endif; ?>
					</div>
				</div>
				<span class="affilio-overview-hero__badge is-<?php echo $program_ready ? 'ready' : 'attention'; ?>"><span class="dashicons <?php echo esc_attr( $program_ready ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>" aria-hidden="true"></span><?php echo $program_ready ? esc_html__( 'Launch ready', 'dreamax-affiliates' ) : esc_html__( 'Setup attention', 'dreamax-affiliates' ); ?></span>
			</header>

			<section class="affilio-overview-snapshot" aria-labelledby="affilio-overview-snapshot-title"><div class="affilio-overview-section-heading"><span class="affilio-overview-section-heading__icon dashicons dashicons-chart-area" aria-hidden="true"></span><div><span class="affilio-overview-eyebrow"><?php esc_html_e( 'Live snapshot', 'dreamax-affiliates' ); ?></span><h2 id="affilio-overview-snapshot-title"><?php esc_html_e( 'Current program performance', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Core counts update from the current affiliate, visit, referral, and configuration records.', 'dreamax-affiliates' ); ?></p></div></div>
			<div class="affilio-overview-grid affilio-overview-metrics" aria-label="<?php echo esc_attr__( 'Affiliate program summary', 'dreamax-affiliates' ); ?>">
				<?php /* translators: %1$s: number of active affiliates; %2$s: number of affiliates awaiting review. */ ?>
				<?php $this->render_overview_metric( __( 'Affiliates', 'dreamax-affiliates' ), Affilio_I18n::number( $affiliate_total ), sprintf( __( '%1$s active, %2$s awaiting review', 'dreamax-affiliates' ), Affilio_I18n::number( $active_count ), Affilio_I18n::number( $pending_count ) ), admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG ), 'dashicons-groups', 'brand' ); ?>
				<?php $this->render_overview_metric( __( 'Tracked Clicks', 'dreamax-affiliates' ), Affilio_I18n::number( $click_count ), __( 'All recorded affiliate visits', 'dreamax-affiliates' ), admin_url( 'admin.php?page=affilio-reports' ), 'dashicons-admin-links', 'brand' ); ?>
				<?php $this->render_overview_metric( __( 'Referrals', 'dreamax-affiliates' ), Affilio_I18n::number( $referral_count ), __( 'Recorded commissions and conversions', 'dreamax-affiliates' ), admin_url( 'admin.php?page=affilio-referrals' ), 'dashicons-networking', 'good' ); ?>
				<?php $this->render_overview_metric( __( 'Commission Rule', 'dreamax-affiliates' ), $commission_label, 'flat' === $commission_type ? __( 'Flat amount per order', 'dreamax-affiliates' ) : __( 'Percentage of the commission base', 'dreamax-affiliates' ), admin_url( 'admin.php?page=' . Affilio_Settings::PAGE_SLUG ), 'dashicons-money-alt', 'good' ); ?>
			</div></section>

			<div class="affilio-overview-grid affilio-overview-columns">
				<section class="affilio-admin-card affilio-overview-card" aria-labelledby="affilio-launch-checklist-title">
					<div class="affilio-overview-card__heading">
						<span class="affilio-overview-card__icon dashicons dashicons-clipboard" aria-hidden="true"></span>
						<div><span class="affilio-overview-eyebrow"><?php esc_html_e( 'Launch assurance', 'dreamax-affiliates' ); ?></span><h2 id="affilio-launch-checklist-title"><?php esc_html_e( 'Readiness checklist', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Complete these essentials before inviting affiliates.', 'dreamax-affiliates' ); ?></p></div>
						<span class="affilio-overview-count"><?php echo esc_html( Affilio_I18n::number( $readiness_count ) . ' / ' . Affilio_I18n::number( $readiness_total ) ); ?></span>
					</div>
					<progress class="affilio-overview-progress" value="<?php echo esc_attr( $readiness_count ); ?>" max="<?php echo esc_attr( $readiness_total ); ?>"><?php echo esc_html( Affilio_I18n::number( $readiness_count ) . ' / ' . Affilio_I18n::number( $readiness_total ) ); ?></progress>
					<ul class="affilio-checklist">
						<?php $this->render_checklist_item( $setup_complete, __( 'Core setup saved', 'dreamax-affiliates' ), admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG ) ); ?>
						<?php $this->render_checklist_item( $registration['ready'], __( 'Registration page is published and contains its shortcode', 'dreamax-affiliates' ), $registration['edit_url'] ); ?>
						<?php $this->render_checklist_item( $dashboard['ready'], __( 'Dashboard page is published and contains its shortcode', 'dreamax-affiliates' ), $dashboard['edit_url'] ); ?>
						<?php $this->render_checklist_item( $woocommerce, __( 'WooCommerce is active for sale attribution', 'dreamax-affiliates' ), admin_url( 'plugins.php' ) ); ?>
					</ul>
				</section>

				<section class="affilio-admin-card affilio-overview-card" aria-labelledby="affilio-attention-title">
					<div class="affilio-overview-card__heading">
						<span class="affilio-overview-card__icon dashicons dashicons-bell" aria-hidden="true"></span>
						<div><span class="affilio-overview-eyebrow"><?php esc_html_e( 'Review queue', 'dreamax-affiliates' ); ?></span><h2 id="affilio-attention-title"><?php esc_html_e( 'Needs attention', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Keep applications and payout requests moving.', 'dreamax-affiliates' ); ?></p></div>
						<span class="affilio-overview-count <?php echo 0 === $attention_total ? 'is-clear' : 'has-attention'; ?>"><?php echo esc_html( Affilio_I18n::number( $attention_total ) ); ?></span>
					</div>
					<?php if ( 0 === $pending_count && 0 === $request_count ) : ?>
						<p class="affilio-empty-state"><?php esc_html_e( 'There are no pending affiliate applications or payout requests right now.', 'dreamax-affiliates' ); ?></p>
					<?php else : ?>
						<ul class="affilio-task-list">
							<?php
							if ( $pending_count > 0 ) :
								?>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG . '&status=pending' ) ); ?>">
								<?php
								/* translators: %s: number of pending affiliate applications. */
								echo esc_html( sprintf( _n( '%s affiliate application is waiting for review', '%s affiliate applications are waiting for review', $pending_count, 'dreamax-affiliates' ), Affilio_I18n::number( $pending_count ) ) );
								?>
								</a></li><?php endif; ?>
							<?php
							if ( $request_count > 0 ) :
								?>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-payout-requests&status=requested' ) ); ?>">
								<?php
								/* translators: %s: number of pending payout requests. */
								echo esc_html( sprintf( _n( '%s payout request is waiting', '%s payout requests are waiting', $request_count, 'dreamax-affiliates' ), Affilio_I18n::number( $request_count ) ) );
								?>
								</a></li><?php endif; ?>
						</ul>
					<?php endif; ?>
					<div class="affilio-overview-next-action">
					<span class="affilio-overview-eyebrow"><?php esc_html_e( 'Recommended next step', 'dreamax-affiliates' ); ?></span>
					<h3><?php esc_html_e( 'Helpful next action', 'dreamax-affiliates' ); ?></h3>
					<?php if ( ! $setup_complete || ! $registration['ready'] || ! $dashboard['ready'] ) : ?>
						<p><?php esc_html_e( 'Finish the setup checklist so affiliates have a working application and dashboard.', 'dreamax-affiliates' ); ?></p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Open Setup', 'dreamax-affiliates' ); ?></a>
					<?php elseif ( 0 === $active_count ) : ?>
						<p><?php esc_html_e( 'Invite your first affiliate or add an existing WordPress user manually.', 'dreamax-affiliates' ); ?></p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG . '&view=add' ) ); ?>"><?php esc_html_e( 'Add Affiliate', 'dreamax-affiliates' ); ?></a>
					<?php elseif ( 0 === $click_count ) : ?>
						<p><?php esc_html_e( 'Open the affiliate dashboard and test one referral link before sharing the program publicly.', 'dreamax-affiliates' ); ?></p>
						<?php
						if ( $dashboard['url'] ) :
							?>
							<a class="button" href="<?php echo esc_url( $dashboard['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Test Dashboard', 'dreamax-affiliates' ); ?></a><?php endif; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'Review performance regularly and process eligible commissions through the manual payout workflow.', 'dreamax-affiliates' ); ?></p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-reports' ) ); ?>"><?php esc_html_e( 'View Reports', 'dreamax-affiliates' ); ?></a>
					<?php endif; ?>
					</div>
				</section>
			</div>

			<section class="affilio-admin-card affilio-quick-links" aria-labelledby="affilio-quick-links-title">
				<div class="affilio-overview-section-heading"><span class="affilio-overview-section-heading__icon dashicons dashicons-performance" aria-hidden="true"></span><div><span class="affilio-overview-eyebrow"><?php esc_html_e( 'Operational shortcuts', 'dreamax-affiliates' ); ?></span><h2 id="affilio-quick-links-title"><?php esc_html_e( 'Quick actions', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Open the most-used affiliate workflows without leaving your operating context.', 'dreamax-affiliates' ); ?></p></div></div>
				<div class="affilio-quick-link-grid">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG ) ); ?>"><span class="affilio-quick-link__icon dashicons dashicons-groups" aria-hidden="true"></span><span class="affilio-quick-link__content"><strong><?php esc_html_e( 'Affiliates', 'dreamax-affiliates' ); ?></strong><small><?php esc_html_e( 'Approve applications and manage accounts.', 'dreamax-affiliates' ); ?></small></span><span class="affilio-quick-link__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals' ) ); ?>"><span class="affilio-quick-link__icon dashicons dashicons-networking" aria-hidden="true"></span><span class="affilio-quick-link__content"><strong><?php esc_html_e( 'Referrals', 'dreamax-affiliates' ); ?></strong><small><?php esc_html_e( 'Review commissions or add a manual referral.', 'dreamax-affiliates' ); ?></small></span><span class="affilio-quick-link__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-reports' ) ); ?>"><span class="affilio-quick-link__icon dashicons dashicons-chart-area" aria-hidden="true"></span><span class="affilio-quick-link__content"><strong><?php esc_html_e( 'Reports', 'dreamax-affiliates' ); ?></strong><small><?php esc_html_e( 'See clicks, conversions, and earnings.', 'dreamax-affiliates' ); ?></small></span><span class="affilio-quick-link__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-payouts' ) ); ?>"><span class="affilio-quick-link__icon dashicons dashicons-money-alt" aria-hidden="true"></span><span class="affilio-quick-link__content"><strong><?php esc_html_e( 'Payouts', 'dreamax-affiliates' ); ?></strong><small><?php esc_html_e( 'Create batches and record completed payments.', 'dreamax-affiliates' ); ?></small></span><span class="affilio-quick-link__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Affilio_Creatives::POST_TYPE ) ); ?>"><span class="affilio-quick-link__icon dashicons dashicons-format-image" aria-hidden="true"></span><span class="affilio-quick-link__content"><strong><?php esc_html_e( 'Creatives', 'dreamax-affiliates' ); ?></strong><small><?php esc_html_e( 'Publish reusable banners and campaign links.', 'dreamax-affiliates' ); ?></small></span><span class="affilio-quick-link__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Affilio_Settings::PAGE_SLUG ) ); ?>"><span class="affilio-quick-link__icon dashicons dashicons-admin-generic" aria-hidden="true"></span><span class="affilio-quick-link__content"><strong><?php esc_html_e( 'Settings', 'dreamax-affiliates' ); ?></strong><small><?php esc_html_e( 'Adjust tracking, commissions, emails, and privacy.', 'dreamax-affiliates' ); ?></small></span><span class="affilio-quick-link__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders one overview metric card.
	 *
	 * @param string $label Metric label.
	 * @param string $value Metric value.
	 * @param string $description Metric explanation.
	 * @param string $url Destination URL.
	 * @param string $icon Dashicon class.
	 * @param string $tone Visual tone.
	 * @return void
	 */
	private function render_overview_metric( $label, $value, $description, $url, $icon, $tone ) {
		?>
		<a class="affilio-overview-metric is-<?php echo esc_attr( $tone ); ?>" href="<?php echo esc_url( $url ); ?>">
			<span class="affilio-overview-metric__icon dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<span class="affilio-overview-metric__content"><span class="affilio-overview-metric-label"><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong><small><?php echo esc_html( $description ); ?></small></span>
			<span class="affilio-overview-metric__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
		</a>
		<?php
	}

	/**
	 * Renders one setup checklist item.
	 *
	 * @param bool   $complete Whether the item is complete.
	 * @param string $label Item label.
	 * @param string $url Destination URL.
	 * @return void
	 */
	private function render_checklist_item( $complete, $label, $url ) {
		?>
		<li class="<?php echo esc_attr( $complete ? 'is-complete' : 'needs-action' ); ?>">
			<span class="affilio-checklist__icon <?php echo esc_attr( 'dashicons ' . ( $complete ? 'dashicons-yes-alt' : 'dashicons-marker' ) ); ?>" aria-hidden="true"></span>
			<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<span class="affilio-checklist__state"><?php echo $complete ? esc_html__( 'Ready', 'dreamax-affiliates' ) : esc_html__( 'Review', 'dreamax-affiliates' ); ?></span>
		</li>
		<?php
	}

	/**
	 * Returns publication and shortcode health for one program page.
	 *
	 * @param string $option Page ID option.
	 * @param string $shortcode Required shortcode tag.
	 * @return array{ready:bool,url:string,edit_url:string}
	 */
	private function get_program_page_state( $option, $shortcode ) {
		$page_id  = absint( get_option( $option, 0 ) );
		$page     = $page_id ? get_post( $page_id ) : null;
		$ready    = $page && 'page' === $page->post_type && 'publish' === $page->post_status && has_shortcode( $page->post_content, $shortcode );
		$url      = $page_id ? get_permalink( $page_id ) : '';
		$edit_url = $page_id ? get_edit_post_link( $page_id, 'raw' ) : admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG . '&step=2' );

		return array(
			'ready'    => (bool) $ready,
			'url'      => $url ? $url : '',
			'edit_url' => $edit_url ? $edit_url : admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG . '&step=2' ),
		);
	}

	/**
	 * Renders affiliate list or affiliate payout-profile edit screen.
	 *
	 * @return void
	 */
	public function render_affiliates_page() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $view ) {
			$this->render_affiliate_edit_page();
			return;
		}

		require_once AFFILIO_PLUGIN_DIR . 'includes/admin/class-affilio-affiliates-list-table.php';
		$table = new Affilio_Affiliates_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Affiliates', 'dreamax-affiliates' ); ?></h1>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="affilio-affiliates">
				<?php $table->search_box( __( 'Search affiliates', 'dreamax-affiliates' ), 'affilio-affiliates' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the affiliate commission and payout-profile edit form.
	 *
	 * @return void
	 */
	private function render_affiliate_edit_page() {
		$affiliate_id = isset( $_GET['affiliate_id'] ) ? absint( $_GET['affiliate_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$affiliate    = $affiliate_id ? affilio()->affiliates_db->get( $affiliate_id ) : null;

		if ( ! $affiliate ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Affiliate not found', 'dreamax-affiliates' ) . '</h1></div>';
			return;
		}

		$user            = get_userdata( $affiliate->user_id );
		$methods         = affilio()->payouts->get_payout_methods();
		$commission_type = ! empty( $affiliate->commission_type ) ? $affiliate->commission_type : 'inherit';
		$commission_rate = isset( $affiliate->commission_rate ) && null !== $affiliate->commission_rate ? $affiliate->commission_rate : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Edit Affiliate', 'dreamax-affiliates' ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-affiliates' ) ); ?>">&larr; <?php esc_html_e( 'Back to affiliates', 'dreamax-affiliates' ); ?></a></p>
			<p><strong><?php echo esc_html( $user ? $user->display_name : __( '(deleted user)', 'dreamax-affiliates' ) ); ?></strong></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="affilio_admin_save_payout_profile">
				<input type="hidden" name="affiliate_id" value="<?php echo esc_attr( $affiliate->id ); ?>">
				<?php wp_nonce_field( 'affilio_admin_save_payout_profile_' . $affiliate->id ); ?>

				<h2><?php esc_html_e( 'Commission', 'dreamax-affiliates' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="affilio-admin-commission-type"><?php esc_html_e( 'Commission rule', 'dreamax-affiliates' ); ?></label></th>
						<td>
							<select id="affilio-admin-commission-type" name="commission_type">
								<option value="inherit" <?php selected( $commission_type, 'inherit' ); ?>><?php esc_html_e( 'Use site default', 'dreamax-affiliates' ); ?></option>
								<option value="percentage" <?php selected( $commission_type, 'percentage' ); ?>><?php esc_html_e( 'Percentage override', 'dreamax-affiliates' ); ?></option>
								<option value="flat" <?php selected( $commission_type, 'flat' ); ?>><?php esc_html_e( 'Flat amount per order', 'dreamax-affiliates' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Product and category rules take priority over this affiliate-level rule.', 'dreamax-affiliates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="affilio-admin-commission-rate"><?php esc_html_e( 'Commission rate', 'dreamax-affiliates' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" id="affilio-admin-commission-rate" name="commission_rate" value="<?php echo esc_attr( $commission_rate ); ?>">
							<p class="description"><?php esc_html_e( 'Enter percentage points or a flat order amount. Ignored when using the site default.', 'dreamax-affiliates' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Payout Details', 'dreamax-affiliates' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="affilio-admin-payout-method"><?php esc_html_e( 'Payout method', 'dreamax-affiliates' ); ?></label></th>
						<td>
							<select id="affilio-admin-payout-method" name="payout_method">
								<?php foreach ( $methods as $method_key => $method_label ) : ?>
									<option value="<?php echo esc_attr( $method_key ); ?>" <?php selected( $affiliate->payout_method ?? 'paypal', $method_key ); ?>><?php echo esc_html( $method_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="affilio-admin-payout-email"><?php esc_html_e( 'Payout/contact email', 'dreamax-affiliates' ); ?></label></th>
						<td><input class="regular-text" type="email" id="affilio-admin-payout-email" name="payout_email" value="<?php echo esc_attr( $affiliate->payout_email ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="affilio-admin-payout-details"><?php esc_html_e( 'Payout destination / account details', 'dreamax-affiliates' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="5" id="affilio-admin-payout-details" name="payout_details"><?php echo esc_textarea( $affiliate->payout_details ?? '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'For bank transfer or other manual methods, enter the destination/account details used to receive payouts. Do not store passwords or card security codes.', 'dreamax-affiliates' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Affiliate', 'dreamax-affiliates' ) ); ?>
			</form>
		</div>
		<?php
	}


	/**
	 * Renders the Free click, conversion, referral, and commission reports.
	 *
	 * @return void
	 */
	public function render_reports_page() {
		$filters          = Affilio_Reports::get_filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filters (get_filters_from_request sanitizes every field it reads), not a state-changing action.
		$referral_filters = array(
			'affiliate_id' => $filters['affiliate_id'],
			'date_from'    => $filters['date_from'],
			'date_to'      => $filters['date_to'],
			'date_from_ui' => $filters['date_from_ui'],
			'date_to_ui'   => $filters['date_to_ui'],
			'campaign'     => $filters['campaign'],
			'converted'    => $filters['converted'],
		);
		$report_data      = affilio()->analytics->get_data( $filters['affiliate_id'], $filters );
		$visit_summary    = $report_data['visits'];
		$referral_summary = $report_data['referrals'];
		$affiliates       = affilio()->affiliates_db->query_for_selector();

		require_once AFFILIO_PLUGIN_DIR . 'includes/admin/class-affilio-visits-list-table.php';
		$table = new Affilio_Visits_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap affilio-reports-page">
			<hr class="wp-header-end">
			<header class="affilio-reports-hero">
				<div class="affilio-reports-hero__content">
					<span class="affilio-reports-eyebrow"><?php esc_html_e( 'Performance workspace', 'dreamax-affiliates' ); ?></span>
					<h1><?php esc_html_e( 'Affiliate Reports', 'dreamax-affiliates' ); ?></h1>
					<p><?php esc_html_e( 'Understand affiliate traffic, conversions, referrals, and recorded commission from one trusted reporting workspace.', 'dreamax-affiliates' ); ?></p>
				</div>
				<span class="affilio-reports-hero__badge"><span class="dashicons dashicons-chart-area" aria-hidden="true"></span><?php esc_html_e( 'Core reporting', 'dreamax-affiliates' ); ?></span>
			</header>

			<section class="affilio-reports-panel affilio-reports-filter-panel" aria-labelledby="affilio-report-filters-title">
				<div class="affilio-reports-section-heading">
					<span class="affilio-reports-section-heading__icon dashicons dashicons-filter" aria-hidden="true"></span>
					<div><span class="affilio-reports-eyebrow"><?php esc_html_e( 'Report scope', 'dreamax-affiliates' ); ?></span><h2 id="affilio-report-filters-title"><?php esc_html_e( 'Filter performance data', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Use these filters to review the same period and affiliate activity across core reports and Pro analytics.', 'dreamax-affiliates' ); ?></p></div>
				</div>
			<form method="get" class="affilio-report-filters">
				<input type="hidden" name="page" value="affilio-reports">
				<div class="affilio-report-field"><label for="affilio-report-affiliate"><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></label>
					<select id="affilio-report-affiliate" name="affiliate_id">
						<option value="0"><?php esc_html_e( 'All affiliates', 'dreamax-affiliates' ); ?></option>
						<?php foreach ( $affiliates as $affiliate ) : ?>
							<option value="<?php echo esc_attr( $affiliate->id ); ?>" <?php selected( $filters['affiliate_id'], $affiliate->id ); ?>><?php echo esc_html( '' !== (string) $affiliate->display_name ? $affiliate->display_name : '#' . $affiliate->id ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="affilio-report-field"><label for="affilio-report-date-from"><?php esc_html_e( 'From', 'dreamax-affiliates' ); ?></label><input id="affilio-report-date-from" type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from_ui'] ); ?>"></div>
				<div class="affilio-report-field"><label for="affilio-report-date-to"><?php esc_html_e( 'To', 'dreamax-affiliates' ); ?></label><input id="affilio-report-date-to" type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to_ui'] ); ?>"></div>
				<div class="affilio-report-field"><label for="affilio-report-campaign"><?php esc_html_e( 'Campaign', 'dreamax-affiliates' ); ?></label><input id="affilio-report-campaign" type="text" name="campaign" value="<?php echo esc_attr( $filters['campaign'] ); ?>" maxlength="100" placeholder="<?php echo esc_attr__( 'Exact campaign label', 'dreamax-affiliates' ); ?>"></div>
				<div class="affilio-report-field"><label for="affilio-report-conversion"><?php esc_html_e( 'Conversion', 'dreamax-affiliates' ); ?></label>
					<select id="affilio-report-conversion" name="converted">
						<option value=""><?php esc_html_e( 'All clicks', 'dreamax-affiliates' ); ?></option>
						<option value="1" <?php selected( $filters['converted'], 1 ); ?>><?php esc_html_e( 'Converted', 'dreamax-affiliates' ); ?></option>
						<option value="0" <?php selected( $filters['converted'], 0 ); ?>><?php esc_html_e( 'Not converted', 'dreamax-affiliates' ); ?></option>
					</select>
				</div>
				<div class="affilio-report-filter-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'dreamax-affiliates' ); ?></button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-reports' ) ); ?>"><?php esc_html_e( 'Reset', 'dreamax-affiliates' ); ?></a>
				</div>
			</form>
			</section>

			<section class="affilio-reports-summary" aria-labelledby="affilio-report-summary-title">
				<div class="affilio-reports-section-heading affilio-reports-section-heading--compact"><span class="affilio-reports-eyebrow"><?php esc_html_e( 'Core snapshot', 'dreamax-affiliates' ); ?></span><h2 id="affilio-report-summary-title"><?php esc_html_e( 'Current filtered performance', 'dreamax-affiliates' ); ?></h2></div>
			<dl class="affilio-report-cards" aria-label="<?php echo esc_attr__( 'Affiliate report summary', 'dreamax-affiliates' ); ?>">
				<?php $this->render_report_card( __( 'Clicks', 'dreamax-affiliates' ), Affilio_I18n::number( $visit_summary['clicks'] ), 'dashicons-admin-links', 'brand' ); ?>
				<?php $this->render_report_card( __( 'Unique Visitors', 'dreamax-affiliates' ), Affilio_I18n::number( $visit_summary['unique_visitors'] ), 'dashicons-groups', 'brand' ); ?>
				<?php $this->render_report_card( __( 'Conversions', 'dreamax-affiliates' ), Affilio_I18n::number( $visit_summary['conversions'] ), 'dashicons-yes-alt', 'good' ); ?>
				<?php $this->render_report_card( __( 'Conversion Rate', 'dreamax-affiliates' ), Affilio_I18n::percentage( $visit_summary['conversion_rate'], 2 ), 'dashicons-chart-line', 'good' ); ?>
				<?php $this->render_report_card( __( 'Referrals', 'dreamax-affiliates' ), Affilio_I18n::number( $referral_summary['count'] ), 'dashicons-networking', 'brand' ); ?>
				<?php $this->render_report_card( __( 'Recorded Commission', 'dreamax-affiliates' ), $this->format_currency_totals( $referral_summary['commission_totals'] ), 'dashicons-money-alt', 'good' ); ?>
			</dl>
			</section>

			<?php
			/**
			 * Lets a separately distributed add-on render provider-owned report sections.
			 *
			 * @since 2.0.0
			 *
			 * @param array $report_data Basic immutable summary plus provider datasets.
			 * @param array $filters     Sanitized report filters.
			 */
			do_action( 'affilio_admin_reports_after_summary', $report_data, $filters );
			?>

			<section class="affilio-reports-panel affilio-reports-export" aria-labelledby="affilio-report-export-title"><div class="affilio-reports-section-heading"><span class="affilio-reports-section-heading__icon dashicons dashicons-download" aria-hidden="true"></span><div><span class="affilio-reports-eyebrow"><?php esc_html_e( 'Portable records', 'dreamax-affiliates' ); ?></span><h2 id="affilio-report-export-title"><?php esc_html_e( 'Export filtered data', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Download the data matching your current filters for offline review.', 'dreamax-affiliates' ); ?></p></div></div><div class="affilio-export-actions">
				<a class="button" href="<?php echo esc_url( Affilio_Reports::get_admin_export_url( 'visits', $filters ) ); ?>"><span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span><?php esc_html_e( 'Export Clicks CSV', 'dreamax-affiliates' ); ?></a>
				<a class="button" href="<?php echo esc_url( Affilio_Reports::get_admin_export_url( 'referrals', $referral_filters ) ); ?>"><span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span><?php esc_html_e( 'Export Referrals CSV', 'dreamax-affiliates' ); ?></a>
			</div></section>

			<section class="affilio-reports-panel affilio-reports-log" aria-labelledby="affilio-report-log-title"><div class="affilio-reports-section-heading"><span class="affilio-reports-section-heading__icon dashicons dashicons-list-view" aria-hidden="true"></span><div><span class="affilio-reports-eyebrow"><?php esc_html_e( 'Event detail', 'dreamax-affiliates' ); ?></span><h2 id="affilio-report-log-title"><?php esc_html_e( 'Click Log', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Inspect individual tracked visits within the active report filters.', 'dreamax-affiliates' ); ?></p></div></div>
			<form method="get" class="affilio-reports-log__form">
				<input type="hidden" name="page" value="affilio-reports">
				<?php foreach ( array( 'affiliate_id', 'date_from', 'date_to', 'campaign', 'converted' ) as $key ) : ?>
					<?php if ( isset( $_GET[ $key ] ) && '' !== (string) wp_unslash( $_GET[ $key ] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only filter state used to re-populate the report form (not a state-changing action); isset() does not use the value, and the value is sanitized on the next line where it is actually output. ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
					<?php endif; ?>
				<?php endforeach; ?>
				<?php $table->search_box( __( 'Search clicks', 'dreamax-affiliates' ), 'affilio-visits' ); ?>
				<div class="affilio-admin-table-wrap"><?php $table->display(); ?></div>
			</form></section>
		</div>
		<?php
	}

	/**
	 * Renders a summary metric card.
	 *
	 * @param string $label Card label.
	 * @param string $value Card value.
	 * @param string $icon  Dashicon class.
	 * @param string $tone  Presentation tone.
	 * @return void
	 */
	private function render_report_card( $label, $value, $icon = 'dashicons-chart-bar', $tone = 'brand' ) {
		?>
		<div class="affilio-report-card is-<?php echo esc_attr( $tone ); ?>">
			<span class="affilio-report-card__icon dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div>
		</div>
		<?php
	}

	/**
	 * Renders the referrals list and batch-creation instructions.
	 *
	 * @return void
	 */
	public function render_referrals_page() {
		$filters = Affilio_Reports::get_filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filters (get_filters_from_request sanitizes every field it reads), not a state-changing action.
		require_once AFFILIO_PLUGIN_DIR . 'includes/admin/class-affilio-referrals-list-table.php';
		$table = new Affilio_Referrals_List_Table();
		$table->prepare_items();
		$totals_by_currency = affilio()->referrals_db->get_total_owed();
		$affiliates         = affilio()->affiliates_db->query_for_selector();
		$currencies         = affilio()->referrals_db->get_currencies();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Referrals', 'dreamax-affiliates' ); ?></h1>
			<p class="affilio-summary">
				<?php esc_html_e( 'Available to pay:', 'dreamax-affiliates' ); ?>
				<strong><?php echo esc_html( $this->format_currency_totals( $totals_by_currency ) ); ?></strong>
			</p>

			<form method="get" class="affilio-report-filters affilio-referral-filters">
				<input type="hidden" name="page" value="affilio-referrals">
				<label><span><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></span>
					<select name="affiliate_id">
						<option value="0"><?php esc_html_e( 'All affiliates', 'dreamax-affiliates' ); ?></option>
						<?php foreach ( $affiliates as $affiliate ) : ?>
							<option value="<?php echo esc_attr( $affiliate->id ); ?>" <?php selected( $filters['affiliate_id'], $affiliate->id ); ?>><?php echo esc_html( '' !== (string) $affiliate->display_name ? $affiliate->display_name : '#' . $affiliate->id ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></span>
					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'dreamax-affiliates' ); ?></option>
						<?php foreach ( \Affilio\Domain\Referral\ReferralStatus::all() as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( Affilio_I18n::status_label( $status ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Currency', 'dreamax-affiliates' ); ?></span>
					<select name="currency">
						<option value=""><?php esc_html_e( 'All currencies', 'dreamax-affiliates' ); ?></option>
						<?php foreach ( $currencies as $currency ) : ?>
							<option value="<?php echo esc_attr( $currency ); ?>" <?php selected( $filters['currency'], $currency ); ?>><?php echo esc_html( $currency ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label><span><?php esc_html_e( 'Source', 'dreamax-affiliates' ); ?></span>
					<select name="source">
						<option value=""><?php esc_html_e( 'All sources', 'dreamax-affiliates' ); ?></option>
						<option value="link" <?php selected( $filters['source'], 'link' ); ?>><?php esc_html_e( 'Referral link', 'dreamax-affiliates' ); ?></option>
						<option value="coupon" <?php selected( $filters['source'], 'coupon' ); ?>><?php esc_html_e( 'Affiliate coupon', 'dreamax-affiliates' ); ?></option>
						<option value="import" <?php selected( $filters['source'], 'import' ); ?>><?php esc_html_e( 'Imported', 'dreamax-affiliates' ); ?></option>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Coupon code', 'dreamax-affiliates' ); ?></span><input type="search" name="coupon_code" value="<?php echo esc_attr( $filters['coupon_code'] ); ?>"></label>
				<label><span><?php esc_html_e( 'From', 'dreamax-affiliates' ); ?></span><input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from_ui'] ); ?>"></label>
				<label><span><?php esc_html_e( 'To', 'dreamax-affiliates' ); ?></span><input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to_ui'] ); ?>"></label>
				<label><span><?php esc_html_e( 'Order / Referral ID', 'dreamax-affiliates' ); ?></span><input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
				<div class="affilio-report-filter-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'dreamax-affiliates' ); ?></button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals' ) ); ?>"><?php esc_html_e( 'Reset', 'dreamax-affiliates' ); ?></a>
				</div>
			</form>

			<p>
				<?php esc_html_e( 'Select unpaid referrals, choose “Create payout batch,” and click Apply. Referrals are grouped by affiliate and currency.', 'dreamax-affiliates' ); ?>
				<a class="button" href="<?php echo esc_url( Affilio_Reports::get_admin_export_url( 'referrals', $filters ) ); ?>"><?php esc_html_e( 'Export Filtered CSV', 'dreamax-affiliates' ); ?></a>
			</p>

			<form method="post">
				<input type="hidden" name="page" value="affilio-referrals">
				<?php foreach ( array( 'affiliate_id', 'status', 'currency', 'source', 'coupon_code', 'date_from', 'date_to', 's', 'orderby', 'order', 'paged' ) as $key ) : ?>
					<?php if ( isset( $_GET[ $key ] ) && '' !== (string) wp_unslash( $_GET[ $key ] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only filter state used to re-populate the report form (not a state-changing action); isset() does not use the value, and the value is sanitized on the next line where it is actually output. ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
					<?php endif; ?>
				<?php endforeach; ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the payouts list or one payout detail screen.
	 *
	 * @return void
	 */
	public function render_payouts_page() {
		$payout_id = isset( $_GET['payout_id'] ) ? absint( $_GET['payout_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $payout_id ) {
			$this->render_payout_detail( $payout_id );
			return;
		}

		require_once AFFILIO_PLUGIN_DIR . 'includes/admin/class-affilio-payouts-list-table.php';
		$statuses  = \Affilio\Domain\Payout\PayoutStatus::all();
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status    = in_array( $status, $statuses, true ) ? $status : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$counts    = affilio()->payouts_db->get_status_counts();
		$total     = array_sum( $counts );
		$filtered  = affilio()->payouts_db->count(
			array(
				'status' => $status,
				's'      => $search,
			)
		);
		$clear_url = add_query_arg(
			array_filter(
				array(
					'page'   => 'affilio-payouts',
					'status' => $status,
				)
			),
			admin_url( 'admin.php' )
		);
		$table     = new Affilio_Payouts_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap affilio-payouts-page">
			<hr class="wp-header-end">
			<header class="affilio-payouts-hero">
				<div class="affilio-payouts-hero__content">
					<span class="affilio-payouts-eyebrow"><?php esc_html_e( 'Payment operations', 'dreamax-affiliates' ); ?></span>
					<h1><?php esc_html_e( 'Payout Batches', 'dreamax-affiliates' ); ?></h1>
					<p><?php esc_html_e( 'Review controlled payment batches, verify destination details, and record completion only after funds have been sent.', 'dreamax-affiliates' ); ?></p>
				</div>
				<a class="affilio-payout-button affilio-payouts-hero__action" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals&status=unpaid' ) ); ?>">
					<svg class="affilio-payout-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 2a1 1 0 0 1 1 1v6h6a1 1 0 1 1 0 2h-6v6a1 1 0 1 1-2 0v-6H3a1 1 0 1 1 0-2h6V3a1 1 0 0 1 1-1Z"/></svg>
					<span><?php esc_html_e( 'Create from Referrals', 'dreamax-affiliates' ); ?></span>
				</a>
			</header>
			<div class="affilio-payout-summary" aria-label="<?php echo esc_attr__( 'Payout lifecycle summary', 'dreamax-affiliates' ); ?>">
				<article class="affilio-payout-stat"><span class="affilio-payout-stat__icon dashicons dashicons-list-view" aria-hidden="true"></span><div><small><?php esc_html_e( 'Total batches', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $total ) ); ?></strong><p><?php esc_html_e( 'Complete payout history', 'dreamax-affiliates' ); ?></p></div></article>
				<article class="affilio-payout-stat is-processing"><span class="affilio-payout-stat__icon dashicons dashicons-update" aria-hidden="true"></span><div><small><?php esc_html_e( 'Processing', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $counts['processing'] ?? 0 ) ); ?></strong><p><?php esc_html_e( 'Awaiting payment outcome', 'dreamax-affiliates' ); ?></p></div></article>
				<article class="affilio-payout-stat is-good"><span class="affilio-payout-stat__icon dashicons dashicons-yes-alt" aria-hidden="true"></span><div><small><?php esc_html_e( 'Paid', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( $counts['paid'] ?? 0 ) ); ?></strong><p><?php esc_html_e( 'Successfully completed', 'dreamax-affiliates' ); ?></p></div></article>
				<article class="affilio-payout-stat is-attention"><span class="affilio-payout-stat__icon dashicons dashicons-warning" aria-hidden="true"></span><div><small><?php esc_html_e( 'Closed unpaid', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( ( $counts['failed'] ?? 0 ) + ( $counts['cancelled'] ?? 0 ) ) ); ?></strong><p><?php esc_html_e( 'Failed or cancelled', 'dreamax-affiliates' ); ?></p></div></article>
			</div>
			<aside class="affilio-payout-assurance" role="note"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><p><strong><?php esc_html_e( 'Controlled payout ledger.', 'dreamax-affiliates' ); ?></strong> <?php esc_html_e( 'Batches are created from eligible unpaid referrals, stay currency-isolated, and lock their linked commissions until the payment reaches a final state.', 'dreamax-affiliates' ); ?></p></aside>
			<section class="affilio-payout-panel affilio-payout-directory <?php echo $table->has_items() ? 'has-items' : 'is-empty'; ?>">
				<div class="affilio-payout-section-heading">
					<span class="affilio-payout-section-heading__icon dashicons dashicons-money-alt" aria-hidden="true"></span>
					<div><span class="affilio-payouts-eyebrow"><?php esc_html_e( 'Payment ledger', 'dreamax-affiliates' ); ?></span><h2><?php esc_html_e( 'Payout records', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Search a visible record or filter the complete lifecycle before opening its controlled detail view.', 'dreamax-affiliates' ); ?></p></div>
					<?php /* translators: %s: number of payout records matching the active filters. */ ?>
					<span class="affilio-payout-count"><?php echo esc_html( sprintf( _n( '%s record', '%s records', $filtered, 'dreamax-affiliates' ), Affilio_I18n::number( $filtered ) ) ); ?></span>
				</div>
				<div class="affilio-payout-toolbar">
					<nav class="affilio-payout-views" aria-label="<?php echo esc_attr__( 'Filter payouts by status', 'dreamax-affiliates' ); ?>"><?php $table->views(); ?></nav>
					<form class="affilio-payout-search" method="get">
						<input type="hidden" name="page" value="affilio-payouts">
						<?php if ( '' !== $status ) : ?>
							<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
						<?php endif; ?>
						<label class="screen-reader-text" for="affilio-payout-search-input"><?php esc_html_e( 'Search payouts', 'dreamax-affiliates' ); ?></label>
						<input type="search" id="affilio-payout-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Batch, affiliate, amount, method, destination, or date', 'dreamax-affiliates' ); ?>">
						<button class="button affilio-payout-search__submit" type="submit"><svg class="affilio-payout-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8.5 3a5.5 5.5 0 1 0 3.35 9.86l3.64 3.65a1 1 0 0 0 1.42-1.42l-3.65-3.64A5.5 5.5 0 0 0 8.5 3Zm-3.5 5.5a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z"/></svg><span><?php esc_html_e( 'Search', 'dreamax-affiliates' ); ?></span></button>
						<?php if ( '' !== $search ) : ?>
							<a class="button affilio-payout-search__clear" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Clear', 'dreamax-affiliates' ); ?></a>
						<?php endif; ?>
					</form>
				</div>
				<form class="affilio-payout-list-form" method="get">
					<input type="hidden" name="page" value="affilio-payouts">
					<?php if ( '' !== $status ) : ?>
						<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
					<?php endif; ?>
					<?php if ( '' !== $search ) : ?>
						<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
					<?php endif; ?>
					<?php $table->display(); ?>
				</form>
			</section>
			<aside class="affilio-payout-footer-note" role="note"><span class="dashicons dashicons-lock" aria-hidden="true"></span><p><?php esc_html_e( 'Payment changes remain auditable. Mark a batch paid only after settlement; cancelling or recording failure releases its linked referrals through the guarded payout workflow.', 'dreamax-affiliates' ); ?></p></aside>
		</div>
		<?php
	}

	/**
	 * Renders one payout with linked referrals and completion controls.
	 *
	 * @param int $payout_id Payout ID.
	 * @return void
	 */
	private function render_payout_detail( $payout_id ) {
		$payout = affilio()->payouts_db->get( $payout_id );

		if ( ! $payout ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Payout not found', 'dreamax-affiliates' ) . '</h1></div>';
			return;
		}

		$affiliate = affilio()->affiliates_db->get( $payout->affiliate_id );
		$user      = $affiliate ? get_userdata( $affiliate->user_id ) : null;
		$referrals = affilio()->referrals_db->get_by_payout( $payout->id );
		$methods   = affilio()->payouts->get_payout_methods();
		?>
		<div class="wrap affilio-payouts-page affilio-payout-detail">
			<hr class="wp-header-end">
			<header class="affilio-payouts-hero affilio-payout-detail-hero">
				<div class="affilio-payouts-hero__content">
					<span class="affilio-payouts-eyebrow"><?php esc_html_e( 'Payment record', 'dreamax-affiliates' ); ?></span>
					<?php /* translators: %s: payout batch key/identifier. */ ?>
					<h1><?php echo esc_html( sprintf( __( 'Payout %s', 'dreamax-affiliates' ), $payout->batch_key ) ); ?></h1>
					<p><?php esc_html_e( 'Verify the recipient, destination, linked commissions, and settlement evidence for this controlled payout batch.', 'dreamax-affiliates' ); ?></p>
					<a class="affilio-payout-button affilio-payout-detail-hero__back" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-payouts' ) ); ?>"><svg class="affilio-payout-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.7 4.3a1 1 0 0 1 0 1.4L8.42 10l4.28 4.3a1 1 0 0 1-1.4 1.4l-5-5a1 1 0 0 1 0-1.4l5-5a1 1 0 0 1 1.4 0Z"/></svg><span><?php esc_html_e( 'Back to Payouts', 'dreamax-affiliates' ); ?></span></a>
				</div>
				<span class="affilio-payouts-hero__badge is-<?php echo esc_attr( $payout->status ); ?>"><span class="dashicons dashicons-shield" aria-hidden="true"></span><?php echo esc_html( Affilio_I18n::status_label( $payout->status ) ); ?></span>
			</header>
			<div class="affilio-payout-summary" aria-label="<?php echo esc_attr__( 'Payout summary', 'dreamax-affiliates' ); ?>">
				<article class="affilio-payout-stat"><span class="affilio-payout-stat__icon dashicons dashicons-groups" aria-hidden="true"></span><div><small><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></small><strong class="is-text"><?php echo esc_html( $user ? $user->display_name : __( '(unknown)', 'dreamax-affiliates' ) ); ?></strong><p><?php esc_html_e( 'Payment recipient', 'dreamax-affiliates' ); ?></p></div></article>
				<article class="affilio-payout-stat is-good"><span class="affilio-payout-stat__icon dashicons dashicons-money-alt" aria-hidden="true"></span><div><small><?php esc_html_e( 'Batch amount', 'dreamax-affiliates' ); ?></small><strong class="is-text"><?php echo esc_html( Affilio_I18n::amount( $payout->amount, $payout->currency ) ); ?></strong><p><?php esc_html_e( 'Currency-isolated total', 'dreamax-affiliates' ); ?></p></div></article>
				<article class="affilio-payout-stat"><span class="affilio-payout-stat__icon dashicons dashicons-bank" aria-hidden="true"></span><div><small><?php esc_html_e( 'Payment method', 'dreamax-affiliates' ); ?></small><strong class="is-text"><?php echo esc_html( Affilio_I18n::payment_method_label( $payout->payment_method, $methods ) ); ?></strong><p><?php esc_html_e( 'Saved recipient profile', 'dreamax-affiliates' ); ?></p></div></article>
				<article class="affilio-payout-stat is-processing"><span class="affilio-payout-stat__icon dashicons dashicons-networking" aria-hidden="true"></span><div><small><?php esc_html_e( 'Linked referrals', 'dreamax-affiliates' ); ?></small><strong><?php echo esc_html( Affilio_I18n::number( count( $referrals ) ) ); ?></strong><p><?php esc_html_e( 'Commissions in this batch', 'dreamax-affiliates' ); ?></p></div></article>
			</div>
			<div class="affilio-payout-detail-grid">
				<section class="affilio-payout-panel">
					<div class="affilio-payout-section-heading"><span class="affilio-payout-section-heading__icon dashicons dashicons-location-alt" aria-hidden="true"></span><div><span class="affilio-payouts-eyebrow"><?php esc_html_e( 'Recipient snapshot', 'dreamax-affiliates' ); ?></span><h2><?php esc_html_e( 'Payment destination', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Confirm these saved payout details before sending funds outside WordPress.', 'dreamax-affiliates' ); ?></p></div></div>
					<pre class="affilio-destination-pre"><?php echo esc_html( $payout->payment_destination ); ?></pre>
				</section>
				<section class="affilio-payout-panel">
					<div class="affilio-payout-section-heading"><span class="affilio-payout-section-heading__icon dashicons dashicons-clipboard" aria-hidden="true"></span><div><span class="affilio-payouts-eyebrow"><?php esc_html_e( 'Audit context', 'dreamax-affiliates' ); ?></span><h2><?php esc_html_e( 'Batch record', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Lifecycle timestamps and settlement evidence remain attached to this batch.', 'dreamax-affiliates' ); ?></p></div></div>
					<dl class="affilio-payout-record">
						<div><dt><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></dt><dd><span class="affilio-status affilio-status-<?php echo esc_attr( $payout->status ); ?>"><?php echo esc_html( Affilio_I18n::status_label( $payout->status ) ); ?></span></dd></div>
						<div><dt><?php esc_html_e( 'Created', 'dreamax-affiliates' ); ?></dt><dd><?php echo esc_html( Affilio_I18n::date( $payout->date_created ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Paid', 'dreamax-affiliates' ); ?></dt><dd><?php echo $payout->date_paid ? esc_html( Affilio_I18n::date( $payout->date_paid ) ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd></div>
						<div><dt><?php esc_html_e( 'Reference', 'dreamax-affiliates' ); ?></dt><dd><?php echo $payout->reference ? esc_html( $payout->reference ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd></div>
						<div class="is-wide"><dt><?php esc_html_e( 'Notes', 'dreamax-affiliates' ); ?></dt><dd><?php echo $payout->notes ? nl2br( esc_html( $payout->notes ) ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd></div>
					</dl>
				</section>
			</div>
			<section class="affilio-payout-panel affilio-payout-referrals">
				<div class="affilio-payout-section-heading"><span class="affilio-payout-section-heading__icon dashicons dashicons-networking" aria-hidden="true"></span><div><span class="affilio-payouts-eyebrow"><?php esc_html_e( 'Commission ownership', 'dreamax-affiliates' ); ?></span><h2><?php esc_html_e( 'Included Referrals', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'These commissions remain locked to this payout until its lifecycle workflow releases or settles them.', 'dreamax-affiliates' ); ?></p></div>
					<?php /* translators: %s: number of referrals linked to this payout. */ ?>
					<span class="affilio-payout-count"><?php echo esc_html( sprintf( _n( '%s referral', '%s referrals', count( $referrals ), 'dreamax-affiliates' ), Affilio_I18n::number( count( $referrals ) ) ) ); ?></span>
				</div>
			<div class="affilio-admin-table-wrap">
			<table class="widefat striped affilio-payout-referrals__table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Referrals included in this payout', 'dreamax-affiliates' ); ?></caption>
				<thead><tr><th scope="col"><?php esc_html_e( 'Referral', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Order', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Commission', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $referrals ) ) : ?>
					<tr class="affilio-payout-empty-row"><td colspan="4"><?php esc_html_e( 'No referrals are linked to this payout.', 'dreamax-affiliates' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $referrals as $referral ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr__( 'Referral', 'dreamax-affiliates' ); ?>">#<?php echo esc_html( $referral->id ); ?></td>
							<td data-label="<?php echo esc_attr__( 'Order', 'dreamax-affiliates' ); ?>"><?php echo wp_kses_post( $this->get_order_link( $referral->order_id ) ); ?></td>
							<td data-label="<?php echo esc_attr__( 'Commission', 'dreamax-affiliates' ); ?>"><?php echo esc_html( Affilio_I18n::amount( $referral->commission_amount, $referral->currency ) ); ?></td>
							<td data-label="<?php echo esc_attr__( 'Status', 'dreamax-affiliates' ); ?>"><span class="affilio-status affilio-status-<?php echo esc_attr( $referral->status ); ?>"><?php echo esc_html( Affilio_I18n::status_label( $referral->status ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</div>
			</section>

			<?php if ( 'processing' === $payout->status ) : ?>
				<section class="affilio-payout-panel affilio-payout-actions">
					<div class="affilio-payout-section-heading"><span class="affilio-payout-section-heading__icon dashicons dashicons-shield-alt" aria-hidden="true"></span><div><span class="affilio-payouts-eyebrow"><?php esc_html_e( 'Controlled decision', 'dreamax-affiliates' ); ?></span><h2><?php esc_html_e( 'Record the payment outcome', 'dreamax-affiliates' ); ?></h2><p><?php esc_html_e( 'Use the successful path only after settlement, or close the batch safely so its referrals can be reviewed again.', 'dreamax-affiliates' ); ?></p></div></div>
					<div class="affilio-payout-actions__grid">
					<form class="affilio-payout-action-card is-success" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<h3><?php esc_html_e( 'Complete Payout', 'dreamax-affiliates' ); ?></h3>
						<p><?php esc_html_e( 'Store optional settlement evidence and mark every linked commission paid atomically.', 'dreamax-affiliates' ); ?></p>
						<input type="hidden" name="action" value="affilio_mark_payout_paid">
						<input type="hidden" name="payout_id" value="<?php echo esc_attr( $payout->id ); ?>">
						<?php wp_nonce_field( 'affilio_mark_payout_paid_' . $payout->id ); ?>
						<label for="affilio-payout-reference"><?php esc_html_e( 'Payment reference', 'dreamax-affiliates' ); ?> <span><?php esc_html_e( 'Optional', 'dreamax-affiliates' ); ?></span></label>
						<input class="regular-text" type="text" id="affilio-payout-reference" name="reference">
						<label for="affilio-payout-notes"><?php esc_html_e( 'Internal notes', 'dreamax-affiliates' ); ?> <span><?php esc_html_e( 'Optional', 'dreamax-affiliates' ); ?></span></label>
						<textarea class="large-text" rows="4" id="affilio-payout-notes" name="notes"></textarea>
						<button class="button button-primary affilio-payout-action-button" type="submit" name="submit"><svg class="affilio-payout-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7.6 14.7 3.3 10.4a1 1 0 0 1 1.4-1.4l2.9 2.9 7.7-7.7a1 1 0 1 1 1.4 1.4l-9.1 9.1Z"/></svg><span><?php esc_html_e( 'Mark Payout as Paid', 'dreamax-affiliates' ); ?></span></button>
					</form>

					<div class="affilio-payout-action-card is-attention">
						<h3><?php esc_html_e( 'Close without settlement', 'dreamax-affiliates' ); ?></h3>
						<p><?php esc_html_e( 'Both paths return linked commissions to unpaid status. Record failure when a payment attempt was actually made.', 'dreamax-affiliates' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="affilio_mark_payout_failed">
						<input type="hidden" name="payout_id" value="<?php echo esc_attr( $payout->id ); ?>">
						<?php wp_nonce_field( 'affilio_mark_payout_failed_' . $payout->id ); ?>
						<label for="affilio-payout-failure-reason"><?php esc_html_e( 'Failure reason', 'dreamax-affiliates' ); ?> <span><?php esc_html_e( 'Required', 'dreamax-affiliates' ); ?></span></label>
						<textarea class="large-text" rows="3" id="affilio-payout-failure-reason" name="failure_reason" required></textarea>
						<button class="button affilio-payout-action-button" type="submit" name="submit"><svg class="affilio-payout-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 2a1.8 1.8 0 0 1 1.56.9l6.1 10.6A1.8 1.8 0 0 1 16.1 16H3.9a1.8 1.8 0 0 1-1.56-2.7L8.44 2.9A1.8 1.8 0 0 1 10 2Zm0 3.3a1 1 0 0 0-1 1v3.4a1 1 0 1 0 2 0V6.3a1 1 0 0 0-1-1Zm0 7a1.15 1.15 0 1 0 0 2.3 1.15 1.15 0 0 0 0-2.3Z"/></svg><span><?php esc_html_e( 'Mark Payout as Failed', 'dreamax-affiliates' ); ?></span></button>
						</form>
						<form class="affilio-payout-cancel-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this payout and return its referrals to unpaid status?', 'dreamax-affiliates' ) ); ?>');">
							<input type="hidden" name="action" value="affilio_cancel_payout">
							<input type="hidden" name="payout_id" value="<?php echo esc_attr( $payout->id ); ?>">
							<?php wp_nonce_field( 'affilio_cancel_payout_' . $payout->id ); ?>
							<button class="button affilio-payout-action-button is-cancel" type="submit" name="submit"><svg class="affilio-payout-button-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M5.7 4.3 10 8.6l4.3-4.3a1 1 0 1 1 1.4 1.4L11.4 10l4.3 4.3a1 1 0 0 1-1.4 1.4L10 11.4l-4.3 4.3a1 1 0 0 1-1.4-1.4L8.6 10 4.3 5.7a1 1 0 0 1 1.4-1.4Z"/></svg><span><?php esc_html_e( 'Cancel Payout', 'dreamax-affiliates' ); ?></span></button>
						</form>
					</div>
					</div>
				</section>
			<?php else : ?>
				<aside class="affilio-payout-footer-note" role="note"><span class="dashicons dashicons-lock" aria-hidden="true"></span><p><?php esc_html_e( 'This payout has reached a final state. Its recorded destination, evidence, and linked referral history are read-only.', 'dreamax-affiliates' ); ?></p></aside>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders settings.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$registration_page_id = absint( get_option( Affilio_Onboarding::OPTION_REGISTRATION_PAGE, 0 ) );
		$dashboard_page_id    = absint( get_option( Affilio_Onboarding::OPTION_DASHBOARD_PAGE, 0 ) );
		$configured_pages     = (int) ( 0 < $registration_page_id ) + (int) ( 0 < $dashboard_page_id );
		$privacy_enabled      = (bool) get_option( 'affilio_anonymize_ip_addresses', true );
		$retention_days       = absint( get_option( 'affilio_ip_retention_days', 90 ) );
		$notification_state   = Affilio_Email_Templates::notification_settings();
		$notification_total   = count( Affilio_Email_Templates::definitions() );
		$notification_enabled = count( array_filter( $notification_state ) );
		$payout_enabled       = (bool) get_option( 'affilio_enable_payout_requests', true );
		/* translators: %s: number of days that stored IP values are retained. */
		$privacy_detail = sprintf( __( 'Stored IP retention: %s days.', 'dreamax-affiliates' ), Affilio_I18n::number( $retention_days ) );
		?>
		<div class="wrap affilio-settings-wrap">
			<hr class="wp-header-end">
			<header class="affilio-settings-hero">
				<div class="affilio-settings-hero__content"><span class="affilio-settings-eyebrow"><?php esc_html_e( 'Program control center', 'dreamax-affiliates' ); ?></span><h1><?php esc_html_e( 'Settings', 'dreamax-affiliates' ); ?></h1><p><?php esc_html_e( 'Configure attribution, commissions, affiliate access, notifications, payouts, and privacy from one trusted workspace.', 'dreamax-affiliates' ); ?></p></div>
				<span class="affilio-settings-hero__badge"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><?php esc_html_e( 'Core configuration', 'dreamax-affiliates' ); ?></span>
			</header>
			<?php settings_errors(); ?>

			<div class="affilio-settings-summary" aria-label="<?php echo esc_attr__( 'Configuration summary', 'dreamax-affiliates' ); ?>">
				<?php $this->render_settings_summary_card( __( 'Affiliate pages', 'dreamax-affiliates' ), sprintf( '%1$s / %2$s', Affilio_I18n::number( $configured_pages ), Affilio_I18n::number( 2 ) ), 2 === $configured_pages ? __( 'Both standalone journeys are selected.', 'dreamax-affiliates' ) : __( 'Review the registration and dashboard pages.', 'dreamax-affiliates' ), 'dashicons-admin-page', 2 === $configured_pages ? 'good' : 'warning' ); ?>
				<?php $this->render_settings_summary_card( __( 'Visitor privacy', 'dreamax-affiliates' ), $privacy_enabled ? __( 'Protected', 'dreamax-affiliates' ) : __( 'Review', 'dreamax-affiliates' ), $privacy_detail, 'dashicons-privacy', $privacy_enabled ? 'good' : 'warning' ); ?>
				<?php $this->render_settings_summary_card( __( 'Email delivery', 'dreamax-affiliates' ), sprintf( '%1$s / %2$s', Affilio_I18n::number( $notification_enabled ), Affilio_I18n::number( $notification_total ) ), __( 'Essential notification switches enabled.', 'dreamax-affiliates' ), 'dashicons-email-alt', 'brand' ); ?>
				<?php $this->render_settings_summary_card( __( 'Payout requests', 'dreamax-affiliates' ), $payout_enabled ? __( 'Available', 'dreamax-affiliates' ) : __( 'Paused', 'dreamax-affiliates' ), $payout_enabled ? __( 'Affiliates may submit eligible requests.', 'dreamax-affiliates' ) : __( 'New affiliate requests are disabled.', 'dreamax-affiliates' ), 'dashicons-money-alt', $payout_enabled ? 'good' : 'neutral' ); ?>
			</div>

			<aside class="affilio-settings-boundary" role="note"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Manage your core program settings', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'Set up your affiliate pages, commission defaults, notifications, and privacy here. Configure Pro features from their dedicated pages.', 'dreamax-affiliates' ); ?></p></div></aside>

			<form class="affilio-settings-form" method="post" action="options.php">
				<?php settings_fields( Affilio_Settings::OPTION_GROUP ); ?>
				<div class="affilio-settings-grid"><?php $this->render_settings_sections(); ?></div>
				<div class="affilio-settings-save"><div class="affilio-settings-save__message"><span class="dashicons dashicons-saved" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Save the complete configuration', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'Changes are validated by their existing field-specific sanitizers before they are stored.', 'dreamax-affiliates' ); ?></p></div></div><?php submit_button( __( 'Save all settings', 'dreamax-affiliates' ), 'primary', 'submit', false ); ?></div>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders registered Settings API sections inside semantic premium cards.
	 *
	 * Field callbacks and persistence remain owned by the WordPress Settings API.
	 *
	 * @return void
	 */
	private function render_settings_sections() {
		global $wp_settings_fields, $wp_settings_sections;

		$page = Affilio_Settings::PAGE_SLUG;
		if ( empty( $wp_settings_sections[ $page ] ) ) {
			return;
		}

		$presentation = array(
			'affilio_pages_section'          => array( 'dashicons-admin-page', __( 'Journey', 'dreamax-affiliates' ), 'is-wide' ),
			'affilio_main_section'           => array( 'dashicons-admin-generic', __( 'Program rules', 'dreamax-affiliates' ), 'is-wide' ),
			'affilio_registration_section'   => array( 'dashicons-feedback', __( 'Application form', 'dreamax-affiliates' ), 'is-wide' ),
			'affilio_approval_section'       => array( 'dashicons-yes-alt', __( 'Qualification', 'dreamax-affiliates' ), '' ),
			'affilio_payout_request_section' => array( 'dashicons-money-alt', __( 'Payout workflow', 'dreamax-affiliates' ), '' ),
			'affilio_email_section'          => array( 'dashicons-email-alt', __( 'Delivery controls', 'dreamax-affiliates' ), 'is-wide' ),
			'affilio_data_section'           => array( 'dashicons-trash', __( 'Uninstall policy', 'dreamax-affiliates' ), 'is-wide is-danger' ),
		);

		foreach ( $wp_settings_sections[ $page ] as $section ) {
			$section_id = isset( $section['id'] ) ? sanitize_key( $section['id'] ) : '';
			if ( '' === $section_id ) {
				continue;
			}

			$style      = $presentation[ $section_id ] ?? array( 'dashicons-admin-settings', __( 'Configuration', 'dreamax-affiliates' ), 'is-wide' );
			$title_id   = $section_id . '-title';
			$card_class = trim( 'affilio-settings-card ' . $style[2] );
			$has_fields = ! empty( $wp_settings_fields[ $page ][ $section_id ] );
			?>
			<section class="<?php echo esc_attr( $card_class ); ?>" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
				<header class="affilio-settings-card__header"><span class="affilio-settings-card__icon dashicons <?php echo esc_attr( $style[0] ); ?>" aria-hidden="true"></span><div><span class="affilio-settings-eyebrow"><?php echo esc_html( $style[1] ); ?></span>
				<?php
				if ( ! empty( $section['title'] ) ) :
					?>
					<h2 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $section['title'] ); ?></h2><?php endif; ?>
					<?php
					if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) :
						?>
					<div class="affilio-settings-card__intro"><?php call_user_func( $section['callback'], $section ); ?></div><?php endif; ?></div></header>
				<?php
				if ( $has_fields ) :
					?>
					<div class="affilio-settings-card__body"><table class="form-table" role="presentation"><tbody><?php do_settings_fields( $page, $section_id ); ?></tbody></table></div><?php endif; ?>
			</section>
			<?php
		}
	}

	/**
	 * Renders one read-only settings summary card.
	 *
	 * @param string $label Card label.
	 * @param string $value Current value.
	 * @param string $detail Supporting explanation.
	 * @param string $icon Dashicon class.
	 * @param string $tone Presentation tone.
	 * @return void
	 */
	private function render_settings_summary_card( $label, $value, $detail, $icon, $tone ) {
		?>
		<article class="affilio-settings-summary__card is-<?php echo esc_attr( $tone ); ?>"><span class="affilio-settings-summary__icon dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><div><small><?php echo esc_html( $label ); ?></small><strong><?php echo esc_html( $value ); ?></strong><p><?php echo esc_html( $detail ); ?></p></div></article>
		<?php
	}

	/**
	 * Enqueues admin CSS only on Dreamax Affiliates screens.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function maybe_enqueue_admin_assets( $hook ) {
		$screen             = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_creative_screen = $screen && Affilio_Creatives::POST_TYPE === $screen->post_type;

		if ( false === strpos( (string) $hook, 'affilio' ) && ! $is_creative_screen ) {
			return;
		}
		$admin_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-admin.css';
		$admin_css_version = is_readable( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : AFFILIO_VERSION;

		wp_enqueue_style( 'affilio-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-admin.css', array(), $admin_css_version );
		wp_style_add_data( 'affilio-admin', 'rtl', 'replace' );
		if ( $is_creative_screen ) {
			$creatives_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-creatives-admin.css';
			$creatives_css_version = is_readable( $creatives_css_path ) ? (string) filemtime( $creatives_css_path ) : AFFILIO_VERSION;
			$creatives_js_path     = AFFILIO_PLUGIN_DIR . 'assets/js/affilio-creatives-admin.js';
			$creatives_js_version  = is_readable( $creatives_js_path ) ? (string) filemtime( $creatives_js_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-creatives-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-creatives-admin.css', array( 'affilio-admin' ), $creatives_css_version );
			wp_enqueue_script( 'affilio-creatives-admin', AFFILIO_PLUGIN_URL . 'assets/js/affilio-creatives-admin.js', array(), $creatives_js_version, true );
		}
		if ( 'toplevel_page_affilio' === (string) $hook ) {
			$overview_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-overview-admin.css';
			$overview_css_version = is_readable( $overview_css_path ) ? (string) filemtime( $overview_css_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-overview-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-overview-admin.css', array( 'affilio-admin' ), $overview_css_version );
		}
		if ( false !== strpos( (string) $hook, self::AFFILIATES_PAGE_SLUG ) ) {
			$affiliates_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-affiliates-admin.css';
			$affiliates_css_version = is_readable( $affiliates_css_path ) ? (string) filemtime( $affiliates_css_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-affiliates-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-affiliates-admin.css', array( 'affilio-admin' ), $affiliates_css_version );
		}
		if ( false !== strpos( (string) $hook, self::REFERRALS_PAGE_SLUG ) ) {
			$referrals_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-referrals-admin.css';
			$referrals_css_version = is_readable( $referrals_css_path ) ? (string) filemtime( $referrals_css_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-referrals-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-referrals-admin.css', array( 'affilio-admin' ), $referrals_css_version );
		}
		if ( false !== strpos( (string) $hook, 'affilio-coupons' ) ) {
			$coupons_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-coupons-admin.css';
			$coupons_css_version = is_readable( $coupons_css_path ) ? (string) filemtime( $coupons_css_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-coupons-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-coupons-admin.css', array( 'affilio-admin' ), $coupons_css_version );
		}
		if ( false !== strpos( (string) $hook, 'affilio-payout-requests' ) ) {
			$payout_requests_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-payout-requests-admin.css';
			$payout_requests_css_version = is_readable( $payout_requests_css_path ) ? (string) filemtime( $payout_requests_css_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-payout-requests-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-payout-requests-admin.css', array( 'affilio-admin' ), $payout_requests_css_version );
		}
		if ( false !== strpos( (string) $hook, 'affilio-payouts' ) ) {
			$payouts_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-payouts-admin.css';
			$payouts_css_version = is_readable( $payouts_css_path ) ? (string) filemtime( $payouts_css_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-payouts-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-payouts-admin.css', array( 'affilio-admin' ), $payouts_css_version );
		}
		if ( false !== strpos( (string) $hook, 'affilio-reports' ) ) {
			$reports_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-reports-admin.css';
			$reports_css_version = is_readable( $reports_css_path ) ? (string) filemtime( $reports_css_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-reports-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-reports-admin.css', array( 'affilio-admin' ), $reports_css_version );
		}
		if ( false !== strpos( (string) $hook, Affilio_Diagnostics::PAGE_SLUG ) ) {
			$diagnostics_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-diagnostics-admin.css';
			$diagnostics_css_version = is_readable( $diagnostics_css_path ) ? (string) filemtime( $diagnostics_css_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-diagnostics-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-diagnostics-admin.css', array( 'affilio-admin' ), $diagnostics_css_version );
		}
		if ( false !== strpos( (string) $hook, Affilio_Settings::PAGE_SLUG ) ) {
			$settings_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-settings-admin.css';
			$settings_css_version = is_readable( $settings_css_path ) ? (string) filemtime( $settings_css_path ) : AFFILIO_VERSION;
			wp_enqueue_style( 'affilio-settings-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-settings-admin.css', array( 'affilio-admin' ), $settings_css_version );
		}
	}

	/**
	 * Formats currency totals for display.
	 *
	 * @param array<string,float> $totals Totals by currency.
	 * @return string
	 */
	private function format_currency_totals( array $totals ) {
		if ( empty( $totals ) ) {
			return Affilio_I18n::number( 0, 2 );
		}

		$parts = array();
		foreach ( $totals as $currency => $amount ) {
			$parts[] = Affilio_I18n::amount( $amount, $currency );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Returns an escaped-ready order link.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private function get_order_link( $order_id ) {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
				return sprintf( '<a href="%1$s">#%2$d</a>', esc_url( $order->get_edit_order_url() ), (int) $order_id );
			}
		}
		return '#' . (int) $order_id;
	}
}
