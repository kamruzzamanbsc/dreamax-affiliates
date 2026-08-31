<?php
/**
 * Optional feature metadata contract for separately distributed extensions.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allows an extension to publish developer-facing feature metadata.
 */
interface FeatureProvider {

	/**
	 * Returns extension-owned feature definitions keyed by stable identifier.
	 *
	 * Core feature tier assignments must not be overwritten.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function features(): array;
}
