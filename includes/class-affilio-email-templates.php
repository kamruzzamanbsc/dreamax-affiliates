<?php
/**
 * Essential plain-text notification definitions and provider orchestration.
 *
 * Dreamax Affiliates Free ships fixed, translatable subjects and bodies plus per-event
 * enable/disable controls. Editable content belongs to a separately
 * distributed EmailTemplateProvider implementation.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Email_Templates {

	/**
	 * Returns notification labels and safe Free defaults.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions() {
		return array(
			'admin_application' => array(
				'label'   => __( 'Admin: new affiliate application', 'dreamax-affiliates' ),
				'enabled' => true,
				'subject' => __( 'New affiliate application from {affiliate_name}', 'dreamax-affiliates' ),
				'body'    => __( "A new affiliate application was submitted.\n\nName: {affiliate_name}\nEmail: {affiliate_email}\nStatus: {status}\n\nReview it in WordPress admin: {admin_url}", 'dreamax-affiliates' ),
			),
			'affiliate_status' => array(
				'label'   => __( 'Affiliate: application or account status changed', 'dreamax-affiliates' ),
				'enabled' => true,
				'subject' => __( 'Your affiliate account status is now {status}', 'dreamax-affiliates' ),
				'body'    => __( "Hello {affiliate_name},\n\nYour affiliate account status is now: {status}.\nReason: {reason}\n\nAffiliate area: {dashboard_url}", 'dreamax-affiliates' ),
			),
			'admin_referral' => array(
				'label'   => __( 'Admin: new referral', 'dreamax-affiliates' ),
				'enabled' => true,
				'subject' => __( 'New affiliate referral recorded', 'dreamax-affiliates' ),
				'body'    => __( "A new referral was recorded.\n\nAffiliate: {affiliate_name}\nOrder/reference: {order_id}\nCommission: {commission} {currency}\nStatus: {status}", 'dreamax-affiliates' ),
			),
			'affiliate_referral' => array(
				'label'   => __( 'Affiliate: new referral', 'dreamax-affiliates' ),
				'enabled' => true,
				'subject' => __( 'You earned a new affiliate commission', 'dreamax-affiliates' ),
				'body'    => __( "Hello {affiliate_name},\n\nA referral was recorded for you.\nCommission: {commission} {currency}\nStatus: {status}\n\nAffiliate area: {dashboard_url}", 'dreamax-affiliates' ),
			),
			'affiliate_referral_status' => array(
				'label'   => __( 'Affiliate: referral cancelled or released', 'dreamax-affiliates' ),
				'enabled' => true,
				'subject' => __( 'Affiliate referral status changed to {status}', 'dreamax-affiliates' ),
				'body'    => __( "Hello {affiliate_name},\n\nA referral status changed to {status}.\nReason: {reason}\nCommission: {commission} {currency}\n\nAffiliate area: {dashboard_url}", 'dreamax-affiliates' ),
			),
			'affiliate_payout_created' => array(
				'label'   => __( 'Affiliate: payout batch created', 'dreamax-affiliates' ),
				'enabled' => true,
				'subject' => __( 'Your affiliate payout is being processed', 'dreamax-affiliates' ),
				'body'    => __( "Hello {affiliate_name},\n\nA payout batch was created.\nAmount: {amount} {currency}\nBatch: {batch}\n\nAffiliate area: {dashboard_url}", 'dreamax-affiliates' ),
			),
			'affiliate_payout_paid' => array(
				'label'   => __( 'Affiliate: payout marked paid', 'dreamax-affiliates' ),
				'enabled' => true,
				'subject' => __( 'Your affiliate payout has been marked paid', 'dreamax-affiliates' ),
				'body'    => __( "Hello {affiliate_name},\n\nYour payout has been completed.\nAmount: {amount} {currency}\nBatch: {batch}\nReference: {reference}\n\nAffiliate area: {dashboard_url}", 'dreamax-affiliates' ),
			),
			'admin_payout_request' => array(
				'label'   => __( 'Admin: new payout request', 'dreamax-affiliates' ),
				'enabled' => true,
				'subject' => __( 'New affiliate payout request', 'dreamax-affiliates' ),
				'body'    => __( "A payout request was submitted.\n\nAffiliate: {affiliate_name}\nAmount: {amount} {currency}\n\nReview it in WordPress admin: {admin_url}", 'dreamax-affiliates' ),
			),
			'affiliate_payout_request_status' => array(
				'label'   => __( 'Affiliate: payout request reviewed', 'dreamax-affiliates' ),
				'enabled' => true,
				'subject' => __( 'Your payout request is now {status}', 'dreamax-affiliates' ),
				'body'    => __( "Hello {affiliate_name},\n\nYour payout request is now: {status}.\nAmount: {amount} {currency}\nNote: {reason}\n\nAffiliate area: {dashboard_url}", 'dreamax-affiliates' ),
			),
		);
	}

	/**
	 * Returns one normalized notification template.
	 *
	 * @param string $key Notification key.
	 * @return array<string,mixed>|null
	 */
	public static function get( $key ) {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $key ] ) ) {
			return null;
		}

		$template             = $definitions[ $key ];
		$notification_settings = self::notification_settings();
		if ( array_key_exists( $key, $notification_settings ) ) {
			$template['enabled'] = (bool) $notification_settings[ $key ];
		}

		$registry = affilio()->service( 'core.email_template_providers' );
		if ( ! $registry instanceof \Affilio\Core\ProviderRegistry ) {
			return $template;
		}

		foreach ( $registry->all() as $provider ) {
			try {
				$replacement = $provider->template( (string) $key, $template );
			} catch ( \Throwable $error ) {
				do_action( 'affilio_email_template_provider_error', $error, $provider, $key );
				continue;
			}

			if ( ! is_array( $replacement ) ) {
				continue;
			}

			$template = self::normalize_template( $replacement, $template );
		}

		return $template;
	}

	/**
	 * Returns stored enable/disable choices for essential notifications.
	 *
	 * @return array<string,bool>
	 */
	public static function notification_settings() {
		$stored = get_option( 'affilio_email_notifications', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$output = array();

		foreach ( self::definitions() as $key => $definition ) {
			$output[ $key ] = array_key_exists( $key, $stored ) ? ! empty( $stored[ $key ] ) : ! empty( $definition['enabled'] );
		}

		return $output;
	}

	/**
	 * Sanitizes Free notification enable/disable controls.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<string,bool>
	 */
	public static function sanitize_notification_settings( $value ) {
		$value  = is_array( $value ) ? $value : array();
		$output = array();

		foreach ( self::definitions() as $key => $definition ) {
			unset( $definition );
			$output[ $key ] = ! empty( $value[ $key ] );
		}

		return $output;
	}

	/**
	 * Deprecated compatibility sanitizer.
	 *
	 * Subject/body editing was extracted from the Free package. The method is
	 * retained to avoid fatal errors in integrations that called it directly,
	 * but only notification enablement is accepted.
	 *
	 * @param mixed $value Legacy option value.
	 * @return array<string,bool>
	 */
	public static function sanitize( $value ) {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '2.0.4', 'Affilio_Email_Templates::sanitize_notification_settings()' );
		}

		$normalized = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $row ) {
				$normalized[ $key ] = is_array( $row ) ? ! empty( $row['enabled'] ) : ! empty( $row );
			}
		}
		return self::sanitize_notification_settings( $normalized );
	}

	/**
	 * Normalizes a provider-owned template while retaining required defaults.
	 *
	 * @param array<string,mixed> $candidate Provider candidate.
	 * @param array<string,mixed> $fallback  Previous safe template.
	 * @return array<string,mixed>
	 */
	private static function normalize_template( array $candidate, array $fallback ) {
		return array(
			'label'   => $fallback['label'],
			'enabled' => (bool) $fallback['enabled'],
			'subject' => isset( $candidate['subject'] ) ? sanitize_text_field( (string) $candidate['subject'] ) : (string) $fallback['subject'],
			'body'    => isset( $candidate['body'] ) ? sanitize_textarea_field( (string) $candidate['body'] ) : (string) $fallback['body'],
		);
	}
}
