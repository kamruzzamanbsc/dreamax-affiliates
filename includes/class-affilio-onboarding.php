<?php
/**
 * First-run setup, automatic page creation, and onboarding helpers.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Onboarding {

	const PAGE_SLUG                = 'affilio-setup';
	const OPTION_COMPLETED         = 'affilio_setup_completed';
	const OPTION_REDIRECT          = 'affilio_setup_redirect';
	const OPTION_REGISTRATION_PAGE = 'affilio_registration_page_id';
	const OPTION_DASHBOARD_PAGE    = 'affilio_dashboard_page_id';

	/**
	 * Registers onboarding hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_setup' ) );
		add_action( 'admin_notices', array( $this, 'render_setup_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_setup_assets' ) );

		add_action( 'admin_post_affilio_setup_save_pages', array( $this, 'save_pages_step' ) );
		add_action( 'admin_post_affilio_setup_save_settings', array( $this, 'save_settings_step' ) );
		add_action( 'admin_post_affilio_setup_finish', array( $this, 'finish_setup' ) );

		add_filter( 'plugin_action_links_' . AFFILIO_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Runs on activation. Creates safe default pages and schedules the
	 * first-run redirect without deleting or replacing existing content.
	 *
	 * @param bool $schedule_redirect Whether to schedule the first-run admin redirect.
	 * @return void
	 */
	public static function activate( $schedule_redirect = true ) {
		self::create_default_pages();
		add_option( self::OPTION_COMPLETED, 0, '', false );

		if ( $schedule_redirect && ! get_option( self::OPTION_COMPLETED, false ) ) {
			update_option( self::OPTION_REDIRECT, 1, false );
		}

		add_option( 'affilio_enable_my_account_tab', 1, '', false );
		update_option( 'affilio_rewrite_flush_required', 1, false );
	}

	/**
	 * Adds onboarding defaults when upgrading an existing installation.
	 *
	 * @return void
	 */
	public static function upgrade_to_140() {
		self::create_default_pages();
		add_option( self::OPTION_COMPLETED, 0, '', false );
		add_option( 'affilio_enable_my_account_tab', 1, '', false );
		update_option( 'affilio_rewrite_flush_required', 1, false );
	}

	/**
	 * Creates the registration and dashboard pages if suitable pages have
	 * not already been assigned. Existing assigned pages are never replaced.
	 *
	 * @return array<string,int>
	 */
	public static function create_default_pages() {
		return array(
			'registration' => self::ensure_default_page(
				self::OPTION_REGISTRATION_PAGE,
				__( 'Affiliate Registration', 'dreamax-affiliates' ),
				'affiliate-registration',
				'[dreamax_affiliates_registration]'
			),
			'dashboard'    => self::ensure_default_page(
				self::OPTION_DASHBOARD_PAGE,
				__( 'Affiliate Dashboard', 'dreamax-affiliates' ),
				'affiliate-dashboard',
				'[dreamax_affiliates_dashboard]'
			),
		);
	}

	/**
	 * Returns the configured registration page URL.
	 *
	 * @return string
	 */
	public static function get_registration_page_url() {
		return self::get_page_url( self::OPTION_REGISTRATION_PAGE );
	}

	/**
	 * Returns the configured dashboard page URL.
	 *
	 * @return string
	 */
	public static function get_dashboard_page_url() {
		return self::get_page_url( self::OPTION_DASHBOARD_PAGE );
	}

	/**
	 * Registers the setup screen below the Dreamax Affiliates menu.
	 *
	 * @return void
	 */
	public function register_setup_page() {
		add_submenu_page(
			'affilio',
			__( 'Dreamax Affiliates Setup', 'dreamax-affiliates' ),
			__( 'Setup', 'dreamax-affiliates' ),
			Affilio_Capabilities::MANAGE_AFFILIATES,
			self::PAGE_SLUG,
			array( $this, 'render_setup_page' )
		);
	}

	/**
	 * Redirects a single-site, interactive activation to the setup wizard.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_setup() {
		if ( ! get_option( self::OPTION_REDIRECT, false ) ) {
			return;
		}

		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only core activation routing flag.
			return;
		}

		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			return;
		}

		delete_option( self::OPTION_REDIRECT );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Displays a dismiss-free setup reminder until setup is completed.
	 *
	 * @return void
	 */
	public function render_setup_notice() {
		if (
			get_option( self::OPTION_COMPLETED, false )
			|| ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES )
			|| ! affilio_is_plugin_admin_notice_screen()
		) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		if ( self::PAGE_SLUG === $page ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Dreamax Affiliates is ready to configure.', 'dreamax-affiliates' ); ?></strong>
				<?php esc_html_e( 'Review the generated pages and core affiliate-program settings in the setup wizard.', 'dreamax-affiliates' ); ?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Run Setup', 'dreamax-affiliates' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Adds Setup and Settings links to the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_plugin_action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Setup', 'dreamax-affiliates' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . Affilio_Settings::PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'dreamax-affiliates' ) . '</a>',
		);

		return array_merge( $custom, $links );
	}

	/**
	 * Loads the wizard presentation only on the Setup screen.
	 *
	 * @param string $hook Current administration screen hook.
	 * @return void
	 */
	public function enqueue_setup_assets( $hook ) {
		if ( false === strpos( (string) $hook, '_page_' . self::PAGE_SLUG ) ) {
			return;
		}
		$path = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-setup-admin.css';
		wp_enqueue_style(
			'affilio-setup-admin',
			AFFILIO_PLUGIN_URL . 'assets/css/affilio-setup-admin.css',
			array( 'affilio-admin' ),
			is_readable( $path ) ? (string) filemtime( $path ) : AFFILIO_VERSION
		);
	}
	/**
	 * Renders the current setup wizard step.
	 *
	 * @return void
	 */
	public function render_setup_page() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}

		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = max( 1, min( 4, $step ) );
		?>
		<div class="wrap affilio-setup-wrap">
			<hr class="wp-header-end">
			<header class="affilio-setup-hero">
				<div class="affilio-setup-hero__content">
					<span class="affilio-setup-eyebrow"><?php esc_html_e( 'Your affiliate program starts here', 'dreamax-affiliates' ); ?></span>
					<h1><?php esc_html_e( 'Let’s set up your program', 'dreamax-affiliates' ); ?></h1>
					<p><?php esc_html_e( 'Choose your affiliate pages, define your rewards, and review the essentials before inviting your first partners.', 'dreamax-affiliates' ); ?></p>
				</div>
				<div class="affilio-setup-position">
					<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
					<div><span><?php esc_html_e( 'Guided setup', 'dreamax-affiliates' ); ?></span><strong><?php /* translators: %d: current wizard step, from 1 to 4. */ echo esc_html( sprintf( __( 'Step %d of 4', 'dreamax-affiliates' ), $step ) ); ?></strong></div>
				</div>
			</header>
			<?php $this->render_progress( $step ); ?>
			<div class="affilio-setup-layout">
			<section class="affilio-setup-card" aria-label="<?php esc_attr_e( 'Current setup step', 'dreamax-affiliates' ); ?>">
				<?php
				switch ( $step ) {
					case 2:
						$this->render_pages_step();
						break;
					case 3:
						$this->render_settings_step();
						break;
					case 4:
						$this->render_finish_step();
						break;
					default:
						$this->render_welcome_step();
				}
				?>
			</section>
			<?php $this->render_step_guidance( $step ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves selected pages and ensures their required shortcodes exist.
	 *
	 * @return void
	 */
	public function save_pages_step() {
		$this->verify_setup_request( 'affilio_setup_pages' );

		// verify_setup_request() above (current_user_can() + check_admin_referer()) runs first
		// on every call; the linter cannot see the nonce check inside that helper method.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$registration_page_id = isset( $_POST['registration_page_id'] ) ? absint( $_POST['registration_page_id'] ) : 0;
		$dashboard_page_id    = isset( $_POST['dashboard_page_id'] ) ? absint( $_POST['dashboard_page_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$registration_page_id = $this->prepare_selected_page(
			$registration_page_id,
			self::OPTION_REGISTRATION_PAGE,
			__( 'Affiliate Registration', 'dreamax-affiliates' ),
			'affiliate-registration',
			'dreamax_affiliates_registration'
		);
		$dashboard_page_id = $this->prepare_selected_page(
			$dashboard_page_id,
			self::OPTION_DASHBOARD_PAGE,
			__( 'Affiliate Dashboard', 'dreamax-affiliates' ),
			'affiliate-dashboard',
			'dreamax_affiliates_dashboard'
		);

		if ( ! $registration_page_id || ! $dashboard_page_id ) {
			$this->redirect_with_error( 2, __( 'Dreamax Affiliates could not prepare both pages. Please verify your page permissions and try again.', 'dreamax-affiliates' ) );
		}

		wp_safe_redirect( $this->setup_url( 3, array( 'saved' => 'pages' ) ) );
		exit;
	}

	/**
	 * Saves core program settings from the wizard.
	 *
	 * @return void
	 */
	public function save_settings_step() {
		$this->verify_setup_request( 'affilio_setup_settings' );

		// verify_setup_request() above (current_user_can() + check_admin_referer()) runs first
		// on every call; the linter cannot see the nonce check inside that helper method.
		// $commission_rate's (float) cast is a sanitizer the linter does not recognize by name.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$commission_type = isset( $_POST['commission_type'] ) ? sanitize_key( wp_unslash( $_POST['commission_type'] ) ) : 'percentage';
		$commission_type = in_array( $commission_type, array( 'percentage', 'flat' ), true ) ? $commission_type : 'percentage';
		$commission_rate = isset( $_POST['commission_rate'] ) ? max( 0, (float) wp_unslash( $_POST['commission_rate'] ) ) : 20;
		$cookie_duration = isset( $_POST['cookie_duration'] ) ? absint( $_POST['cookie_duration'] ) : AFFILIO_DEFAULT_COOKIE_DAYS;
		$cookie_duration = max( 1, min( 365, $cookie_duration ) );
		$attribution     = isset( $_POST['attribution_model'] ) ? sanitize_key( wp_unslash( $_POST['attribution_model'] ) ) : 'last_click';
		$attribution     = in_array( $attribution, array( 'first_click', 'last_click' ), true ) ? $attribution : 'last_click';

		$auto_approve = isset( $_POST['auto_approve'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['auto_approve'] ) );
		update_option( 'affilio_auto_approve_affiliates', $auto_approve ? 1 : 0 );
		update_option( 'affilio_default_commission_type', $commission_type );
		update_option( 'affilio_default_commission_rate', $commission_rate );
		update_option( 'affilio_cookie_duration_days', $cookie_duration );
		update_option( 'affilio_attribution_model', $attribution );
		$enable_my_account_tab = isset( $_POST['enable_my_account_tab'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enable_my_account_tab'] ) );
		update_option( 'affilio_enable_my_account_tab', $enable_my_account_tab ? 1 : 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		update_option( 'affilio_rewrite_flush_required', 1, false );

		wp_safe_redirect( $this->setup_url( 4, array( 'saved' => 'settings' ) ) );
		exit;
	}

	/**
	 * Marks setup complete.
	 *
	 * @return void
	 */
	public function finish_setup() {
		$this->verify_setup_request( 'affilio_setup_finish' );
		update_option( self::OPTION_COMPLETED, 1, false );
		delete_option( self::OPTION_REDIRECT );

		wp_safe_redirect( $this->setup_url( 4, array( 'completed' => '1' ) ) );
		exit;
	}

	/**
	 * @param int $step Current step.
	 * @return void
	 */
	private function render_progress( $step ) {
		$steps = array(
			1 => array( __( 'Welcome', 'dreamax-affiliates' ), __( 'Check the essentials', 'dreamax-affiliates' ) ),
			2 => array( __( 'Pages', 'dreamax-affiliates' ), __( 'Connect your pages', 'dreamax-affiliates' ) ),
			3 => array( __( 'Settings', 'dreamax-affiliates' ), __( 'Define your rewards', 'dreamax-affiliates' ) ),
			4 => array( __( 'Finish', 'dreamax-affiliates' ), __( 'Review your program', 'dreamax-affiliates' ) ),
		);
		?>
		<ol class="affilio-setup-progress" aria-label="<?php esc_attr_e( 'Setup progress', 'dreamax-affiliates' ); ?>">
			<?php foreach ( $steps as $number => $labels ) : ?>
				<li class="<?php echo esc_attr( $number === $step ? 'is-current' : 'is-other' ); ?>" <?php echo $number === $step ? 'aria-current="step"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute. ?>>
					<span class="affilio-setup-progress__number"><?php echo esc_html( $number ); ?></span>
					<div class="affilio-setup-progress__label"><strong><?php echo esc_html( $labels[0] ); ?></strong><small><?php echo esc_html( $labels[1] ); ?></small></div>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Renders short guidance for the current step.
	 *
	 * @param int $step Current wizard step.
	 * @return void
	 */
	private function render_step_guidance( $step ) {
		$guidance = array(
			1 => array( __( 'A clear path to launch', 'dreamax-affiliates' ), __( 'A few thoughtful defaults give your partners a consistent experience from their first visit.', 'dreamax-affiliates' ), array( __( 'Check your site readiness.', 'dreamax-affiliates' ), __( 'Choose where partners apply and sign in.', 'dreamax-affiliates' ), __( 'Set rewards that suit your store.', 'dreamax-affiliates' ) ) ),
			2 => array( __( 'Two pages. One journey.', 'dreamax-affiliates' ), __( 'Give applicants a clear starting point and approved affiliates a dedicated place to work.', 'dreamax-affiliates' ), array( __( 'Registration welcomes new applications.', 'dreamax-affiliates' ), __( 'The dashboard serves approved affiliates.', 'dreamax-affiliates' ), __( 'Preview your selected pages before continuing.', 'dreamax-affiliates' ) ) ),
			3 => array( __( 'Start with simple rules', 'dreamax-affiliates' ), __( 'Choose how affiliates join, how they earn, and which referral click receives credit.', 'dreamax-affiliates' ), array( __( 'Review applications manually or approve automatically.', 'dreamax-affiliates' ), __( 'Choose a percentage or flat reward.', 'dreamax-affiliates' ), __( 'Adjust these choices later in Settings.', 'dreamax-affiliates' ) ) ),
			4 => array( __( 'Make your first referral count', 'dreamax-affiliates' ), __( 'Review your saved choices, then test the complete partner journey before opening your program.', 'dreamax-affiliates' ), array( __( 'Preview the registration and dashboard pages.', 'dreamax-affiliates' ), __( 'Confirm your commission and attribution rules.', 'dreamax-affiliates' ), __( 'Run one referral through a staging order.', 'dreamax-affiliates' ) ) ),
		);
		$item = $guidance[ $step ];
		?>
		<aside class="affilio-setup-guide" aria-labelledby="affilio-setup-guide-title">
			<div class="affilio-setup-guide__heading"><span class="affilio-setup-icon-tile dashicons dashicons-lightbulb" aria-hidden="true"></span><span class="affilio-setup-eyebrow"><?php esc_html_e( 'A little guidance', 'dreamax-affiliates' ); ?></span></div>
			<h2 id="affilio-setup-guide-title"><?php echo esc_html( $item[0] ); ?></h2>
			<p><?php echo esc_html( $item[1] ); ?></p>
			<ul><?php foreach ( $item[2] as $tip ) : ?><li><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><span><?php echo esc_html( $tip ); ?></span></li><?php endforeach; ?></ul>
			<div class="affilio-setup-reassurance"><span class="dashicons dashicons-shield" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Your existing work stays', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'Revisiting setup keeps your affiliates and referral history. Page and program changes are applied when you save.', 'dreamax-affiliates' ); ?></p></div></div>
		</aside>
		<?php
	}

	/**
	 * @return void
	 */
	private function render_welcome_step() {
		$checks = array(
			array( __( 'WordPress version', 'dreamax-affiliates' ), get_bloginfo( 'version' ), version_compare( get_bloginfo( 'version' ), '6.0', '>=' ) ),
			array( __( 'PHP version', 'dreamax-affiliates' ), PHP_VERSION, version_compare( PHP_VERSION, '8.0', '>=' ) ),
			array( __( 'WooCommerce', 'dreamax-affiliates' ), affilio()->is_woocommerce_active() ? __( 'Active', 'dreamax-affiliates' ) : __( 'Not detected', 'dreamax-affiliates' ), affilio()->is_woocommerce_active() ),
		);
		?>
		<h2><?php esc_html_e( 'Welcome to Dreamax Affiliates', 'dreamax-affiliates' ); ?></h2>
		<p><?php esc_html_e( 'This guided setup prepares the public application page, the private affiliate dashboard, your default commission, and referral tracking behavior.', 'dreamax-affiliates' ); ?></p>
		<div class="affilio-setup-benefits">
			<div><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><strong><?php esc_html_e( 'Affiliate pages', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Use safe generated pages or select your own.', 'dreamax-affiliates' ); ?></span></div>
			<div><span class="dashicons dashicons-money-alt" aria-hidden="true"></span><strong><?php esc_html_e( 'Commission basics', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Choose a percentage or flat amount per order.', 'dreamax-affiliates' ); ?></span></div>
			<div><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><strong><?php esc_html_e( 'Referral tracking', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Set the cookie duration and click attribution rule.', 'dreamax-affiliates' ); ?></span></div>
		</div>
		<h3><?php esc_html_e( 'System readiness', 'dreamax-affiliates' ); ?></h3>
		<table class="widefat striped affilio-system-checks">
				<caption class="screen-reader-text"><?php esc_html_e( 'System readiness checks', 'dreamax-affiliates' ); ?></caption>
			<tbody>
			<?php foreach ( $checks as $check ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $check[0] ); ?></th>
					<td><?php echo esc_html( $check[1] ); ?></td>
					<td><span class="affilio-check-<?php echo $check[2] ? 'pass' : 'warn'; ?>"><?php echo $check[2] ? esc_html__( 'Ready', 'dreamax-affiliates' ) : esc_html__( 'Review', 'dreamax-affiliates' ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! affilio()->is_woocommerce_active() ) : ?>
			<p class="notice notice-warning inline"><?php esc_html_e( 'You can finish setup without WooCommerce, but sale attribution and the optional My Account affiliate shortcut will remain inactive until WooCommerce is installed and activated.', 'dreamax-affiliates' ); ?></p>
		<?php endif; ?>
		<p class="affilio-setup-actions"><a class="button button-primary button-hero" href="<?php echo esc_url( $this->setup_url( 2 ) ); ?>"><span><?php esc_html_e( 'Start Setup', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a></p>
		<?php
	}

	/**
	 * @return void
	 */
	private function render_pages_step() {
		$registration_id = absint( get_option( self::OPTION_REGISTRATION_PAGE, 0 ) );
		$dashboard_id    = absint( get_option( self::OPTION_DASHBOARD_PAGE, 0 ) );
		$this->render_query_notice();
		?>
		<h2><?php esc_html_e( 'Connect your affiliate pages', 'dreamax-affiliates' ); ?></h2>
		<p><?php esc_html_e( 'Dreamax Affiliates created sensible default pages. You can keep them or select existing pages; the required shortcode will be added only when it is missing.', 'dreamax-affiliates' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="affilio_setup_save_pages">
			<?php wp_nonce_field( 'affilio_setup_pages' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="affilio-registration-page"><?php esc_html_e( 'Registration page', 'dreamax-affiliates' ); ?></label></th>
					<td>
						<?php
						echo wp_dropdown_pages( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated by WordPress core.
							array(
								'name'              => 'registration_page_id',
								'id'                => 'affilio-registration-page',
								'selected'          => $registration_id,
								'show_option_none'  => __( 'Create a new default page', 'dreamax-affiliates' ),
								'option_none_value' => '0',
								'echo'              => 0,
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Visitors use this page to apply. Dreamax Affiliates adds [dreamax_affiliates_registration] only when it is missing.', 'dreamax-affiliates' ); ?></p>
						<?php $this->render_page_details( $registration_id, 'dreamax_affiliates_registration' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="affilio-dashboard-page"><?php esc_html_e( 'Dashboard page', 'dreamax-affiliates' ); ?></label></th>
					<td>
						<?php
						echo wp_dropdown_pages( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated by WordPress core.
							array(
								'name'              => 'dashboard_page_id',
								'id'                => 'affilio-dashboard-page',
								'selected'          => $dashboard_id,
								'show_option_none'  => __( 'Create a new default page', 'dreamax-affiliates' ),
								'option_none_value' => '0',
								'echo'              => 0,
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Approved affiliates use this page to create links, view results, and manage payout details.', 'dreamax-affiliates' ); ?></p>
						<?php $this->render_page_details( $dashboard_id, 'dreamax_affiliates_dashboard' ); ?>
					</td>
				</tr>
			</table>
			<p class="affilio-setup-actions">
				<a class="button" href="<?php echo esc_url( $this->setup_url( 1 ) ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><span><?php esc_html_e( 'Back', 'dreamax-affiliates' ); ?></span></a>
				<button class="button button-primary" type="submit"><span><?php esc_html_e( 'Save and Continue', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>
			</p>
		</form>
		<?php
	}

	/**
	 * @return void
	 */
	private function render_settings_step() {
		$this->render_query_notice();
		?>
		<h2><?php esc_html_e( 'Shape your affiliate program', 'dreamax-affiliates' ); ?></h2>
		<p><?php esc_html_e( 'Start with simple defaults. Every option on this screen can be changed later from Dreamax Affiliates Settings.', 'dreamax-affiliates' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="affilio_setup_save_settings">
			<?php wp_nonce_field( 'affilio_setup_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Affiliate approval', 'dreamax-affiliates' ); ?></th>
					<td><label><input type="checkbox" name="auto_approve" value="1" <?php checked( get_option( 'affilio_auto_approve_affiliates', false ) ); ?>><span> <?php esc_html_e( 'Automatically approve new applications', 'dreamax-affiliates' ); ?></span></label><p class="description"><?php esc_html_e( 'Leave this off when you want to review each applicant before they can promote your store.', 'dreamax-affiliates' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="affilio-setup-commission-type"><?php esc_html_e( 'Commission', 'dreamax-affiliates' ); ?></label></th>
					<td>
						<div class="affilio-setup-commission-fields"><select id="affilio-setup-commission-type" aria-describedby="affilio-setup-commission-help" name="commission_type">
							<option value="percentage" <?php selected( get_option( 'affilio_default_commission_type', 'percentage' ), 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'dreamax-affiliates' ); ?></option>
							<option value="flat" <?php selected( get_option( 'affilio_default_commission_type', 'percentage' ), 'flat' ); ?>><?php esc_html_e( 'Flat amount', 'dreamax-affiliates' ); ?></option>
						</select>
						<label class="screen-reader-text" for="affilio-setup-commission-rate"><?php esc_html_e( 'Commission rate', 'dreamax-affiliates' ); ?></label>
						<input id="affilio-setup-commission-rate" aria-describedby="affilio-setup-commission-help" type="number" step="0.01" min="0" name="commission_rate" value="<?php echo esc_attr( get_option( 'affilio_default_commission_rate', 20 ) ); ?>">
						</div><p id="affilio-setup-commission-help" class="description"><?php esc_html_e( 'For percentage commissions, enter percentage points such as 20. For flat commissions, enter the amount paid per qualifying order.', 'dreamax-affiliates' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="affilio-setup-cookie-duration"><?php esc_html_e( 'Referral duration', 'dreamax-affiliates' ); ?></label></th>
					<td><input id="affilio-setup-cookie-duration" type="number" min="1" max="365" name="cookie_duration" value="<?php echo esc_attr( get_option( 'affilio_cookie_duration_days', AFFILIO_DEFAULT_COOKIE_DAYS ) ); ?>"> <?php esc_html_e( 'days', 'dreamax-affiliates' ); ?><p class="description"><?php esc_html_e( 'A sale can be credited when it happens within this many days after the affiliate click.', 'dreamax-affiliates' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="affilio-setup-attribution"><?php esc_html_e( 'Attribution model', 'dreamax-affiliates' ); ?></label></th>
					<td>
						<select id="affilio-setup-attribution" name="attribution_model">
							<option value="last_click" <?php selected( get_option( 'affilio_attribution_model', 'last_click' ), 'last_click' ); ?>><?php esc_html_e( 'Last affiliate click', 'dreamax-affiliates' ); ?></option>
							<option value="first_click" <?php selected( get_option( 'affilio_attribution_model', 'last_click' ), 'first_click' ); ?>><?php esc_html_e( 'First affiliate click', 'dreamax-affiliates' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Last click is the simplest default. First click keeps the earliest affiliate visit during the referral window.', 'dreamax-affiliates' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WooCommerce My Account', 'dreamax-affiliates' ); ?></th>
					<td>
						<label><input type="checkbox" name="enable_my_account_tab" value="1" <?php checked( get_option( 'affilio_enable_my_account_tab', true ) ); ?>><span> <?php esc_html_e( 'Add an Affiliate Dashboard shortcut to WooCommerce My Account', 'dreamax-affiliates' ); ?></span></label><p class="description"><?php esc_html_e( 'This is a link to the standalone affiliate portal; the dashboard is not embedded inside WooCommerce My Account.', 'dreamax-affiliates' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="affilio-setup-actions">
				<a class="button" href="<?php echo esc_url( $this->setup_url( 2 ) ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><span><?php esc_html_e( 'Back', 'dreamax-affiliates' ); ?></span></a>
				<button class="button button-primary" type="submit"><span><?php esc_html_e( 'Save and Continue', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>
			</p>
		</form>
		<?php
	}

	/**
	 * @return void
	 */
	private function render_finish_step() {
		$registration_url = self::get_registration_page_url();
		$dashboard_url    = self::get_dashboard_page_url();
		$completed        = get_option( self::OPTION_COMPLETED, false );
		$commission_type  = get_option( 'affilio_default_commission_type', 'percentage' );
		$commission_rate  = (float) get_option( 'affilio_default_commission_rate', 20 );
		$commission_label = 'flat' === $commission_type
			? sprintf(
				/* translators: %s: formatted currency amount. */
				__( '%s per qualifying order', 'dreamax-affiliates' ),
				Affilio_I18n::amount( $commission_rate, function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' )
			)
			: sprintf(
				/* translators: %s: formatted percentage. */
				__( '%s per qualifying order', 'dreamax-affiliates' ),
				Affilio_I18n::percentage( $commission_rate, 2 )
			);
		$attribution      = get_option( 'affilio_attribution_model', 'last_click' );
		$attribution_label = 'first_click' === $attribution
			? __( 'First affiliate click', 'dreamax-affiliates' )
			: __( 'Last affiliate click', 'dreamax-affiliates' );
		$this->render_query_notice();
		?>
		<h2><?php echo $completed ? esc_html__( 'Dreamax Affiliates setup is complete', 'dreamax-affiliates' ) : esc_html__( 'Review your affiliate program', 'dreamax-affiliates' ); ?></h2>
		<p><?php esc_html_e( 'Review the essentials below. You can return to this wizard at any time without removing affiliate data.', 'dreamax-affiliates' ); ?></p>
		<ul class="affilio-setup-summary">
			<li><strong><?php esc_html_e( 'Registration:', 'dreamax-affiliates' ); ?></strong> <a href="<?php echo esc_url( $registration_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $registration_url ); ?></a></li>
			<li><strong><?php esc_html_e( 'Dashboard:', 'dreamax-affiliates' ); ?></strong> <a href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $dashboard_url ); ?></a></li>
			<li><strong><?php esc_html_e( 'Commission:', 'dreamax-affiliates' ); ?></strong> <?php echo esc_html( $commission_label ); ?></li>
			<li><strong><?php esc_html_e( 'Attribution:', 'dreamax-affiliates' ); ?></strong> <?php echo esc_html( $attribution_label ); ?></li>
		</ul>
		<div class="affilio-launch-steps">
			<h3><?php esc_html_e( 'Recommended launch test', 'dreamax-affiliates' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Open the registration page and submit one test application.', 'dreamax-affiliates' ); ?></li>
				<li><?php esc_html_e( 'Approve the test affiliate and copy a referral link from the dashboard.', 'dreamax-affiliates' ); ?></li>
				<li><?php esc_html_e( 'Complete a staging WooCommerce order through that link and confirm the referral appears.', 'dreamax-affiliates' ); ?></li>
				<li><?php esc_html_e( 'Test the manual payout workflow before sending real funds.', 'dreamax-affiliates' ); ?></li>
			</ol>
		</div>
		<?php if ( ! $completed ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="affilio_setup_finish">
				<?php wp_nonce_field( 'affilio_setup_finish' ); ?>
				<p class="affilio-setup-actions">
					<a class="button" href="<?php echo esc_url( $this->setup_url( 3 ) ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><span><?php esc_html_e( 'Back', 'dreamax-affiliates' ); ?></span></a>
					<button class="button button-primary button-hero" type="submit"><span><?php esc_html_e( 'Finish Setup', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></button>
				</p>
			</form>
		<?php else : ?>
			<p class="affilio-setup-actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio' ) ); ?>"><span><?php esc_html_e( 'Open Overview', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-dashboard" aria-hidden="true"></span></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio-affiliates' ) ); ?>"><span><?php esc_html_e( 'Manage Affiliates', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-groups" aria-hidden="true"></span></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Affilio_Settings::PAGE_SLUG ) ); ?>"><span><?php esc_html_e( 'Open Settings', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Shows the current page state and convenient preview/edit links.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $shortcode Required shortcode tag.
	 * @return void
	 */
	private function render_page_details( $page_id, $shortcode ) {
		$page     = $page_id ? get_post( $page_id ) : null;
		$is_ready = $page && 'page' === $page->post_type && 'publish' === $page->post_status && has_shortcode( $page->post_content, $shortcode );
		?>
		<p class="affilio-page-state">
			<span class="<?php echo esc_attr( 'affilio-check-' . ( $is_ready ? 'pass' : 'warn' ) ); ?>"><?php echo $is_ready ? esc_html__( 'Ready', 'dreamax-affiliates' ) : esc_html__( 'Needs review', 'dreamax-affiliates' ); ?></span>
			<?php if ( $page ) : ?>
				<a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview', 'dreamax-affiliates' ); ?></a>
				<a href="<?php echo esc_url( get_edit_post_link( $page_id, 'raw' ) ); ?>"><?php esc_html_e( 'Edit page', 'dreamax-affiliates' ); ?></a>
			<?php else : ?>
				<span><?php esc_html_e( 'A safe default page will be created when you continue.', 'dreamax-affiliates' ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * @param string $nonce_action Expected nonce action.
	 * @return void
	 */
	private function verify_setup_request( $nonce_action ) {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Makes a selected page safe for its Dreamax Affiliates shortcode, or creates a new
	 * default page when no selection was supplied.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $option Option key.
	 * @param string $title Default title.
	 * @param string $slug Default slug.
	 * @param string $shortcode_tag Shortcode tag without brackets.
	 * @return int
	 */
	private function prepare_selected_page( $page_id, $option, $title, $slug, $shortcode_tag ) {
		if ( ! $page_id ) {
			return self::ensure_default_page( $option, $title, $slug, '[' . $shortcode_tag . ']' );
		}

		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return 0;
		}

		if ( ! has_shortcode( $page->post_content, $shortcode_tag ) ) {
			$result = wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => rtrim( (string) $page->post_content ) . "\n\n[" . $shortcode_tag . ']'
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return 0;
			}
		}

		update_option( $option, $page_id, false );
		return $page_id;
	}

	/**
	 * @param int    $step Wizard step.
	 * @param string $nonce_error Error message.
	 * @return void
	 */
	private function redirect_with_error( $step, $nonce_error ) {
		set_transient( 'affilio_setup_error_' . get_current_user_id(), sanitize_text_field( $nonce_error ), MINUTE_IN_SECONDS );
		wp_safe_redirect( $this->setup_url( $step, array( 'error' => '1' ) ) );
		exit;
	}

	/**
	 * Displays safe feedback from setup form redirects.
	 *
	 * @return void
	 */
	private function render_query_notice() {
		if ( isset( $_GET['error'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key     = 'affilio_setup_error_' . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key );
			if ( $message ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
			}
		}

		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'dreamax-affiliates' ) . '</p></div>';
		}
	}

	/**
	 * @param int                 $step Wizard step.
	 * @param array<string,string> $args Optional query arguments.
	 * @return string
	 */
	private function setup_url( $step, array $args = array() ) {
		$args = array_merge(
			array(
				'page' => self::PAGE_SLUG,
				'step' => absint( $step ),
			),
			$args
		);
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * @param string $option Option key.
	 * @param string $title Page title.
	 * @param string $slug Page slug.
	 * @param string $shortcode Full shortcode.
	 * @return int
	 */
	private static function ensure_default_page( $option, $title, $slug, $shortcode ) {
		$current_id = absint( get_option( $option, 0 ) );
		$current    = $current_id ? get_post( $current_id ) : null;

		if ( $current && 'page' === $current->post_type && 'trash' !== $current->post_status ) {
			return $current_id;
		}

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing && 'trash' !== $existing->post_status && has_shortcode( $existing->post_content, trim( $shortcode, '[]' ) ) ) {
			update_option( $option, $existing->ID, false );
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $shortcode,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'comment_status' => 'closed',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		update_option( $option, (int) $page_id, false );
		return (int) $page_id;
	}

	/**
	 * @param string $option Page-ID option.
	 * @return string
	 */
	private static function get_page_url( $option ) {
		$page_id = absint( get_option( $option, 0 ) );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ? $url : home_url( '/' );
	}
}
