<?php
/**
 * WordPress execution-context detection.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Distinguishes admin screens from AJAX, cron, REST, CLI, and frontend work.
 */
final class RequestContext {

	public const FRONTEND = 'frontend';
	public const ADMIN    = 'admin';
	public const AJAX     = 'ajax';
	public const CRON     = 'cron';
	public const REST     = 'rest';
	public const CLI      = 'cli';

	/**
	 * Returns the current execution context.
	 *
	 * @return string
	 */
	public function current(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return self::CLI;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return self::CRON;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return self::AJAX;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::REST;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return self::ADMIN;
		}

		return self::FRONTEND;
	}

	/**
	 * Whether a human-facing WordPress administration screen is loading.
	 *
	 * @return bool
	 */
	public function is_admin_screen(): bool {
		return self::ADMIN === $this->current();
	}

	/**
	 * Whether the context is safe for frontend UI registration.
	 *
	 * @return bool
	 */
	public function is_frontend(): bool {
		return self::FRONTEND === $this->current();
	}
}
