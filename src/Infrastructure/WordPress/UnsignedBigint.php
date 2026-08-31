<?php
/**
 * Normalizes positive unsigned BIGINT identifiers without integer casting.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Protects WooCommerce and imported identifiers from platform-sized integer
 * truncation before they reach an unsigned BIGINT database column.
 */
final class UnsignedBigint {

	/**
	 * Maximum value accepted by a MySQL BIGINT UNSIGNED column.
	 */
	private const MAX_VALUE = '18446744073709551615';

	/**
	 * Returns a canonical positive numeric string, or an empty string when the
	 * value falls outside the supported database range.
	 *
	 * @param mixed $value Candidate identifier.
	 * @return string
	 */
	public static function normalize( $value ): string {
		$value = trim( (string) $value );

		if ( ! preg_match( '/^[1-9][0-9]{0,19}$/', $value ) ) {
			return '';
		}

		if ( 20 === strlen( $value ) && strcmp( $value, self::MAX_VALUE ) > 0 ) {
			return '';
		}

		return $value;
	}
}
