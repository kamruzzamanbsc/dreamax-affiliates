<?php
/**
 * Contract for separately distributed Dreamax Affiliates extensions such as Pro.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Affilio\Core\ServiceRegistry;

/**
 * Describes the minimum compatibility information an extension must expose.
 */
interface Extension {

	/**
	 * Returns the minimum Dreamax Affiliates Free plugin version required by the extension.
	 *
	 * @return string
	 */
	public function required_core_version(): string;

	/**
	 * Returns the minimum compatible Dreamax Affiliates Core API version.
	 *
	 * @return string
	 */
	public function required_api_version(): string;

	/**
	 * Boots the extension after compatibility has been verified.
	 *
	 * @param ServiceRegistry $services Core service registry.
	 * @return void
	 */
	public function boot( ServiceRegistry $services ): void;
}
