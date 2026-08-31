<?php
/**
 * Plugin Name:       Dreamax Affiliates
 * Description:       Self-hosted WooCommerce affiliate tracking, coupons, reports, commissions, creatives, and manual payouts.
 * Version:           2.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * WC requires at least: 8.2
 * WC tested up to:      10.9
 * Author:            Dreamax Soft
 * Author URI:        https://dreamaxsoft.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dreamax-affiliates
 * Domain Path:       /languages
 *
 * @package Dreamax_Affiliates
 */

// Exit if accessed directly - never allow this file to be loaded outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * -----------------------------------------------------------------------
 * AFFILIO BOOTSTRAP
 * -----------------------------------------------------------------------
 * This file defines constants, declares WooCommerce feature compatibility,
 * and hands off to the main singleton bootstrap.
 * -----------------------------------------------------------------------
 */

// -----------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------

if ( ! defined( 'AFFILIO_VERSION' ) ) {
	define( 'AFFILIO_VERSION', '2.1.2' );
}

// Bump this only when a change to a create_table() method requires it.
// 1.1: added the visits.token column (attribution security fix).
// 1.2: added a UNIQUE constraint on referrals.order_id.
// 1.3: added affiliate payout profile fields, payout linkage on referrals,
// and the payouts table used by the manual batch workflow.
// 1.4: added privacy-conscious unique-visitor hashes and campaign labels
// for the reporting and referral-link generator features.
// Steps 4 and 5 add no database columns.
// 1.5: added referral source, coupon code, and campaign columns.
// 1.6: added the affiliate registration-date index for paginated admin queries.
// 1.6.1: added affiliate application/status metadata and audit events.
// 1.7: added manual-referral fields, commission eligibility, and payout requests.
// 1.8: added composite indexes for report, queue, payout, and multisite-scale queries.
if ( ! defined( 'AFFILIO_DB_VERSION' ) ) {
	define( 'AFFILIO_DB_VERSION', '1.8' );
}

// Versioned public compatibility contract for separately distributed add-ons.
if ( ! defined( 'AFFILIO_CORE_API_VERSION' ) ) {
	define( 'AFFILIO_CORE_API_VERSION', '1.3.0' );
}

if ( ! defined( 'AFFILIO_PLUGIN_FILE' ) ) {
	define( 'AFFILIO_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'AFFILIO_PLUGIN_DIR' ) ) {
	define( 'AFFILIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'AFFILIO_PLUGIN_URL' ) ) {
	define( 'AFFILIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'AFFILIO_PLUGIN_BASENAME' ) ) {
	define( 'AFFILIO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

// Default referral-cookie lifetime in days.
if ( ! defined( 'AFFILIO_DEFAULT_COOKIE_DAYS' ) ) {
	define( 'AFFILIO_DEFAULT_COOKIE_DAYS', 30 );
}

if ( ! defined( 'AFFILIO_MINIMUM_WORDPRESS_VERSION' ) ) {
	define( 'AFFILIO_MINIMUM_WORDPRESS_VERSION', '6.4' );
}

if ( ! defined( 'AFFILIO_MINIMUM_PHP_VERSION' ) ) {
	define( 'AFFILIO_MINIMUM_PHP_VERSION', '8.0' );
}

/**
 * Returns whether the current admin screen is directly relevant to
 * Dreamax Affiliates notices.
 *
 * Routine plugin notices stay on Dreamax Affiliates screens and the Plugins
 * screen instead of appearing across unrelated areas of wp-admin.
 *
 * @return bool
 */
function affilio_is_plugin_admin_notice_screen() {
	if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
		return false;
	}

	$screen = get_current_screen();
	if ( ! $screen || empty( $screen->id ) ) {
		return false;
	}

	$screen_id = (string) $screen->id;

	return in_array( $screen_id, array( 'plugins', 'plugins-network' ), true )
		|| false !== strpos( $screen_id, 'affilio' );
}

/**
 * Shows an actionable compatibility notice when the server cannot safely
 * load the plugin. The main file intentionally avoids PHP 8-only syntax so
 * this notice can still be registered on older PHP versions.
 *
 * @return void
 */
function affilio_environment_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || ! affilio_is_plugin_admin_notice_screen() ) {
		return;
	}

	$message = sprintf(
		/* translators: 1: minimum WordPress version, 2: minimum PHP version. */
		__( 'Dreamax Affiliates requires WordPress %1$s or later and PHP %2$s or later. The plugin has not been loaded.', 'dreamax-affiliates' ),
		AFFILIO_MINIMUM_WORDPRESS_VERSION,
		AFFILIO_MINIMUM_PHP_VERSION
	);

	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

$affilio_wordpress_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0';
if ( version_compare( PHP_VERSION, AFFILIO_MINIMUM_PHP_VERSION, '<' ) || version_compare( $affilio_wordpress_version, AFFILIO_MINIMUM_WORDPRESS_VERSION, '<' ) ) {
	add_action( 'admin_notices', 'affilio_environment_notice' );
	return;
}

// -----------------------------------------------------------------------
// WooCommerce feature compatibility
// -----------------------------------------------------------------------
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				AFFILIO_PLUGIN_FILE,
				true
			);

			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				AFFILIO_PLUGIN_FILE,
				true
			);
		}
	}
);

// -----------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------

require_once AFFILIO_PLUGIN_DIR . 'src/Bootstrap/Autoloader.php';

( static function () {
	$autoloader = new \Affilio\Bootstrap\Autoloader( AFFILIO_PLUGIN_DIR . 'src' );
	$autoloader->register();
} )();

require_once AFFILIO_PLUGIN_DIR . 'includes/class-affilio.php';

register_activation_hook( AFFILIO_PLUGIN_FILE, array( 'Affilio', 'activate' ) );
register_deactivation_hook( AFFILIO_PLUGIN_FILE, array( 'Affilio', 'deactivate' ) );

/**
 * Returns the single Dreamax Affiliates instance.
 *
 * Use this the same way you'd use WC() or EDD() from other plugins —
 * e.g. `affilio()->affiliates_db->get_by_user_id( $user_id )` — instead
 * of reaching for a global.
 *
 * @return Affilio
 */
function affilio() {
	return Affilio::instance();
}

/**
 * Returns the public Dreamax Affiliates Core API version used by Free/Pro compatibility
 * checks. This value is independent from the plugin release version.
 *
 * @return string
 */
function affilio_core_api_version() {
	return AFFILIO_CORE_API_VERSION;
}

/**
 * Determines whether the installed Core API supports a requested version.
 *
 * @param string $required_version Required Core API version.
 * @return bool
 */
function affilio_supports_core_api( $required_version ) {
	$compatibility = new \Affilio\Core\Compatibility( AFFILIO_VERSION );
	return $compatibility->supports_api( (string) $required_version );
}

/**
 * Returns the developer-facing Free/Pro feature productization catalog.
 *
 * The catalog defines the fixed Free/Shared runtime boundary. It is not a
 * license or remote-entitlement system.
 *
 * @return array<string,array<string,string>>
 */
function affilio_feature_catalog() {
	$catalog = affilio()->service( 'core.feature_catalog' );

	return $catalog instanceof \Affilio\Core\FeatureCatalog ? $catalog->all() : array();
}

/**
 * Returns one feature-plan entry or null when the identifier is unknown.
 *
 * @param string $identifier Stable feature identifier.
 * @return array<string,string>|null
 */
function affilio_feature_plan( $identifier ) {
	$catalog = affilio()->service( 'core.feature_catalog' );

	return $catalog instanceof \Affilio\Core\FeatureCatalog
		? $catalog->get( (string) $identifier )
		: null;
}


/**
 * Whether one audited feature belongs to the Free/Shared runtime.
 *
 * This is a fixed product boundary, not a license or remote entitlement check.
 * Separately distributed add-ons must register their own implementation.
 *
 * @param string $identifier Stable feature identifier.
 * @return bool
 */
function affilio_feature_available( $identifier ) {
	$gate = affilio()->service( 'core.feature_gate' );
	return $gate instanceof \Affilio\Core\FeatureGate
		? $gate->is_available( (string) $identifier )
		: false;
}

/**
 * Returns a privacy-safe inventory of preserved Free/Pro transition data.
 *
 * Values are never returned; the payload exposes only option presence and the
 * known metadata-key names retained for a separately distributed add-on.
 *
 * @return array<string,mixed>
 */
function affilio_free_tier_data_status() {
	$preserver = affilio()->service( 'core.free_tier_data_preserver' );

	return $preserver instanceof \Affilio\Infrastructure\WordPress\FreeTierDataPreserver
		? $preserver::status()
		: array();
}

/**
 * Registers a separately distributed line-level commission provider.
 *
 * @param \Affilio\Contracts\CommissionRuleProvider $provider Provider instance.
 * @return bool
 */
function affilio_register_commission_rule_provider( $provider ) {
	$registry = affilio()->service( 'core.commission_rule_providers' );
	return $registry instanceof \Affilio\Core\ProviderRegistry
		? $registry->register( $provider )
		: false;
}

/**
 * Registers a separately distributed visit-fraud policy provider.
 *
 * @param \Affilio\Contracts\FraudPolicyProvider $provider Provider instance.
 * @return bool
 */
function affilio_register_fraud_policy_provider( $provider ) {
	$registry = affilio()->service( 'core.fraud_policy_providers' );
	return $registry instanceof \Affilio\Core\ProviderRegistry
		? $registry->register( $provider )
		: false;
}

/**
 * Registers a separately distributed analytics provider.
 *
 * @param \Affilio\Contracts\AnalyticsProvider $provider Provider instance.
 * @return bool
 */
function affilio_register_analytics_provider( $provider ) {
	$registry = affilio()->service( 'core.analytics_providers' );
	return $registry instanceof \Affilio\Core\ProviderRegistry
		? $registry->register( $provider )
		: false;
}

/**
 * Registers a separately distributed email-template provider.
 *
 * @param \Affilio\Contracts\EmailTemplateProvider $provider Provider instance.
 * @return bool
 */
function affilio_register_email_template_provider( $provider ) {
	$registry = affilio()->service( 'core.email_template_providers' );
	return $registry instanceof \Affilio\Core\ProviderRegistry
		? $registry->register( $provider )
		: false;
}

// Boot the plugin once all other plugins have loaded, so is_woocommerce_active()
// and any future integration checks see an accurate, fully-loaded plugin list.
add_action( 'plugins_loaded', 'affilio' );
