<?php
/**
 * Referral commission state model.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Domain\Referral;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central referral statuses and guarded transitions.
 */
final class ReferralStatus {

	public const PENDING    = 'pending';
	public const UNPAID     = 'unpaid';
	public const PROCESSING = 'processing';
	public const PAID       = 'paid';
	public const CANCELLED  = 'cancelled';

	/**
	 * Returns every persisted referral status.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PENDING, self::UNPAID, self::PROCESSING, self::PAID, self::CANCELLED );
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
	 * Checks whether a transition is allowed.
	 *
	 * Recovery transitions are limited to operations already used by the
	 * current payout rollback workflow; they are not intended for ordinary UI.
	 *
	 * @param string $from           Current status.
	 * @param string $to             Requested status.
	 * @param bool   $allow_recovery Whether rollback-only transitions are allowed.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to, bool $allow_recovery = false ): bool {
		$transitions = array(
			self::PENDING    => array( self::UNPAID, self::PROCESSING, self::CANCELLED ),
			self::UNPAID     => array( self::PROCESSING, self::CANCELLED ),
			self::PROCESSING => array( self::PAID, self::UNPAID, self::CANCELLED ),
			self::PAID       => array(),
			self::CANCELLED  => array(),
		);

		if ( $from === $to ) {
			return true;
		}

		if ( in_array( $to, $transitions[ $from ] ?? array(), true ) ) {
			return true;
		}

		return $allow_recovery && self::PAID === $from && self::PROCESSING === $to;
	}

	/**
	 * Whether normal business operations should treat the status as terminal.
	 *
	 * @param string $status Status value.
	 * @return bool
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( self::PAID, self::CANCELLED ), true );
	}
}
