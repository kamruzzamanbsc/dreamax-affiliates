<?php
/**
 * Free/Pro and third-party extension compatibility contract.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Affilio\Contracts\Extension;

/**
 * Evaluates extension requirements without loading premium implementation.
 */
final class Compatibility {

	/**
	 * Stable Core API version exposed by Dreamax Affiliates Free.
	 */
	public const API_VERSION = '1.3.0';

	/**
	 * Current Free plugin version.
	 *
	 * @var string
	 */
	private string $core_version;

	/**
	 * Creates the compatibility service.
	 *
	 * @param string $core_version Current Free plugin version.
	 */
	public function __construct( string $core_version ) {
		$this->core_version = $core_version;
	}

	/**
	 * Returns the current Core API version.
	 *
	 * @return string
	 */
	public function api_version(): string {
		return self::API_VERSION;
	}

	/**
	 * Returns the current Free plugin version.
	 *
	 * @return string
	 */
	public function core_version(): string {
		return $this->core_version;
	}

	/**
	 * Checks a requested API version.
	 *
	 * Compatibility is intentionally limited to the same major API version.
	 * The installed API must also be greater than or equal to the requested
	 * version.
	 *
	 * @param string $required_version Required semantic version.
	 * @return bool
	 */
	public function supports_api( string $required_version ): bool {
		$required_major = $this->major_version( $required_version );
		$current_major  = $this->major_version( self::API_VERSION );

		return null !== $required_major
			&& $required_major === $current_major
			&& version_compare( self::API_VERSION, $required_version, '>=' );
	}

	/**
	 * Validates a separately distributed extension before its boot method runs.
	 *
	 * @param Extension $extension Extension instance.
	 * @return array{compatible:bool,errors:string[]}
	 */
	public function validate_extension( Extension $extension ): array {
		$errors = array();

		if ( version_compare( $this->core_version, $extension->required_core_version(), '<' ) ) {
			$errors[] = 'core_version';
		}

		if ( ! $this->supports_api( $extension->required_api_version() ) ) {
			$errors[] = 'api_version';
		}

		return array(
			'compatible' => empty( $errors ),
			'errors'     => $errors,
		);
	}

	/**
	 * Extracts a numeric major version.
	 *
	 * @param string $version Semantic version.
	 * @return int|null
	 */
	private function major_version( string $version ): ?int {
		if ( ! preg_match( '/^(\d+)(?:\.\d+){0,2}(?:[-+].*)?$/', $version, $matches ) ) {
			return null;
		}

		return (int) $matches[1];
	}
}
