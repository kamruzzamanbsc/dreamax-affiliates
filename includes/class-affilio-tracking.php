<?php
/**
 * Referral-link tracking and secure attribution-token handling.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Tracking {

	/**
	 * Cookie name. The value is an opaque random token, never an affiliate ID.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'affilio_ref';

	/**
	 * Referral query-string variable.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'ref';

	/**
	 * Optional campaign query-string variable.
	 *
	 * @var string
	 */
	const CAMPAIGN_QUERY_VAR = 'aff_campaign';

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_track_visit' ) );
	}

	/**
	 * Records an eligible referral click and applies the configured first- or
	 * last-click attribution model.
	 *
	 * @return void
	 */
	public function maybe_track_visit() {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only tracking parameter.
			return;
		}

		$referral_code = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $referral_code ) {
			return;
		}

		$affiliate = affilio()->affiliates_db->get_by_referral_code( $referral_code );

		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			return;
		}

		$ip_address = $this->get_client_ip();

		if ( Affilio_Fraud_Prevention::should_reject_visit( $ip_address ) ) {
			return;
		}

		if ( Affilio_Fraud_Prevention::is_self_referral( $affiliate->user_id, get_current_user_id() ) ) {
			return;
		}

		$token      = $this->generate_token();
		$campaign   = isset( $_GET[ self::CAMPAIGN_QUERY_VAR ] ) ? $this->sanitize_campaign( wp_unslash( $_GET[ self::CAMPAIGN_QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_campaign() (below) sanitizes and truncates; not a WPCS-recognized sanitizer name.
		$visit_id   = $this->log_visit( (int) $affiliate->id, $referral_code, $ip_address, $token, $campaign );

		if ( ! $visit_id ) {
			return;
		}

		$attribution_model = get_option( 'affilio_attribution_model', 'last_click' );

		// Under first-click attribution, preserve an existing valid cookie while
		// still recording the later click for reporting.
		if ( 'first_click' === $attribution_model && self::resolve_tracked_visit() ) {
			return;
		}

		$this->set_referral_cookie( $token );
	}

	/**
	 * Generates a cryptographically secure attribution token.
	 *
	 * @return string
	 */
	private function generate_token() {
		return bin2hex( random_bytes( 20 ) );
	}

	/**
	 * Sets a secure, HTTP-only, SameSite=Lax attribution cookie.
	 *
	 * @param string $token Attribution token.
	 * @return void
	 */
	private function set_referral_cookie( $token ) {
		if ( headers_sent() ) {
			return;
		}

		$days = self::get_cookie_duration_days();

		setcookie(
			self::COOKIE_NAME,
			$token,
			array(
				'expires'  => time() + ( $days * DAY_IN_SECONDS ),
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::COOKIE_NAME ] = $token;
	}

	/**
	 * Logs a visit and returns its ID.
	 *
	 * @param int    $affiliate_id  Affiliate row ID.
	 * @param string $referral_code Referral code.
	 * @param string $ip_address    Client IP address.
	 * @param string $token         Attribution token.
	 * @param string $campaign      Optional campaign label.
	 * @return int|false
	 */
	private function log_visit( $affiliate_id, $referral_code, $ip_address, $token, $campaign = '' ) {
		$stored_ip = $ip_address;

		if ( (bool) get_option( 'affilio_anonymize_ip_addresses', true ) && function_exists( 'wp_privacy_anonymize_ip' ) ) {
			$stored_ip = wp_privacy_anonymize_ip( $ip_address );
		}

		$visitor_hash = $this->build_visitor_hash( $ip_address, $token );

		return affilio()->visits_db->insert(
			array(
				'affiliate_id'  => $affiliate_id,
				'referral_code' => $referral_code,
				'token'         => $token,
				'visitor_hash'  => $visitor_hash,
				'campaign'      => $campaign ? $campaign : null,
				'landing_page'  => esc_url_raw( $this->get_current_url() ),
				'referrer_url'  => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : null,
				'ip_address'    => $stored_ip,
				'converted'     => 0,
				'date_created'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Builds the current request URL.
	 *
	 * @return string
	 */
	private function get_current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line via sanitize_text_field(); also esc_url_raw()'d below before use.
		$uri = '/' . ltrim( sanitize_text_field( $uri ), '/' );
		$url = home_url( $uri );

		return remove_query_arg( array( self::QUERY_VAR, self::CAMPAIGN_QUERY_VAR ), esc_url_raw( $url ) );
	}


	/**
	 * Builds a privacy-conscious unique-visitor identifier without storing the
	 * raw user agent in the database. The value is site-specific and cannot be
	 * correlated across installations.
	 *
	 * @param string $ip_address Client IP address.
	 * @param string $fallback   Random visit token used if no IP is available.
	 * @return string
	 */
	private function build_visitor_hash( $ip_address, $fallback ) {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$identity   = trim( (string) $ip_address ) . '|' . substr( $user_agent, 0, 255 );

		if ( '|' === $identity ) {
			$identity = (string) $fallback;
		}

		return hash_hmac( 'sha256', $identity, wp_salt( 'auth' ) );
	}

	/**
	 * Sanitizes campaign labels used by the referral-link generator.
	 *
	 * @param mixed $campaign Raw campaign value.
	 * @return string
	 */
	private function sanitize_campaign( $campaign ) {
		$campaign = sanitize_text_field( (string) $campaign );
		return substr( $campaign, 0, 100 );
	}

	/**
	 * Returns the direct client address. Proxy headers are deliberately not
	 * trusted because they are spoofable unless the hosting proxy is known.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Returns a validated cookie lifetime.
	 *
	 * @return int Days, between 1 and 365.
	 */
	public static function get_cookie_duration_days() {
		$days = (int) get_option( 'affilio_cookie_duration_days', AFFILIO_DEFAULT_COOKIE_DAYS );
		$days = (int) apply_filters( 'affilio_cookie_duration_days', $days );

		return max( 1, min( 365, $days ) );
	}

	/**
	 * Resolves the current cookie to its unexpired visit row.
	 *
	 * @return object|null
	 */
	public static function resolve_tracked_visit() {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

		if ( ! preg_match( '/^[a-f0-9]{40}$/', $token ) ) {
			return null;
		}

		$visit = affilio()->visits_db->get_by_token( $token );

		if ( ! $visit ) {
			return null;
		}

		$issued_time = strtotime( $visit->date_created );

		if ( ! $issued_time || time() > ( $issued_time + ( self::get_cookie_duration_days() * DAY_IN_SECONDS ) ) ) {
			return null;
		}

		return $visit;
	}

	/**
	 * Backward-compatible affiliate-ID resolver.
	 *
	 * @return int|null
	 */
	public static function resolve_tracked_affiliate_id() {
		$visit = self::resolve_tracked_visit();

		return $visit ? (int) $visit->affiliate_id : null;
	}
}
