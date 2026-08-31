<?php
/**
 * Email-template provider contract for optional add-ons.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface EmailTemplateProvider {
	public function identifier(): string;

	public function priority(): int;

	/**
	 * Returns a replacement template or null to leave the current template.
	 *
	 * @param string $key      Notification key.
	 * @param array  $template Current normalized template.
	 * @return array<string,mixed>|null
	 */
	public function template( string $key, array $template ): ?array;
}
