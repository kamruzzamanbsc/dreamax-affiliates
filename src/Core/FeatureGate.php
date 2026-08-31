<?php
/**
 * Runtime product-tier gate for the WordPress.org Free package.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the fixed Free/Shared runtime boundary without implementing licensing.
 *
 * A separately distributed add-on must provide its own implementation and UI;
 * it must not attempt to turn dormant code in the WordPress.org package on.
 */
final class FeatureGate {

	/**
	 * Audited feature catalog.
	 *
	 * @var FeatureCatalog
	 */
	private FeatureCatalog $catalog;

	/**
	 * @param FeatureCatalog $catalog Audited feature catalog.
	 */
	public function __construct( FeatureCatalog $catalog ) {
		$this->catalog = $catalog;
	}

	/**
	 * Whether the Free runtime owns and may bootstrap a feature.
	 *
	 * @param string $identifier Stable feature identifier.
	 * @return bool
	 */
	public function is_available( string $identifier ): bool {
		$feature = $this->catalog->get( $identifier );
		$tier    = is_array( $feature ) ? (string) ( $feature['target_tier'] ?? '' ) : '';

		return in_array( $tier, array( FeatureCatalog::TIER_FREE, FeatureCatalog::TIER_SHARED ), true );
	}

	/**
	 * Whether the feature belongs to a separately distributed commercial add-on.
	 *
	 * @param string $identifier Stable feature identifier.
	 * @return bool
	 */
	public function is_pro( string $identifier ): bool {
		$feature = $this->catalog->get( $identifier );
		return is_array( $feature ) && FeatureCatalog::TIER_PRO === ( $feature['target_tier'] ?? '' );
	}

	/**
	 * Returns the audited tier or an empty string for an unknown identifier.
	 *
	 * @param string $identifier Stable feature identifier.
	 * @return string
	 */
	public function tier( string $identifier ): string {
		$feature = $this->catalog->get( $identifier );
		return is_array( $feature ) ? (string) ( $feature['target_tier'] ?? '' ) : '';
	}
}
