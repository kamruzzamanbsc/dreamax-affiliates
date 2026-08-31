<?php
/**
 * Payout state model.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Domain\Payout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central payout statuses and guarded transitions.
 */
final class PayoutStatus {

	public const PROCESSING = 'processing';
	public const PAID       = 'paid';
	public const CANCELLED  = 'cancelled';
	public const FAILED     = 'failed';

	/**
	 * Returns every persisted payout status.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PROCESSING, self::PAID, self::CANCELLED, self::FAILED );
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
	 * @param string $from           Current status.
	 * @param string $to             Requested status.
	 * @param bool   $allow_recovery Whether rollback-only transitions are allowed.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to, bool $allow_recovery = false ): bool {
		$transitions = array(
			self::PROCESSING => array( self::PAID, self::CANCELLED, self::FAILED ),
			self::FAILED     => array( self::PROCESSING, self::CANCELLED ),
			self::PAID       => array(),
			self::CANCELLED  => array(),
		);

		if ( $from === $to || in_array( $to, $transitions[ $from ] ?? array(), true ) ) {
			return true;
		}

		return $allow_recovery && self::PAID === $from && self::PROCESSING === $to;
	}
}
