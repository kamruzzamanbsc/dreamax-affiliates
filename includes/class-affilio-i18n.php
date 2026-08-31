<?php
/**
 * Translation-safe labels for stored status, source, and payment keys.
 *
 * Canonical database values remain stable English identifiers. This helper
 * converts them to user-facing labels at render time so interfaces can be
 * fully localized without changing stored data.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Affilio_I18n {

	/**
	 * Returns a translated label for an affiliate, referral, payout, or request status.
	 *
	 * @param string $status Canonical status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'pending'    => __( 'Pending', 'dreamax-affiliates' ),
			'active'     => __( 'Active', 'dreamax-affiliates' ),
			'rejected'   => __( 'Rejected', 'dreamax-affiliates' ),
			'suspended'  => __( 'Suspended', 'dreamax-affiliates' ),
			'banned'     => __( 'Banned', 'dreamax-affiliates' ),
			'unpaid'     => __( 'Unpaid', 'dreamax-affiliates' ),
			'processing' => __( 'Processing', 'dreamax-affiliates' ),
			'paid'       => __( 'Paid', 'dreamax-affiliates' ),
			'cancelled'  => __( 'Cancelled', 'dreamax-affiliates' ),
			'failed'     => __( 'Failed', 'dreamax-affiliates' ),
			'requested'  => __( 'Requested', 'dreamax-affiliates' ),
			'approved'   => __( 'Approved', 'dreamax-affiliates' ),
			'refunded'   => __( 'Refunded', 'dreamax-affiliates' ),
		);

		$key = sanitize_key( (string) $status );
		return isset( $labels[ $key ] ) ? $labels[ $key ] : self::humanize_key( $key );
	}

	/**
	 * Returns a translated referral-source label.
	 *
	 * @param string $source Canonical source key.
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = array(
			'link'     => __( 'Referral link', 'dreamax-affiliates' ),
			'coupon'   => __( 'Affiliate coupon', 'dreamax-affiliates' ),
			'manual'   => __( 'Manual referral', 'dreamax-affiliates' ),
			'imported' => __( 'Imported referral', 'dreamax-affiliates' ),
			'direct'   => __( 'Direct attribution', 'dreamax-affiliates' ),
		);

		$key = sanitize_key( (string) $source );
		return isset( $labels[ $key ] ) ? $labels[ $key ] : self::humanize_key( $key );
	}

	/**
	 * Returns a translated payment-method label, preferring configured labels.
	 *
	 * @param string $method  Canonical method key.
	 * @param array  $methods Configured method labels keyed by method.
	 * @return string
	 */
	public static function payment_method_label( $method, array $methods = array() ) {
		$key = sanitize_key( (string) $method );
		if ( isset( $methods[ $key ] ) && '' !== (string) $methods[ $key ] ) {
			return (string) $methods[ $key ];
		}

		$labels = array(
			'paypal'        => __( 'PayPal', 'dreamax-affiliates' ),
			'bank'          => __( 'Bank transfer', 'dreamax-affiliates' ),
			'bank_transfer' => __( 'Bank transfer', 'dreamax-affiliates' ),
			'manual'        => __( 'Manual payment', 'dreamax-affiliates' ),
			'stripe'        => __( 'Stripe', 'dreamax-affiliates' ),
		);

		return isset( $labels[ $key ] ) ? $labels[ $key ] : self::humanize_key( $key );
	}

	/**
	 * Formats a number using the active WordPress locale.
	 *
	 * @param float|int $number   Number to format.
	 * @param int       $decimals Decimal places.
	 * @return string
	 */
	public static function number( $number, $decimals = 0 ) {
		return number_format_i18n( (float) $number, max( 0, absint( $decimals ) ) );
	}

	/**
	 * Formats a percentage using localized digits and separators.
	 *
	 * @param float|int $number   Percentage value without the percent sign.
	 * @param int       $decimals Decimal places.
	 * @param bool      $signed   Whether positive values should include a plus sign.
	 * @return string
	 */
	public static function percentage( $number, $decimals = 2, $signed = false ) {
		$value  = (float) $number;
		$prefix = $signed && $value > 0 ? '+' : '';
		return $prefix . self::number( $value, $decimals ) . '%';
	}

	/**
	 * Formats a monetary amount for plain-text output.
	 *
	 * WooCommerce formatting is preferred because it respects the store's
	 * currency placement and decimal settings. The returned value is plain text
	 * so callers can escape it in any HTML context.
	 *
	 * @param float|int $amount   Amount.
	 * @param string    $currency ISO currency code.
	 * @return string
	 */
	public static function amount( $amount, $currency = '' ) {
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );
		if ( '' === $currency && function_exists( 'get_woocommerce_currency' ) ) {
			$currency = strtoupper( sanitize_text_field( get_woocommerce_currency() ) );
		}

		if ( function_exists( 'wc_price' ) ) {
			$html = wc_price(
				(float) $amount,
				array(
					'currency' => $currency,
				)
			);
			return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
		}

		return trim( $currency . ' ' . self::number( $amount, 2 ) );
	}

	/**
	 * Formats a local WordPress date from a MySQL date or datetime value.
	 *
	 * @param string $mysql_datetime MySQL date or datetime.
	 * @param bool      $include_time   Whether to include the site time format.
	 * @return string
	 */
	public static function date( $mysql_datetime, $include_time = false ) {
		if ( empty( $mysql_datetime ) ) {
			return '';
		}

		$format = get_option( 'date_format' );
		if ( $include_time ) {
			$format .= ' ' . get_option( 'time_format' );
		}

		return mysql2date( $format, (string) $mysql_datetime, true );
	}

	/**
	 * Provides a safe fallback for extension-defined keys.
	 *
	 * @param string $key Canonical key.
	 * @return string
	 */
	private static function humanize_key( $key ) {
		$key = trim( str_replace( array( '-', '_' ), ' ', (string) $key ) );
		return '' === $key ? __( 'Unknown', 'dreamax-affiliates' ) : ucwords( $key );
	}
}
