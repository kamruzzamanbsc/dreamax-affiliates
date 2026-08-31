<?php
/**
 * Small database-backed mutex for cron and integration events.
 *
 * WordPress options have a unique option_name key, so add_option() is an
 * atomic acquisition primitive even when no persistent object cache exists.
 * Locks are short-lived, recover automatically after their TTL, and carry an
 * owner token so a delayed worker cannot release a replacement lock.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AtomicLock {

	/**
	 * Lock tokens owned by the current PHP request.
	 *
	 * @var array<string,string>
	 */
	private static $owned_tokens = array();

	/**
	 * Acquires a named lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Maximum lock age in seconds.
	 * @return bool
	 */
	public static function acquire( $name, $ttl = 300 ) {
		$lock_id = self::lock_id( $name );
		$key     = 'affilio_lock_' . $lock_id;
		$now   = time();
		$maximum_ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$ttl         = max( 30, min( $maximum_ttl, absint( $ttl ) ) );
		$token = self::make_token();
		$value = array(
			'token'   => $token,
			'created' => $now,
		);

		if ( add_option( 'affilio_lock_' . $lock_id, $value, '', 'no' ) ) {
			self::$owned_tokens[ $key ] = $token;
			return true;
		}

		$existing = get_option( 'affilio_lock_' . $lock_id, array() );
		$created  = is_array( $existing ) ? absint( $existing['created'] ?? 0 ) : absint( $existing );
		if ( $created && $created > ( $now - $ttl ) ) {
			return false;
		}

		// A crashed request may leave a stale lock. Delete and compete for it
		// again; the unique option-name constraint still permits one winner.
		delete_option( 'affilio_lock_' . $lock_id );
		if ( ! add_option( 'affilio_lock_' . $lock_id, $value, '', 'no' ) ) {
			return false;
		}

		self::$owned_tokens[ $key ] = $token;
		return true;
	}

	/**
	 * Releases a named lock only when this request still owns it.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function release( $name ) {
		$lock_id = self::lock_id( $name );
		$key     = 'affilio_lock_' . $lock_id;
		if ( empty( self::$owned_tokens[ $key ] ) ) {
			return;
		}

		$current = get_option( 'affilio_lock_' . $lock_id, array() );
		$token   = is_array( $current ) ? (string) ( $current['token'] ?? '' ) : '';
		if ( hash_equals( self::$owned_tokens[ $key ], $token ) ) {
			delete_option( 'affilio_lock_' . $lock_id );
		}

		unset( self::$owned_tokens[ $key ] );
	}

	/**
	 * Force-clears one named lock during lifecycle cleanup.
	 *
	 * This method is intentionally separate from release(): release() verifies
	 * request ownership during normal concurrent work, while deactivation and
	 * uninstall must be able to remove locks left by a crashed earlier request.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function clear( $name ) {
		$lock_id = self::lock_id( $name );
		$key     = 'affilio_lock_' . $lock_id;
		delete_option( 'affilio_lock_' . $lock_id );
		unset( self::$owned_tokens[ $key ] );
	}

	/**
	 * Creates a request-unique lock owner token without persisting secrets.
	 *
	 * @return string
	 */
	private static function make_token() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return hash( 'sha256', uniqid( '', true ) . wp_rand() );
	}

	/**
	 * Returns the bounded hash suffix used for a lock option.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function lock_id( $name ) {
		return substr( hash( 'sha256', (string) $name ), 0, 40 );
	}
}
