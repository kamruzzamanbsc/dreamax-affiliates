<?php
/**
 * Basic Free-tier reporting summary and analytics extension bridge.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the required Free report totals without shipping advanced analytics.
 *
 * Separately distributed add-ons may register AnalyticsProvider instances to
 * append provider-owned datasets. Core summary keys are restored after every
 * provider call so an extension cannot replace the Free reporting contract.
 */
class Affilio_Report_Summary {

	/** Cache lifetime for combined basic report totals. */
	const CACHE_TTL = 300;

	/**
	 * Returns basic clicks, conversions, referrals, and earnings data.
	 *
	 * @param int   $affiliate_id Affiliate ID, or zero for the whole program.
	 * @param array $filters      Sanitized report filters.
	 * @return array{summary:array,visits:array,referrals:array,extensions:array}
	 */
	public function get_data( $affiliate_id = 0, array $filters = array() ) {
		$affiliate_id = absint( $affiliate_id );
		$filters      = $this->normalize_filters( $filters, $affiliate_id );
		$registry     = affilio()->service( 'core.analytics_providers' );
		$providers    = $registry instanceof \Affilio\Core\ProviderRegistry ? $registry->identifiers() : array();
		$resolver     = function () use ( $affiliate_id, $filters ) {
			return $this->build_data( $affiliate_id, $filters );
		};

		return isset( affilio()->performance_cache )
			? affilio()->performance_cache->remember(
				'basic-report-summary-v2',
				array(
					'affiliate_id' => $affiliate_id,
					'filters'      => $filters,
					'providers'    => $providers,
				),
				$resolver,
				self::CACHE_TTL
			)
			: $resolver();
	}

	/**
	 * Builds an uncached summary and lets external providers append datasets.
	 *
	 * @param int   $affiliate_id Affiliate ID, or zero for the whole program.
	 * @param array $filters      Sanitized report filters.
	 * @return array
	 */
	private function build_data( $affiliate_id, array $filters ) {
		$visits    = affilio()->visits_db->get_report_summary( $filters );
		$referrals = affilio()->referrals_db->get_report_summary( $filters );
		$core      = array(
			'summary'    => array(
				'clicks'            => (int) ( $visits['clicks'] ?? 0 ),
				'unique_visitors'   => (int) ( $visits['unique_visitors'] ?? 0 ),
				'conversions'       => (int) ( $visits['conversions'] ?? 0 ),
				'conversion_rate'   => (float) ( $visits['conversion_rate'] ?? 0 ),
				'referrals'         => (int) ( $referrals['count'] ?? 0 ),
				'commission_totals' => $this->normalize_totals( $referrals['commission_totals'] ?? array() ),
				'paid_totals'       => $this->normalize_totals( $referrals['paid_totals'] ?? array() ),
				'open_totals'       => $this->normalize_totals( $referrals['open_totals'] ?? array() ),
			),
			'visits'     => $visits,
			'referrals'  => $referrals,
			'extensions' => array(),
		);
		$data      = $core;
		$registry  = affilio()->service( 'core.analytics_providers' );
		$providers = $registry instanceof \Affilio\Core\ProviderRegistry ? $registry->all() : array();

		foreach ( $providers as $provider ) {
			try {
				$candidate = $provider->augment( $data, (int) $affiliate_id, $filters );
			} catch ( Throwable $error ) {
				/**
				 * Fires when an optional analytics provider cannot build its dataset.
				 *
				 * The error is not displayed to visitors and the Free summary remains
				 * available. Add-ons may use this hook for privacy-safe diagnostics.
				 *
				 * @param Throwable $error    Provider error.
				 * @param object    $provider Provider instance.
				 */
				do_action( 'affilio_analytics_provider_error', $error, $provider );
				continue;
			}

			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$data = $candidate;
			// Required Free keys are immutable across provider augmentation.
			$data['summary']   = $core['summary'];
			$data['visits']    = $core['visits'];
			$data['referrals'] = $core['referrals'];
			if ( ! isset( $data['extensions'] ) || ! is_array( $data['extensions'] ) ) {
				$data['extensions'] = array();
			}
		}

		return $data;
	}

	/**
	 * Applies the requested affiliate scope without accepting arbitrary values.
	 *
	 * @param array $filters      Report filters.
	 * @param int   $affiliate_id Explicit affiliate scope.
	 * @return array
	 */
	private function normalize_filters( array $filters, $affiliate_id ) {
		if ( $affiliate_id > 0 ) {
			$filters['affiliate_id'] = $affiliate_id;
		} elseif ( isset( $filters['affiliate_id'] ) ) {
			$filters['affiliate_id'] = absint( $filters['affiliate_id'] );
		}

		return $filters;
	}

	/**
	 * Normalizes currency totals for a stable public payload.
	 *
	 * @param mixed $totals Raw totals.
	 * @return array<string,float>
	 */
	private function normalize_totals( $totals ) {
		if ( ! is_array( $totals ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $totals as $currency => $amount ) {
			$currency = strtoupper( substr( sanitize_text_field( (string) $currency ), 0, 10 ) );
			if ( '' === $currency ) {
				continue;
			}
			$normalized[ $currency ] = (float) $amount;
		}
		ksort( $normalized );
		return $normalized;
	}
}
