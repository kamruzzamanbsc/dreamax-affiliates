<?php
/**
 * Minimal PSR-4-compatible autoloader for the Dreamax Affiliates namespace.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads namespaced Dreamax Affiliates classes from the plugin's src directory.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this loader.
	 */
	private const PREFIX = 'Affilio\\';

	/**
	 * Absolute base directory for namespaced classes.
	 *
	 * @var string
	 */
	private string $base_directory;

	/**
	 * Whether the loader has already been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Creates the loader.
	 *
	 * @param string $base_directory Absolute path to the src directory.
	 */
	public function __construct( string $base_directory ) {
		$this->base_directory = rtrim( $base_directory, '/\\' ) . DIRECTORY_SEPARATOR;
	}

	/**
	 * Registers the loader once.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		spl_autoload_register( array( $this, 'load' ) );
		$this->registered = true;
	}

	/**
	 * Loads a class when it belongs to the Dreamax Affiliates namespace.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( self::PREFIX ) );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';
		$file           = $this->base_directory . $relative_path;

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
