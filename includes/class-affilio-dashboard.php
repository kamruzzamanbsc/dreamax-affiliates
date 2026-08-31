<?php
/**
 * Affiliate-facing dashboard: referral link, stats, referrals, payout
 * profile, and payout history. Rendered via [dreamax_affiliates_dashboard].
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Dashboard {

	public function __construct() {
		add_shortcode( 'dreamax_affiliates_dashboard', array( $this, 'render_dashboard' ) );
		add_action( 'admin_post_affilio_save_affiliate_profile', array( $this, 'handle_profile_settings_save' ) );
		add_filter( 'the_title', array( $this, 'filter_dashboard_page_title' ), 20, 2 );
		add_filter( 'body_class', array( $this, 'dashboard_body_class' ) );
	}


	/**
	 * Removes the visible WordPress page title from the configured standalone
	 * affiliate dashboard page. The document title and titles elsewhere are
	 * left untouched.
	 *
	 * @param string $title   Existing title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function filter_dashboard_page_title( $title, $post_id ) {
		if ( is_admin() || ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
			return $title;
		}

		$dashboard_page_id = absint( get_option( 'affilio_dashboard_page_id', 0 ) );

		if ( $dashboard_page_id && $dashboard_page_id === (int) $post_id && is_page( $dashboard_page_id ) ) {
			return '';
		}

		return $title;
	}

	/**
	 * Adds a body class on the configured standalone dashboard page so themes
	 * that render page headings outside the Loop can still be handled safely
	 * by scoped frontend CSS.
	 *
	 * @param string[] $classes Existing body classes.
	 * @return string[]
	 */
	public function dashboard_body_class( $classes ) {
		$dashboard_page_id = absint( get_option( 'affilio_dashboard_page_id', 0 ) );

		if ( ! is_admin() && $dashboard_page_id && is_page( $dashboard_page_id ) ) {
			$classes[] = 'affilio-standalone-dashboard-page';
		}

		return $classes;
	}

	/**
	 * Renders the affiliate dashboard shortcode.
	 *
	 * @return string
	 */
	public function render_dashboard() {
		if ( ! is_user_logged_in() ) {
			$current_url = get_permalink();
			$login_url   = wp_login_url( $current_url ? $current_url : home_url( '/' ) );
			return $this->notice(
				esc_html__( 'Please log in to view your affiliate dashboard.', 'dreamax-affiliates' ) . ' ' .
				'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Log in', 'dreamax-affiliates' ) . '</a>'
			);
		}

		$affiliate = affilio()->affiliates_db->get_by_user_id( get_current_user_id() );

		if ( ! $affiliate ) {
			$registration_page_id = absint( get_option( 'affilio_registration_page_id', 0 ) );
			$registration_url     = $registration_page_id ? get_permalink( $registration_page_id ) : '';
			$message              = esc_html__( 'You are not yet registered as an affiliate.', 'dreamax-affiliates' );

			if ( $registration_url ) {
				$message .= ' <a href="' . esc_url( $registration_url ) . '">' . esc_html__( 'Apply now', 'dreamax-affiliates' ) . '</a>';
			}

			return $this->notice( $message );
		}

		if ( 'active' !== $affiliate->status ) {
			return $this->application_status_card( $affiliate );
		}

		$report_source = array(
			'date_from' => isset( $_GET['affilio_date_from'] ) ? wp_unslash( $_GET['affilio_date_from'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized one line below by Affilio_Reports::get_filters_from_request() (sanitize_date()); read-only report filter, not a state-changing action.
			'date_to'   => isset( $_GET['affilio_date_to'] ) ? wp_unslash( $_GET['affilio_date_to'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized one line below by Affilio_Reports::get_filters_from_request() (sanitize_date()); read-only report filter, not a state-changing action.
		);
		$filters                 = Affilio_Reports::get_filters_from_request( $report_source );
		$filters['affiliate_id'] = (int) $affiliate->id;
		$referral_url           = Affilio_Reports::build_referral_url( home_url( '/' ), $affiliate->referral_code );
		$report_data            = affilio()->analytics->get_data( (int) $affiliate->id, $filters );
		$visit_summary          = $report_data['visits'];
		$referral_summary       = $report_data['referrals'];
		$recent_clicks          = affilio()->visits_db->query( array_merge( $filters, array( 'number' => 10 ) ) );
		$referrals              = affilio()->referrals_db->query( array_merge( $filters, array( 'number' => 20 ) ) );
		$totals                 = array(
			'count'             => $referral_summary['count'],
			'paid_formatted'    => $this->format_currency_totals( $referral_summary['paid_totals'] ),
			'pending_formatted' => $this->format_currency_totals( $referral_summary['open_totals'] ),
		);
		$payouts                 = affilio()->payouts_db->get_by_affiliate( $affiliate->id, 20 );
		$payout_statuses         = array();
		foreach ( $payouts as $payout_row ) {
			$payout_statuses[ (int) $payout_row->id ] = (string) $payout_row->status;
		}
		$methods                 = affilio()->payouts->get_payout_methods();
		$visit_export_url        = Affilio_Reports::get_affiliate_export_url( 'visits', $affiliate->id, $filters );
		$referral_export_url     = Affilio_Reports::get_affiliate_export_url( 'referrals', $affiliate->id, $filters );
		$assigned_coupons        = affilio()->coupons ? affilio()->coupons->get_by_affiliate( $affiliate->id ) : array();
		$creatives               = affilio()->creatives ? affilio()->creatives->get_for_affiliate( $affiliate->referral_code ) : array();
		$payout_requests          = affilio()->payout_requests_db->get_by_affiliate( $affiliate->id, 20 );
		$available_payout_totals = affilio()->payout_requests->get_available_totals( $affiliate->id );
		$payout_threshold        = max( 0, (float) get_option( 'affilio_minimum_payout_threshold', 50 ) );
		$eligible_payout_totals  = array_filter(
			$available_payout_totals,
			static function ( $amount ) use ( $payout_threshold ) {
				return (float) $amount + 0.0001 >= $payout_threshold && (float) $amount > 0;
			}
		);

		$payout_method          = affilio()->payouts->sanitize_payout_method( $affiliate->payout_method ?? 'paypal' );
		$validated_destination = affilio()->payout_requests->get_destination( $affiliate, $payout_method );
		$invalid_bank_details  = 'bank_transfer' === $payout_method && '' === $validated_destination;

		$open_payout_requests   = array();
		$requestable_totals     = $eligible_payout_totals;
		foreach ( array_keys( $eligible_payout_totals ) as $currency ) {
			$open_request = affilio()->payout_requests_db->get_open( $affiliate->id, $currency );
			if ( $open_request ) {
				$open_payout_requests[ $currency ] = $open_request;
				unset( $requestable_totals[ $currency ] );
			}
		}
		$dashboard_id         = wp_unique_id( 'affilio-dashboard-' );
		$qr_panel_id          = wp_unique_id( 'affilio-qr-panel-' );
		$link_section_id      = $dashboard_id . '-links';
		$overview_section_id  = $dashboard_id . '-overview';
		$creatives_section_id = $dashboard_id . '-creatives';
		$payouts_section_id   = $dashboard_id . '-payouts';
		$profile_section_id   = 'affilio-profile-settings';
		$destination_id       = $dashboard_id . '-link-destination';
		$campaign_id          = $dashboard_id . '-link-campaign';
		$generated_link_id    = $dashboard_id . '-generated-link';
		$date_from_id         = $dashboard_id . '-date-from';
		$date_to_id           = $dashboard_id . '-date-to';
		$payout_method_id     = $dashboard_id . '-payout-method';
		$payout_email_id      = $dashboard_id . '-payout-email';
		$payout_details_id    = $dashboard_id . '-payout-details';
		$payout_help_id       = $dashboard_id . '-payout-details-help';
		$website_id           = $dashboard_id . '-website';
		$promotion_id         = $dashboard_id . '-promotion-method';
		$social_id            = $dashboard_id . '-social-profile';
		$request_currency_id  = $dashboard_id . '-request-currency';
		$request_note_id      = $dashboard_id . '-request-note';
		$current_user         = wp_get_current_user();
		$display_name         = $current_user && $current_user->exists() ? $current_user->display_name : __( 'Affiliate', 'dreamax-affiliates' );
		$payout_profile_ready = is_email( $affiliate->payout_email ?? '' ) && '' !== $validated_destination;
		$has_activity         = (int) $visit_summary['clicks'] > 0 || (int) $referral_summary['count'] > 0;
		$dashboard_return_url = class_exists( 'Affilio_My_Account' ) ? Affilio_My_Account::get_preferred_dashboard_url() : get_permalink();
		$logout_url           = wp_logout_url( $dashboard_return_url ? $dashboard_return_url : home_url( '/' ) );

		ob_start();
		?>
		<section class="affilio-dashboard affilio-portal" id="<?php echo esc_attr( $dashboard_id ); ?>" data-affilio-portal aria-labelledby="<?php echo esc_attr( $dashboard_id . '-title' ); ?>">
			<header class="affilio-dashboard-welcome" data-affilio-panel="overview">
				<div class="affilio-dashboard-welcome-top">
					<div class="affilio-dashboard-welcome-copy">
						<p class="affilio-dashboard-eyebrow"><?php esc_html_e( 'Affiliate dashboard', 'dreamax-affiliates' ); ?></p>
						<?php /* translators: %s: the logged-in affiliate's display name. */ ?>
						<h2 id="<?php echo esc_attr( $dashboard_id . '-title' ); ?>"><?php echo esc_html( sprintf( __( 'Welcome, %s', 'dreamax-affiliates' ), $display_name ) ); ?></h2>
					</div>
				</div>
				<p class="affilio-dashboard-intro"><?php esc_html_e( 'Create referral links, follow performance, and manage payouts from one place.', 'dreamax-affiliates' ); ?></p>
			</header>
			<?php $this->render_profile_notice(); ?>

			<section class="affilio-affiliate-start" data-affilio-panel="overview" aria-labelledby="<?php echo esc_attr( $dashboard_id . '-start-title' ); ?>">
				<h3 id="<?php echo esc_attr( $dashboard_id . '-start-title' ); ?>"><?php esc_html_e( 'Quick start', 'dreamax-affiliates' ); ?></h3>
				<ol class="affilio-affiliate-steps">
					<li><a href="#<?php echo esc_attr( $link_section_id ); ?>" data-affilio-panel-target="links"><span class="affilio-step-number">1</span><strong><?php esc_html_e( 'Create your link', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Choose a page or product and generate your personal URL.', 'dreamax-affiliates' ); ?></span></a></li>
					<li><a href="#<?php echo esc_attr( $link_section_id ); ?>" data-affilio-panel-target="links"><span class="affilio-step-number">2</span><strong><?php esc_html_e( 'Share it', 'dreamax-affiliates' ); ?></strong><span><?php esc_html_e( 'Copy the link, use a social button, or download its QR code.', 'dreamax-affiliates' ); ?></span></a></li>
					<li class="<?php echo esc_attr( $has_activity ? 'is-complete' : '' ); ?>"><a href="#<?php echo esc_attr( $overview_section_id ); ?>" data-affilio-panel-target="results"><span class="affilio-step-number">3</span><strong><?php esc_html_e( 'Follow results', 'dreamax-affiliates' ); ?></strong><span><?php echo $has_activity ? esc_html__( 'Activity is being recorded.', 'dreamax-affiliates' ) : esc_html__( 'Your first click and referral will appear in Results.', 'dreamax-affiliates' ); ?></span></a></li>
					<li class="<?php echo esc_attr( $payout_profile_ready ? 'is-complete' : '' ); ?>"><a href="#<?php echo esc_attr( $profile_section_id ); ?>" data-affilio-panel-target="profile"><span class="affilio-step-number">4</span><strong><?php esc_html_e( 'Prepare for payouts', 'dreamax-affiliates' ); ?></strong><span><?php echo $payout_profile_ready ? esc_html__( 'Your payout profile is ready.', 'dreamax-affiliates' ) : esc_html__( 'Add the details the program owner needs to pay you.', 'dreamax-affiliates' ); ?></span></a></li>
				</ol>
			</section>

			<section class="affilio-portal-overview-summary" data-affilio-panel="overview" aria-labelledby="<?php echo esc_attr( $dashboard_id . '-summary-title' ); ?>">
				<div class="affilio-portal-overview-heading">
					<div>
						<p class="affilio-dashboard-eyebrow"><?php esc_html_e( 'Performance snapshot', 'dreamax-affiliates' ); ?></p>
						<h3 id="<?php echo esc_attr( $dashboard_id . '-summary-title' ); ?>"><?php esc_html_e( 'Your affiliate activity', 'dreamax-affiliates' ); ?></h3>
					</div>
					<a href="#<?php echo esc_attr( $overview_section_id ); ?>" data-affilio-panel-target="results"><?php esc_html_e( 'View full results', 'dreamax-affiliates' ); ?></a>
				</div>
				<dl class="affilio-portal-overview-stats" aria-label="<?php echo esc_attr__( 'Affiliate performance snapshot', 'dreamax-affiliates' ); ?>">
					<div><dt><?php esc_html_e( 'Clicks', 'dreamax-affiliates' ); ?></dt><dd><?php echo esc_html( Affilio_I18n::number( $visit_summary['clicks'] ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Visitors', 'dreamax-affiliates' ); ?></dt><dd><?php echo esc_html( Affilio_I18n::number( $visit_summary['unique_visitors'] ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Referrals', 'dreamax-affiliates' ); ?></dt><dd><?php echo esc_html( Affilio_I18n::number( $totals['count'] ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Conversion', 'dreamax-affiliates' ); ?></dt><dd><?php echo esc_html( Affilio_I18n::percentage( $visit_summary['conversion_rate'], 2 ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Pending', 'dreamax-affiliates' ); ?></dt><dd><?php echo esc_html( $totals['pending_formatted'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Paid', 'dreamax-affiliates' ); ?></dt><dd><?php echo esc_html( $totals['paid_formatted'] ); ?></dd></div>
				</dl>
			</section>

			<nav class="affilio-dashboard-nav affilio-portal-sidebar" data-affilio-portal-nav aria-label="<?php echo esc_attr__( 'Affiliate dashboard sections', 'dreamax-affiliates' ); ?>">
				<div class="affilio-portal-sidebar-head">
					<span class="affilio-portal-brand-mark" aria-hidden="true">D</span>
					<div>
						<strong><?php esc_html_e( 'Affiliate Portal', 'dreamax-affiliates' ); ?></strong>
						<span><?php echo esc_html( $display_name ); ?></span>
					</div>
				</div>
				<ul>
					<li><a href="#<?php echo esc_attr( $dashboard_id ); ?>" data-affilio-panel-target="overview"><span class="affilio-portal-nav-index" aria-hidden="true">01</span><span><?php esc_html_e( 'Overview', 'dreamax-affiliates' ); ?></span></a></li>
					<li><a href="#<?php echo esc_attr( $link_section_id ); ?>" data-affilio-panel-target="links"><span class="affilio-portal-nav-index" aria-hidden="true">02</span><span><?php esc_html_e( 'Referral Links', 'dreamax-affiliates' ); ?></span></a></li>
					<li><a href="#<?php echo esc_attr( $overview_section_id ); ?>" data-affilio-panel-target="results"><span class="affilio-portal-nav-index" aria-hidden="true">03</span><span><?php esc_html_e( 'Results', 'dreamax-affiliates' ); ?></span></a></li>
					<?php if ( ! empty( $creatives ) ) : ?><li><a href="#<?php echo esc_attr( $creatives_section_id ); ?>" data-affilio-panel-target="creatives"><span class="affilio-portal-nav-index" aria-hidden="true">04</span><span><?php esc_html_e( 'Creatives', 'dreamax-affiliates' ); ?></span></a></li><?php endif; ?>
					<li><a href="#<?php echo esc_attr( $payouts_section_id ); ?>" data-affilio-panel-target="payouts"><span class="affilio-portal-nav-index" aria-hidden="true"><?php echo esc_html( ! empty( $creatives ) ? '05' : '04' ); ?></span><span><?php esc_html_e( 'Payouts', 'dreamax-affiliates' ); ?></span></a></li>
					<li><a href="#<?php echo esc_attr( $profile_section_id ); ?>" data-affilio-panel-target="profile"><span class="affilio-portal-nav-index" aria-hidden="true"><?php echo esc_html( ! empty( $creatives ) ? '06' : '05' ); ?></span><span><?php esc_html_e( 'Profile & Settings', 'dreamax-affiliates' ); ?></span></a></li>
				</ul>
				<div class="affilio-portal-sidebar-foot">
					<div class="affilio-portal-sidebar-code">
						<span><?php esc_html_e( 'Referral code', 'dreamax-affiliates' ); ?></span>
						<code><?php echo esc_html( $affiliate->referral_code ); ?></code>
					</div>
					<a class="affilio-portal-logout" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'dreamax-affiliates' ); ?></a>
				</div>
			</nav>

			<section class="affilio-dashboard-section affilio-portal-panel" data-affilio-panel="links" hidden aria-hidden="true" id="<?php echo esc_attr( $link_section_id ); ?>" aria-labelledby="<?php echo esc_attr( $link_section_id . '-title' ); ?>">
				<h3 id="<?php echo esc_attr( $link_section_id . '-title' ); ?>"><?php esc_html_e( 'Create and Share Referral Links', 'dreamax-affiliates' ); ?></h3>
				<p><?php esc_html_e( 'Start with the homepage or paste any page or product URL from this website. The generated link already includes your referral code.', 'dreamax-affiliates' ); ?></p>
				<div class="affilio-link-generator" data-referral-code="<?php echo esc_attr( $affiliate->referral_code ); ?>" data-home-url="<?php echo esc_url( home_url( '/' ) ); ?>">
				<p class="affilio-field">
					<label for="<?php echo esc_attr( $destination_id ); ?>"><?php esc_html_e( 'Page or product URL', 'dreamax-affiliates' ); ?></label>
					<input type="url" id="<?php echo esc_attr( $destination_id ); ?>" class="affilio-link-destination" inputmode="url" autocomplete="url" value="<?php echo esc_url( home_url( '/' ) ); ?>">
				</p>
				<p class="affilio-field">
					<label for="<?php echo esc_attr( $campaign_id ); ?>"><?php esc_html_e( 'Campaign label (optional)', 'dreamax-affiliates' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $campaign_id ); ?>" class="affilio-link-campaign" maxlength="100" placeholder="<?php echo esc_attr__( 'summer-email', 'dreamax-affiliates' ); ?>">
				</p>
				<p class="affilio-field">
					<label for="<?php echo esc_attr( $generated_link_id ); ?>"><?php esc_html_e( 'Your affiliate link', 'dreamax-affiliates' ); ?></label>
					<input type="text" readonly aria-readonly="true" id="<?php echo esc_attr( $generated_link_id ); ?>" class="affilio-referral-link" value="<?php echo esc_url( $referral_url ); ?>">
				</p>
				<p class="affilio-link-actions">
					<button type="button" class="affilio-generate-link"><?php esc_html_e( 'Generate Link', 'dreamax-affiliates' ); ?></button>
					<button type="button" class="affilio-copy-link"><?php esc_html_e( 'Copy Link', 'dreamax-affiliates' ); ?></button>
					<span class="affilio-copy-status" role="status" aria-live="polite" aria-atomic="true"></span>
				</p>

				<div class="affilio-share-tools">
					<h4><?php esc_html_e( 'Share Your Affiliate Link', 'dreamax-affiliates' ); ?></h4>
					<div class="affilio-share-actions" role="group" aria-label="<?php echo esc_attr__( 'Affiliate link sharing options', 'dreamax-affiliates' ); ?>">
						<button type="button" class="affilio-share-button affilio-native-share" data-affilio-share="native"><?php esc_html_e( 'Share', 'dreamax-affiliates' ); ?></button>
						<button type="button" class="affilio-share-button" data-affilio-share="facebook"><?php esc_html_e( 'Facebook', 'dreamax-affiliates' ); ?></button>
						<button type="button" class="affilio-share-button" data-affilio-share="linkedin"><?php esc_html_e( 'LinkedIn', 'dreamax-affiliates' ); ?></button>
						<button type="button" class="affilio-share-button" data-affilio-share="x"><?php esc_html_e( 'X', 'dreamax-affiliates' ); ?></button>
						<button type="button" class="affilio-share-button" data-affilio-share="whatsapp"><?php esc_html_e( 'WhatsApp', 'dreamax-affiliates' ); ?></button>
						<button type="button" class="affilio-share-button" data-affilio-share="email"><?php esc_html_e( 'Email', 'dreamax-affiliates' ); ?></button>
					</div>
					<div class="affilio-qr-tools">
						<button type="button" class="affilio-show-qr" aria-expanded="false" aria-controls="<?php echo esc_attr( $qr_panel_id ); ?>"><?php esc_html_e( 'Create QR Code', 'dreamax-affiliates' ); ?></button>
						<div class="affilio-qr-panel" id="<?php echo esc_attr( $qr_panel_id ); ?>" hidden tabindex="-1">
							<div class="affilio-qr-output" role="img" aria-label="<?php echo esc_attr__( 'QR code for the generated affiliate link', 'dreamax-affiliates' ); ?>"></div>
							<button type="button" class="affilio-download-qr"><?php esc_html_e( 'Download QR as SVG', 'dreamax-affiliates' ); ?></button>
							<span class="affilio-qr-status" role="status" aria-live="polite" aria-atomic="true"></span>
						</div>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $assigned_coupons ) ) : ?>
				<h3><?php esc_html_e( 'Your Coupon Codes', 'dreamax-affiliates' ); ?></h3>
				<div class="affilio-coupon-grid">
					<?php foreach ( $assigned_coupons as $coupon ) : ?>
						<div class="affilio-coupon-card">
							<code><?php echo esc_html( $coupon['code'] ); ?></code>
							<?php if ( $coupon['campaign'] ) : ?><small><?php echo esc_html( $coupon['campaign'] ); ?></small><?php endif; ?>
							<button type="button" class="affilio-copy-value" data-copy-value="<?php echo esc_attr( $coupon['code'] ); ?>"><?php esc_html_e( 'Copy Code', 'dreamax-affiliates' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			</section>

			<section class="affilio-dashboard-section affilio-portal-panel" data-affilio-panel="results" hidden aria-hidden="true" id="<?php echo esc_attr( $overview_section_id ); ?>" aria-labelledby="<?php echo esc_attr( $overview_section_id . '-title' ); ?>">
				<h3 id="<?php echo esc_attr( $overview_section_id . '-title' ); ?>"><?php esc_html_e( 'Your Results', 'dreamax-affiliates' ); ?></h3>
				<p><?php esc_html_e( 'See the traffic, referrals, and commissions attributed to your links and coupon codes.', 'dreamax-affiliates' ); ?></p>
			<form method="get" class="affilio-dashboard-report-filter" aria-label="<?php echo esc_attr__( 'Filter affiliate reports', 'dreamax-affiliates' ); ?>">
				<input type="hidden" name="affilio_panel" value="results">
				<div class="affilio-filter-field">
					<label for="<?php echo esc_attr( $date_from_id ); ?>"><?php esc_html_e( 'From', 'dreamax-affiliates' ); ?></label>
					<input type="date" id="<?php echo esc_attr( $date_from_id ); ?>" name="affilio_date_from" value="<?php echo esc_attr( $filters['date_from_ui'] ); ?>">
				</div>
				<div class="affilio-filter-field">
					<label for="<?php echo esc_attr( $date_to_id ); ?>"><?php esc_html_e( 'To', 'dreamax-affiliates' ); ?></label>
					<input type="date" id="<?php echo esc_attr( $date_to_id ); ?>" name="affilio_date_to" value="<?php echo esc_attr( $filters['date_to_ui'] ); ?>">
				</div>
				<button type="submit"><?php esc_html_e( 'Filter Reports', 'dreamax-affiliates' ); ?></button>
			</form>

			<dl class="affilio-stats" aria-label="<?php echo esc_attr__( 'Affiliate performance summary', 'dreamax-affiliates' ); ?>">
				<div class="affilio-stat"><dt class="affilio-stat-label"><?php esc_html_e( 'Clicks', 'dreamax-affiliates' ); ?></dt><dd class="affilio-stat-number"><?php echo esc_html( Affilio_I18n::number( $visit_summary['clicks'] ) ); ?></dd></div>
				<div class="affilio-stat"><dt class="affilio-stat-label"><?php esc_html_e( 'Unique Visitors', 'dreamax-affiliates' ); ?></dt><dd class="affilio-stat-number"><?php echo esc_html( Affilio_I18n::number( $visit_summary['unique_visitors'] ) ); ?></dd></div>
				<div class="affilio-stat"><dt class="affilio-stat-label"><?php esc_html_e( 'Conversion Rate', 'dreamax-affiliates' ); ?></dt><dd class="affilio-stat-number"><?php echo esc_html( Affilio_I18n::percentage( $visit_summary['conversion_rate'], 2 ) ); ?></dd></div>
				<div class="affilio-stat"><dt class="affilio-stat-label"><?php esc_html_e( 'Referrals', 'dreamax-affiliates' ); ?></dt><dd class="affilio-stat-number"><?php echo esc_html( Affilio_I18n::number( $totals['count'] ) ); ?></dd></div>
				<div class="affilio-stat"><dt class="affilio-stat-label"><?php esc_html_e( 'Paid commission', 'dreamax-affiliates' ); ?></dt><dd class="affilio-stat-number"><?php echo esc_html( $totals['paid_formatted'] ); ?></dd></div>
				<div class="affilio-stat"><dt class="affilio-stat-label"><?php esc_html_e( 'Pending / processing', 'dreamax-affiliates' ); ?></dt><dd class="affilio-stat-number"><?php echo esc_html( $totals['pending_formatted'] ); ?></dd></div>
			</dl>

			<?php
			/**
			 * Lets a separately distributed add-on render provider-owned dashboard reports.
			 *
			 * @param array  $report_data Basic immutable summary plus provider datasets.
			 * @param object $affiliate   Current affiliate record.
			 * @param array  $filters     Sanitized report filters.
			 */
			do_action( 'affilio_affiliate_dashboard_after_summary', $report_data, $affiliate, $filters );
			?>

			<p class="affilio-export-links">
				<a href="<?php echo esc_url( $visit_export_url ); ?>" class="affilio-button-link"><?php esc_html_e( 'Download Clicks CSV', 'dreamax-affiliates' ); ?></a>
				<a href="<?php echo esc_url( $referral_export_url ); ?>" class="affilio-button-link"><?php esc_html_e( 'Download Referrals CSV', 'dreamax-affiliates' ); ?></a>
			</p>

			<h3><?php esc_html_e( 'Recent Clicks', 'dreamax-affiliates' ); ?></h3>
			<?php if ( empty( $recent_clicks ) ) : ?>
				<div class="affilio-dashboard-empty"><strong><?php esc_html_e( 'No tracked clicks yet', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'Generate a referral link above and share it. New visits will appear here after someone opens that link.', 'dreamax-affiliates' ); ?></p><a href="#<?php echo esc_attr( $link_section_id ); ?>" data-affilio-panel-target="links"><?php esc_html_e( 'Create a link', 'dreamax-affiliates' ); ?></a></div>
			<?php else : ?>
				<div class="affilio-table-wrap">
					<table class="affilio-referrals-table affilio-clicks-table">
						<caption class="affilio-sr-only"><?php esc_html_e( 'Recent affiliate clicks', 'dreamax-affiliates' ); ?></caption>
						<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Landing Page', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Campaign', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Converted', 'dreamax-affiliates' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $recent_clicks as $click ) : ?>
							<tr>
								<td><?php echo esc_html( $this->format_date( $click->date_created ) ); ?></td>
								<td><?php echo esc_html( wp_html_excerpt( $click->landing_page, 60, '&hellip;' ) ); ?></td>
								<td><?php echo $click->campaign ? esc_html( $click->campaign ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><?php echo $click->converted ? esc_html__( 'Yes', 'dreamax-affiliates' ) : esc_html__( 'No', 'dreamax-affiliates' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Recent Referrals', 'dreamax-affiliates' ); ?></h3>
			<?php if ( empty( $referrals ) ) : ?>
				<div class="affilio-dashboard-empty"><strong><?php esc_html_e( 'No referrals yet', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'A referral appears after a qualifying order is attributed to your link or assigned coupon.', 'dreamax-affiliates' ); ?></p></div>
			<?php else : ?>
				<div class="affilio-table-wrap">
					<table class="affilio-referrals-table affilio-recent-referrals-table">
						<caption class="affilio-sr-only"><?php esc_html_e( 'Recent affiliate referrals', 'dreamax-affiliates' ); ?></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'dreamax-affiliates' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Order Amount', 'dreamax-affiliates' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Commission', 'dreamax-affiliates' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Source', 'dreamax-affiliates' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $referrals as $referral ) : ?>
								<tr>
									<td><?php echo esc_html( $this->format_date( $referral->date_created ) ); ?></td>
									<td><?php echo esc_html( $this->format_amount( $referral->amount, $referral->currency ) ); ?></td>
									<td><?php echo esc_html( $this->format_amount( $referral->commission_amount, $referral->currency ) ); ?></td>
									<td><?php echo esc_html( Affilio_I18n::source_label( ! empty( $referral->source ) ? $referral->source : 'link' ) ); ?><?php if ( ! empty( $referral->coupon_code ) ) : ?> <code><?php echo esc_html( $referral->coupon_code ); ?></code><?php endif; ?></td>
									<td><span class="affilio-status affilio-status-<?php echo esc_attr( $referral->status ); ?>"><?php echo esc_html( Affilio_I18n::status_label( $referral->status ) ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			</section>

			<?php if ( ! empty( $creatives ) ) : ?>
			<section class="affilio-dashboard-section affilio-portal-panel" data-affilio-panel="creatives" hidden aria-hidden="true" id="<?php echo esc_attr( $creatives_section_id ); ?>" aria-labelledby="<?php echo esc_attr( $creatives_section_id . '-title' ); ?>">
				<h3 id="<?php echo esc_attr( $creatives_section_id . '-title' ); ?>"><?php esc_html_e( 'Creative Library', 'dreamax-affiliates' ); ?></h3>
				<p><?php esc_html_e( 'Use these ready-made banners and links. Every URL below already contains your affiliate code.', 'dreamax-affiliates' ); ?></p>
				<div class="affilio-creative-grid">
					<?php foreach ( $creatives as $creative ) : ?>
						<?php $creative_input_id = wp_unique_id( 'affilio-creative-url-' ); ?>
						<article class="affilio-creative-card">
							<?php if ( $creative['image_url'] ) : ?><img src="<?php echo esc_url( $creative['image_url'] ); ?>" alt="<?php echo esc_attr( $creative['title'] ); ?>"><?php endif; ?>
							<h4><?php echo esc_html( $creative['title'] ); ?></h4>
							<?php if ( $creative['description'] ) : ?><p><?php echo esc_html( $creative['description'] ); ?></p><?php endif; ?>
							<label for="<?php echo esc_attr( $creative_input_id ); ?>"><?php esc_html_e( 'Affiliate URL', 'dreamax-affiliates' ); ?></label><input id="<?php echo esc_attr( $creative_input_id ); ?>" type="text" readonly aria-readonly="true" value="<?php echo esc_attr( $creative['url'] ); ?>">
							<div class="affilio-creative-actions"><button type="button" class="affilio-copy-value" data-copy-value="<?php echo esc_attr( $creative['url'] ); ?>"><?php esc_html_e( 'Copy Link', 'dreamax-affiliates' ); ?></button><button type="button" class="affilio-copy-value" data-copy-value="<?php echo esc_attr( $creative['html'] ); ?>"><?php esc_html_e( 'Copy HTML', 'dreamax-affiliates' ); ?></button></div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>


			<section class="affilio-dashboard-section affilio-profile-settings-section affilio-portal-panel" data-affilio-panel="profile" hidden aria-hidden="true" id="<?php echo esc_attr( $profile_section_id ); ?>" aria-labelledby="<?php echo esc_attr( $profile_section_id . '-title' ); ?>">
				<div class="affilio-profile-page-head">
					<div>
						<p class="affilio-dashboard-eyebrow"><?php esc_html_e( 'Affiliate account', 'dreamax-affiliates' ); ?></p>
						<h3 id="<?php echo esc_attr( $profile_section_id . '-title' ); ?>"><?php esc_html_e( 'Profile & Settings', 'dreamax-affiliates' ); ?></h3>
						<p><?php esc_html_e( 'Manage the profile information used by the affiliate program and keep your payout preferences ready for approved commissions.', 'dreamax-affiliates' ); ?></p>
					</div>
					<span class="affilio-profile-status-badge"><?php esc_html_e( 'Active affiliate', 'dreamax-affiliates' ); ?></span>
				</div>

				<form class="affilio-profile-settings-form affilio-profile-settings-form--premium" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="affilio_save_affiliate_profile">
					<?php wp_nonce_field( 'affilio_save_affiliate_profile' ); ?>

					<section class="affilio-profile-settings-group affilio-profile-card" aria-labelledby="<?php echo esc_attr( $profile_section_id . '-promotion-title' ); ?>">
						<div class="affilio-profile-settings-heading affilio-profile-card-head">
							<span class="affilio-profile-step" aria-hidden="true">01</span>
							<div>
								<h4 id="<?php echo esc_attr( $profile_section_id . '-promotion-title' ); ?>"><?php esc_html_e( 'Promotion profile', 'dreamax-affiliates' ); ?></h4>
								<p><?php esc_html_e( 'Tell the program owner where you publish and how you introduce products to your audience.', 'dreamax-affiliates' ); ?></p>
							</div>
						</div>

						<div class="affilio-profile-fields-grid">
							<p class="affilio-field">
								<label for="<?php echo esc_attr( $website_id ); ?>"><?php esc_html_e( 'Website', 'dreamax-affiliates' ); ?></label>
								<span class="affilio-profile-control">
									<input type="url" id="<?php echo esc_attr( $website_id ); ?>" name="website_url" value="<?php echo esc_attr( $affiliate->website_url ?? '' ); ?>" placeholder="https://example.com" inputmode="url" autocomplete="url">
								</span>
								<small><?php esc_html_e( 'Your main website, publication, or product-review destination.', 'dreamax-affiliates' ); ?></small>
							</p>

							<p class="affilio-field">
								<label for="<?php echo esc_attr( $social_id ); ?>"><?php esc_html_e( 'Primary social profile', 'dreamax-affiliates' ); ?></label>
								<span class="affilio-profile-control">
									<input type="url" id="<?php echo esc_attr( $social_id ); ?>" name="social_profile" value="<?php echo esc_attr( $affiliate->social_profile ?? '' ); ?>" placeholder="https://" inputmode="url">
								</span>
								<small><?php esc_html_e( 'Optional public social or creator profile.', 'dreamax-affiliates' ); ?></small>
							</p>

							<p class="affilio-field affilio-field--full">
								<label for="<?php echo esc_attr( $promotion_id ); ?>"><?php esc_html_e( 'How do you promote this site?', 'dreamax-affiliates' ); ?> <span aria-hidden="true">*</span></label>
								<span class="affilio-profile-control">
									<textarea id="<?php echo esc_attr( $promotion_id ); ?>" name="promotion_method" rows="5" required aria-required="true"><?php echo esc_textarea( $affiliate->promotion_method ?? '' ); ?></textarea>
								</span>
								<small><?php esc_html_e( 'Describe your audience, channel, content style, and promotion method.', 'dreamax-affiliates' ); ?></small>
							</p>
						</div>
					</section>

					<section class="affilio-profile-settings-group affilio-profile-card" aria-labelledby="<?php echo esc_attr( $profile_section_id . '-payout-title' ); ?>">
						<div class="affilio-profile-settings-heading affilio-profile-card-head">
							<span class="affilio-profile-step" aria-hidden="true">02</span>
							<div>
								<h4 id="<?php echo esc_attr( $profile_section_id . '-payout-title' ); ?>"><?php esc_html_e( 'Payout preferences', 'dreamax-affiliates' ); ?></h4>
								<p><?php esc_html_e( 'Keep the destination for approved commissions current. Sensitive passwords or card security codes should never be entered here.', 'dreamax-affiliates' ); ?></p>
							</div>
						</div>

						<div class="affilio-profile-fields-grid">
							<p class="affilio-field">
								<label for="<?php echo esc_attr( $payout_method_id ); ?>"><?php esc_html_e( 'Payout method', 'dreamax-affiliates' ); ?></label>
								<span class="affilio-profile-control">
									<select id="<?php echo esc_attr( $payout_method_id ); ?>" class="affilio-payout-method" name="payout_method">
										<?php foreach ( $methods as $method_key => $method_label ) : ?>
											<option value="<?php echo esc_attr( $method_key ); ?>" <?php selected( $affiliate->payout_method ?? 'paypal', $method_key ); ?>><?php echo esc_html( $method_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</span>
							</p>

							<p class="affilio-field">
								<label for="<?php echo esc_attr( $payout_email_id ); ?>"><?php esc_html_e( 'Payout/contact email', 'dreamax-affiliates' ); ?> <span aria-hidden="true">*</span></label>
								<span class="affilio-profile-control">
									<input type="email" id="<?php echo esc_attr( $payout_email_id ); ?>" name="payout_email" value="<?php echo esc_attr( $affiliate->payout_email ); ?>" autocomplete="email" required aria-required="true">
								</span>
								<small><?php esc_html_e( 'Used for payout communication and supported email-based methods.', 'dreamax-affiliates' ); ?></small>
							</p>

							<p class="affilio-field affilio-field--full">
								<label for="<?php echo esc_attr( $payout_details_id ); ?>"><?php esc_html_e( 'Payout destination / account details', 'dreamax-affiliates' ); ?></label>
								<span class="affilio-profile-control">
									<textarea id="<?php echo esc_attr( $payout_details_id ); ?>" class="affilio-payout-details" name="payout_details" rows="5" aria-describedby="<?php echo esc_attr( $payout_help_id ); ?>"><?php echo esc_textarea( $affiliate->payout_details ?? '' ); ?></textarea>
								</span>
								<small id="<?php echo esc_attr( $payout_help_id ); ?>"><?php esc_html_e( 'For bank transfer or other manual methods, enter the destination/account details needed to receive the payout. These details are shown to administrators as the payout destination.', 'dreamax-affiliates' ); ?></small>
							</p>
						</div>
					</section>

					<footer class="affilio-profile-settings-foot affilio-profile-settings-foot--premium">
						<div class="affilio-profile-security-note">
							<strong><?php esc_html_e( 'Affiliate settings only', 'dreamax-affiliates' ); ?></strong>
							<p><?php esc_html_e( 'Your WordPress login credentials remain managed by WordPress. This form only updates affiliate-program profile and payout preferences.', 'dreamax-affiliates' ); ?></p>
						</div>
						<button type="submit"><?php esc_html_e( 'Save Profile & Settings', 'dreamax-affiliates' ); ?></button>
					</footer>
				</form>
			</section>

			<section class="affilio-dashboard-section affilio-portal-panel" data-affilio-panel="payouts" hidden aria-hidden="true" id="<?php echo esc_attr( $payouts_section_id ); ?>" aria-labelledby="<?php echo esc_attr( $payouts_section_id . '-title' ); ?>">
				<h3 id="<?php echo esc_attr( $payouts_section_id . '-title' ); ?>"><?php esc_html_e( 'Payouts', 'dreamax-affiliates' ); ?></h3>
			<p><?php esc_html_e( 'Review available balances, request eligible payouts, and follow payout history. Payment preferences are managed in Profile & Settings.', 'dreamax-affiliates' ); ?></p>
			<div class="affilio-payout-profile-summary">
				<div>
					<span><?php esc_html_e( 'Payout method', 'dreamax-affiliates' ); ?></span>
					<strong><?php echo esc_html( Affilio_I18n::payment_method_label( $payout_method, $methods ) ); ?></strong>
				</div>
				<div>
					<span><?php esc_html_e( 'Payout/contact email', 'dreamax-affiliates' ); ?></span>
					<strong><?php echo esc_html( $affiliate->payout_email ? $affiliate->payout_email : __( 'Not set', 'dreamax-affiliates' ) ); ?></strong>
				</div>
				<a href="#<?php echo esc_attr( $profile_section_id ); ?>" data-affilio-panel-target="profile"><?php esc_html_e( 'Edit profile & payout settings', 'dreamax-affiliates' ); ?></a>
			</div>

			<?php if ( $invalid_bank_details ) : ?>
				<p class="affilio-form-message affilio-error" role="alert">
					<?php esc_html_e( 'Add valid bank transfer account details in Profile & Settings before requesting a payout.', 'dreamax-affiliates' ); ?>
				</p>
			<?php endif; ?>

<h3><?php esc_html_e( 'Request a Payout', 'dreamax-affiliates' ); ?></h3>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: minimum payout amount. */
						__( 'Minimum request threshold: %s per currency.', 'dreamax-affiliates' ),
						Affilio_I18n::number( $payout_threshold, 2 )
					)
				);
				?>
			</p>

			<?php if ( ! empty( $available_payout_totals ) ) : ?>
				<ul class="affilio-payout-balances" aria-label="<?php echo esc_attr__( 'Available unpaid balances', 'dreamax-affiliates' ); ?>">
					<?php foreach ( $available_payout_totals as $currency => $amount ) : ?>
						<li><strong><?php echo esc_html( $currency ); ?>:</strong> <?php echo esc_html( $this->format_amount( $amount, $currency ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $open_payout_requests ) ) : ?>
				<?php foreach ( $open_payout_requests as $currency => $open_request ) : ?>
					<p class="affilio-form-message affilio-info affilio-payout-action-message" role="status">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: payout currency code. */
								__( 'You already have an open payout request for %s. Cancel it or wait for admin review before submitting another request for this currency.', 'dreamax-affiliates' ),
								$currency
							)
						);
						?>
					</p>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( get_option( 'affilio_enable_payout_requests', true ) ) : ?>
				<?php if ( empty( $eligible_payout_totals ) ) : ?>
					<p><?php esc_html_e( 'No unpaid currency balance has reached the request threshold yet.', 'dreamax-affiliates' ); ?></p>
				<?php elseif ( $invalid_bank_details ) : ?>
					<p class="affilio-form-message affilio-warning affilio-payout-action-message" role="status">
						<?php esc_html_e( 'Update your bank transfer account details to enable payout requests.', 'dreamax-affiliates' ); ?>
					</p>
				<?php elseif ( empty( $requestable_totals ) ) : ?>
					<p><?php esc_html_e( 'There are no additional eligible currencies available for a new payout request right now.', 'dreamax-affiliates' ); ?></p>
				<?php else : ?>
					<form class="affilio-payout-request-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="affilio_request_payout">
						<?php wp_nonce_field( 'affilio_request_payout' ); ?>
						<label for="<?php echo esc_attr( $request_currency_id ); ?>"><?php esc_html_e( 'Currency and eligible balance', 'dreamax-affiliates' ); ?></label>
						<select id="<?php echo esc_attr( $request_currency_id ); ?>" name="currency" required aria-required="true">
								<?php foreach ( $requestable_totals as $currency => $amount ) : ?>
									<option value="<?php echo esc_attr( $currency ); ?>"><?php echo esc_html( $this->format_amount( $amount, $currency ) ); ?></option>
								<?php endforeach; ?>
							</select>
						<label for="<?php echo esc_attr( $request_note_id ); ?>"><?php esc_html_e( 'Request note (optional)', 'dreamax-affiliates' ); ?></label>
						<textarea id="<?php echo esc_attr( $request_note_id ); ?>" name="request_note" rows="3" maxlength="2000"></textarea>
						<button type="submit"><?php esc_html_e( 'Submit Payout Request', 'dreamax-affiliates' ); ?></button>
					</form>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'New payout requests are currently disabled by the program administrator.', 'dreamax-affiliates' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $payout_requests ) ) : ?>
				<h4><?php esc_html_e( 'Payout Request History', 'dreamax-affiliates' ); ?></h4>
				<div class="affilio-table-wrap">
					<table class="affilio-referrals-table affilio-payout-requests-table">
						<caption class="affilio-sr-only"><?php esc_html_e( 'Payout request history', 'dreamax-affiliates' ); ?></caption>
						<thead>
							<tr><th scope="col"><?php esc_html_e( 'Date', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Amount', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Admin note', 'dreamax-affiliates' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'dreamax-affiliates' ); ?></th></tr>
						</thead>
						<tbody>
							<?php foreach ( $payout_requests as $request ) : ?>
								<?php
								$request_display_status = (string) $request->status;
								if (
									'approved' === $request_display_status
									&& ! empty( $request->payout_id )
									&& isset( $payout_statuses[ (int) $request->payout_id ] )
									&& 'paid' === $payout_statuses[ (int) $request->payout_id ]
								) {
									$request_display_status = 'paid';
								}
								?>
								<tr>
									<td><?php echo esc_html( $this->format_date( $request->date_created ) ); ?></td>
									<td><?php echo esc_html( $this->format_amount( $request->amount, $request->currency ) ); ?></td>
									<td><span class="affilio-status affilio-status-<?php echo esc_attr( $request_display_status ); ?>"><?php echo esc_html( Affilio_I18n::status_label( $request_display_status ) ); ?></span></td>
									<td><?php echo $request->admin_note ? esc_html( $request->admin_note ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static entity. ?></td>
									<td>
										<?php if ( 'requested' === $request->status ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="affilio_cancel_payout_request">
												<input type="hidden" name="request_id" value="<?php echo esc_attr( $request->id ); ?>">
												<?php wp_nonce_field( 'affilio_cancel_payout_request_' . $request->id ); ?>
												<button type="submit" class="affilio-payout-request-cancel"><?php esc_html_e( 'Cancel', 'dreamax-affiliates' ); ?></button>
											</form>
										<?php else : ?>
											&mdash;
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Payout History', 'dreamax-affiliates' ); ?></h3>
			<?php if ( empty( $payouts ) ) : ?>
				<div class="affilio-dashboard-empty"><strong><?php esc_html_e( 'No payout history yet', 'dreamax-affiliates' ); ?></strong><p><?php esc_html_e( 'Completed and in-progress payout batches will appear here.', 'dreamax-affiliates' ); ?></p></div>
			<?php else : ?>
				<div class="affilio-table-wrap">
					<table class="affilio-referrals-table affilio-payouts-table">
						<caption class="affilio-sr-only"><?php esc_html_e( 'Payout history', 'dreamax-affiliates' ); ?></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'dreamax-affiliates' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Batch', 'dreamax-affiliates' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Amount', 'dreamax-affiliates' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Method', 'dreamax-affiliates' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'dreamax-affiliates' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Reference', 'dreamax-affiliates' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $payouts as $payout ) : ?>
								<tr>
									<td><?php echo esc_html( $this->format_date( $payout->date_paid ? $payout->date_paid : $payout->date_created ) ); ?></td>
									<td><code><?php echo esc_html( $payout->batch_key ); ?></code></td>
									<td><?php echo esc_html( $this->format_amount( $payout->amount, $payout->currency ) ); ?></td>
									<td><?php echo esc_html( Affilio_I18n::payment_method_label( $payout->payment_method, $methods ) ); ?></td>
									<td><span class="affilio-status affilio-status-<?php echo esc_attr( $payout->status ); ?>"><?php echo esc_html( Affilio_I18n::status_label( $payout->status ) ); ?></span></td>
									<td><?php echo $payout->reference ? esc_html( $payout->reference ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static entity. ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			</section>
		</section>
		<?php
		return ob_get_clean();
	}


	/**
	 * Saves the logged-in affiliate's standalone profile/settings form.
	 *
	 * Only affiliate-program fields are managed here. WordPress login
	 * credentials remain owned by WordPress itself.
	 *
	 * @return void
	 */
	public function handle_profile_settings_save() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in first.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'affilio_save_affiliate_profile' );

		$affiliate = affilio()->affiliates_db->get_by_user_id( get_current_user_id() );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			wp_die( esc_html__( 'An active affiliate account is required to update these settings.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$website_url      = isset( $_POST['website_url'] ) ? esc_url_raw( wp_unslash( $_POST['website_url'] ) ) : '';
		$promotion_method = isset( $_POST['promotion_method'] ) ? sanitize_textarea_field( wp_unslash( $_POST['promotion_method'] ) ) : '';
		$social_profile   = isset( $_POST['social_profile'] ) ? esc_url_raw( wp_unslash( $_POST['social_profile'] ) ) : '';
		$payout_method    = isset( $_POST['payout_method'] ) ? affilio()->payouts->sanitize_payout_method( wp_unslash( $_POST['payout_method'] ) ) : 'paypal'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizer validates against registered methods.
		$payout_email     = isset( $_POST['payout_email'] ) ? sanitize_email( wp_unslash( $_POST['payout_email'] ) ) : '';
		$payout_details   = isset( $_POST['payout_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payout_details'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$error = '';

		if ( '' === trim( $promotion_method ) ) {
			$error = __( 'Please describe how you promote this site.', 'dreamax-affiliates' );
		} elseif ( ! is_email( $payout_email ) ) {
			$error = __( 'Enter a valid payout/contact email address.', 'dreamax-affiliates' );
		} elseif ( 'paypal' !== $payout_method && '' === trim( $payout_details ) ) {
			$error = __( 'Enter payout account details or instructions for the selected payout method.', 'dreamax-affiliates' );
		}

		$dashboard_url = class_exists( 'Affilio_My_Account' )
			? Affilio_My_Account::get_preferred_dashboard_url()
			: get_permalink( absint( get_option( 'affilio_dashboard_page_id', 0 ) ) );

		$dashboard_url = $dashboard_url ? $dashboard_url : home_url( '/' );

		if ( $error ) {
			set_transient( 'affilio_profile_front_notice_' . get_current_user_id(), $error, MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'affilio_profile_error', '1', $dashboard_url ) . '#affilio-profile-settings' );
			exit;
		}

		$data = array(
			'website_url'      => $website_url,
			'promotion_method' => $promotion_method,
			'social_profile'   => $social_profile,
			'payout_method'    => $payout_method,
			'payout_email'     => $payout_email,
			'payout_details'   => $payout_details,
		);

		$updated = affilio()->affiliates_db->update(
			(int) $affiliate->id,
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $updated ) {
			set_transient(
				'affilio_profile_front_notice_' . get_current_user_id(),
				__( 'Your affiliate profile could not be saved. Please try again.', 'dreamax-affiliates' ),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect( add_query_arg( 'affilio_profile_error', '1', $dashboard_url ) . '#affilio-profile-settings' );
			exit;
		}

		if ( affilio()->audit ) {
			affilio()->audit->record(
				'affiliate',
				(int) $affiliate->id,
				'profile_updated',
				'',
				array( 'source' => 'affiliate_dashboard' )
			);
		}

		wp_safe_redirect( add_query_arg( 'affilio_profile_updated', '1', $dashboard_url ) . '#affilio-profile-settings' );
		exit;
	}

	/**
	 * Displays payout-profile save feedback.
	 *
	 * @return void
	 */
	private function render_profile_notice() {
		if ( isset( $_GET['affilio_profile_updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['affilio_profile_updated'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p role="status" aria-live="polite" aria-atomic="true" tabindex="-1" class="affilio-form-message affilio-success" data-affilio-notice-panel="profile" data-affilio-ephemeral-notice="1">' . esc_html__( 'Your affiliate profile and payout settings were saved.', 'dreamax-affiliates' ) . '</p>';
		}

		if ( isset( $_GET['affilio_profile_error'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['affilio_profile_error'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key     = 'affilio_profile_front_notice_' . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key );
			if ( $message ) {
				echo '<p role="alert" aria-live="assertive" aria-atomic="true" tabindex="-1" class="affilio-form-message affilio-error" data-affilio-notice-panel="profile" data-affilio-ephemeral-notice="1">' . esc_html( $message ) . '</p>';
			}
		}

		$notice = get_transient( 'affilio_payout_request_notice_' . get_current_user_id() );
		if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
			delete_transient( 'affilio_payout_request_notice_' . get_current_user_id() );
			$class = 'error' === ( $notice['type'] ?? '' ) ? 'affilio-error' : 'affilio-success';
			echo '<p role="status" aria-live="polite" aria-atomic="true" tabindex="-1" class="affilio-form-message affilio-payout-request-notice ' . esc_attr( $class ) . '" data-affilio-notice-panel="payouts" data-affilio-ephemeral-notice="1">' . esc_html( $notice['message'] ) . '</p>';
		}
		if ( isset( $_GET['affilio_payout_updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['affilio_payout_updated'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p role="status" aria-live="polite" aria-atomic="true" tabindex="-1" class="affilio-form-message affilio-success" data-affilio-notice-panel="payouts" data-affilio-ephemeral-notice="1">' . esc_html__( 'Your payout settings were saved.', 'dreamax-affiliates' ) . '</p>';
		}

		if ( isset( $_GET['affilio_payout_error'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['affilio_payout_error'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key     = 'affilio_payout_front_notice_' . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key );
			if ( $message ) {
				echo '<p role="alert" aria-live="assertive" aria-atomic="true" tabindex="-1" class="affilio-form-message affilio-error" data-affilio-notice-panel="payouts" data-affilio-ephemeral-notice="1">' . esc_html( $message ) . '</p>';
			}
		}
	}

	/**
	 * Renders a clear application-state card for affiliates that are not active yet.
	 *
	 * Keeping this state intentionally compact prevents pending or restricted
	 * affiliates from seeing empty dashboard tools before they can use them.
	 *
	 * @param object $affiliate Affiliate database row.
	 * @return string
	 */
	private function application_status_card( $affiliate ) {
		$status = sanitize_key( (string) ( $affiliate->status ?? 'pending' ) );
		$label    = Affilio_I18n::status_label( $status );
		$title_id = wp_unique_id( 'affilio-application-state-title-' );

		$copy = array(
			'pending' => array(
				'title'       => __( 'Application under review', 'dreamax-affiliates' ),
				'description' => __( 'Your affiliate application has been received and is waiting for review. You will be able to access referral tools after the application is approved.', 'dreamax-affiliates' ),
				'note'        => __( 'You can continue using your account while the application is being reviewed.', 'dreamax-affiliates' ),
			),
			'rejected' => array(
				'title'       => __( 'Application not approved', 'dreamax-affiliates' ),
				'description' => __( 'Your affiliate application was not approved. Contact the site owner if you need clarification or believe the decision should be reviewed.', 'dreamax-affiliates' ),
				'note'        => __( 'Affiliate links and earning tools are unavailable for this account.', 'dreamax-affiliates' ),
			),
			'suspended' => array(
				'title'       => __( 'Affiliate account suspended', 'dreamax-affiliates' ),
				'description' => __( 'Your affiliate access is temporarily suspended. Contact the site owner for more information.', 'dreamax-affiliates' ),
				'note'        => __( 'Referral and payout tools remain unavailable while the account is suspended.', 'dreamax-affiliates' ),
			),
			'banned' => array(
				'title'       => __( 'Affiliate account unavailable', 'dreamax-affiliates' ),
				'description' => __( 'This account is not currently permitted to participate in the affiliate program.', 'dreamax-affiliates' ),
				'note'        => __( 'Contact the site owner if you have questions about this status.', 'dreamax-affiliates' ),
			),
		);

		$state = isset( $copy[ $status ] ) ? $copy[ $status ] : array(
			'title'       => __( 'Affiliate application status', 'dreamax-affiliates' ),
			'description' => __( 'Your affiliate account is not active yet. Contact the site owner if you need more information.', 'dreamax-affiliates' ),
			'note'        => __( 'Affiliate tools become available when the account is active.', 'dreamax-affiliates' ),
		);

		$dashboard_return_url = class_exists( 'Affilio_My_Account' ) ? Affilio_My_Account::get_preferred_dashboard_url() : get_permalink();
		$logout_url           = wp_logout_url( $dashboard_return_url ? $dashboard_return_url : home_url( '/' ) );

		$reason = '';
		if ( in_array( $status, array( 'rejected', 'suspended', 'banned' ), true ) && ! empty( $affiliate->status_reason ) ) {
			$reason = sanitize_textarea_field( (string) $affiliate->status_reason );
		}

		ob_start();
		?>
		<section class="affilio-application-state affilio-application-state-<?php echo esc_attr( $status ); ?>" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
			<div class="affilio-application-state-heading">
				<div>
					<p class="affilio-application-state-eyebrow"><?php esc_html_e( 'Affiliate Area', 'dreamax-affiliates' ); ?></p>
					<h2 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $state['title'] ); ?></h2>
				</div>
				<span class="affilio-application-status-badge affilio-application-status-<?php echo esc_attr( $status ); ?>">
					<span class="affilio-application-status-dot" aria-hidden="true"></span>
					<?php echo esc_html( $label ); ?>
				</span>
			</div>

			<p class="affilio-application-state-description"><?php echo esc_html( $state['description'] ); ?></p>

			<dl class="affilio-application-state-meta">
				<div>
					<dt><?php esc_html_e( 'Application status', 'dreamax-affiliates' ); ?></dt>
					<dd><?php echo esc_html( $label ); ?></dd>
				</div>
				<?php if ( $reason ) : ?>
				<div>
					<dt><?php esc_html_e( 'Status note', 'dreamax-affiliates' ); ?></dt>
					<dd><?php echo esc_html( $reason ); ?></dd>
				</div>
				<?php endif; ?>
			</dl>

			<p class="affilio-application-state-note"><?php echo esc_html( $state['note'] ); ?></p>
			<nav class="affilio-application-state-actions" aria-label="<?php echo esc_attr__( 'Affiliate account actions', 'dreamax-affiliates' ); ?>">
				<a href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'dreamax-affiliates' ); ?></a>
			</nav>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * @param string $message Already-escaped HTML message.
	 * @return string
	 */
	private function notice( $message ) {
		return '<p class="affilio-notice">' . $message . '</p>';
	}

	/**
	 * @param string $referral_code Affiliate referral code.
	 * @return string
	 */
	private function build_referral_url( $referral_code ) {
		return Affilio_Reports::build_referral_url( home_url( '/' ), $referral_code );
	}

	/**
	 * Calculates totals by currency.
	 *
	 * @param object[] $referrals Referral rows.
	 * @return array
	 */
	private function calculate_totals( $referrals ) {
		$paid    = array();
		$pending = array();

		foreach ( $referrals as $referral ) {
			$currency = ! empty( $referral->currency ) ? $referral->currency : $this->get_fallback_currency();

			if ( 'paid' === $referral->status ) {
				$paid[ $currency ] = ( $paid[ $currency ] ?? 0 ) + (float) $referral->commission_amount;
			} elseif ( in_array( $referral->status, array( 'pending', 'unpaid', 'processing' ), true ) ) {
				$pending[ $currency ] = ( $pending[ $currency ] ?? 0 ) + (float) $referral->commission_amount;
			}
		}

		return array(
			'count'             => count( $referrals ),
			'paid_formatted'    => $this->format_currency_totals( $paid ),
			'pending_formatted' => $this->format_currency_totals( $pending ),
		);
	}

	/**
	 * @param array<string,float> $totals Currency totals.
	 * @return string
	 */
	private function format_currency_totals( array $totals ) {
		if ( empty( $totals ) ) {
			return $this->format_amount( 0, $this->get_fallback_currency() );
		}

		$parts = array();
		foreach ( $totals as $currency => $amount ) {
			$parts[] = $this->format_amount( $amount, $currency );
		}
		return implode( ', ', $parts );
	}

	/**
	 * @return string
	 */
	private function get_fallback_currency() {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
	}

	/**
	 * @param float  $amount Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	private function format_amount( $amount, $currency ) {
		if ( empty( $currency ) ) {
			$currency = $this->get_fallback_currency();
		}
		return Affilio_I18n::amount( $amount, $currency );
	}

	/**
	 * @param string $mysql_datetime MySQL datetime.
	 * @return string
	 */
	private function format_date( $mysql_datetime ) {
		return Affilio_I18n::date( $mysql_datetime );
	}
}
