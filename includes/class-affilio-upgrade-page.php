<?php
/**
 * Information and next steps for the separately distributed Pro add-on.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Upgrade_Page {

	const PAGE_SLUG = 'affilio-pro';
	const PRO_PURCHASE_URL = 'https://dreamaxsoft.gumroad.com/l/dreamax-affiliates-pro?wanted=true';

	/**
	 * Registers assets for this information page.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Loads styles only on this page, excluding similarly named Pro screens.
	 *
	 * @param string $hook Current admin screen hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$suffix = '_page_' . self::PAGE_SLUG;
		if ( substr( (string) $hook, -strlen( $suffix ) ) !== $suffix ) {
			return;
		}

		$file = 'assets/css/affilio-upgrade-admin.css';
		wp_enqueue_style(
			'affilio-upgrade-admin',
			AFFILIO_PLUGIN_URL . $file,
			array( 'affilio-admin' ),
			file_exists( AFFILIO_PLUGIN_DIR . $file ) ? (string) filemtime( AFFILIO_PLUGIN_DIR . $file ) : AFFILIO_VERSION
		);
	}

	/**
	 * Returns a link only when a supported Pro menu is registered and accessible.
	 *
	 * @param string $slug Known Pro menu slug.
	 * @return string
	 */
	private function available_pro_url( $slug ) {
		if ( ! in_array( $slug, array( 'affilio-pro-license', 'affilio-pro-getting-started' ), true ) ) {
			return '';
		}

		foreach ( (array) ( $GLOBALS['submenu']['affilio'] ?? array() ) as $item ) {
			if ( isset( $item[1], $item[2] ) && $slug === $item[2] && current_user_can( $item[1] ) ) {
				return admin_url( 'admin.php?page=' . $slug );
			}
		}

		return '';
	}

	/**
	 * Renders the upgrade information page without changing program data.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dreamax-affiliates' ) );
		}

		$pro_active  = (bool) apply_filters( 'affilio_pro_is_active', defined( 'AFFILIO_PRO_VERSION' ) );
		$pro_version = (string) apply_filters( 'affilio_pro_version', defined( 'AFFILIO_PRO_VERSION' ) ? AFFILIO_PRO_VERSION : '' );
		$upgrade_url = (string) apply_filters( 'affilio_pro_upgrade_url', self::PRO_PURCHASE_URL );
		$url_parts   = wp_parse_url( $upgrade_url );
		$upgrade_url = is_array( $url_parts ) && ! empty( $url_parts['host'] ) && in_array( strtolower( $url_parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ? $upgrade_url : '';
		$license_url = $pro_active ? $this->available_pro_url( 'affilio-pro-license' ) : '';
		$guide_url   = $pro_active ? $this->available_pro_url( 'affilio-pro-getting-started' ) : '';

		// Describe released workflows explicitly; the catalog also contains future scope.
		$features = array(
			array(
				'icon'  => 'dashicons-admin-settings',
				'title' => __( 'Commission rules', 'dreamax-affiliates' ),
				'text'  => __( 'Match rewards to what you sell with more precise commission choices.', 'dreamax-affiliates' ),
				'items' => array( __( 'Product, variation, and category rules', 'dreamax-affiliates' ), __( 'Flat commission per item', 'dreamax-affiliates' ) ),
			),
			array(
				'icon'  => 'dashicons-shield',
				'title' => __( 'Fraud policies', 'dreamax-affiliates' ),
				'text'  => __( 'Add targeted checks to your program alongside the safeguards in Free.', 'dreamax-affiliates' ),
				'items' => array( __( 'Blocked referrer domain policies', 'dreamax-affiliates' ), __( 'Click velocity controls', 'dreamax-affiliates' ) ),
			),
			array(
				'icon'  => 'dashicons-chart-area',
				'title' => __( 'Advanced analytics', 'dreamax-affiliates' ),
				'text'  => __( 'See which parts of your affiliate program contribute to results.', 'dreamax-affiliates' ),
				'items' => array( __( 'Campaign and coupon breakdowns', 'dreamax-affiliates' ), __( 'Product performance insights', 'dreamax-affiliates' ) ),
			),
			array(
				'icon'  => 'dashicons-email-alt',
				'title' => __( 'Email templates', 'dreamax-affiliates' ),
				'text'  => __( 'Give affiliate notifications a consistent voice that fits your store.', 'dreamax-affiliates' ),
				'items' => array( __( 'Editable notification subjects and messages', 'dreamax-affiliates' ), __( 'Plain-text templates with placeholders', 'dreamax-affiliates' ) ),
			),
			array(
				'icon'  => 'dashicons-clock',
				'title' => __( 'Holding automation', 'dreamax-affiliates' ),
				'text'  => __( 'Build a waiting period into your commission workflow before payout.', 'dreamax-affiliates' ),
				'items' => array( __( 'Configurable commission holding period', 'dreamax-affiliates' ), __( 'Automatic release of eligible held referrals', 'dreamax-affiliates' ) ),
			),
			array(
				'icon'  => 'dashicons-upload',
				'title' => __( 'Bulk import', 'dreamax-affiliates' ),
				'text'  => __( 'Bring existing affiliate records into your program with a guided import.', 'dreamax-affiliates' ),
				'items' => array( __( 'Affiliate CSV import', 'dreamax-affiliates' ), __( 'Historical referral import', 'dreamax-affiliates' ) ),
			),
		);

		$comparison = array(
			array( __( 'Commission control', 'dreamax-affiliates' ), __( 'Percentage, flat per order, and affiliate rates', 'dreamax-affiliates' ), __( 'Adds product, variation, category, and per-item rules', 'dreamax-affiliates' ) ),
			array( __( 'Program protection', 'dreamax-affiliates' ), __( 'Self-referral protection', 'dreamax-affiliates' ), __( 'Adds blocked-domain and velocity policies', 'dreamax-affiliates' ) ),
			array( __( 'Reporting', 'dreamax-affiliates' ), __( 'Core reports and CSV exports', 'dreamax-affiliates' ), __( 'Adds campaign, coupon, and product analytics', 'dreamax-affiliates' ) ),
			array( __( 'Affiliate emails', 'dreamax-affiliates' ), __( 'Essential program notifications', 'dreamax-affiliates' ), __( 'Adds customizable plain-text email templates', 'dreamax-affiliates' ) ),
			array( __( 'Commission holding', 'dreamax-affiliates' ), __( 'Manual referral management', 'dreamax-affiliates' ), __( 'Adds holding periods and automatic release', 'dreamax-affiliates' ) ),
			array( __( 'Data migration', 'dreamax-affiliates' ), __( 'Individual affiliate and referral management', 'dreamax-affiliates' ), __( 'Adds affiliate and historical referral CSV imports', 'dreamax-affiliates' ) ),
			array( __( 'Payout workflow', 'dreamax-affiliates' ), __( 'Payout requests and manual payout batches', 'dreamax-affiliates' ), __( 'Uses the same manual payout workflow', 'dreamax-affiliates' ) ),
		);
		?>
		<div class="wrap affilio-upgrade-wrap">
			<hr class="wp-header-end">
			<header class="affilio-upgrade-hero">
				<div class="affilio-upgrade-hero-copy">
					<p class="affilio-upgrade-eyebrow"><?php esc_html_e( 'Advanced affiliate workflows', 'dreamax-affiliates' ); ?></p>
					<h1><?php esc_html_e( 'Dreamax Affiliates', 'dreamax-affiliates' ); ?> <span class="affilio-upgrade-pro-badge"><?php esc_html_e( 'Pro', 'dreamax-affiliates' ); ?></span></h1>
					<p class="affilio-upgrade-lead"><?php esc_html_e( 'More control over rewards. Clearer performance insights. Less routine work as your affiliate program grows.', 'dreamax-affiliates' ); ?></p>
					<div class="affilio-upgrade-actions">
						<?php if ( $license_url ) : ?>
							<a class="button affilio-upgrade-hero-primary" href="<?php echo esc_url( $license_url ); ?>"><span><?php esc_html_e( 'Open Pro License', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
						<?php elseif ( $guide_url ) : ?>
							<a class="button affilio-upgrade-hero-primary" href="<?php echo esc_url( $guide_url ); ?>"><span><?php esc_html_e( 'Open Getting Started', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
						<?php elseif ( ! $pro_active && $upgrade_url ) : ?>
							<a class="button affilio-upgrade-hero-primary" href="<?php echo esc_url( $upgrade_url, array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer"><span><?php esc_html_e( 'Explore Pro', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-external" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'dreamax-affiliates' ); ?></span></a>
						<?php else : ?>
							<a class="button affilio-upgrade-hero-primary" href="#affilio-pro-features"><span><?php esc_html_e( 'Explore Pro features', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></a>
						<?php endif; ?>
						<a class="button affilio-upgrade-hero-secondary" href="#affilio-pro-compare"><span><?php esc_html_e( 'Compare Free & Pro', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></a>
					</div>
				</div>
				<div class="affilio-upgrade-hero-note">
					<span class="affilio-upgrade-hero-icon dashicons dashicons-chart-line" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Built on your Free foundation', 'dreamax-affiliates' ); ?></strong>
					<p><?php esc_html_e( 'A separate add-on that works with your existing affiliates, referrals, and settings.', 'dreamax-affiliates' ); ?></p>
				</div>
			</header>

			<section class="affilio-upgrade-installation" aria-labelledby="affilio-upgrade-installation-title">
				<span class="affilio-upgrade-icon <?php echo esc_attr( $pro_active ? 'is-success' : '' ); ?>"><span class="dashicons <?php echo esc_attr( $pro_active ? 'dashicons-yes-alt' : 'dashicons-info-outline' ); ?>" aria-hidden="true"></span></span>
				<div class="affilio-upgrade-installation-copy">
					<h2 id="affilio-upgrade-installation-title"><?php echo $pro_active ? esc_html__( 'Pro add-on detected', 'dreamax-affiliates' ) : esc_html__( 'Free edition active', 'dreamax-affiliates' ); ?></h2>
					<p>
						<?php if ( $pro_active ) : ?>
							<?php
							/* translators: %s: installed Pro add-on version number. */
							echo esc_html( sprintf( __( 'Installed version: %s.', 'dreamax-affiliates' ), $pro_version ?: __( 'unknown', 'dreamax-affiliates' ) ) );
							?>
							<?php esc_html_e( 'Add-on detection does not confirm license status.', 'dreamax-affiliates' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Your core affiliate tools are ready to use. Pro is optional and installed separately.', 'dreamax-affiliates' ); ?>
						<?php endif; ?>
					</p>
				</div>
				<span class="affilio-upgrade-installation-tag"><?php esc_html_e( 'Your installation', 'dreamax-affiliates' ); ?></span>
			</section>

			<section class="affilio-upgrade-section" id="affilio-pro-features" aria-labelledby="affilio-pro-features-title">
				<div class="affilio-upgrade-section-heading">
					<p class="affilio-upgrade-eyebrow"><?php esc_html_e( 'Inside Pro', 'dreamax-affiliates' ); ?></p>
					<h2 id="affilio-pro-features-title"><?php esc_html_e( 'Tools for your next stage of growth', 'dreamax-affiliates' ); ?></h2>
					<p><?php esc_html_e( 'Extend the workflows you already use with these advanced capabilities.', 'dreamax-affiliates' ); ?></p>
				</div>
				<div class="affilio-upgrade-features">
					<?php foreach ( $features as $feature ) : ?>
						<article class="affilio-upgrade-feature">
							<div class="affilio-upgrade-feature-heading">
								<span class="affilio-upgrade-icon"><span class="dashicons <?php echo esc_attr( $feature['icon'] ); ?>" aria-hidden="true"></span></span>
								<h3><?php echo esc_html( $feature['title'] ); ?></h3>
							</div>
							<p><?php echo esc_html( $feature['text'] ); ?></p>
							<ul>
								<?php foreach ( $feature['items'] as $item ) : ?>
									<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><span><?php echo esc_html( $item ); ?></span></li>
								<?php endforeach; ?>
							</ul>
						</article>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="affilio-upgrade-section affilio-upgrade-comparison" id="affilio-pro-compare" aria-labelledby="affilio-pro-compare-title">
				<div class="affilio-upgrade-section-heading">
					<p class="affilio-upgrade-eyebrow"><?php esc_html_e( 'Choose what fits', 'dreamax-affiliates' ); ?></p>
					<h2 id="affilio-pro-compare-title"><?php esc_html_e( 'A complete foundation. More room to grow.', 'dreamax-affiliates' ); ?></h2>
					<p><?php esc_html_e( 'Free keeps your core program running. Pro adds more control in the areas below.', 'dreamax-affiliates' ); ?></p>
				</div>
				<div class="affilio-upgrade-table-wrap">
					<table class="affilio-upgrade-table" role="table">
						<caption class="screen-reader-text"><?php esc_html_e( 'Compare Dreamax Affiliates Free features with the Pro add-on.', 'dreamax-affiliates' ); ?></caption>
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Workflow', 'dreamax-affiliates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Included in Free', 'dreamax-affiliates' ); ?></th>
							<th scope="col"><?php esc_html_e( 'With Pro', 'dreamax-affiliates' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $comparison as $row ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
									<td><span class="affilio-upgrade-mobile-label" aria-hidden="true"><?php esc_html_e( 'Free', 'dreamax-affiliates' ); ?></span><?php echo esc_html( $row[1] ); ?></td>
									<td><span class="affilio-upgrade-mobile-label" aria-hidden="true"><?php esc_html_e( 'Pro', 'dreamax-affiliates' ); ?></span><?php echo esc_html( $row[2] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="affilio-upgrade-foundation"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><span><?php esc_html_e( 'Always in Free: unlimited affiliate records, referral links, coupons, creatives, the affiliate dashboard, and core reports.', 'dreamax-affiliates' ); ?></span></p>
			</section>

			<section class="affilio-upgrade-next" aria-labelledby="affilio-upgrade-next-title">
				<div>
					<p class="affilio-upgrade-eyebrow"><?php esc_html_e( 'Your next step', 'dreamax-affiliates' ); ?></p>
					<h2 id="affilio-upgrade-next-title"><?php echo $pro_active ? esc_html__( 'Make the most of your Pro add-on', 'dreamax-affiliates' ) : esc_html__( 'Keep your program. Extend your toolkit.', 'dreamax-affiliates' ); ?></h2>
					<p>
						<?php if ( $pro_active && ( $license_url || $guide_url ) ) : ?>
							<?php esc_html_e( 'Review your license status, then use Getting Started to configure the workflows your program needs.', 'dreamax-affiliates' ); ?>
						<?php elseif ( $pro_active ) : ?>
							<?php esc_html_e( 'Pro was detected, but its management pages are not available for this account. Review plugin notices and access permissions before configuring Pro.', 'dreamax-affiliates' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Already have the Pro add-on ZIP? Install and activate it from Plugins while keeping Dreamax Affiliates Free active. Then open Pro License to review your license status.', 'dreamax-affiliates' ); ?>
						<?php endif; ?>
					</p>
					<div class="affilio-upgrade-actions">
						<?php if ( $guide_url ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $guide_url ); ?>"><span><?php esc_html_e( 'Open Getting Started', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
						<?php elseif ( $license_url ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $license_url ); ?>"><span><?php esc_html_e( 'Open Pro License', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
						<?php endif; ?>
						<a class="button affilio-upgrade-outline" href="<?php echo esc_url( admin_url( 'admin.php?page=affilio' ) ); ?>"><span><?php esc_html_e( 'Back to Overview', 'dreamax-affiliates' ); ?></span><span class="dashicons dashicons-dashboard" aria-hidden="true"></span></a>
					</div>
				</div>
				<div class="affilio-upgrade-reassurance">
					<span class="affilio-upgrade-icon is-success"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span></span>
					<h3><?php esc_html_e( 'Your existing work stays', 'dreamax-affiliates' ); ?></h3>
					<p><?php esc_html_e( 'Adding Pro keeps your Free affiliates and referrals in place. Free remains independently useful, with no need to rebuild your program.', 'dreamax-affiliates' ); ?></p>
				</div>
			</section>
		</div>
		<?php
	}
}
