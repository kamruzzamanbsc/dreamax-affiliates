<?php
/**
 * Commission qualification policy for the Free plugin.
 *
 * The Free tier creates qualifying referrals as immediately payout-eligible.
 * Extensions may request a validated initial hold and may explicitly hand
 * existing holds to Free for date-preserving release automation.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Commission_Approval {

	/** Bounded held-referral release hook. */
	const LEGACY_CRON_HOOK = 'affilio_release_eligible_referrals';

	/** Site-local flag recording explicit ownership handoff. */
	const HANDOFF_OPTION = 'affilio_commission_handoff';

	/** Maximum referrals released by one worker invocation. */
	const RELEASE_BATCH_SIZE = 500;

	/** Retry delay after lock contention or a due row that was not released. */
	const RETRY_DELAY = 300;

	/**
	 * Registers the dormant handoff worker.
	 */
	public function __construct() {
		add_action( self::LEGACY_CRON_HOOK, array( $this, 'run_handoff_worker' ) );
		add_action( 'init', array( __CLASS__, 'ensure_handoff_schedule' ) );
	}

	/**
	 * Returns allowlisted WooCommerce qualifying status slugs.
	 *
	 * @return string[]
	 */
	public static function get_qualifying_statuses() {
		$stored = get_option( 'affilio_qualifying_order_statuses', array( 'completed' ) );
		if ( ! is_array( $stored ) ) {
			$stored = array( 'completed' );
		}

		$supported = array( 'pending', 'processing', 'on-hold', 'completed' );
		$known     = $supported;

		// WooCommerce status labels are translated inside wc_get_order_statuses().
		// Dreamax Affiliates boots on plugins_loaded, which is intentionally before
		// WordPress init. Calling that WooCommerce helper during bootstrap can force
		// the woocommerce text domain to load too early under WP_DEBUG. The four
		// statuses above are WooCommerce core statuses, so use the static allowlist
		// during early bootstrap and only consult WooCommerce after init has begun.
		if ( did_action( 'init' ) && function_exists( 'wc_get_order_statuses' ) ) {
			$known = array();
			foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
				$status = sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );
				if ( in_array( $status, $supported, true ) ) {
					$known[] = $status;
				}
			}
		}

		$allowed = array();
		foreach ( $stored as $status ) {
			$status = sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );
			if ( $status && in_array( $status, $known, true ) ) {
				$allowed[] = $status;
			}
		}

		return array_values( array_unique( $allowed ? $allowed : array( 'completed' ) ) );
	}

	/**
	 * Returns the validated initial referral state.
	 *
	 * The default remains immediately eligible. Extensions receive only a
	 * bounded scalar context and may request pending/future eligibility.
	 *
	 * @param array $context Referral qualification context.
	 * @return array{status:string,date_eligible:?string}
	 */
	public static function get_initial_state( array $context = array() ) {
		$default = array(
			'status'        => 'unpaid',
			'date_eligible' => null,
		);

		/**
		 * Filters the initial state for a newly qualified/restored referral.
		 *
		 * @param array $default Validated default state.
		 * @param array $context Privacy-minimized scalar qualification context.
		 */
		$state = apply_filters( 'affilio_commission_initial_state', $default, self::normalize_context( $context ) );

		if ( ! is_array( $state ) || ! isset( $state['status'] ) || ! is_string( $state['status'] ) ) {
			return $default;
		}

		$status = sanitize_key( $state['status'] );
		$date   = $state['date_eligible'] ?? null;

		if ( 'unpaid' === $status && null === $date ) {
			return $default;
		}

		if ( 'pending' !== $status || ! is_string( $date ) ) {
			return $default;
		}

		$date = self::normalize_mysql_datetime( $date, true );
		if ( null === $date ) {
			return $default;
		}

		return array(
			'status'        => 'pending',
			'date_eligible' => $date,
		);
	}

	/**
	 * Whether a referral is protected by an active future hold.
	 *
	 * @param object|array $referral Referral row.
	 * @return bool
	 */
	public static function is_active_hold( $referral ) {
		$status = is_array( $referral ) ? ( $referral['status'] ?? '' ) : ( $referral->status ?? '' );
		$date   = is_array( $referral ) ? ( $referral['date_eligible'] ?? null ) : ( $referral->date_eligible ?? null );

		return 'pending' === $status && is_string( $date ) && null !== self::normalize_mysql_datetime( $date, true );
	}

	/**
	 * Releases one bounded batch through Free's transition/audit owner.
	 *
	 * @param int $limit Maximum referrals to release.
	 * @return int[] IDs actually transitioned from pending to unpaid.
	 */
	public function release_eligible( $limit = self::RELEASE_BATCH_SIZE ) {
		if ( ! \Affilio\Domain\Referral\ReferralStatus::can_transition( 'pending', 'unpaid' ) ) {
			return array();
		}

		$limit  = max( 1, min( 2000, absint( $limit ) ) );
		$ids    = affilio()->referrals_db->get_eligible_ids( $limit );
		$ids    = affilio()->referrals_db->release_eligible_ids( $ids );
		$reason = __( 'Commission holding period completed.', 'dreamax-affiliates' );

		foreach ( $ids as $referral_id ) {
			affilio()->audit->record(
				'referral',
				$referral_id,
				'status_changed',
				$reason,
				array(
					'from' => 'pending',
					'to'   => 'unpaid',
				),
				0
			);
			do_action( 'affilio_referral_status_changed', $referral_id, 'unpaid', 'pending', $reason );
		}

		return $ids;
	}

	/**
	 * Explicitly hands date-preserving release responsibility to Free.
	 *
	 * @return bool Whether handoff is active after the request.
	 */
	public static function request_handoff() {
		if ( false === get_option( self::HANDOFF_OPTION, false ) ) {
			add_option( self::HANDOFF_OPTION, 1, '', false );
		} else {
			update_option( self::HANDOFF_OPTION, 1, false );
		}

		self::schedule_handoff_worker( 1 );
		return self::is_handoff_requested();
	}

	/**
	 * Withdraws Free handoff after the extension confirms its worker is ready.
	 *
	 * @return void
	 */
	public static function withdraw_handoff() {
		delete_option( self::HANDOFF_OPTION );
		wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
		\Affilio\Infrastructure\WordPress\AtomicLock::release( 'commission-release-worker' );
	}

	/**
	 * Returns whether this site explicitly requested Free handoff.
	 *
	 * @return bool
	 */
	public static function is_handoff_requested() {
		return 1 === absint( get_option( self::HANDOFF_OPTION, 0 ) );
	}

	/**
	 * Restores a missing handoff event after activation or a lost cron entry.
	 *
	 * @return void
	 */
	public static function ensure_handoff_schedule() {
		if ( self::is_handoff_requested() && ! wp_next_scheduled( self::LEGACY_CRON_HOOK ) ) {
			self::schedule_handoff_worker( 1 );
		}
	}

	/**
	 * Runs one bounded, lock-protected release batch and schedules remaining work.
	 *
	 * @return int[] IDs actually released.
	 */
	public function run_handoff_worker() {
		if ( ! self::is_handoff_requested() ) {
			return array();
		}

		if ( ! \Affilio\Infrastructure\WordPress\AtomicLock::acquire( 'commission-release-worker', 5 * MINUTE_IN_SECONDS ) ) {
			self::schedule_handoff_worker( self::RETRY_DELAY );
			return array();
		}

		try {
			$released = $this->release_eligible( self::RELEASE_BATCH_SIZE );
			if ( count( $released ) >= self::RELEASE_BATCH_SIZE ) {
				self::schedule_handoff_worker( 1 );
				return $released;
			}

			$next = affilio()->referrals_db->get_next_held_eligibility();
			if ( null !== $next ) {
				$next_time = self::mysql_datetime_timestamp( $next );
				$delay     = null === $next_time || $next_time <= time() ? self::RETRY_DELAY : $next_time - time();
				self::schedule_handoff_worker( $delay );
			}

			return $released;
		} finally {
			\Affilio\Infrastructure\WordPress\AtomicLock::release( 'commission-release-worker' );
		}
	}

	/**
	 * Clears the legacy holding worker without scheduling a replacement.
	 *
	 * @return void
	 */
	public static function clear_legacy_schedule() {
		wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
		\Affilio\Infrastructure\WordPress\AtomicLock::release( 'commission-release-worker' );
	}

	/**
	 * Schedules one site-local single event only while handoff is active.
	 *
	 * @param int $delay Delay in seconds.
	 * @return void
	 */
	private static function schedule_handoff_worker( $delay ) {
		if ( ! self::is_handoff_requested() || wp_next_scheduled( self::LEGACY_CRON_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, absint( $delay ) ), self::LEGACY_CRON_HOOK );
	}

	/**
	 * Keeps only source-supported scalar context fields.
	 *
	 * @param array $context Untrusted caller context.
	 * @return array
	 */
	private static function normalize_context( array $context ) {
		$normalized = array();
		foreach ( array( 'event', 'source' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$normalized[ $key ] = substr( sanitize_key( (string) $context[ $key ] ), 0, 20 );
			}
		}
		foreach ( array( 'order_id', 'affiliate_id', 'visit_id' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$normalized[ $key ] = absint( $context[ $key ] );
			}
		}
		if ( isset( $context['qualified_at'] ) && is_string( $context['qualified_at'] ) ) {
			$date = self::normalize_mysql_datetime( $context['qualified_at'], false );
			if ( null !== $date ) {
				$normalized['qualified_at'] = $date;
			}
		}

		return $normalized;
	}

	/**
	 * Strictly normalizes a site-local MySQL datetime.
	 *
	 * @param string $value       Candidate datetime.
	 * @param bool   $future_only Require a future instant.
	 * @return string|null
	 */
	private static function normalize_mysql_datetime( $value, $future_only ) {
		$value  = trim( (string) $value );
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();

		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return null;
		}

		if ( $future_only && $date->getTimestamp() <= time() ) {
			return null;
		}

		return $value;
	}

	/**
	 * Converts one validated site-local MySQL datetime to a Unix timestamp.
	 *
	 * @param string $value MySQL datetime.
	 * @return int|null
	 */
	private static function mysql_datetime_timestamp( $value ) {
		$date = self::normalize_mysql_datetime( $value, false );
		if ( null === $date ) {
			return null;
		}

		return \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date, wp_timezone() )->getTimestamp();
	}
}
