<?php
/**
 * Affiliate account state model.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Domain\Affiliate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central affiliate statuses and allowed transitions.
 */
final class AffiliateStatus {

	public const PENDING   = 'pending';
	public const ACTIVE    = 'active';
	public const REJECTED  = 'rejected';
	public const SUSPENDED = 'suspended';
	public const BANNED    = 'banned';

	/**
	 * Returns every persisted status.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PENDING, self::ACTIVE, self::REJECTED, self::SUSPENDED, self::BANNED );
	}

	/**
	 * Determines whether a status is supported.
	 *
	 * @param string $status Status value.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Checks whether an account transition is allowed.
	 *
	 * Banned accounts can be restored only by an authorized manager through
	 * the explicit reactivation workflow. Keeping that path available avoids
	 * destructive deletion while still making the ban state terminal for
	 * ordinary affiliate-facing access.
	 *
	 * @param string $from Current status.
	 * @param string $to   Requested status.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		$transitions = array(
			self::PENDING   => array( self::ACTIVE, self::REJECTED, self::BANNED ),
			self::ACTIVE    => array( self::SUSPENDED, self::REJECTED, self::BANNED ),
			self::REJECTED  => array( self::PENDING, self::ACTIVE, self::BANNED ),
			self::SUSPENDED => array( self::ACTIVE, self::REJECTED, self::BANNED ),
			self::BANNED    => array( self::PENDING, self::ACTIVE ),
		);

		return $from === $to || in_array( $to, $transitions[ $from ] ?? array(), true );
	}

	/**
	 * Whether the status permits tracking and dashboard access.
	 *
	 * @param string $status Status value.
	 * @return bool
	 */
	public static function is_active( string $status ): bool {
		return self::ACTIVE === $status;
	}
}
