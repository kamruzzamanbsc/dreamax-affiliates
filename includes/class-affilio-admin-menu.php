<?php
/**
 * Registers and renders Dreamax Affiliates admin screens.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Admin_Menu {

	const AFFILIATES_PAGE_SLUG = 'affilio-affiliates';

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
		add_submenu_page( 'affilio', __( 'Referrals', 'dreamax-affiliates' ), __( 'Referrals', 'dreamax-affiliates' ), $capability, 'affilio-referrals', array( affilio()->service( 'admin.workflows' ), 'render_referrals' ) );
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
		?>
		<div class="wrap affilio-overview-wrap">
			<h1><?php esc_html_e( 'Dreamax Affiliates Overview', 'dreamax-affiliates' ); ?></h1>
			<p class="affilio-overview-intro"><?php esc_html_e( 'Launch your affiliate program, review the work that needs attention, and jump directly to the next task.', 'dreamax-affiliates' ); ?></p>

			<div class="affilio-overview-actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG ) ); ?>"><?php echo $setup_complete ? esc_html__( 'Review Setup', 'dreamax-affiliates' ) : esc_html__( 'Continue Setup', 'dreamax-affiliates' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Manage Affiliates', 'dreamax-affiliates' ); ?></a>
				<?php if ( $registration['url'] ) : ?><a class="button" href="<?php echo esc_url( $registration['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Registration Page', 'dreamax-affiliates' ); ?></a><?php endif; ?>
			</div>

			<div class="affilio-overview-grid affilio-overview-metrics" aria-label="<?php echo esc_attr__( 'Affiliate program summary', 'dreamax-affiliates' ); ?>">
				<?php /* translators: %1$s: number of active affiliates; %2$s: number of affiliates awaiting review. */ ?>
				<?php $this->render_overview_metric( __( 'Affiliates', 'dreamax-affiliates' ), Affilio_I18n::number( $affiliate_total ), sprintf( __( '%1$s active, %2$s awaiting review', 'dreamax-affiliates' ), Affilio_I18n::number( $active_count ), Affilio_I18n::number( $pending_count ) ), admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG ) ); ?>
				<?php $this->render_overview_metric( __( 'Tracked Clicks', 'dreamax-affiliates' ), Affilio_I18n::number( $click_count ), __( 'All recorded affiliate visits', 'dreamax-affiliates' ), admin_url( 'admin.php?page=affilio-reports' ) ); ?>
				<?php $this->render_overview_metric( __( 'Referrals', 'dreamax-affiliates' ), Affilio_I18n::number( $referral_count ), __( 'Recorded commissions and conversions', 'dreamax-affiliates' ), admin_url( 'admin.php?page=affilio-referrals' ) ); ?>
				<?php $this->render_overview_metric( __( 'Commission Rule', 'dreamax-affiliates' ), $commission_label, 'flat' === $commission_type ? __( 'Flat amount per order', 'dreamax-affiliates' ) : __( 'Percentage of the commission base', 'dreamax-affiliates' ), admin_url( 'admin.php?page=' . Affilio_Settings::PAGE_SLUG ) ); ?>
			</div>

			<div class="affilio-overview-grid affilio-overview-columns">
				<section class="affilio-admin-card" aria-labelledby="affilio-launch-checklist-title">
					<h2 id="affilio-launch-checklist-title"><?php esc_html_e( 'Launch Checklist', 'dreamax-affiliates' ); ?></h2>
					<p><?php esc_html_e( 'Complete these essentials before inviting affiliates.', 'dreamax-affiliates' ); ?></p>
					<ul class="affilio-checklist">
						<?php $this->render_checklist_item( $setup_complete, __( 'Core setup saved', 'dreamax-affiliates' ), admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG ) ); ?>
						<?php $this->render_checklist_item( $registration['ready'], __( 'Registration page is published and contains its shortcode', 'dreamax-affiliates' ), $registration['edit_url'] ); ?>
						<?php $this->render_checklist_item( $dashboard['ready'], __( 'Dashboard page is published and contains its shortcode', 'dreamax-affiliates' ), $dashboard['edit_url'] ); ?>
						<?php $this->render_checklist_item( $woocommerce, __( 'WooCommerce is active for sale attribution', 'dreamax-affiliates' ), admin_url( 'plugins.php' ) ); ?>
					</ul>
				</section>

				<section class="affilio-admin-card" aria-labelledby="affilio-attention-title">
					<h2 id="affilio-attention-title"><?php esc_html_e( 'Needs Attention', 'dreamax-affiliates' ); ?></h2>
					<?php if ( 0 === $pending_count && 0 === $request_count ) : ?>
						<p class="affilio-empty-state"><?php esc_html_e( 'There are no pending affiliate applications or payout requests right now.', 'dreamax-affiliates' ); ?></p>
					<?php else : ?>
						<ul class="affilio-task-list">
							<?php /* translators: %s: number of pending affiliate applications. */ ?>
							<?php if ( $pending_count > 0 ) : ?><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG . '&status=pending' ) ); ?>"><?php echo esc_html( sprintf( _n( '%s affiliate application is waiting for review', '%s affiliate applications are waiting for review', $pending_count, 'dreamax-affiliates' ), Affilio_I18n::number( $pending_count ) ) ); ?></a></li><?php endif; ?>
							<?php /* translators: %s: number of pending payout requests. */ ?>
							<?php if ( $request_count > 0 ) : ?><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-payout-requests&status=requested' ) ); ?>"><?php echo esc_html( sprintf( _n( '%s payout request is waiting', '%s payout requests are waiting', $request_count, 'dreamax-affiliates' ), Affilio_I18n::number( $request_count ) ) ); ?></a></li><?php endif; ?>
						</ul>
					<?php endif; ?>
					<h3><?php esc_html_e( 'Helpful next action', 'dreamax-affiliates' ); ?></h3>
					<?php if ( ! $setup_complete || ! $registration['ready'] || ! $dashboard['ready'] ) : ?>
						<p><?php esc_html_e( 'Finish the setup checklist so affiliates have a working application and dashboard.', 'dreamax-affiliates' ); ?></p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Open Setup', 'dreamax-affiliates' ); ?></a>
					<?php elseif ( 0 === $active_count ) : ?>
						<p><?php esc_html_e( 'Invite your first affiliate or add an existing WordPress user manually.', 'dreamax-affiliates' ); ?></p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG . '&view=add' ) ); ?>"><?php esc_html_e( 'Add Affiliate', 'dreamax-affiliates' ); ?></a>
					<?php elseif ( 0 === $click_count ) : ?>
						<p><?php esc_html_e( 'Open the affiliate dashboard and test one referral link before sharing the program publicly.', 'dreamax-affiliates' ); ?></p>
						<?php if ( $dashboard['url'] ) : ?><a class="button" href="<?php echo esc_url( $dashboard['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Test Dashboard', 'dreamax-affiliates' ); ?></a><?php endif; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'Review performance regularly and process eligible commissions through the manual payout workflow.', 'dreamax-affiliates' ); ?></p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-reports' ) ); ?>"><?php esc_html_e( 'View Reports', 'dreamax-affiliates' ); ?></a>
					<?php endif; ?>
				</section>
			</div>

			<section class="affilio-admin-card affilio-quick-links" aria-labelledby="affilio-quick-links-title">
				<h2 id="affilio-quick-links-title"><?php esc_html_e( 'Quick Actions', 'dreamax-affiliates' ); ?></h2>
				<div class="affilio-quick-link-grid">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::AFFILIATES_PAGE_SLUG ) ); ?>"><strong><?php esc_html_e( 'Affiliates', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Approve applications and manage accounts.', 'dreamax-affiliates' ); ?></span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-referrals' ) ); ?>"><strong><?php esc_html_e( 'Referrals', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Review commissions or add a manual referral.', 'dreamax-affiliates' ); ?></span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-reports' ) ); ?>"><strong><?php esc_html_e( 'Reports', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'See clicks, conversions, and earnings.', 'dreamax-affiliates' ); ?></span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-payouts' ) ); ?>"><strong><?php esc_html_e( 'Payouts', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Create batches and record completed payments.', 'dreamax-affiliates' ); ?></span></a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Affilio_Creatives::POST_TYPE ) ); ?>"><strong><?php esc_html_e( 'Creatives', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Publish reusable banners and campaign links.', 'dreamax-affiliates' ); ?></span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Affilio_Settings::PAGE_SLUG ) ); ?>"><strong><?php esc_html_e( 'Settings', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Adjust tracking, commissions, emails, and privacy.', 'dreamax-affiliates' ); ?></span></a>
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
	 * @return void
	 */
	private function render_overview_metric( $label, $value, $description, $url ) {
		?>
		<a class="affilio-overview-metric" href="<?php echo esc_url( $url ); ?>">
			<span class="affilio-overview-metric-label"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $value ); ?></strong>
			<span><?php echo esc_html( $description ); ?></span>
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
			<span class="<?php echo esc_attr( 'dashicons ' . ( $complete ? 'dashicons-yes-alt' : 'dashicons-marker' ) ); ?>" aria-hidden="true"></span>
			<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<span class="screen-reader-text"><?php echo $complete ? esc_html__( 'Complete', 'dreamax-affiliates' ) : esc_html__( 'Needs action', 'dreamax-affiliates' ); ?></span>
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

		$user             = get_userdata( $affiliate->user_id );
		$methods          = affilio()->payouts->get_payout_methods();
		$commission_type  = ! empty( $affiliate->commission_type ) ? $affiliate->commission_type : 'inherit';
		$commission_rate  = isset( $affiliate->commission_rate ) && null !== $affiliate->commission_rate ? $affiliate->commission_rate : '';
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
			<h1><?php esc_html_e( 'Affiliate Reports', 'dreamax-affiliates' ); ?></h1>

			<form method="get" class="affilio-report-filters">
				<input type="hidden" name="page" value="affilio-reports">
				<label>
					<span><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></span>
					<select name="affiliate_id">
						<option value="0"><?php esc_html_e( 'All affiliates', 'dreamax-affiliates' ); ?></option>
						<?php foreach ( $affiliates as $affiliate ) : ?>
							<option value="<?php echo esc_attr( $affiliate->id ); ?>" <?php selected( $filters['affiliate_id'], $affiliate->id ); ?>><?php echo esc_html( '' !== (string) $affiliate->display_name ? $affiliate->display_name : '#' . $affiliate->id ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'From', 'dreamax-affiliates' ); ?></span>
					<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from_ui'] ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'To', 'dreamax-affiliates' ); ?></span>
					<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to_ui'] ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Campaign', 'dreamax-affiliates' ); ?></span>
					<input type="text" name="campaign" value="<?php echo esc_attr( $filters['campaign'] ); ?>" maxlength="100">
				</label>
				<label>
					<span><?php esc_html_e( 'Conversion', 'dreamax-affiliates' ); ?></span>
					<select name="converted">
						<option value=""><?php esc_html_e( 'All clicks', 'dreamax-affiliates' ); ?></option>
						<option value="1" <?php selected( $filters['converted'], 1 ); ?>><?php esc_html_e( 'Converted', 'dreamax-affiliates' ); ?></option>
						<option value="0" <?php selected( $filters['converted'], 0 ); ?>><?php esc_html_e( 'Not converted', 'dreamax-affiliates' ); ?></option>
					</select>
				</label>
				<div class="affilio-report-filter-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'dreamax-affiliates' ); ?></button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-reports' ) ); ?>"><?php esc_html_e( 'Reset', 'dreamax-affiliates' ); ?></a>
				</div>
			</form>

			<dl class="affilio-report-cards" aria-label="<?php echo esc_attr__( 'Affiliate report summary', 'dreamax-affiliates' ); ?>">
				<?php $this->render_report_card( __( 'Clicks', 'dreamax-affiliates' ), Affilio_I18n::number( $visit_summary['clicks'] ) ); ?>
				<?php $this->render_report_card( __( 'Unique Visitors', 'dreamax-affiliates' ), Affilio_I18n::number( $visit_summary['unique_visitors'] ) ); ?>
				<?php $this->render_report_card( __( 'Conversions', 'dreamax-affiliates' ), Affilio_I18n::number( $visit_summary['conversions'] ) ); ?>
				<?php $this->render_report_card( __( 'Conversion Rate', 'dreamax-affiliates' ), Affilio_I18n::percentage( $visit_summary['conversion_rate'], 2 ) ); ?>
				<?php $this->render_report_card( __( 'Referrals', 'dreamax-affiliates' ), Affilio_I18n::number( $referral_summary['count'] ) ); ?>
				<?php $this->render_report_card( __( 'Recorded Commission', 'dreamax-affiliates' ), $this->format_currency_totals( $referral_summary['commission_totals'] ) ); ?>
			</dl>

			<?php
			/**
			 * Lets a separately distributed add-on render provider-owned report sections.
			 *
			 * @param array $report_data Basic immutable summary plus provider datasets.
			 * @param array $filters     Sanitized report filters.
			 */
			do_action( 'affilio_admin_reports_after_summary', $report_data, $filters );
			?>

			<p class="affilio-export-actions">
				<a class="button" href="<?php echo esc_url( Affilio_Reports::get_admin_export_url( 'visits', $filters ) ); ?>"><?php esc_html_e( 'Export Clicks CSV', 'dreamax-affiliates' ); ?></a>
				<a class="button" href="<?php echo esc_url( Affilio_Reports::get_admin_export_url( 'referrals', $referral_filters ) ); ?>"><?php esc_html_e( 'Export Referrals CSV', 'dreamax-affiliates' ); ?></a>
			</p>


			<h2><?php esc_html_e( 'Click Log', 'dreamax-affiliates' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="affilio-reports">
				<?php foreach ( array( 'affiliate_id', 'date_from', 'date_to', 'campaign', 'converted' ) as $key ) : ?>
					<?php if ( isset( $_GET[ $key ] ) && '' !== (string) wp_unslash( $_GET[ $key ] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only filter state used to re-populate the report form (not a state-changing action); isset() does not use the value, and the value is sanitized on the next line where it is actually output. ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
					<?php endif; ?>
				<?php endforeach; ?>
				<?php $table->search_box( __( 'Search clicks', 'dreamax-affiliates' ), 'affilio-visits' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param string $label Card label.
	 * @param string $value Card value.
	 * @return void
	 */
	private function render_report_card( $label, $value ) {
		?>
		<div class="affilio-report-card">
			<dt><?php echo esc_html( $label ); ?></dt>
			<dd><?php echo esc_html( $value ); ?></dd>
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
		$table = new Affilio_Payouts_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payouts', 'dreamax-affiliates' ); ?></h1>
			<p><?php esc_html_e( 'Create new payout batches from the Referrals screen. Mark a payout paid only after the funds have been sent.', 'dreamax-affiliates' ); ?></p>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="affilio-payouts">
				<?php $table->search_box( __( 'Search payouts', 'dreamax-affiliates' ), 'affilio-payouts' ); ?>
				<?php $table->display(); ?>
			</form>
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
		<div class="wrap affilio-payout-detail">
			<?php /* translators: %s: payout batch key/identifier. */ ?>
		<h1><?php echo esc_html( sprintf( __( 'Payout %s', 'dreamax-affiliates' ), $payout->batch_key ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-payouts' ) ); ?>">&larr; <?php esc_html_e( 'Back to payouts', 'dreamax-affiliates' ); ?></a></p>

			<div class="affilio-admin-table-wrap">
			<table class="widefat striped affilio-payout-summary-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Payout summary', 'dreamax-affiliates' ); ?></caption>
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'Affiliate', 'dreamax-affiliates' ); ?></th><td><?php echo esc_html( $user ? $user->display_name : __( '(unknown)', 'dreamax-affiliates' ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Amount', 'dreamax-affiliates' ); ?></th><td><?php echo esc_html( Affilio_I18n::amount( $payout->amount, $payout->currency ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Method', 'dreamax-affiliates' ); ?></th><td><?php echo esc_html( Affilio_I18n::payment_method_label( $payout->payment_method, $methods ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Destination', 'dreamax-affiliates' ); ?></th><td><pre class="affilio-destination-pre"><?php echo esc_html( $payout->payment_destination ); ?></pre></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></th><td><span class="affilio-status affilio-status-<?php echo esc_attr( $payout->status ); ?>"><?php echo esc_html( Affilio_I18n::status_label( $payout->status ) ); ?></span></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Reference', 'dreamax-affiliates' ); ?></th><td><?php echo $payout->reference ? esc_html( $payout->reference ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Notes', 'dreamax-affiliates' ); ?></th><td><?php echo $payout->notes ? nl2br( esc_html( $payout->notes ) ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			</tbody>
			</table>
			</div>

			<h2><?php esc_html_e( 'Included Referrals', 'dreamax-affiliates' ); ?></h2>
			<div class="affilio-admin-table-wrap">
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'Referrals included in this payout', 'dreamax-affiliates' ); ?></caption>
				<thead><tr><th scope="col"><?php esc_html_e( 'Referral', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Order', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Commission', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $referrals ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No referrals are linked to this payout.', 'dreamax-affiliates' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $referrals as $referral ) : ?>
						<tr>
							<td>#<?php echo esc_html( $referral->id ); ?></td>
							<td><?php echo wp_kses_post( $this->get_order_link( $referral->order_id ) ); ?></td>
							<td><?php echo esc_html( Affilio_I18n::amount( $referral->commission_amount, $referral->currency ) ); ?></td>
							<td><?php echo esc_html( Affilio_I18n::status_label( $referral->status ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</div>

			<?php if ( 'processing' === $payout->status ) : ?>
				<div class="affilio-payout-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<h2><?php esc_html_e( 'Complete Payout', 'dreamax-affiliates' ); ?></h2>
						<input type="hidden" name="action" value="affilio_mark_payout_paid">
						<input type="hidden" name="payout_id" value="<?php echo esc_attr( $payout->id ); ?>">
						<?php wp_nonce_field( 'affilio_mark_payout_paid_' . $payout->id ); ?>
						<p><label for="affilio-payout-reference"><strong><?php esc_html_e( 'Payment reference (optional)', 'dreamax-affiliates' ); ?></strong></label><br><input class="regular-text" type="text" id="affilio-payout-reference" name="reference"></p>
						<p><label for="affilio-payout-notes"><strong><?php esc_html_e( 'Internal notes (optional)', 'dreamax-affiliates' ); ?></strong></label><br><textarea class="large-text" rows="4" id="affilio-payout-notes" name="notes"></textarea></p>
						<?php submit_button( __( 'Mark Payout as Paid', 'dreamax-affiliates' ), 'primary', 'submit', false ); ?>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this payout and return its referrals to unpaid status?', 'dreamax-affiliates' ) ); ?>');">
						<input type="hidden" name="action" value="affilio_cancel_payout">
						<input type="hidden" name="payout_id" value="<?php echo esc_attr( $payout->id ); ?>">
						<?php wp_nonce_field( 'affilio_cancel_payout_' . $payout->id ); ?>
						<?php submit_button( __( 'Cancel Payout', 'dreamax-affiliates' ), 'secondary', 'submit', false ); ?>
					</form>


					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<h2><?php esc_html_e( 'Failed Payment Attempt', 'dreamax-affiliates' ); ?></h2>
						<input type="hidden" name="action" value="affilio_mark_payout_failed">
						<input type="hidden" name="payout_id" value="<?php echo esc_attr( $payout->id ); ?>">
						<?php wp_nonce_field( 'affilio_mark_payout_failed_' . $payout->id ); ?>
						<p><label for="affilio-payout-failure-reason"><strong><?php esc_html_e( 'Failure reason', 'dreamax-affiliates' ); ?></strong></label><br><textarea class="large-text" rows="3" id="affilio-payout-failure-reason" name="failure_reason" required></textarea></p>
						<?php submit_button( __( 'Mark Payout as Failed', 'dreamax-affiliates' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dreamax Affiliates Settings', 'dreamax-affiliates' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( Affilio_Settings::OPTION_GROUP ); ?>
				<?php do_settings_sections( Affilio_Settings::PAGE_SLUG ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Enqueues admin CSS only on Dreamax Affiliates screens.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function maybe_enqueue_admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_creative_screen = $screen && Affilio_Creatives::POST_TYPE === $screen->post_type;

		if ( false === strpos( (string) $hook, 'affilio' ) && ! $is_creative_screen ) {
			return;
		}
		$admin_css_path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-admin.css';
		$admin_css_version = is_readable( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : AFFILIO_VERSION;

		wp_enqueue_style( 'affilio-admin', AFFILIO_PLUGIN_URL . 'assets/css/affilio-admin.css', array(), $admin_css_version );
		wp_style_add_data( 'affilio-admin', 'rtl', 'replace' );

	}

	/**
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
