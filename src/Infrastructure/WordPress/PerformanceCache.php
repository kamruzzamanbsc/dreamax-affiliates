<?php
/**
 * Versioned, site-scoped cache for expensive Dreamax Affiliates report calculations.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps report caches bounded and invalidates them without wildcard scans.
 */
final class PerformanceCache {

	/** Cache group used by persistent object-cache implementations. */
	private const GROUP = 'affilio';

	/** Non-autoloaded generation option used for O(1) invalidation. */
	private const GENERATION_OPTION = 'affilio_cache_generation';

	/** Default cache lifetime. */
	private const DEFAULT_TTL = 300;

	/** Maximum accepted cache lifetime. */
	private const MAX_TTL = 3600;

	/** @var int|null */
	private $generation;

	/** @var array{hits:int,misses:int,writes:int,invalidations:int} */
	private $stats = array(
		'hits'          => 0,
		'misses'        => 0,
		'writes'        => 0,
		'invalidations' => 0,
	);

	/**
	 * Registers invalidation hooks for low-frequency data changes.
	 *
	 * Visits intentionally expire by TTL instead of invalidating on every click,
	 * which avoids turning each tracked request into an option write.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$hooks = array(
			'affilio_referral_created',
			'affilio_referral_status_changed',
			'affilio_referral_refund_adjusted',
			'affilio_processing_referral_adjusted',
			'affilio_payout_batch_created',
			'affilio_payout_paid',
			'affilio_payout_failed',
			'affilio_payout_cancelled',
			'affilio_payout_request_created',
			'affilio_payout_request_status_changed',
			'affilio_new_affiliate_registered',
			'affilio_affiliate_status_changed',
			'affilio_coupon_assigned',
			'affilio_coupon_unassigned',
		);

		foreach ( $hooks as $hook ) {
			add_action( $hook, array( $this, 'invalidate' ), 99, 10 );
		}

		add_action( 'added_option', array( $this, 'maybe_invalidate_for_added_option' ), 10, 2 );
		add_action( 'updated_option', array( $this, 'maybe_invalidate_for_option' ), 10, 3 );
		add_action( 'deleted_option', array( $this, 'maybe_invalidate_for_deleted_option' ), 10, 1 );
	}

	/**
	 * Returns a cached value or resolves and stores it.
	 *
	 * @param string   $namespace Stable cache namespace.
	 * @param array    $arguments Deterministic cache-key arguments.
	 * @param callable $resolver  Value resolver.
	 * @param int      $ttl       Lifetime in seconds.
	 * @return mixed
	 */
	public function remember( $namespace, array $arguments, callable $resolver, $ttl = self::DEFAULT_TTL ) {
		$key       = $this->build_key( $namespace, $arguments );
		$transient = $this->transient_name( $key );
		$found     = false;
		$envelope  = wp_cache_get( $key, self::GROUP, false, $found );

		if ( $found && is_array( $envelope ) && array_key_exists( 'value', $envelope ) ) {
			++$this->stats['hits'];
			return $envelope['value'];
		}

		$envelope = get_transient( $transient );
		if ( is_array( $envelope ) && array_key_exists( 'value', $envelope ) ) {
			wp_cache_set( $key, $envelope, self::GROUP, $this->normalize_ttl( $ttl ) );
			++$this->stats['hits'];
			return $envelope['value'];
		}

		++$this->stats['misses'];
		$value    = call_user_func( $resolver );
		$ttl      = $this->normalize_ttl( $ttl );
		$envelope = array( 'value' => $value );

		wp_cache_set( $key, $envelope, self::GROUP, $ttl );
		set_transient( $transient, $envelope, $ttl );
		++$this->stats['writes'];

		return $value;
	}

	/**
	 * Invalidates all current-site report caches in constant time.
	 *
	 * @return void
	 */
	public function invalidate( ...$ignored ) {
		$this->generation = self::invalidate_current_site();
		++$this->stats['invalidations'];
	}

	/**
	 * Bumps the site cache generation without requiring the plugin singleton.
	 *
	 * Lifecycle cleanup uses this before deleting transient fallbacks so stale
	 * values in a persistent object cache cannot be reused after reactivation.
	 *
	 * @return int New generation.
	 */
	public static function invalidate_current_site() {
		$found      = false;
		$generation = wp_cache_get( self::GENERATION_OPTION, self::GROUP, false, $found );
		if ( ! $found ) {
			$generation = get_option( self::GENERATION_OPTION, 1 );
		}

		$next = max( 1, absint( $generation ) ) + 1;
		update_option( self::GENERATION_OPTION, $next, false );
		wp_cache_set( self::GENERATION_OPTION, $next, self::GROUP );

		return $next;
	}

	/**
	 * Invalidates caches after a relevant option is created.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Initial value.
	 * @return void
	 */
	public function maybe_invalidate_for_added_option( $option, $value ) {
		unset( $value );
		if ( $this->is_cache_relevant_option( $option ) ) {
			$this->invalidate();
		}
	}

	/**
	 * Invalidates caches after administrator-facing Dreamax Affiliates settings change.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public function maybe_invalidate_for_option( $option, $old_value, $value ) {
		if ( $old_value === $value || ! $this->is_cache_relevant_option( $option ) ) {
			return;
		}

		$this->invalidate();
	}

	/**
	 * Invalidates caches after a relevant option is removed.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public function maybe_invalidate_for_deleted_option( $option ) {
		if ( $this->is_cache_relevant_option( $option ) ) {
			$this->invalidate();
		}
	}

	/**
	 * Returns runtime-only cache counters for diagnostics and benchmarks.
	 *
	 * @return array{hits:int,misses:int,writes:int,invalidations:int}
	 */
	public function stats() {
		return $this->stats;
	}

	/**
	 * Returns the current generation for diagnostics.
	 *
	 * @return int
	 */
	public function current_generation() {
		return $this->generation();
	}

	/**
	 * @param string $namespace Cache namespace.
	 * @param array  $arguments Cache arguments.
	 * @return string
	 */
	private function build_key( $namespace, array $arguments ) {
		$site_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$namespace = sanitize_key( (string) $namespace );
		$payload   = wp_json_encode( $this->normalize_arguments( $arguments ) );

		return 'v' . $this->generation() . ':s' . $site_id . ':' . $namespace . ':' . md5( (string) $payload );
	}

	/**
	 * Sorts associative arrays recursively so equivalent filters share a key.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private function normalize_arguments( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( $this->is_associative( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->normalize_arguments( $item );
		}

		return $value;
	}

	/**
	 * @param array $value Array to inspect.
	 * @return bool
	 */
	private function is_associative( array $value ) {
		if ( array() === $value ) {
			return false;
		}

		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/**
	 * @return int
	 */
	private function generation() {
		if ( null !== $this->generation ) {
			return $this->generation;
		}

		$found      = false;
		$generation = wp_cache_get( self::GENERATION_OPTION, self::GROUP, false, $found );
		if ( ! $found ) {
			$generation = get_option( self::GENERATION_OPTION, 1 );
			wp_cache_set( self::GENERATION_OPTION, $generation, self::GROUP );
		}

		$this->generation = max( 1, absint( $generation ) );
		return $this->generation;
	}

	/**
	 * @param string $key Object-cache key.
	 * @return string
	 */
	private function transient_name( $key ) {
		return 'affilio_pc_' . md5( $key );
	}

	/**
	 * @param int $ttl Requested TTL.
	 * @return int
	 */
	private function normalize_ttl( $ttl ) {
		return max( 30, min( self::MAX_TTL, absint( $ttl ) ) );
	}

	/**
	 * @param string $option Option name.
	 * @return bool
	 */
	private function is_cache_relevant_option( $option ) {
		$option = (string) $option;
		if ( 0 !== strpos( $option, 'affilio_' ) ) {
			return false;
		}

		$ignored = array(
			self::GENERATION_OPTION,
			'affilio_version',
			'affilio_db_version',
			'affilio_upgrade_pending',
			'affilio_upgrade_lock',
			'affilio_migration_state',
			'affilio_setup_redirect',
			'affilio_rewrite_flush_required',
		);

		if ( in_array( $option, $ignored, true ) ) {
			return false;
		}

		return false === strpos( $option, '_notice_' ) && false === strpos( $option, '_lock_' );
	}
}
