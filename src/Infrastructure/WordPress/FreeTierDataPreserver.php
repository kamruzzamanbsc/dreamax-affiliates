<?php
/**
 * Non-destructive Free/Pro productization state and legacy-data inventory.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records that the Free-tier split completed without copying or deleting data.
 *
 * The marker intentionally stores only option-presence and metadata-key names.
 * It never stores the values of commission rules, fraud policies, email
 * templates, affiliate data, order data, or financial records.
 */
final class FreeTierDataPreserver {

	/** Non-autoloaded option containing the idempotent transition marker. */
	public const MARKER_OPTION = 'affilio_free_tier_productization';

	/** Marker payload schema. */
	public const MARKER_SCHEMA = '1.0.0';

	/**
	 * Advanced options retained for a separately distributed add-on.
	 *
	 * @var string[]
	 */
	private const PRESERVED_OPTIONS = array(
		'affilio_blocked_domains',
		'affilio_ip_velocity_threshold',
		'affilio_ip_velocity_window_hours',
		'affilio_commission_holding_days',
		'affilio_email_templates',
	);

	/**
	 * Legacy product/variation commission metadata retained in post meta.
	 *
	 * @var string[]
	 */
	private const PRESERVED_POST_META = array(
		'_affilio_commission_mode',
		'_affilio_commission_rate',
	);

	/**
	 * Legacy category commission metadata retained in term meta.
	 *
	 * @var string[]
	 */
	private const PRESERVED_TERM_META = array(
		'affilio_commission_mode',
		'affilio_commission_rate',
	);

	/**
	 * Creates or refreshes the privacy-safe productization marker.
	 *
	 * Re-running this method with the same plugin version performs no write. It
	 * does not normalize, copy, delete, or otherwise mutate any preserved value.
	 *
	 * @param string $plugin_version Version that completed the transition.
	 * @return array<string,mixed> Stored marker payload.
	 */
	public static function mark_complete( string $plugin_version ): array {
		$plugin_version = sanitize_text_field( $plugin_version );
		$existing       = get_option( self::MARKER_OPTION, array() );
		$existing       = is_array( $existing ) ? $existing : array();

		if (
			self::MARKER_SCHEMA === ( $existing['schema'] ?? '' )
			&& $plugin_version === ( $existing['verified_with'] ?? '' )
			&& ! empty( $existing['completed'] )
		) {
			return $existing;
		}

		$payload = array(
			'schema'             => self::MARKER_SCHEMA,
			'completed'          => true,
			'completed_at_gmt'   => isset( $existing['completed_at_gmt'] )
				? sanitize_text_field( (string) $existing['completed_at_gmt'] )
				: gmdate( 'c' ),
			'verified_with'      => $plugin_version,
			'preserved_options'  => self::option_presence(),
			'preserved_postmeta' => self::preserved_post_meta_keys(),
			'preserved_termmeta' => self::preserved_term_meta_keys(),
		);

		if ( empty( $existing ) ) {
			add_option( self::MARKER_OPTION, $payload, '', false );
		} else {
			update_option( self::MARKER_OPTION, $payload, false );
		}

		return $payload;
	}

	/**
	 * Returns a privacy-safe status payload for diagnostics and tests.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$marker = get_option( self::MARKER_OPTION, array() );

		return array(
			'marker'             => is_array( $marker ) ? $marker : array(),
			'preserved_options'  => self::option_presence(),
			'preserved_postmeta' => self::preserved_post_meta_keys(),
			'preserved_termmeta' => self::preserved_term_meta_keys(),
		);
	}

	/**
	 * Returns advanced option names that Free must preserve while installed.
	 *
	 * @return string[]
	 */
	public static function preserved_option_names(): array {
		return self::PRESERVED_OPTIONS;
	}

	/**
	 * Returns legacy post-meta keys owned by advanced commission providers.
	 *
	 * @return string[]
	 */
	public static function preserved_post_meta_keys(): array {
		return self::PRESERVED_POST_META;
	}

	/**
	 * Returns legacy term-meta keys owned by advanced commission providers.
	 *
	 * @return string[]
	 */
	public static function preserved_term_meta_keys(): array {
		return self::PRESERVED_TERM_META;
	}

	/**
	 * Reports only whether each advanced option exists, never its value.
	 *
	 * @return array<string,bool>
	 */
	private static function option_presence(): array {
		$presence = array();

		foreach ( self::PRESERVED_OPTIONS as $option_name ) {
			$sentinel                 = new \stdClass();
			$presence[ $option_name ] = $sentinel !== get_option( $option_name, $sentinel );
		}

		return $presence;
	}
}
