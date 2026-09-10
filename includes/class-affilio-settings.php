<?php
/**
 * Settings screen for the independently useful WordPress.org Free workflows.
 * Advanced fraud policies and editable email content are provided only by
 * separately distributed add-ons through typed extension contracts.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and validates the independently useful Core settings workspace.
 */
class Affilio_Settings {

	const OPTION_GROUP = 'affilio_settings_group';
	const PAGE_SLUG    = 'affilio-settings';

	/**
	 * Registers Settings API initialization.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers every setting field via the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			Affilio_Onboarding::OPTION_REGISTRATION_PAGE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_registration_page_id' ),
				'default'           => 0,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			Affilio_Onboarding::OPTION_DASHBOARD_PAGE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_dashboard_page_id' ),
				'default'           => 0,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_enable_my_account_tab',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_my_account_enabled' ),
				'default'           => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_auto_approve_affiliates',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_cookie_duration_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_cookie_duration' ),
				'default'           => AFFILIO_DEFAULT_COOKIE_DAYS,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_attribution_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_attribution_model' ),
				'default'           => 'last_click',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_coupon_attribution_priority',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_coupon_priority' ),
				'default'           => 'coupon_first',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_anonymize_ip_addresses',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_ip_retention_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_ip_retention_days' ),
				'default'           => 90,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_delete_data_on_uninstall',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_default_commission_type',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_commission_type' ),
				'default'           => 'percentage',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_default_commission_rate',
			array(
				'type'              => 'number',
				'sanitize_callback' => array( $this, 'sanitize_commission_rate' ),
				'default'           => 20,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_registration_fields',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_registration_fields' ),
				'default'           => Affilio_Registration::get_profile_field_definitions(),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_qualifying_order_statuses',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_qualifying_statuses' ),
				'default'           => array( 'completed' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_enable_payout_requests',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_minimum_payout_threshold',
			array(
				'type'              => 'number',
				'sanitize_callback' => array( $this, 'sanitize_payout_threshold' ),
				'default'           => 50,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'affilio_email_notifications',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Affilio_Email_Templates', 'sanitize_notification_settings' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'affilio_pages_section',
			__( 'Pages and Account Integration', 'dreamax-affiliates' ),
			array( $this, 'render_pages_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			Affilio_Onboarding::OPTION_REGISTRATION_PAGE,
			__( 'Registration page', 'dreamax-affiliates' ),
			array( $this, 'render_registration_page_field' ),
			self::PAGE_SLUG,
			'affilio_pages_section'
		);

		add_settings_field(
			Affilio_Onboarding::OPTION_DASHBOARD_PAGE,
			__( 'Dashboard page', 'dreamax-affiliates' ),
			array( $this, 'render_dashboard_page_field' ),
			self::PAGE_SLUG,
			'affilio_pages_section'
		);

		add_settings_field(
			'affilio_enable_my_account_tab',
			__( 'WooCommerce My Account', 'dreamax-affiliates' ),
			array( $this, 'render_my_account_field' ),
			self::PAGE_SLUG,
			'affilio_pages_section'
		);

		add_settings_section(
			'affilio_main_section',
			__( 'General Settings', 'dreamax-affiliates' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'affilio_auto_approve_affiliates',
			__( 'Auto-approve new affiliates', 'dreamax-affiliates' ),
			array( $this, 'render_auto_approve_field' ),
			self::PAGE_SLUG,
			'affilio_main_section'
		);

		add_settings_field(
			'affilio_cookie_duration_days',
			__( 'Referral cookie duration', 'dreamax-affiliates' ),
			array( $this, 'render_cookie_duration_field' ),
			self::PAGE_SLUG,
			'affilio_main_section'
		);

		add_settings_field(
			'affilio_attribution_model',
			__( 'Attribution model', 'dreamax-affiliates' ),
			array( $this, 'render_attribution_model_field' ),
			self::PAGE_SLUG,
			'affilio_main_section'
		);

		add_settings_field(
			'affilio_coupon_attribution_priority',
			__( 'Coupon attribution priority', 'dreamax-affiliates' ),
			array( $this, 'render_coupon_priority_field' ),
			self::PAGE_SLUG,
			'affilio_main_section'
		);

		add_settings_field(
			'affilio_anonymize_ip_addresses',
			__( 'IP privacy', 'dreamax-affiliates' ),
			array( $this, 'render_anonymize_ip_field' ),
			self::PAGE_SLUG,
			'affilio_main_section'
		);

		add_settings_field(
			'affilio_ip_retention_days',
			__( 'IP retention period', 'dreamax-affiliates' ),
			array( $this, 'render_ip_retention_field' ),
			self::PAGE_SLUG,
			'affilio_main_section'
		);

		add_settings_field(
			'affilio_default_commission_type',
			__( 'Default commission type', 'dreamax-affiliates' ),
			array( $this, 'render_commission_type_field' ),
			self::PAGE_SLUG,
			'affilio_main_section'
		);

		add_settings_field(
			'affilio_default_commission_rate',
			__( 'Default commission rate', 'dreamax-affiliates' ),
			array( $this, 'render_commission_rate_field' ),
			self::PAGE_SLUG,
			'affilio_main_section'
		);

		add_settings_section(
			'affilio_registration_section',
			__( 'Affiliate Registration Fields', 'dreamax-affiliates' ),
			array( $this, 'render_registration_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'affilio_registration_fields',
			__( 'Application fields', 'dreamax-affiliates' ),
			array( $this, 'render_registration_fields' ),
			self::PAGE_SLUG,
			'affilio_registration_section'
		);

		add_settings_section(
			'affilio_approval_section',
			__( 'Commission Approval', 'dreamax-affiliates' ),
			array( $this, 'render_approval_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'affilio_qualifying_order_statuses',
			__( 'Qualifying order statuses', 'dreamax-affiliates' ),
			array( $this, 'render_qualifying_statuses' ),
			self::PAGE_SLUG,
			'affilio_approval_section'
		);

		add_settings_section(
			'affilio_payout_request_section',
			__( 'Affiliate Payout Requests', 'dreamax-affiliates' ),
			array( $this, 'render_payout_request_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'affilio_enable_payout_requests',
			__( 'Payout requests', 'dreamax-affiliates' ),
			array( $this, 'render_enable_payout_requests' ),
			self::PAGE_SLUG,
			'affilio_payout_request_section'
		);

		add_settings_field(
			'affilio_minimum_payout_threshold',
			__( 'Minimum payout threshold', 'dreamax-affiliates' ),
			array( $this, 'render_payout_threshold' ),
			self::PAGE_SLUG,
			'affilio_payout_request_section'
		);

		add_settings_section(
			'affilio_email_section',
			__( 'Email Notifications', 'dreamax-affiliates' ),
			array( $this, 'render_email_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'affilio_email_notifications',
			__( 'Essential notifications', 'dreamax-affiliates' ),
			array( $this, 'render_email_notifications' ),
			self::PAGE_SLUG,
			'affilio_email_section'
		);

		add_settings_section(
			'affilio_data_section',
			__( 'Data Removal', 'dreamax-affiliates' ),
			array( $this, 'render_data_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'affilio_delete_data_on_uninstall',
			__( 'Delete data on uninstall', 'dreamax-affiliates' ),
			array( $this, 'render_delete_data_field' ),
			self::PAGE_SLUG,
			'affilio_data_section'
		);
	}

	/**
	 * Sanitizes the selected registration page.
	 *
	 * @param mixed $value Raw registration page ID.
	 * @return int
	 */
	public function sanitize_registration_page_id( $value ) {
		return $this->sanitize_page_with_shortcode( $value, 'dreamax_affiliates_registration' );
	}

	/**
	 * Sanitizes the selected affiliate dashboard page.
	 *
	 * @param mixed $value Raw dashboard page ID.
	 * @return int
	 */
	public function sanitize_dashboard_page_id( $value ) {
		return $this->sanitize_page_with_shortcode( $value, 'dreamax_affiliates_dashboard' );
	}

	/**
	 * Validates an administrator-selected page and appends the required
	 * shortcode only when it is missing.
	 *
	 * @param mixed  $value Raw page ID.
	 * @param string $shortcode_tag Shortcode tag without brackets.
	 * @return int
	 */
	private function sanitize_page_with_shortcode( $value, $shortcode_tag ) {
		$page_id = absint( $value );
		if ( ! $page_id ) {
			return 0;
		}

		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			add_settings_error(
				'affilio_pages',
				'affilio_invalid_page',
				__( 'The selected Dreamax Affiliates page is not valid.', 'dreamax-affiliates' )
			);
			return 0;
		}

		if ( ! has_shortcode( $page->post_content, $shortcode_tag ) ) {
			$result = wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => rtrim( (string) $page->post_content ) . "\n\n[" . $shortcode_tag . ']',
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'affilio_pages',
					'affilio_page_update_failed',
					__( 'Dreamax Affiliates could not add the required shortcode to the selected page.', 'dreamax-affiliates' )
				);
				return 0;
			}
		}

		return $page_id;
	}

	/**
	 * Sanitizes the WooCommerce account integration toggle.
	 *
	 * @param mixed $value Raw checkbox value.
	 * @return bool
	 */
	public function sanitize_my_account_enabled( $value ) {
		update_option( 'affilio_rewrite_flush_required', 1, false );
		return rest_sanitize_boolean( $value );
	}

	/**
	 * Sanitizes the referral cookie duration.
	 *
	 * @param mixed $value Raw cookie duration.
	 * @return int
	 */
	public function sanitize_cookie_duration( $value ) {
		return max( 1, min( 365, absint( $value ) ) );
	}

	/**
	 * Sanitizes the referral attribution model.
	 *
	 * @param mixed $value Raw attribution model.
	 * @return string
	 */
	public function sanitize_attribution_model( $value ) {
		return in_array( $value, array( 'first_click', 'last_click' ), true ) ? $value : 'last_click';
	}


	/**
	 * Sanitizes coupon-versus-cookie attribution priority.
	 *
	 * @param mixed $value Raw coupon priority.
	 * @return string
	 */
	public function sanitize_coupon_priority( $value ) {
		return in_array( $value, array( 'coupon_first', 'cookie_first' ), true ) ? $value : 'coupon_first';
	}

	/**
	 * Sanitizes the stored IP retention period.
	 *
	 * @param mixed $value Raw retention period.
	 * @return int
	 */
	public function sanitize_ip_retention_days( $value ) {
		return max( 1, min( 3650, absint( $value ) ) );
	}

	/**
	 * Sanitizes the default commission type.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string 'percentage' or 'flat'.
	 */
	public function sanitize_commission_type( $value ) {
		return in_array( $value, array( 'percentage', 'flat' ), true ) ? $value : 'percentage';
	}

	/**
	 * Sanitizes the default commission rate.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return float Never negative.
	 */
	public function sanitize_commission_rate( $value ) {
		return max( 0, (float) $value );
	}

	/**
	 * Describes the affiliate page integration section.
	 *
	 * @return void
	 */
	public function render_pages_section() {
		echo '<p>' . esc_html__( 'Choose the standalone affiliate pages. WooCommerce My Account can optionally show a shortcut to the appropriate standalone affiliate page.', 'dreamax-affiliates' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Affilio_Onboarding::PAGE_SLUG ) ) . '">' . esc_html__( 'Open setup wizard', 'dreamax-affiliates' ) . '</a></p>';
	}

	/**
	 * Renders the registration page selector.
	 *
	 * @return void
	 */
	public function render_registration_page_field() {
		$this->render_page_dropdown( Affilio_Onboarding::OPTION_REGISTRATION_PAGE, 'affilio-registration-page-setting', 'dreamax_affiliates_registration' );
	}

	/**
	 * Renders the affiliate dashboard page selector.
	 *
	 * @return void
	 */
	public function render_dashboard_page_field() {
		$this->render_page_dropdown( Affilio_Onboarding::OPTION_DASHBOARD_PAGE, 'affilio-dashboard-page-setting', 'dreamax_affiliates_dashboard' );
	}

	/**
	 * Renders the WooCommerce account integration toggle.
	 *
	 * @return void
	 */
	public function render_my_account_field() {
		$value = (bool) get_option( 'affilio_enable_my_account_tab', true );
		?>
		<input type="hidden" name="affilio_enable_my_account_tab" value="0">
		<label>
			<input type="checkbox" name="affilio_enable_my_account_tab" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Add an Affiliate Dashboard shortcut to WooCommerce My Account', 'dreamax-affiliates' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'The shortcut opens the standalone affiliate dashboard (or registration page for users who have not applied). Affiliate tools are not embedded inside the WooCommerce account-content column.', 'dreamax-affiliates' ); ?></p>
		<?php
	}

	/**
	 * Renders a validated affiliate page selector.
	 *
	 * @param string $option Option name.
	 * @param string $id Select element ID.
	 * @param string $shortcode_tag Required shortcode tag.
	 * @return void
	 */
	private function render_page_dropdown( $option, $id, $shortcode_tag ) {
		$selected = absint( get_option( $option, 0 ) );
		// $option and $id are always internal literals from this method's own call sites (never
		// user input; see the two callers above), and wp_dropdown_pages() escapes its own markup
		// internally. esc_attr()/esc_html__() are added defensively for the linter and for safety
		// if this private method is ever called with a new value in future.
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( $option ),
				'id'                => esc_attr( $id ),
				'selected'          => absint( $selected ),
				'show_option_none'  => esc_html__( 'Select a page', 'dreamax-affiliates' ),
				'option_none_value' => '0',
			)
		);
		/* translators: %s: shortcode tag required on this page, e.g. "dreamax_affiliates_registration". */
		echo '<p class="description">' . esc_html( sprintf( __( 'The required [%s] shortcode is added automatically when it is missing.', 'dreamax-affiliates' ), $shortcode_tag ) ) . '</p>';
	}

	/**
	 * Renders the auto-approve checkbox.
	 *
	 * Uses the standard hidden-field-before-checkbox pattern: an unchecked
	 * checkbox simply isn't included in a form submission at all, so
	 * without the hidden "0" fallback, unchecking this would leave the
	 * option unchanged rather than turning it off.
	 *
	 * @return void
	 */
	public function render_auto_approve_field() {
		$value = get_option( 'affilio_auto_approve_affiliates', false );
		?>
		<input type="hidden" name="affilio_auto_approve_affiliates" value="0">
		<label>
			<input type="checkbox" name="affilio_auto_approve_affiliates" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Automatically approve new affiliate applications instead of requiring manual review', 'dreamax-affiliates' ); ?>
		</label>
		<?php
	}

	/**
	 * Renders the referral cookie duration field.
	 *
	 * @return void
	 */
	public function render_cookie_duration_field() {
		$value = get_option( 'affilio_cookie_duration_days', AFFILIO_DEFAULT_COOKIE_DAYS );
		?>
		<input type="number" min="1" max="365" name="affilio_cookie_duration_days" value="<?php echo esc_attr( $value ); ?>">
		<?php esc_html_e( 'days', 'dreamax-affiliates' ); ?>
		<p class="description"><?php esc_html_e( 'How long a referral click remains eligible for commission.', 'dreamax-affiliates' ); ?></p>
		<?php
	}

	/**
	 * Renders the referral attribution model field.
	 *
	 * @return void
	 */
	public function render_attribution_model_field() {
		$value = get_option( 'affilio_attribution_model', 'last_click' );
		?>
		<select name="affilio_attribution_model">
			<option value="last_click" <?php selected( $value, 'last_click' ); ?>><?php esc_html_e( 'Last affiliate click', 'dreamax-affiliates' ); ?></option>
			<option value="first_click" <?php selected( $value, 'first_click' ); ?>><?php esc_html_e( 'First affiliate click', 'dreamax-affiliates' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Choose whether a later affiliate link replaces an existing valid attribution.', 'dreamax-affiliates' ); ?></p>
		<?php
	}


	/**
	 * Renders coupon-versus-link attribution priority.
	 *
	 * @return void
	 */
	public function render_coupon_priority_field() {
		$value = get_option( 'affilio_coupon_attribution_priority', 'coupon_first' );
		?>
		<select name="affilio_coupon_attribution_priority">
			<option value="coupon_first" <?php selected( $value, 'coupon_first' ); ?>><?php esc_html_e( 'Assigned coupon overrides referral cookie', 'dreamax-affiliates' ); ?></option>
			<option value="cookie_first" <?php selected( $value, 'cookie_first' ); ?>><?php esc_html_e( 'Referral cookie overrides assigned coupon', 'dreamax-affiliates' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Controls attribution when an order has both a tracked referral visit and an affiliate-assigned coupon.', 'dreamax-affiliates' ); ?></p>
		<?php
	}

	/**
	 * Renders the visitor IP anonymization toggle.
	 *
	 * @return void
	 */
	public function render_anonymize_ip_field() {
		$value = (bool) get_option( 'affilio_anonymize_ip_addresses', true );
		?>
		<input type="hidden" name="affilio_anonymize_ip_addresses" value="0">
		<label>
			<input type="checkbox" name="affilio_anonymize_ip_addresses" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Anonymize visitor IP addresses before storing them', 'dreamax-affiliates' ); ?>
		</label>
		<?php
	}

	/**
	 * Renders the stored IP retention field.
	 *
	 * @return void
	 */
	public function render_ip_retention_field() {
		$value = get_option( 'affilio_ip_retention_days', 90 );
		?>
		<input type="number" min="1" max="3650" name="affilio_ip_retention_days" value="<?php echo esc_attr( $value ); ?>">
		<?php esc_html_e( 'days', 'dreamax-affiliates' ); ?>
		<p class="description"><?php esc_html_e( 'Stored IP values are cleared after this period while visit totals remain available.', 'dreamax-affiliates' ); ?></p>
		<?php
	}

	/**
	 * Renders the default commission type field.
	 *
	 * @return void
	 */
	public function render_commission_type_field() {
		$value = get_option( 'affilio_default_commission_type', 'percentage' );
		?>
		<select id="affilio-default-commission-type" name="affilio_default_commission_type" aria-label="<?php echo esc_attr__( 'Default commission type', 'dreamax-affiliates' ); ?>">
			<option value="percentage" <?php selected( $value, 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'dreamax-affiliates' ); ?></option>
			<option value="flat" <?php selected( $value, 'flat' ); ?>><?php esc_html_e( 'Flat amount', 'dreamax-affiliates' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Renders the default commission rate field.
	 *
	 * @return void
	 */
	public function render_commission_rate_field() {
		$value = get_option( 'affilio_default_commission_rate', 20 );
		?>
		<input id="affilio-default-commission-rate" type="number" step="0.01" min="0" name="affilio_default_commission_rate" aria-label="<?php echo esc_attr__( 'Default commission rate', 'dreamax-affiliates' ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<p class="description"><?php esc_html_e( 'A percentage (e.g. 20 for 20%) or a flat currency amount, depending on the type above. Individual affiliates can still override this.', 'dreamax-affiliates' ); ?></p>
		<?php
	}

	/**
	 * Describes the uninstall data policy section.
	 *
	 * @return void
	 */
	public function render_data_section() {
		echo '<p>' . esc_html__( 'Dreamax Affiliates preserves program data by default when the plugin is deleted, protecting against accidental loss.', 'dreamax-affiliates' ) . '</p>';
	}

	/**
	 * Renders the destructive uninstall data toggle.
	 *
	 * @return void
	 */
	public function render_delete_data_field() {
		$value = (bool) get_option( 'affilio_delete_data_on_uninstall', false );
		?>
		<input type="hidden" name="affilio_delete_data_on_uninstall" value="0">
		<label>
			<input type="checkbox" name="affilio_delete_data_on_uninstall" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Permanently delete Dreamax Affiliates tables, settings, creatives, and plugin-owned metadata when the plugin is deleted', 'dreamax-affiliates' ); ?>
		</label>
		<p class="description"><strong><?php esc_html_e( 'This cannot be undone.', 'dreamax-affiliates' ); ?></strong> <?php esc_html_e( 'WordPress user accounts are never deleted.', 'dreamax-affiliates' ); ?></p>
		<?php
	}

	/**
	 * Sanitizes the controlled registration-field configuration.
	 *
	 * @param mixed $value Raw field configuration.
	 * @return array<string,array<string,mixed>>
	 */
	public function sanitize_registration_fields( $value ) {
		$value   = is_array( $value ) ? $value : array();
		$output  = array();
		$defined = Affilio_Registration::get_profile_field_definitions();

		foreach ( $defined as $key => $definition ) {
			$row = isset( $value[ $key ] ) && is_array( $value[ $key ] ) ? $value[ $key ] : array();

			$output[ $key ] = array(
				'enabled'  => ! empty( $row['enabled'] ),
				'required' => ! empty( $row['enabled'] ) && ! empty( $row['required'] ),
				'order'    => isset( $row['order'] ) ? max( 1, min( 100, absint( $row['order'] ) ) ) : (int) $definition['order'],
			);
		}

		return $output;
	}

	/**
	 * Sanitizes WooCommerce order statuses used to qualify commissions.
	 *
	 * @param mixed $value Raw statuses.
	 * @return string[]
	 */
	public function sanitize_qualifying_statuses( $value ) {
		$value   = is_array( $value ) ? $value : array();
		$allowed = array_keys( $this->get_order_status_choices() );
		$clean   = array();

		foreach ( $value as $status ) {
			$status = sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );
			if ( in_array( $status, $allowed, true ) ) {
				$clean[] = $status;
			}
		}

		if ( empty( $clean ) ) {
			$clean = array( 'completed' );
		}

		return array_values( array_unique( $clean ) );
	}


	/**
	 * Sanitizes the minimum payout-request threshold.
	 *
	 * @param mixed $value Raw threshold.
	 * @return float
	 */
	public function sanitize_payout_threshold( $value ) {
		$value = (float) $value;
		if ( ! is_finite( $value ) ) {
			return 0.0;
		}

		return max( 0, min( 99999999999.99, round( $value, 2 ) ) );
	}

	/**
	 * Returns supported WooCommerce order statuses.
	 *
	 * @return array<string,string>
	 */
	private function get_order_status_choices() {
		$supported = array( 'pending', 'processing', 'on-hold', 'completed' );
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$output = array();
			foreach ( wc_get_order_statuses() as $key => $label ) {
				$status = sanitize_key( preg_replace( '/^wc-/', '', $key ) );
				if ( in_array( $status, $supported, true ) ) {
					$output[ $status ] = $label;
				}
			}
			if ( empty( $output ) ) {
				return array( 'completed' => __( 'Completed', 'dreamax-affiliates' ) );
			}

			return $output;
		}

		return array(
			'pending'    => __( 'Pending payment', 'dreamax-affiliates' ),
			'processing' => __( 'Processing', 'dreamax-affiliates' ),
			'on-hold'    => __( 'On hold', 'dreamax-affiliates' ),
			'completed'  => __( 'Completed', 'dreamax-affiliates' ),
		);
	}

	/**
	 * Describes the registration-field settings section.
	 *
	 * @return void
	 */
	public function render_registration_section() {
		echo '<p>' . esc_html__( 'Choose the basic information collected with each affiliate application. Existing honeypot, consent, nonce, and rate-limit protections remain active. An anti-spam filter is available for approved CAPTCHA integrations.', 'dreamax-affiliates' ) . '</p>';
	}

	/**
	 * Renders the basic field enable, required, and order controls.
	 *
	 * @return void
	 */
	public function render_registration_fields() {
		$config = Affilio_Registration::get_profile_field_config();
		$rows   = Affilio_Registration::get_profile_field_definitions();

		echo '<div class="affilio-admin-table-wrap"><table class="widefat striped affilio-registration-fields-table"><caption class="screen-reader-text">' . esc_html__( 'Affiliate application field configuration', 'dreamax-affiliates' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Field', 'dreamax-affiliates' ) . '</th><th scope="col">' . esc_html__( 'Enabled', 'dreamax-affiliates' ) . '</th><th scope="col">' . esc_html__( 'Required', 'dreamax-affiliates' ) . '</th><th scope="col">' . esc_html__( 'Order', 'dreamax-affiliates' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $key => $definition ) {
			$row           = $config[ $key ];
			$enabled_id    = 'affilio-registration-' . sanitize_html_class( $key ) . '-enabled';
			$required_id   = 'affilio-registration-' . sanitize_html_class( $key ) . '-required';
			$order_id      = 'affilio-registration-' . sanitize_html_class( $key ) . '-order';
			$enabled_name  = 'affilio_registration_fields[' . $key . '][enabled]';
			$required_name = 'affilio_registration_fields[' . $key . '][required]';
			$order_name    = 'affilio_registration_fields[' . $key . '][order]';
			?>
			<tr>
				<th scope="row"><?php echo esc_html( $definition['label'] ); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr( $enabled_name ); ?>" value="0">
					<input type="checkbox" id="<?php echo esc_attr( $enabled_id ); ?>" name="<?php echo esc_attr( $enabled_name ); ?>" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?>>
					<?php /* translators: %s: registration field label, e.g. "Website URL". */ ?>
					<label class="screen-reader-text" for="<?php echo esc_attr( $enabled_id ); ?>"><?php echo esc_html( sprintf( __( 'Enable %s', 'dreamax-affiliates' ), $definition['label'] ) ); ?></label>
				</td>
				<td>
					<input type="hidden" name="<?php echo esc_attr( $required_name ); ?>" value="0">
					<input type="checkbox" id="<?php echo esc_attr( $required_id ); ?>" name="<?php echo esc_attr( $required_name ); ?>" value="1" <?php checked( ! empty( $row['required'] ) ); ?>>
					<?php /* translators: %s: registration field label, e.g. "Website URL". */ ?>
					<label class="screen-reader-text" for="<?php echo esc_attr( $required_id ); ?>"><?php echo esc_html( sprintf( __( 'Require %s', 'dreamax-affiliates' ), $definition['label'] ) ); ?></label>
				</td>
				<td>
					<?php /* translators: %s: registration field label, e.g. "Website URL". */ ?>
					<label class="screen-reader-text" for="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html( sprintf( __( 'Display order for %s', 'dreamax-affiliates' ), $definition['label'] ) ); ?></label>
					<input type="number" id="<?php echo esc_attr( $order_id ); ?>" min="1" max="100" name="<?php echo esc_attr( $order_name ); ?>" value="<?php echo esc_attr( $row['order'] ); ?>" class="small-text">
				</td>
			</tr>
			<?php
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Describes commission qualification and holding.
	 *
	 * @return void
	 */
	public function render_approval_section() {
		echo '<p>' . esc_html__( 'Choose which WooCommerce order statuses create an immediately payout-eligible referral in the Free plugin.', 'dreamax-affiliates' ) . '</p>';
	}

	/**
	 * Renders qualifying WooCommerce order statuses.
	 *
	 * @return void
	 */
	public function render_qualifying_statuses() {
		$selected = Affilio_Commission_Approval::get_qualifying_statuses();

		foreach ( $this->get_order_status_choices() as $status => $label ) {
			echo '<label class="affilio-inline-checkbox"><input type="checkbox" name="affilio_qualifying_order_statuses[]" value="' . esc_attr( $status ) . '" ' . checked( in_array( $status, $selected, true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
		}
	}


	/**
	 * Describes affiliate payout requests.
	 *
	 * @return void
	 */
	public function render_payout_request_section() {
		echo '<p>' . esc_html__( 'Allow active affiliates to request their available unpaid balance. Administrators review and pay requests manually.', 'dreamax-affiliates' ) . '</p>';
	}

	/**
	 * Renders the payout-request toggle.
	 *
	 * @return void
	 */
	public function render_enable_payout_requests() {
		$value = (bool) get_option( 'affilio_enable_payout_requests', true );
		echo '<input type="hidden" name="affilio_enable_payout_requests" value="0"><label><input type="checkbox" name="affilio_enable_payout_requests" value="1" ' . checked( $value, true, false ) . '> ' . esc_html__( 'Enable affiliate payout requests', 'dreamax-affiliates' ) . '</label>';
	}

	/**
	 * Renders the minimum payout threshold.
	 *
	 * @return void
	 */
	public function render_payout_threshold() {
		$value = (float) get_option( 'affilio_minimum_payout_threshold', 50 );
		echo '<input type="number" min="0" step="0.01" name="affilio_minimum_payout_threshold" value="' . esc_attr( $value ) . '">';
		echo '<p class="description">' . esc_html__( 'The threshold is evaluated separately for each referral currency.', 'dreamax-affiliates' ) . '</p>';
	}


	/**
	 * Describes the essential Free notification settings.
	 *
	 * @return void
	 */
	public function render_email_section() {
		echo '<p>' . esc_html__( 'Choose which essential application, referral, and payout emails Dreamax Affiliates sends. Free uses fixed, translatable plain-text content; a separately distributed add-on may provide editable templates.', 'dreamax-affiliates' ) . '</p>';
	}

	/**
	 * Renders notification enable/disable controls without editable content.
	 *
	 * @return void
	 */
	public function render_email_notifications() {
		$stored = Affilio_Email_Templates::notification_settings();

		echo '<fieldset class="affilio-email-notifications"><legend class="screen-reader-text">' . esc_html__( 'Essential email notifications', 'dreamax-affiliates' ) . '</legend>';
		foreach ( Affilio_Email_Templates::definitions() as $key => $definition ) {
			$field_id = 'affilio-email-notification-' . sanitize_html_class( $key );
			echo '<p><input type="hidden" name="affilio_email_notifications[' . esc_attr( $key ) . ']" value="0">';
			echo '<label for="' . esc_attr( $field_id ) . '"><input id="' . esc_attr( $field_id ) . '" type="checkbox" name="affilio_email_notifications[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $stored[ $key ] ), true, false ) . '> ' . esc_html( $definition['label'] ) . '</label></p>';
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Email delivery depends on the site mail configuration. Existing custom subjects and messages are preserved in the database for a compatible add-on but are not executed or editable by Dreamax Affiliates Free.', 'dreamax-affiliates' ) . '</p>';
	}
}
