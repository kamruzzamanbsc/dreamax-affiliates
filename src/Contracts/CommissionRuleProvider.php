<?php
/**
 * Commission-rule provider contract for separately distributed add-ons.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface CommissionRuleProvider {
	public function identifier(): string;

	public function priority(): int;

	/**
	 * Resolves an optional line-level commission rule.
	 *
	 * Return null when the provider has no decision. A decision may use:
	 * `inherit`, `exclude`, `percentage`, or `flat_item`.
	 *
	 * @param array  $line      Normalized order-line context.
	 * @param object $order     WooCommerce order-like object.
	 * @param object $affiliate Affiliate row.
	 * @return array<string,mixed>|null
	 */
	public function resolve_line_rule( array $line, object $order, object $affiliate ): ?array;
}
