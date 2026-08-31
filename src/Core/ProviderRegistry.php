<?php
/**
 * Typed provider registry for separately distributed extensions.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores extension providers without loading any commercial implementation.
 */
final class ProviderRegistry {

	/**
	 * Required provider interface.
	 *
	 * @var class-string
	 */
	private string $interface;

	/**
	 * Providers keyed by stable identifier.
	 *
	 * @var array<string,object>
	 */
	private array $providers = array();

	/**
	 * @param class-string $interface Required provider interface.
	 */
	public function __construct( string $interface ) {
		$this->interface = $interface;
	}

	/**
	 * Registers or replaces one provider.
	 *
	 * @param mixed $provider Provider instance.
	 * @return bool
	 */
	public function register( $provider ): bool {
		if ( ! is_object( $provider ) || ! $provider instanceof $this->interface || ! method_exists( $provider, 'identifier' ) || ! method_exists( $provider, 'priority' ) ) {
			return false;
		}

		$identifier = sanitize_key( (string) $provider->identifier() );
		if ( '' === $identifier ) {
			return false;
		}

		$this->providers[ $identifier ] = $provider;
		return true;
	}

	/**
	 * Removes one provider.
	 *
	 * @param string $identifier Provider identifier.
	 * @return void
	 */
	public function unregister( string $identifier ): void {
		unset( $this->providers[ sanitize_key( $identifier ) ] );
	}

	/**
	 * Returns providers in deterministic priority order.
	 *
	 * Higher priority providers run first. Identifier is the stable tie-breaker.
	 *
	 * @return object[]
	 */
	public function all(): array {
		$providers = array_values( $this->providers );
		usort(
			$providers,
			static function ( object $left, object $right ): int {
				$priority = (int) $right->priority() <=> (int) $left->priority();
				return 0 !== $priority
					? $priority
					: strcmp( sanitize_key( (string) $left->identifier() ), sanitize_key( (string) $right->identifier() ) );
			}
		);

		return $providers;
	}

	/**
	 * Returns registered identifiers.
	 *
	 * @return string[]
	 */
	public function identifiers(): array {
		return array_map(
			static fn( object $provider ): string => sanitize_key( (string) $provider->identifier() ),
			$this->all()
		);
	}
}
