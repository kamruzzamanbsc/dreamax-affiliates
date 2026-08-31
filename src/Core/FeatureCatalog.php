<?php
/**
 * Machine-readable Free/Pro productization plan.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the target ownership and separation state of Dreamax Affiliates features.
 *
 * The catalog records the completed Free/Shared boundary and the separately
 * distributed provider ownership established during FT-2. Planning states are
 * retained for backward compatibility with earlier catalog consumers.
 */
final class FeatureCatalog {

	public const TIER_FREE     = 'free';
	public const TIER_PRO      = 'pro';
	public const TIER_SHARED   = 'shared';
	public const TIER_DEFERRED = 'deferred';

	public const STATE_KEEP           = 'keep';
	public const STATE_SPLIT_REQUIRED = 'split_required';
	public const STATE_EXTRACT        = 'extract';
	public const STATE_ADD            = 'add';
	public const STATE_DEFERRED       = 'deferred';
	public const STATE_EXTERNAL       = 'external';

	/**
	 * Returns the audited feature definitions.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function all(): array {
		$core_features = $this->definitions();
		$features      = $core_features;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the developer-facing feature catalog.
			 *
			 * Extensions may append their own feature metadata. Core definitions are
			 * restored after filtering so an extension cannot silently reclassify the
			 * product boundary or turn planning metadata into entitlement logic.
			 *
			 * @param array<string,array<string,string>> $features Feature definitions.
			 */
			$filtered = apply_filters( 'affilio_feature_catalog', $features );
			if ( is_array( $filtered ) ) {
				foreach ( $filtered as $identifier => $definition ) {
					if ( isset( $core_features[ $identifier ] ) || ! is_array( $definition ) ) {
						continue;
					}

					$features[ (string) $identifier ] = $definition;
				}
			}
		}

		ksort( $features );
		return $features;
	}

	/**
	 * Returns one feature definition.
	 *
	 * @param string $identifier Stable feature identifier.
	 * @return array<string,string>|null
	 */
	public function get( string $identifier ): ?array {
		$features = $this->all();
		return $features[ $identifier ] ?? null;
	}

	/**
	 * Returns features assigned to one target tier.
	 *
	 * @param string $tier Target tier.
	 * @return array<string,array<string,string>>
	 */
	public function by_tier( string $tier ): array {
		return array_filter(
			$this->all(),
			static function ( array $feature ) use ( $tier ): bool {
				return ( $feature['target_tier'] ?? '' ) === $tier;
			}
		);
	}

	/**
	 * Validates catalog schema and known enum values.
	 *
	 * @return string[] Validation errors.
	 */
	public function validate(): array {
		$errors       = array();
		$valid_tiers  = array( self::TIER_FREE, self::TIER_PRO, self::TIER_SHARED, self::TIER_DEFERRED );
		$valid_states = array( self::STATE_KEEP, self::STATE_SPLIT_REQUIRED, self::STATE_EXTRACT, self::STATE_ADD, self::STATE_DEFERRED, self::STATE_EXTERNAL );
		$required     = array( 'label', 'category', 'target_tier', 'separation', 'owner', 'data_policy' );

		foreach ( $this->all() as $identifier => $feature ) {
			if ( ! preg_match( '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $identifier ) ) {
				$errors[] = 'invalid_identifier:' . $identifier;
			}

			foreach ( $required as $field ) {
				if ( ! isset( $feature[ $field ] ) || '' === trim( (string) $feature[ $field ] ) ) {
					$errors[] = 'missing_' . $field . ':' . $identifier;
				}
			}

			if ( ! in_array( $feature['target_tier'] ?? '', $valid_tiers, true ) ) {
				$errors[] = 'invalid_tier:' . $identifier;
			}

			if ( ! in_array( $feature['separation'] ?? '', $valid_states, true ) ) {
				$errors[] = 'invalid_separation:' . $identifier;
			}
		}

		return $errors;
	}

	/**
	 * Builds the source-of-truth productization inventory.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function definitions(): array {
		$preserve = 'Preserve existing records and settings across Free/Pro changes.';
		$core     = 'Shared quality and data-integrity behavior; never license-gated.';

		return array(
			'affiliate.approval'              => $this->feature( 'Approval, rejection, and ban workflow', 'affiliate', self::TIER_FREE, self::STATE_KEEP, 'admin.workflows', $preserve ),
			'affiliate.profile'               => $this->feature( 'Affiliate profile and payout details', 'affiliate', self::TIER_FREE, self::STATE_KEEP, 'frontend.dashboard', $preserve ),
			'affiliate.registration'          => $this->feature( 'Registration and basic configurable fields', 'affiliate', self::TIER_FREE, self::STATE_KEEP, 'frontend.registration', $preserve ),
			'affiliate.unlimited'             => $this->feature( 'Unlimited affiliate records', 'affiliate', self::TIER_FREE, self::STATE_KEEP, 'database.affiliates', $preserve ),

			'commission.affiliate_override'   => $this->feature( 'Per-affiliate rate override', 'commission', self::TIER_FREE, self::STATE_KEEP, 'application.commission', $preserve ),
			'commission.category_rules'       => $this->feature( 'Category-specific commission rules', 'commission', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.commission_provider', $preserve ),
			'commission.flat_order'           => $this->feature( 'Flat commission per order', 'commission', self::TIER_FREE, self::STATE_KEEP, 'application.commission', $preserve ),
			'commission.flat_per_item'        => $this->feature( 'Flat commission per item', 'commission', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.commission_provider', $preserve ),
			'commission.global_percentage'    => $this->feature( 'Global percentage commission', 'commission', self::TIER_FREE, self::STATE_KEEP, 'application.commission', $preserve ),
			'commission.holding_automation'   => $this->feature( 'Holding-period and scheduled release automation', 'commission', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.commission_automation', 'Holding automation remains external; Free validates initial states and can explicitly accept date-preserving release handoff for existing held rows.' ),
			'commission.manual_referral'      => $this->feature( 'Manual referral and adjustment workflow', 'commission', self::TIER_FREE, self::STATE_KEEP, 'admin.manual_referrals', $preserve ),
			'commission.product_rules'        => $this->feature( 'Product-specific commission rules', 'commission', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.commission_provider', $preserve ),
			'commission.recurring_lifetime'   => $this->feature( 'Recurring and lifetime commissions', 'commission', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),
			'commission.refund_sync'          => $this->feature( 'Full and partial refund synchronization', 'commission', self::TIER_SHARED, self::STATE_KEEP, 'integration.woocommerce', $core ),
			'commission.tiered_mlm'           => $this->feature( 'Tiered or multi-level commissions', 'commission', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists; legal and support scope requires separate approval.' ),
			'commission.variation_rules'      => $this->feature( 'Variation-specific commission rules', 'commission', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.commission_provider', $preserve ),

			'core.accessibility'              => $this->feature( 'Keyboard and screen-reader support', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'support.assets', $core ),
			'core.checkout_blocks'            => $this->feature( 'WooCommerce Cart and Checkout Blocks compatibility', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'integration.woocommerce', $core ),
			'core.concurrency'                => $this->feature( 'Idempotency, unique constraints, and atomic locks', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'core', $core ),
			'core.data_preservation'          => $this->feature( 'Non-destructive Free/Pro transition data preservation', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'core.free_tier_data_preserver', $core ),
			'core.extension_contract'         => $this->feature( 'Versioned Free/Pro extension contract', 'architecture', self::TIER_SHARED, self::STATE_KEEP, 'core.compatibility', $core ),
			'core.hpos'                       => $this->feature( 'WooCommerce HPOS compatibility', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'integration.woocommerce', $core ),
			'core.i18n_rtl'                   => $this->feature( 'Translation and RTL support', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'support.i18n', $core ),
			'core.lifecycle'                  => $this->feature( 'Safe activation, upgrade, deactivation, and uninstall', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'core.upgrade_coordinator', $core ),
			'core.multisite_compatibility'    => $this->feature( 'Safe per-site and network lifecycle compatibility', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'core.multisite_manager', $core ),
			'core.performance'                => $this->feature( 'Bounded queries, caching, indexes, and batching', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'core.performance_cache', $core ),
			'core.privacy'                    => $this->feature( 'Privacy export, erase, retention, and uninstall controls', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'support.privacy', $core ),
			'core.security'                   => $this->feature( 'Authorization, ownership, input, output, and SQL security', 'quality', self::TIER_SHARED, self::STATE_KEEP, 'core', $core ),

			'coupon.basic_attribution'        => $this->feature( 'Basic assigned-coupon attribution', 'coupon', self::TIER_FREE, self::STATE_KEEP, 'application.coupons', $preserve ),
			'coupon.dynamic_generation'       => $this->feature( 'Dynamic coupon generation and templates', 'coupon', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),

			'creatives.basic'                 => $this->feature( 'Basic image and text creatives', 'promotion', self::TIER_FREE, self::STATE_KEEP, 'application.creatives', $preserve ),
			'creatives.scheduling'            => $this->feature( 'Creative categories, targeting, and scheduling', 'promotion', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),

			'dashboard.basic'                 => $this->feature( 'Affiliate summary, referrals, and payout history', 'dashboard', self::TIER_FREE, self::STATE_KEEP, 'frontend.dashboard', $preserve ),
			'dashboard.links_qr_social'       => $this->feature( 'Link generator, QR, and user-triggered sharing', 'dashboard', self::TIER_FREE, self::STATE_KEEP, 'frontend.dashboard', $preserve ),
			'dashboard.white_label'           => $this->feature( 'White-label portal and custom tabs', 'dashboard', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),

			'email.custom_templates'          => $this->feature( 'Editable email subjects and bodies', 'email', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.email_template_provider', $preserve ),
			'email.essential_notifications'   => $this->feature( 'Essential application, referral, and payout emails', 'email', self::TIER_FREE, self::STATE_KEEP, 'support.emails', $preserve ),
			'email.scheduled_summaries'       => $this->feature( 'Scheduled summary emails', 'email', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),

			'fraud.blocked_domains'           => $this->feature( 'Blocked referral-domain rules', 'fraud', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.fraud_policy_provider', $preserve ),
			'fraud.self_referral'             => $this->feature( 'Basic self-referral prevention', 'fraud', self::TIER_FREE, self::STATE_KEEP, 'frontend.tracking', $preserve ),
			'fraud.velocity'                  => $this->feature( 'Click-velocity thresholds', 'fraud', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.fraud_policy_provider', $preserve ),

			'integration.third_party'         => $this->feature( 'Additional commerce, forms, LMS, and CRM integrations', 'integration', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),
			'integration.webhooks_api'        => $this->feature( 'REST API and outbound webhooks', 'integration', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),

			'migration.affiliate_import'      => $this->feature( 'Bulk affiliate import and update', 'migration', self::TIER_PRO, self::STATE_EXTERNAL, 'future.pro.import_service', $preserve ),
			'migration.historical_referrals'  => $this->feature( 'Historical referral import', 'migration', self::TIER_PRO, self::STATE_EXTERNAL, 'future.pro.import_service', $preserve ),

			'multisite.network_management'    => $this->feature( 'Network-wide program management and reporting', 'multisite', self::TIER_PRO, self::STATE_ADD, 'future.pro', 'Keep Core network lifecycle compatibility; add commercial network UI separately.' ),

			'onboarding.setup_wizard'         => $this->feature( 'Guided setup and automatic pages', 'onboarding', self::TIER_FREE, self::STATE_KEEP, 'admin.onboarding', $preserve ),

			'payout.automatic_gateways'       => $this->feature( 'Automatic payout-provider integrations', 'payout', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current payment-provider data exists.' ),
			'payout.manual_batches'           => $this->feature( 'Manual payout batches and status history', 'payout', self::TIER_FREE, self::STATE_KEEP, 'application.payouts', $preserve ),
			'payout.profile'                  => $this->feature( 'Affiliate payout destination profile', 'payout', self::TIER_FREE, self::STATE_KEEP, 'application.payouts', $preserve ),
			'payout.requests'                 => $this->feature( 'Affiliate payout requests and minimum threshold', 'payout', self::TIER_FREE, self::STATE_KEEP, 'application.payout_requests', $preserve ),
			'payout.scheduled_batches'        => $this->feature( 'Scheduled payout batch creation', 'payout', self::TIER_PRO, self::STATE_ADD, 'future.pro', 'Existing manual batches remain Free and preserved.' ),

			'reports.advanced_analytics'      => $this->feature( 'Period comparison and visual analytics', 'reporting', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.analytics_provider', $preserve ),
			'reports.basic_csv'               => $this->feature( 'Bounded basic CSV exports', 'reporting', self::TIER_FREE, self::STATE_KEEP, 'application.reports', $preserve ),
			'reports.basic_summary'           => $this->feature( 'Basic clicks, referrals, conversions, and earnings', 'reporting', self::TIER_FREE, self::STATE_KEEP, 'application.report_summary', $preserve ),
			'reports.campaign_coupon_product' => $this->feature( 'Campaign, coupon, product, and landing-page breakdowns', 'reporting', self::TIER_PRO, self::STATE_EXTERNAL, 'extension.analytics_provider', $preserve ),
			'reports.scheduled'               => $this->feature( 'Scheduled report delivery', 'reporting', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),

			'tools.system_status'             => $this->feature( 'Privacy-safe system status diagnostics', 'tools', self::TIER_FREE, self::STATE_KEEP, 'admin.diagnostics', 'Local checks only; do not transmit site data or expose secrets.' ),
			'tools.test_attribution'          => $this->feature( 'Admin test-attribution workflow', 'tools', self::TIER_FREE, self::STATE_KEEP, 'admin.diagnostics', 'Diagnostic visits are clearly marked, user-scoped, temporary, and removable.' ),
			'tools.upgrade_path'              => $this->feature( 'Restrained opt-in Pro information page', 'tools', self::TIER_FREE, self::STATE_KEEP, 'admin.upgrade_page', 'No remote telemetry, forced notices, feature locks, or bundled Pro implementation.' ),

			'tracking.cross_domain'           => $this->feature( 'Cross-domain attribution', 'tracking', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),
			'tracking.direct_links'           => $this->feature( 'Direct-link attribution without referral parameters', 'tracking', self::TIER_DEFERRED, self::STATE_DEFERRED, 'future.pro', 'No current data exists.' ),
			'tracking.first_last_click'       => $this->feature( 'First-click and last-click attribution', 'tracking', self::TIER_FREE, self::STATE_KEEP, 'frontend.tracking', $preserve ),
			'tracking.referral_links'         => $this->feature( 'Opaque referral links and configurable cookies', 'tracking', self::TIER_FREE, self::STATE_KEEP, 'frontend.tracking', $preserve ),
			'tracking.visit_logging'          => $this->feature( 'Bounded visit and campaign logging', 'tracking', self::TIER_FREE, self::STATE_KEEP, 'database.visits', $preserve ),
		);
	}

	/**
	 * Builds one normalized feature definition.
	 *
	 * @param string $label       Human-readable developer label.
	 * @param string $category    Feature category.
	 * @param string $target_tier Target product tier.
	 * @param string $separation  Planned separation action.
	 * @param string $owner       Current or future owner service.
	 * @param string $data_policy Data-preservation policy.
	 * @return array<string,string>
	 */
	private function feature( string $label, string $category, string $target_tier, string $separation, string $owner, string $data_policy ): array {
		return array(
			'label'       => $label,
			'category'    => $category,
			'target_tier' => $target_tier,
			'separation'  => $separation,
			'owner'       => $owner,
			'data_policy' => $data_policy,
		);
	}
}
