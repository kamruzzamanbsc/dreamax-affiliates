<?php
/**
 * WordPress privacy-tool integration and visit-identifier retention cleanup.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Privacy {

	/**
	 * Maximum referral records returned by one privacy-export callback.
	 *
	 * @var int
	 */
	const EXPORT_BATCH_SIZE = 50;

	/**
	 * Daily cleanup hook.
	 *
	 * @var string
	 */
	const CLEANUP_HOOK = 'affilio_cleanup_expired_visit_ips';

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule_cleanup' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_expired_visit_ips' ) );
	}

	/**
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['affilio'] = array(
			'exporter_friendly_name' => __( 'Dreamax Affiliates affiliate data', 'dreamax-affiliates' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['affilio'] = array(
			'eraser_friendly_name' => __( 'Dreamax Affiliates affiliate data', 'dreamax-affiliates' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Exports the affiliate profile and referral ledger for a user email.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public function export_personal_data( $email_address, $page = 1 ) {
		$page = max( 1, absint( $page ) );
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$affiliate = affilio()->affiliates_db->get_by_user_id( $user->ID );

		if ( ! $affiliate ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data   = array();
		$offset = ( $page - 1 ) * self::EXPORT_BATCH_SIZE;

		if ( 1 === $page ) {
			$data[] = array(
				'group_id'    => 'affilio-affiliate-profile',
				'group_label' => __( 'Affiliate profile', 'dreamax-affiliates' ),
				'item_id'     => 'affilio-affiliate-' . (int) $affiliate->id,
				'data'        => array(
					array(
						'name'  => __( 'Referral code', 'dreamax-affiliates' ),
						'value' => $affiliate->referral_code,
					),
					array(
						'name'  => __( 'Status', 'dreamax-affiliates' ),
						'value' => $affiliate->status,
					),
					array(
						'name'  => __( 'Payout method', 'dreamax-affiliates' ),
						'value' => $affiliate->payout_method ?? 'paypal',
					),
					array(
						'name'  => __( 'Payout email', 'dreamax-affiliates' ),
						'value' => $affiliate->payout_email,
					),
					array(
						'name'  => __( 'Payout details', 'dreamax-affiliates' ),
						'value' => $affiliate->payout_details ?? '',
					),
					array(
						'name'  => __( 'Website', 'dreamax-affiliates' ),
						'value' => $affiliate->website_url,
					),
					array(
						'name'  => __( 'Promotion method', 'dreamax-affiliates' ),
						'value' => $affiliate->promotion_method ?? '',
					),
					array(
						'name'  => __( 'Social profile', 'dreamax-affiliates' ),
						'value' => $affiliate->social_profile ?? '',
					),
					array(
						'name'  => __( 'Application message', 'dreamax-affiliates' ),
						'value' => $affiliate->application_message ?? '',
					),
					array(
						'name'  => __( 'Status reason', 'dreamax-affiliates' ),
						'value' => $affiliate->status_reason ?? '',
					),
					array(
						'name'  => __( 'Registration date', 'dreamax-affiliates' ),
						'value' => $affiliate->date_registered,
					),
				),
			);
		}

		$events = affilio()->events_db->get_for_object(
			'affiliate',
			$affiliate->id,
			self::EXPORT_BATCH_SIZE,
			$offset
		);
		foreach ( $events as $event ) {
			$data[] = array(
				'group_id'    => 'affilio-affiliate-events',
				'group_label' => __( 'Affiliate activity history', 'dreamax-affiliates' ),
				'item_id'     => 'affilio-affiliate-event-' . (int) $event->id,
				'data'        => array(
					array(
						'name'  => __( 'Event', 'dreamax-affiliates' ),
						'value' => $event->event_type,
					),
					array(
						'name'  => __( 'Reason', 'dreamax-affiliates' ),
						'value' => $event->reason,
					),
					array(
						'name'  => __( 'Date', 'dreamax-affiliates' ),
						'value' => $event->date_created,
					),
				),
			);
		}

		$payouts = affilio()->payouts_db->get_by_affiliate(
			$affiliate->id,
			self::EXPORT_BATCH_SIZE,
			$offset
		);
		foreach ( $payouts as $payout ) {
			$data[] = array(
				'group_id'    => 'affilio-payouts',
				'group_label' => __( 'Affiliate payouts', 'dreamax-affiliates' ),
				'item_id'     => 'affilio-payout-' . (int) $payout->id,
				'data'        => array(
					array(
						'name'  => __( 'Batch', 'dreamax-affiliates' ),
						'value' => $payout->batch_key,
					),
					array(
						'name'  => __( 'Amount', 'dreamax-affiliates' ),
						'value' => (string) $payout->amount,
					),
					array(
						'name'  => __( 'Currency', 'dreamax-affiliates' ),
						'value' => $payout->currency,
					),
					array(
						'name'  => __( 'Method', 'dreamax-affiliates' ),
						'value' => $payout->payment_method,
					),
					array(
						'name'  => __( 'Destination', 'dreamax-affiliates' ),
						'value' => $payout->payment_destination,
					),
					array(
						'name'  => __( 'Status', 'dreamax-affiliates' ),
						'value' => $payout->status,
					),
					array(
						'name'  => __( 'Reference', 'dreamax-affiliates' ),
						'value' => $payout->reference,
					),
					array(
						'name'  => __( 'Created', 'dreamax-affiliates' ),
						'value' => $payout->date_created,
					),
					array(
						'name'  => __( 'Paid', 'dreamax-affiliates' ),
						'value' => $payout->date_paid,
					),
				),
			);
		}

		$requests = affilio()->payout_requests_db->get_by_affiliate(
			$affiliate->id,
			self::EXPORT_BATCH_SIZE,
			$offset
		);
		foreach ( $requests as $request ) {
			$data[] = array(
				'group_id'    => 'affilio-payout-requests',
				'group_label' => __( 'Affiliate payout requests', 'dreamax-affiliates' ),
				'item_id'     => 'affilio-payout-request-' . (int) $request->id,
				'data'        => array(
					array(
						'name'  => __( 'Amount', 'dreamax-affiliates' ),
						'value' => (string) $request->amount,
					),
					array(
						'name'  => __( 'Currency', 'dreamax-affiliates' ),
						'value' => $request->currency,
					),
					array(
						'name'  => __( 'Method', 'dreamax-affiliates' ),
						'value' => $request->payment_method,
					),
					array(
						'name'  => __( 'Destination', 'dreamax-affiliates' ),
						'value' => $request->payment_destination,
					),
					array(
						'name'  => __( 'Request note', 'dreamax-affiliates' ),
						'value' => $request->request_note,
					),
					array(
						'name'  => __( 'Admin note', 'dreamax-affiliates' ),
						'value' => $request->admin_note,
					),
					array(
						'name'  => __( 'Status', 'dreamax-affiliates' ),
						'value' => $request->status,
					),
					array(
						'name'  => __( 'Created', 'dreamax-affiliates' ),
						'value' => $request->date_created,
					),
				),
			);
		}

		$referrals = affilio()->referrals_db->get_by_affiliate(
			$affiliate->id,
			self::EXPORT_BATCH_SIZE,
			$offset
		);
		foreach ( $referrals as $referral ) {
			$data[] = array(
				'group_id'    => 'affilio-referrals',
				'group_label' => __( 'Affiliate referrals', 'dreamax-affiliates' ),
				'item_id'     => 'affilio-referral-' . (int) $referral->id,
				'data'        => array(
					array(
						'name'  => __( 'Order ID', 'dreamax-affiliates' ),
						'value' => (string) $referral->order_id,
					),
					array(
						'name'  => __( 'Order amount', 'dreamax-affiliates' ),
						'value' => (string) $referral->amount,
					),
					array(
						'name'  => __( 'Commission', 'dreamax-affiliates' ),
						'value' => (string) $referral->commission_amount,
					),
					array(
						'name'  => __( 'Currency', 'dreamax-affiliates' ),
						'value' => $referral->currency,
					),
					array(
						'name'  => __( 'Status', 'dreamax-affiliates' ),
						'value' => $referral->status,
					),
					array(
						'name'  => __( 'Date', 'dreamax-affiliates' ),
						'value' => $referral->date_created,
					),
				),
			);
		}

		$batch_counts = array(
			count( $events ),
			count( $payouts ),
			count( $requests ),
			count( $referrals ),
		);

		return array(
			'data' => $data,
			'done' => max( $batch_counts ) < self::EXPORT_BATCH_SIZE,
		);
	}

	/**
	 * Anonymizes optional affiliate profile fields and visit identifiers. Financial
	 * referral records are retained because site owners may have accounting
	 * and fraud-prevention obligations.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public function erase_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user || 1 !== (int) $page ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$affiliate = affilio()->affiliates_db->get_by_user_id( $user->ID );

		if ( ! $affiliate ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		affilio()->affiliates_db->update(
			$affiliate->id,
			array(
				'payout_email'        => '',
				'payout_details'      => '',
				'website_url'         => '',
				'promotion_method'    => '',
				'social_profile'      => '',
				'application_message' => '',
				'status_reason'       => '',
				'notes'               => '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$visits_anonymized = affilio()->visits_db->anonymize_by_affiliate( $affiliate->id );
		if ( 0 < (int) $visits_anonymized ) {
			/**
			 * Fires after Free successfully anonymizes visit identifiers.
			 *
			 * @param array $context Privacy-minimized anonymization context.
			 */
			do_action(
				'affilio_visit_identifiers_anonymized',
				array(
					'scope'        => 'affiliate',
					'affiliate_id' => (int) $affiliate->id,
				)
			);
		}
		affilio()->payouts_db->anonymize_by_affiliate( $affiliate->id );
		affilio()->payout_requests_db->anonymize_by_affiliate( $affiliate->id );
		affilio()->events_db->anonymize_for_object( 'affiliate', $affiliate->id );

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array(
				__( 'Affiliate payout, payout-request, application, website, status-reason, and audit-detail fields were erased or anonymized. Referral and payout ledger entries were retained for accounting and program-integrity purposes.', 'dreamax-affiliates' ),
			),
			'done'           => true,
		);
	}

	/**
	 * Adds suggested text to WordPress's privacy-policy guide.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'When visitors use an affiliate referral link, Dreamax Affiliates stores an opaque referral cookie and records the landing page, referring page, timestamp, optional campaign label, an anonymized IP address, and a site-specific one-way visitor hash for referral attribution, unique-visitor reporting, and fraud prevention. The visitor hash cannot be used to recover the raw IP address or user agent and is cleared with retained IP data after the configured retention period. Affiliate applications store the applicant’s payout method, payout details, payout email, website, promotion method, social profile, and application message when enabled. Payout and payout-request records store payment status, amount, notes, and a destination snapshot. Referral, commission, and payout accounting records may be retained for accounting and program-integrity purposes. Dreamax Affiliates preserves its program data when the plugin is deleted unless a site administrator explicitly enables the destructive delete-on-uninstall setting.', 'dreamax-affiliates' ) . '</p>';

		wp_add_privacy_policy_content( 'Dreamax Affiliates', wp_kses_post( wpautop( $content, false ) ) );
	}

	/**
	 * Clears IP and pseudonymous visitor values older than the configured retention period.
	 *
	 * @return void
	 */
	public function cleanup_expired_visit_ips() {
		$retention_days = (int) get_option( 'affilio_ip_retention_days', 90 );
		$retention_days = max( 1, $retention_days );
		$cutoff         = current_datetime()->modify( sprintf( '-%d days', $retention_days ) )->format( 'Y-m-d H:i:s' );
		$anonymized     = affilio()->visits_db->anonymize_expired_ips( $retention_days );

		if ( 0 < (int) $anonymized ) {
			/** This action is documented at the affiliate-erasure dispatch above. */
			do_action(
				'affilio_visit_identifiers_anonymized',
				array(
					'scope'  => 'retention',
					'cutoff' => $cutoff,
				)
			);
		}
	}

	/**
	 * Schedules the daily privacy cleanup.
	 *
	 * @return void
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Removes the scheduled cleanup event.
	 *
	 * @return void
	 */
	public static function unschedule_cleanup() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}
}
