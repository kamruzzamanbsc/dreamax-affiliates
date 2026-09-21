<?php
/**
 * Provides a branded, affiliate-scoped WordPress login and password flow.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps affiliate account access visually consistent without changing auth.
 */
class Affilio_Login_Branding {

	const QUERY_ARG   = 'affilio_access';
	const COOKIE_NAME = 'affilio_access';

	/**
	 * Registers the login-screen and password-email hooks.
	 */
	public function __construct() {
		add_action( 'login_init', array( $this, 'capture_affiliate_context' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_filter( 'login_body_class', array( $this, 'add_body_classes' ), 10, 2 );
		add_filter( 'login_headerurl', array( $this, 'header_url' ) );
		add_filter( 'login_headertext', array( $this, 'header_text' ) );
		add_filter( 'login_message', array( $this, 'intro_message' ) );
		add_filter( 'retrieve_password_message', array( $this, 'brand_password_email' ), 10, 4 );
		add_filter( 'wp_new_user_notification_email', array( $this, 'brand_new_user_email' ), 10, 3 );
	}

	/**
	 * Returns an affiliate-scoped WordPress login URL.
	 *
	 * @param string $redirect_url Destination after sign-in.
	 * @return string
	 */
	public static function login_url( $redirect_url = '' ) {
		return add_query_arg( self::QUERY_ARG, '1', wp_login_url( $redirect_url ) );
	}

	/**
	 * Returns an affiliate-scoped password-recovery URL.
	 *
	 * @param string $redirect_url Destination after sign-in.
	 * @return string
	 */
	public static function password_reset_url( $redirect_url = '' ) {
		return add_query_arg( self::QUERY_ARG, '1', wp_lostpassword_url( $redirect_url ) );
	}

	/**
	 * Remembers the branded flow while WordPress moves between login actions.
	 *
	 * @return void
	 */
	public function capture_affiliate_context() {
		$query_marked    = isset( $_GET[ self::QUERY_ARG ] ) && '1' === sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation context only.
		$redirect_marked = $this->redirect_targets_affiliate_area();

		if ( $query_marked || $redirect_marked ) {
			$_COOKIE[ self::COOKIE_NAME ] = '1';

			if ( ! headers_sent() ) {
				setcookie(
					self::COOKIE_NAME,
					'1',
					array(
						'expires'  => time() + HOUR_IN_SECONDS,
						'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
						'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
		}

		if ( $this->is_affiliate_context() ) {
			add_filter( 'gettext', array( $this, 'account_access_text' ), 10, 3 );
		}
	}

	/**
	 * Loads the dedicated stylesheet only for affiliate account access.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ! $this->is_affiliate_context() ) {
			return;
		}

		$path    = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-login.css';
		$version = file_exists( $path ) ? (string) filemtime( $path ) : AFFILIO_VERSION;

		wp_enqueue_style( 'affilio-login', AFFILIO_PLUGIN_URL . 'assets/css/affilio-login.css', array(), $version );

		$site_icon = get_site_icon_url( 96 );
		if ( $site_icon ) {
			wp_add_inline_style(
				'affilio-login',
				'body.affilio-login.affilio-login-has-icon #login h1 a::before{background-image:url("' . esc_url( $site_icon ) . '");}'
			);
		}
	}

	/**
	 * Adds narrowly scoped body classes for the branded login experience.
	 *
	 * @param string[] $classes Existing login body classes.
	 * @param string   $action  Current login action.
	 * @return string[]
	 */
	public function add_body_classes( $classes, $action ) {
		if ( ! $this->is_affiliate_context() ) {
			return $classes;
		}

		$classes[] = 'affilio-login';
		$classes[] = 'affilio-login-action-' . sanitize_html_class( (string) $action );

		if ( get_site_icon_url( 96 ) ) {
			$classes[] = 'affilio-login-has-icon';
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Uses the public website as the branded logo destination.
	 *
	 * @param string $url Existing URL.
	 * @return string
	 */
	public function header_url( $url ) {
		return $this->is_affiliate_context() ? home_url( '/' ) : $url;
	}

	/**
	 * Uses a useful accessible name for the branded logo.
	 *
	 * @param string $text Existing header text.
	 * @return string
	 */
	public function header_text( $text ) {
		return $this->is_affiliate_context()
			? wp_strip_all_tags( get_bloginfo( 'name' ) )
			: $text;
	}

	/**
	 * Adds action-specific orientation above the standard WordPress form.
	 *
	 * @param string $message Existing login message HTML.
	 * @return string
	 */
	public function intro_message( $message ) {
		if ( ! $this->is_affiliate_context() ) {
			return $message;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation context only.
		$copy   = array(
			'lostpassword' => array(
				'title'       => __( 'Reset your password', 'dreamax-affiliates' ),
				'description' => __( 'Enter your affiliate sign-in email. We will send a secure password-reset link to that address.', 'dreamax-affiliates' ),
			),
			'rp'           => array(
				'title'       => __( 'Choose a new password', 'dreamax-affiliates' ),
				'description' => __( 'Create a strong password for secure access to your affiliate account.', 'dreamax-affiliates' ),
			),
			'resetpass'    => array(
				'title'       => __( 'Choose a new password', 'dreamax-affiliates' ),
				'description' => __( 'Create a strong password for secure access to your affiliate account.', 'dreamax-affiliates' ),
			),
			'login'        => array(
				'title'       => __( 'Affiliate account sign in', 'dreamax-affiliates' ),
				'description' => __( 'Sign in to review your application and access affiliate tools when your account is approved.', 'dreamax-affiliates' ),
			),
		);
		$state  = isset( $copy[ $action ] ) ? $copy[ $action ] : $copy['login'];
		if ( 'lostpassword' === $action && ! isset( $_GET['checkemail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation context only.
			$message = '';
		}

		$intro  = '<section class="affilio-login-intro">';
		$intro .= '<span class="affilio-login-eyebrow">' . esc_html__( 'Affiliate access', 'dreamax-affiliates' ) . '</span>';
		$intro .= '<h2>' . esc_html( $state['title'] ) . '</h2>';
		$intro .= '<p>' . esc_html( $state['description'] ) . '</p>';
		$intro .= '</section>';

		return $intro . $message;
	}

	/**
	 * Clarifies the primary password-recovery action in affiliate context.
	 *
	 * @param string $translated_text Translated text.
	 * @param string $text            Original text.
	 * @param string $domain          Text domain.
	 * @return string
	 */
	public function account_access_text( $translated_text, $text, $domain ) {
		if ( 'default' === $domain && 'Get New Password' === $text ) {
			return __( 'Send reset link', 'dreamax-affiliates' );
		}

		return $translated_text;
	}

	/**
	 * Keeps password links branded for affiliate users.
	 *
	 * @param string  $message    Core password email message.
	 * @param string  $key        Password reset key.
	 * @param string  $user_login User login.
	 * @param WP_User $user_data  User object.
	 * @return string
	 */
	public function brand_password_email( $message, $key, $user_login, $user_data ) {
		unset( $key, $user_login );

		return $this->is_affiliate_context() || $this->is_affiliate_user( $user_data )
			? $this->mark_reset_links( $message )
			: $message;
	}

	/**
	 * Keeps the initial WordPress password-setup link in the branded flow.
	 *
	 * @param array   $email      Notification email arguments.
	 * @param WP_User $user       New user.
	 * @param string  $blogname   Site name.
	 * @return array
	 */
	public function brand_new_user_email( $email, $user, $blogname ) {
		unset( $blogname );

		if ( $this->is_affiliate_user( $user ) && ! empty( $email['message'] ) ) {
			$email['message'] = $this->mark_reset_links( (string) $email['message'] );
		}

		return $email;
	}

	/**
	 * Determines whether this login request belongs to the affiliate flow.
	 *
	 * @return bool
	 */
	private function is_affiliate_context() {
		$query_marked  = isset( $_GET[ self::QUERY_ARG ] ) && '1' === sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation context only.
		$cookie_marked = isset( $_COOKIE[ self::COOKIE_NAME ] ) && '1' === sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

		return $query_marked || $cookie_marked || $this->redirect_targets_affiliate_area();
	}

	/**
	 * Recognizes direct WordPress login links that return to the configured
	 * affiliate dashboard. This also upgrades cached links created before the
	 * explicit affiliate marker was added.
	 *
	 * @return bool
	 */
	private function redirect_targets_affiliate_area() {
		if ( empty( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation context only.
			return false;
		}

		$redirect_url  = wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ), '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation context only.
		$page_id       = absint( get_option( 'affilio_dashboard_page_id', 0 ) );
		$dashboard_url = $page_id ? get_permalink( $page_id ) : '';

		if ( ! $redirect_url || ! $dashboard_url ) {
			return false;
		}

		$redirect_host  = strtolower( (string) wp_parse_url( $redirect_url, PHP_URL_HOST ) );
		$dashboard_host = strtolower( (string) wp_parse_url( $dashboard_url, PHP_URL_HOST ) );
		$redirect_path  = untrailingslashit( (string) wp_parse_url( $redirect_url, PHP_URL_PATH ) );
		$dashboard_path = untrailingslashit( (string) wp_parse_url( $dashboard_url, PHP_URL_PATH ) );

		return $redirect_host === $dashboard_host && $redirect_path === $dashboard_path;
	}

	/**
	 * Checks whether a WordPress user owns an affiliate record.
	 *
	 * @param WP_User|mixed $user User value.
	 * @return bool
	 */
	private function is_affiliate_user( $user ) {
		return $user instanceof WP_User
			&& isset( affilio()->affiliates_db )
			&& (bool) affilio()->affiliates_db->get_by_user_id( $user->ID );
	}

	/**
	 * Adds the affiliate marker to reset-password URLs in an email body.
	 *
	 * @param string $message Email body.
	 * @return string
	 */
	private function mark_reset_links( $message ) {
		return (string) preg_replace_callback(
			'~https?://[^\s<>\"]+~i',
			static function ( $matches ) {
				$url = html_entity_decode( $matches[0], ENT_QUOTES, 'UTF-8' );
				if ( false === strpos( $url, 'wp-login.php' ) || ( false === strpos( $url, 'action=rp' ) && false === strpos( $url, 'action=resetpass' ) ) ) {
					return $matches[0];
				}

				return add_query_arg( self::QUERY_ARG, '1', $url );
			},
			$message
		);
	}
}
