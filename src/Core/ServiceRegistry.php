<?php
/**
 * Lightweight service registry used as the incremental replacement for
 * direct singleton property access.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use InvalidArgumentException;

/**
 * Stores already-created services without constructing hidden dependencies.
 */
final class ServiceRegistry {

	/**
	 * Registered services.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * Registers or replaces a service.
	 *
	 * @param string $identifier Stable service identifier.
	 * @param object $service    Service instance.
	 * @return void
	 */
	public function set( string $identifier, object $service ): void {
		$identifier = trim( $identifier );

		if ( '' === $identifier ) {
			throw new InvalidArgumentException( 'A service identifier cannot be empty.' );
		}

		$this->services[ $identifier ] = $service;
	}

	/**
	 * Determines whether a service exists.
	 *
	 * @param string $identifier Service identifier.
	 * @return bool
	 */
	public function has( string $identifier ): bool {
		return isset( $this->services[ $identifier ] );
	}

	/**
	 * Returns a service or null when it has not been registered.
	 *
	 * @param string $identifier Service identifier.
	 * @return object|null
	 */
	public function get( string $identifier ): ?object {
		return $this->services[ $identifier ] ?? null;
	}

	/**
	 * Returns the registered service identifiers.
	 *
	 * @return string[]
	 */
	public function identifiers(): array {
		return array_keys( $this->services );
	}
}
