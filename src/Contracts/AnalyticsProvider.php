<?php
/**
 * Analytics provider contract for optional add-ons.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface AnalyticsProvider {
	public function identifier(): string;

	public function priority(): int;

	/**
	 * Adds provider-owned datasets without changing the required Free summary, visit, or referral keys.
	 *
	 * @param array $data         Basic Free report payload.
	 * @param int   $affiliate_id Affiliate ID, or zero for the program.
	 * @param array $filters      Sanitized filters.
	 * @return array
	 */
	public function augment( array $data, int $affiliate_id, array $filters ): array;
}
