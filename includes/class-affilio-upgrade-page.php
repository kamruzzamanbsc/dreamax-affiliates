<?php
/**
 * Restrained, opt-in information page for the separately distributed Pro add-on.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Upgrade_Page {

	const PAGE_SLUG = 'affilio-pro';

	/**
	 * Renders the upgrade information page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dreamax-affiliates' ) );
		}

		$pro_active  = (bool) apply_filters( 'affilio_pro_is_active', defined( 'AFFILIO_PRO_VERSION' ) );
		$pro_version = (string) apply_filters( 'affilio_pro_version', defined( 'AFFILIO_PRO_VERSION' ) ? AFFILIO_PRO_VERSION : '' );
		$upgrade_url = (string) apply_filters( 'affilio_pro_upgrade_url', '' );
		$pro_features = affilio()->feature_catalog->by_tier( \Affilio\Core\FeatureCatalog::TIER_PRO );
		?>
		<div class="wrap affilio-upgrade-wrap">
			<h1><?php esc_html_e( 'Dreamax Affiliates Pro', 'dreamax-affiliates' ); ?></h1>
			<p class="affilio-upgrade-intro"><?php esc_html_e( 'Dreamax Affiliates Free remains a complete, independently useful affiliate program. Pro is a separately distributed add-on for advanced workflows and is never required to keep existing Free data or features working.', 'dreamax-affiliates' ); ?></p>

			<div class="affilio-upgrade-status <?php echo esc_attr( $pro_active ? 'is-active' : 'is-inactive' ); ?>">
				<span class="dashicons <?php echo esc_attr( $pro_active ? 'dashicons-yes-alt' : 'dashicons-info-outline' ); ?>" aria-hidden="true"></span>
				<div>
					<strong><?php echo $pro_active ? esc_html__( 'Pro add-on detected', 'dreamax-affiliates' ) : esc_html__( 'Free edition active', 'dreamax-affiliates' ); ?></strong>
					<?php /* translators: %s: installed Dreamax Affiliates Pro add-on version number, e.g. "1.4.0". */ ?>
					<p><?php echo $pro_active ? esc_html( sprintf( __( 'The installed Pro add-on reports version %s.', 'dreamax-affiliates' ), $pro_version ?: __( 'unknown', 'dreamax-affiliates' ) ) ) : esc_html__( 'No Pro add-on was detected. Nothing on this page disables or limits the Free workflows.', 'dreamax-affiliates' ); ?></p>
				</div>
			</div>

			<div class="affilio-upgrade-grid">
				<section class="affilio-admin-card">
					<h2><?php esc_html_e( 'Included in Free', 'dreamax-affiliates' ); ?></h2>
					<ul class="affilio-feature-list is-free">
						<li><?php esc_html_e( 'Unlimited affiliate records and application review', 'dreamax-affiliates' ); ?></li>
						<li><?php esc_html_e( 'Referral links, first/last-click attribution, and coupons', 'dreamax-affiliates' ); ?></li>
						<li><?php esc_html_e( 'Percentage, flat-order, and per-affiliate commission rates', 'dreamax-affiliates' ); ?></li>
						<li><?php esc_html_e( 'Manual referrals, payout requests, and manual payout batches', 'dreamax-affiliates' ); ?></li>
						<li><?php esc_html_e( 'Basic reports, CSV exports, creatives, QR codes, and sharing', 'dreamax-affiliates' ); ?></li>
						<li><?php esc_html_e( 'System Status, attribution testing, privacy, and lifecycle safeguards', 'dreamax-affiliates' ); ?></li>
					</ul>
				</section>

				<section class="affilio-admin-card">
					<h2><?php esc_html_e( 'Advanced Pro scope', 'dreamax-affiliates' ); ?></h2>
					<p><?php esc_html_e( 'The audited product boundary reserves these workflows for separately maintained add-ons. Availability depends on the published Pro release.', 'dreamax-affiliates' ); ?></p>
					<ul class="affilio-feature-list is-pro">
						<?php foreach ( array_slice( $pro_features, 0, 12, true ) as $feature ) : ?>
							<li><?php echo esc_html( $feature['label'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>
			</div>

			<section class="affilio-admin-card affilio-upgrade-principles">
				<h2><?php esc_html_e( 'A restrained upgrade path', 'dreamax-affiliates' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'No dashboard banners, pop-ups, recurring notices, or feature lock screens.', 'dreamax-affiliates' ); ?></li>
					<li><?php esc_html_e( 'No Pro implementation or dormant premium code is bundled in the WordPress.org Free package.', 'dreamax-affiliates' ); ?></li>
					<li><?php esc_html_e( 'Free and shared data remains preserved when an add-on is installed, removed, or upgraded.', 'dreamax-affiliates' ); ?></li>
				</ul>
				<?php if ( $upgrade_url && ! $pro_active ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn About Dreamax Affiliates Pro', 'dreamax-affiliates' ); ?></a>
				<?php elseif ( ! $pro_active ) : ?>
					<p class="description"><?php esc_html_e( 'A verified product URL has not been configured in this package. The upgrade button will appear only when an approved URL is supplied.', 'dreamax-affiliates' ); ?></p>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}
}
