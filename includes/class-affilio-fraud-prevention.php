<?php
/**
 * Core fraud-prevention checks and external policy-provider orchestration.
 *
 * Dreamax Affiliates Free owns only the deterministic self-referral check. Advanced
 * domain, velocity, scoring, and automation policies belong to separately
 * distributed providers registered through the public compatibility API.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Fraud_Prevention {

	/**
	 * Whether a visitor is the affiliate themselves.
	 *
	 * @param int $affiliate_user_id The affiliate's WordPress user ID.
	 * @param int $visitor_user_id   The current visitor/customer user ID, or zero for a guest.
	 * @return bool
	 */
	public static function is_self_referral( $affiliate_user_id, $visitor_user_id ) {
		if ( empty( $affiliate_user_id ) || empty( $visitor_user_id ) ) {
			return false;
		}

		return (int) $affiliate_user_id === (int) $visitor_user_id;
	}

	/**
	 * Applies separately distributed fraud-policy providers before tracking.
	 *
	 * The Free package sends a deliberately bounded context. It never performs
	 * domain blocklisting, click-velocity counting, or remote risk scoring on its
	 * own. Provider failures are isolated so a commercial add-on cannot break
	 * ordinary Free referral tracking.
	 *
	 * @param string $ip_address Visitor IP address as observed by the tracking layer.
	 * @return bool
	 */
	public static function should_reject_visit( $ip_address ) {
		$registry = affilio()->service( 'core.fraud_policy_providers' );
		if ( ! $registry instanceof \Affilio\Core\ProviderRegistry ) {
			return false;
		}

		$context = self::visit_context( $ip_address );
		foreach ( $registry->all() as $provider ) {
			try {
				$reason = sanitize_key( (string) $provider->reject_visit( $context ) );
			} catch ( \Throwable $error ) {
				/**
				 * Fires when an external fraud provider fails.
				 *
				 * @param \Throwable $error      Provider exception/error.
				 * @param object     $provider   Provider instance.
				 * @param array      $context    Bounded visit context.
				 */
				do_action( 'affilio_fraud_policy_provider_error', $error, $provider, $context );
				continue;
			}

			if ( '' === $reason ) {
				continue;
			}

			/**
			 * Fires when a visit is rejected by an external policy provider.
			 *
			 * Existing listeners that accept only the original reason argument remain
			 * compatible; provider identifiers are supplied as an additive argument.
			 *
			 * @param string $reason              Sanitized reason code.
			 * @param string $provider_identifier Provider identifier.
			 */
			do_action( 'affilio_visit_rejected', $reason, sanitize_key( (string) $provider->identifier() ) );
			return true;
		}

		return false;
	}

	/**
	 * Builds the bounded context exposed to external policies.
	 *
	 * @param string $ip_address Raw visitor IP from the current request.
	 * @return array<string,mixed>
	 */
	private static function visit_context( $ip_address ) {
		$normalized_ip = sanitize_text_field( (string) $ip_address );
		if ( (bool) get_option( 'affilio_anonymize_ip_addresses', true ) && function_exists( 'wp_privacy_anonymize_ip' ) ) {
			$normalized_ip = wp_privacy_anonymize_ip( $normalized_ip );
		}

		$referrer_host = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referrer      = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
			$referrer_host = sanitize_text_field( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		}

		return array(
			'ip_address'   => $normalized_ip,
			'referrer_host'=> strtolower( $referrer_host ),
			'user_id'      => get_current_user_id(),
			'site_id'      => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0,
			'timestamp'    => time(),
		);
	}

	/**
	 * Deprecated compatibility shim for code written before the Free/Pro split.
	 *
	 * Advanced domain rules are no longer implemented by Dreamax Affiliates Free. A Pro
	 * provider should use the bounded referrer_host context instead.
	 *
	 * @return bool
	 */
	public static function is_referring_domain_blocked() {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '2.0.4', 'affilio_register_fraud_policy_provider()' );
		}
		return false;
	}

	/**
	 * Deprecated compatibility shim for code written before the Free/Pro split.
	 *
	 * @param string $ip_address Visitor IP address.
	 * @return bool
	 */
	public static function is_ip_velocity_exceeded( $ip_address ) {
		unset( $ip_address );
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '2.0.4', 'affilio_register_fraud_policy_provider()' );
		}
		return false;
	}

	/**
	 * Deprecated compatibility shim for legacy settings integrations.
	 *
	 * Existing stored blocklist data is intentionally preserved for a separate
	 * provider, but Dreamax Affiliates Free does not read or apply it.
	 *
	 * @return string[]
	 */
	public static function get_blocked_domains() {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '2.0.4', 'affilio_register_fraud_policy_provider()' );
		}
		return array();
	}
}
