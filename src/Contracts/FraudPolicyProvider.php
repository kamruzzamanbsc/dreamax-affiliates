<?php
/**
 * Fraud-policy provider contract for separately distributed add-ons.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface FraudPolicyProvider {
	public function identifier(): string;

	public function priority(): int;

	/**
	 * Returns a short reason code when the visit should be rejected.
	 *
	 * @param array $context Privacy-conscious visit context.
	 * @return string|null
	 */
	public function reject_visit( array $context ): ?string;
}
