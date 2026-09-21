<?php
/**
 * Frontend asset loading — only enqueues CSS/JS on pages that actually
 * contain one of the plugin's shortcodes, rather than sitewide.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Assets {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_assets' ), 120 );
	}

	/**
	 * Enqueues the frontend CSS/JS only when the current singular post's
	 * content contains [dreamax_affiliates_registration] or [dreamax_affiliates_dashboard].
	 *
	 * @return void
	 */
	public function maybe_enqueue_frontend_assets() {
		$is_account_endpoint = function_exists( 'is_wc_endpoint_url' )
			&& is_wc_endpoint_url( Affilio_My_Account::ENDPOINT )
			&& get_option( 'affilio_enable_my_account_tab', true );

		if ( ! is_singular() && ! $is_account_endpoint ) {
			return;
		}

		$post             = get_post();
		$has_registration = $post ? has_shortcode( $post->post_content, 'dreamax_affiliates_registration' ) : false;
		$has_dashboard    = $post ? has_shortcode( $post->post_content, 'dreamax_affiliates_dashboard' ) : false;

		if ( ! $has_registration && ! $has_dashboard && ! $is_account_endpoint ) {
			return;
		}

		$frontend_css_path = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-frontend.css';
		$frontend_css_ver  = file_exists( $frontend_css_path ) ? (string) filemtime( $frontend_css_path ) : AFFILIO_VERSION;

		wp_enqueue_style(
			'affilio-frontend',
			AFFILIO_PLUGIN_URL . 'assets/css/affilio-frontend.css',
			array(),
			$frontend_css_ver
		);

		// Load the RTL adjustment layer in addition to the full base stylesheet.
		if ( is_rtl() ) {
			$frontend_rtl_path = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-frontend-rtl.css';
			$frontend_rtl_ver  = file_exists( $frontend_rtl_path ) ? (string) filemtime( $frontend_rtl_path ) : $frontend_css_ver;

			wp_enqueue_style(
				'affilio-frontend-rtl',
				AFFILIO_PLUGIN_URL . 'assets/css/affilio-frontend-rtl.css',
				array( 'affilio-frontend' ),
				$frontend_rtl_ver
			);
		}

		if ( $has_registration ) {
			$registration_css_path = AFFILIO_PLUGIN_DIR . 'assets/css/affilio-registration.css';
			$registration_css_ver  = file_exists( $registration_css_path ) ? (string) filemtime( $registration_css_path ) : AFFILIO_VERSION;
			$registration_css_deps = is_rtl() ? array( 'affilio-frontend', 'affilio-frontend-rtl' ) : array( 'affilio-frontend' );

			wp_enqueue_style(
				'affilio-registration',
				AFFILIO_PLUGIN_URL . 'assets/css/affilio-registration.css',
				$registration_css_deps,
				$registration_css_ver
			);
		}

		$script_dependencies = array( 'jquery' );

		if ( $has_dashboard || $is_account_endpoint ) {
			wp_enqueue_script(
				'affilio-qrcode',
				AFFILIO_PLUGIN_URL . 'assets/vendor/qrcode/affilio-qrcode.js',
				array(),
				AFFILIO_VERSION,
				true
			);
			$script_dependencies[] = 'affilio-qrcode';
		}

		$frontend_js_path = AFFILIO_PLUGIN_DIR . 'assets/js/affilio-frontend.js';
		$frontend_js_ver  = file_exists( $frontend_js_path ) ? (string) filemtime( $frontend_js_path ) : AFFILIO_VERSION;

		wp_enqueue_script(
			'affilio-frontend',
			AFFILIO_PLUGIN_URL . 'assets/js/affilio-frontend.js',
			$script_dependencies,
			$frontend_js_ver,
			true
		);

		wp_localize_script(
			'affilio-frontend',
			'affilioFrontend',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'genericError'     => __( 'Something went wrong. Please try again.', 'dreamax-affiliates' ),
				'copiedMessage'    => __( 'Link copied.', 'dreamax-affiliates' ),
				'invalidUrl'       => __( 'Please enter a page or product URL from this website.', 'dreamax-affiliates' ),
				'copyFailed'       => __( 'Copy failed. Select the link and copy it manually.', 'dreamax-affiliates' ),
				'openDashboard'    => __( 'Open affiliate area', 'dreamax-affiliates' ),
				'shareTitle'       => get_bloginfo( 'name' ),
				'shareText'        => __( 'Visit this page using my affiliate link:', 'dreamax-affiliates' ),
				'shareUnavailable' => __( 'Sharing is not available in this browser. Copy the link instead.', 'dreamax-affiliates' ),
				'qrCreated'        => __( 'QR code created.', 'dreamax-affiliates' ),
				'qrError'          => __( 'The QR code could not be created. Shorten the destination URL and try again.', 'dreamax-affiliates' ),
				'qrFilename'       => __( 'affiliate-link-qr-code.svg', 'dreamax-affiliates' ),
				'qrOpen'           => __( 'QR code panel opened.', 'dreamax-affiliates' ),
				'qrClosed'         => __( 'QR code panel closed.', 'dreamax-affiliates' ),
				'formSubmitting'   => __( 'Submitting your application…', 'dreamax-affiliates' ),
					'submitLabel'      => __( 'Submitting…', 'dreamax-affiliates' ),
					'payoutDetailsRequired' => __( 'Payout destination / account details are required for the selected method.', 'dreamax-affiliates' ),
				'copyCodeMessage'  => __( 'Value copied.', 'dreamax-affiliates' ),
			)
		);
	}
}
