<?php
/**
 * WooCommerce My Account shortcut to the standalone affiliate portal.
 *
 * Dreamax Affiliates keeps registration and affiliate tools on dedicated
 * WordPress pages. WooCommerce My Account can expose an optional shortcut,
 * but it does not embed the full affiliate dashboard inside WooCommerce's
 * account-content column.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_My_Account {

	const ENDPOINT = 'affiliate-area';

	/**
	 * Registers WooCommerce account hooks when WooCommerce is active.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'filter_endpoint_url' ), 20, 4 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_legacy_endpoint' ), 20 );

		// Kept as a defensive fallback for themes/extensions that bypass the
		// normal WooCommerce endpoint URL filter.
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint_fallback' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'filter_endpoint_title' ) );
	}

	/**
	 * Registers the historical WooCommerce account endpoint.
	 *
	 * The endpoint remains registered for backwards compatibility with saved
	 * links/bookmarks. Its normal navigation URL is filtered to the standalone
	 * affiliate portal.
	 *
	 * @return void
	 */
	public static function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flushes rewrite rules once after activation, upgrade, or option change.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( ! get_option( 'affilio_rewrite_flush_required', false ) ) {
			return;
		}

		flush_rewrite_rules( false );
		delete_option( 'affilio_rewrite_flush_required' );
	}

	/**
	 * Adds an optional shortcut immediately before the WooCommerce logout link.
	 *
	 * @param array<string,string> $items Existing menu items.
	 * @return array<string,string>
	 */
	public function add_menu_item( $items ) {
		if ( ! get_option( 'affilio_enable_my_account_tab', true ) ) {
			return $items;
		}

		$label     = apply_filters( 'affilio_my_account_menu_label', __( 'Affiliate Dashboard', 'dreamax-affiliates' ) );
		$new_items = array();

		foreach ( $items as $key => $value ) {
			if ( 'customer-logout' === $key ) {
				$new_items[ self::ENDPOINT ] = $label;
			}
			$new_items[ $key ] = $value;
		}

		if ( ! isset( $new_items[ self::ENDPOINT ] ) ) {
			$new_items[ self::ENDPOINT ] = $label;
		}

		return $new_items;
	}

	/**
	 * Converts the WooCommerce account endpoint URL into a direct standalone
	 * affiliate-portal link.
	 *
	 * WooCommerce exposes the final endpoint URL through
	 * `woocommerce_get_endpoint_url`, allowing extensions to provide linked
	 * account destinations without taking over the My Account content layout.
	 *
	 * @param string $url       WooCommerce-generated endpoint URL.
	 * @param string $endpoint  Endpoint slug after WooCommerce mapping.
	 * @param string $value     Endpoint value.
	 * @param string $permalink Base permalink.
	 * @return string
	 */
	public function filter_endpoint_url( $url, $endpoint, $value, $permalink ) {
		unset( $value, $permalink );

		if ( ! get_option( 'affilio_enable_my_account_tab', true ) || self::ENDPOINT !== $endpoint ) {
			return $url;
		}

		$portal_url = self::get_portal_url();
		return $portal_url ? $portal_url : $url;
	}

	/**
	 * Redirects old /my-account/affiliate-area/ bookmarks to the standalone
	 * portal before WooCommerce begins rendering page content.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_endpoint() {
		if (
			is_admin()
			|| wp_doing_ajax()
			|| ! get_option( 'affilio_enable_my_account_tab', true )
			|| ! function_exists( 'is_wc_endpoint_url' )
			|| ! is_wc_endpoint_url( self::ENDPOINT )
		) {
			return;
		}

		$portal_url = self::get_portal_url();

		if ( ! $portal_url ) {
			return;
		}

		wp_safe_redirect( $portal_url );
		exit;
	}

	/**
	 * Defensive fallback if another WooCommerce customization bypasses the
	 * endpoint-link filter and redirect.
	 *
	 * @return void
	 */
	public function render_endpoint_fallback() {
		$portal_url = self::get_portal_url();

		if ( ! $portal_url ) {
			echo '<p>' . esc_html__( 'The affiliate portal is not configured yet.', 'dreamax-affiliates' ) . '</p>';
			return;
		}

		echo '<p class="affilio-notice">' .
			esc_html__( 'Affiliate tools are available in the standalone affiliate portal.', 'dreamax-affiliates' ) .
			' <a href="' . esc_url( $portal_url ) . '">' .
			esc_html__( 'Open Affiliate Dashboard', 'dreamax-affiliates' ) .
			'</a></p>';
	}

	/**
	 * @param string $title Existing endpoint title.
	 * @return string
	 */
	public function filter_endpoint_title( $title ) {
		unset( $title );
		return __( 'Affiliate Dashboard', 'dreamax-affiliates' );
	}

	/**
	 * Returns the configured standalone dashboard page URL.
	 *
	 * @return string
	 */
	public static function get_dashboard_page_url() {
		$page_id  = absint( get_option( 'affilio_dashboard_page_id', 0 ) );
		$page_url = $page_id ? get_permalink( $page_id ) : '';

		return $page_url ? $page_url : '';
	}

	/**
	 * Returns the configured standalone registration page URL.
	 *
	 * @return string
	 */
	public static function get_registration_page_url() {
		$page_id  = absint( get_option( 'affilio_registration_page_id', 0 ) );
		$page_url = $page_id ? get_permalink( $page_id ) : '';

		return $page_url ? $page_url : '';
	}

	/**
	 * Returns the best standalone destination for the current visitor.
	 *
	 * Existing affiliates (including pending/rejected/suspended states) go to
	 * the dashboard page, where the shortcode renders the appropriate state.
	 * Logged-in users who have not applied yet go to the registration page.
	 *
	 * @return string
	 */
	public static function get_portal_url() {
		$dashboard_url    = self::get_dashboard_page_url();
		$registration_url = self::get_registration_page_url();

		if ( is_user_logged_in() && function_exists( 'affilio' ) && affilio()->affiliates_db ) {
			$affiliate = affilio()->affiliates_db->get_by_user_id( get_current_user_id() );

			if ( $affiliate ) {
				return $dashboard_url ? $dashboard_url : ( $registration_url ? $registration_url : home_url( '/' ) );
			}

			return $registration_url ? $registration_url : ( $dashboard_url ? $dashboard_url : home_url( '/' ) );
		}

		return $dashboard_url ? $dashboard_url : ( $registration_url ? $registration_url : home_url( '/' ) );
	}

	/**
	 * Returns the historical WooCommerce My Account endpoint URL.
	 *
	 * This is retained for compatibility. Normal WooCommerce navigation is
	 * filtered to the standalone portal by filter_endpoint_url().
	 *
	 * @return string
	 */
	public static function get_endpoint_url() {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( self::ENDPOINT );
		}

		return '';
	}

	/**
	 * Returns the canonical affiliate dashboard URL for frontend links/emails.
	 *
	 * The standalone dashboard is intentionally preferred over WooCommerce My
	 * Account so affiliate tools have a predictable, theme-independent canvas.
	 *
	 * @return string
	 */
	public static function get_preferred_dashboard_url() {
		$page_url = self::get_dashboard_page_url();

		if ( $page_url ) {
			return $page_url;
		}

		$registration_url = self::get_registration_page_url();
		return $registration_url ? $registration_url : home_url( '/' );
	}
}
