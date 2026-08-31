<?php
/**
 * Configurable plain-text email notifications.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Emails {

	/** Registers notification hooks. */
	public function __construct() {
		add_action( 'affilio_new_affiliate_registered', array( $this, 'new_application' ), 10, 2 );
		add_action( 'affilio_affiliate_status_changed', array( $this, 'affiliate_status' ), 10, 3 );
		add_action( 'affilio_referral_created', array( $this, 'new_referral' ), 10, 3 );
		add_action( 'affilio_referral_status_changed', array( $this, 'referral_status' ), 10, 4 );
		add_action( 'affilio_payout_batch_created', array( $this, 'payout_created' ), 10, 3 );
		add_action( 'affilio_payout_paid', array( $this, 'payout_paid' ) );
		add_action( 'affilio_payout_request_created', array( $this, 'payout_request_created' ) );
		add_action( 'affilio_payout_request_status_changed', array( $this, 'payout_request_status' ), 10, 3 );
	}

	/**
	 * @param int    $affiliate_id Affiliate ID.
	 * @param string $status       Initial affiliate status.
	 */
	public function new_application( $affiliate_id, $status = 'pending' ) {
		$context = $this->affiliate_context( $affiliate_id );
		if ( ! $context ) {
			return;
		}

		$context['admin_url'] = add_query_arg(
			array(
				'page'         => 'affilio-affiliates',
				'view'         => 'edit',
				'affiliate_id' => absint( $affiliate_id ),
			),
			admin_url( 'admin.php' )
		) . '#affilio-status';

		$this->send( 'admin_application', self::admin_email(), $context );

		$status = sanitize_key( $status );
		if ( 'pending' !== $status ) {
			$context['status'] = $status;
			$this->send( 'affiliate_status', $context['affiliate_email'], $context );
		}
	}

	/**
	 * @param int    $affiliate_id Affiliate ID.
	 * @param string $new_status New status.
	 * @param string $reason Optional reason.
	 */
	public function affiliate_status( $affiliate_id, $new_status, $reason = '' ) {
		$context = $this->affiliate_context( $affiliate_id );
		if ( ! $context ) {
			return;
		}
		$context['status'] = sanitize_key( $new_status );
		$context['reason'] = $reason ? sanitize_textarea_field( $reason ) : __( 'No reason was provided.', 'dreamax-affiliates' );
		$this->send( 'affiliate_status', $context['affiliate_email'], $context );
	}

	/**
	 * @param int $referral_id Referral ID.
	 * @param int $affiliate_id Affiliate ID.
	 * @param int $order_id Order/reference ID.
	 */
	public function new_referral( $referral_id, $affiliate_id = 0, $order_id = 0 ) {
		$context = $this->referral_context( $referral_id );
		if ( ! $context ) {
			return;
		}
		$this->send( 'admin_referral', self::admin_email(), $context );
		$this->send( 'affiliate_referral', $context['affiliate_email'], $context );
	}

	/**
	 * @param int    $referral_id Referral ID.
	 * @param string $new_status New status.
	 * @param string $old_status Previous status.
	 * @param string $reason Reason.
	 */
	public function referral_status( $referral_id, $new_status, $old_status = '', $reason = '' ) {
		if ( ! in_array( $new_status, array( 'cancelled', 'unpaid' ), true ) ) {
			return;
		}
		$context = $this->referral_context( $referral_id );
		if ( ! $context ) {
			return;
		}
		$context['status'] = sanitize_key( $new_status );
		$context['reason'] = $reason ? sanitize_textarea_field( $reason ) : __( 'No reason was provided.', 'dreamax-affiliates' );
		$this->send( 'affiliate_referral_status', $context['affiliate_email'], $context );
	}

	/**
	 * @param string $batch_key Batch key.
	 * @param int[]  $payout_ids Payout IDs.
	 * @param int[]  $referral_ids Referral IDs.
	 */
	public function payout_created( $batch_key, $payout_ids, $referral_ids = array() ) {
		foreach ( array_map( 'absint', (array) $payout_ids ) as $payout_id ) {
			$context = $this->payout_context( $payout_id );
			if ( $context ) {
				$this->send( 'affiliate_payout_created', $context['affiliate_email'], $context );
			}
		}
	}

	/** @param int $payout_id Payout ID. */
	public function payout_paid( $payout_id ) {
		$context = $this->payout_context( $payout_id );
		if ( $context ) {
			$this->send( 'affiliate_payout_paid', $context['affiliate_email'], $context );
		}
	}

	/** @param int $request_id Request ID. */
	public function payout_request_created( $request_id ) {
		$context = $this->payout_request_context( $request_id );
		if ( $context ) {
			$context['admin_url'] = add_query_arg(
				array(
					'page'   => 'affilio-payout-requests',
					'status' => 'requested',
				),
				admin_url( 'admin.php' )
			);
			$this->send( 'admin_payout_request', self::admin_email(), $context );
		}
	}

	/**
	 * @param int    $request_id Request ID.
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 */
	public function payout_request_status( $request_id, $new_status, $old_status = '' ) {
		$context = $this->payout_request_context( $request_id );
		if ( $context ) {
			$context['status'] = sanitize_key( $new_status );
			$this->send( 'affiliate_payout_request_status', $context['affiliate_email'], $context );
		}
	}

	/**
	 * Sends one configured notification.
	 *
	 * @param string              $key Template key.
	 * @param string              $recipient Recipient email.
	 * @param array<string,mixed> $context Placeholder values.
	 * @return bool
	 */
	private function send( $key, $recipient, $context ) {
		$template = Affilio_Email_Templates::get( $key );
		if ( ! $template || empty( $template['enabled'] ) || ! is_email( $recipient ) ) {
			return false;
		}
		$context = array_merge(
			array(
				'site_name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'admin_url'     => admin_url( 'admin.php?page=affilio-affiliates' ),
				'dashboard_url' => class_exists( 'Affilio_My_Account' ) ? Affilio_My_Account::get_preferred_dashboard_url() : home_url( '/' ),
				'reason'        => __( 'No reason was provided.', 'dreamax-affiliates' ),
				'reference'     => __( 'Not provided', 'dreamax-affiliates' ),
			),
			$context
		);
		$replace = array();
		foreach ( $context as $placeholder => $value ) {
			$replace[ '{' . $placeholder . '}' ] = is_scalar( $value ) ? (string) $value : '';
		}
		$subject = strtr( (string) $template['subject'], $replace );
		$body    = strtr( (string) $template['body'], $replace );
		$headers = array(
			sprintf(
				'From: %1$s <%2$s>',
				self::from_name(),
				self::from_email()
			),
		);

		return (bool) wp_mail( $recipient, $subject, $body, $headers );
	}

	/** @return array<string,string>|null */
	private function affiliate_context( $affiliate_id ) {
		$affiliate = affilio()->affiliates_db->get( absint( $affiliate_id ) );
		$user      = $affiliate ? get_userdata( $affiliate->user_id ) : null;
		if ( ! $affiliate || ! $user || ! is_email( $user->user_email ) ) {
			return null;
		}
		return array(
			'affiliate_name'  => $user->display_name,
			'affiliate_email' => $user->user_email,
			'status'          => (string) $affiliate->status,
			'reason'          => ! empty( $affiliate->status_reason ) ? (string) $affiliate->status_reason : __( 'No reason was provided.', 'dreamax-affiliates' ),
		);
	}

	/** @return array<string,string>|null */
	private function referral_context( $referral_id ) {
		$referral = affilio()->referrals_db->get( absint( $referral_id ) );
		$context  = $referral ? $this->affiliate_context( $referral->affiliate_id ) : null;
		if ( ! $referral || ! $context ) {
			return null;
		}
		$context['order_id']   = ! empty( $referral->manual_reference ) ? $referral->manual_reference : '#' . absint( $referral->order_id );
		$context['commission'] = number_format_i18n( (float) $referral->commission_amount, 2 );
		$context['currency']   = (string) $referral->currency;
		$context['status']     = (string) $referral->status;
		$context['reason']     = ! empty( $referral->status_reason ) ? (string) $referral->status_reason : __( 'No reason was provided.', 'dreamax-affiliates' );
		return $context;
	}

	/** @return array<string,string>|null */
	private function payout_context( $payout_id ) {
		$payout  = affilio()->payouts_db->get( absint( $payout_id ) );
		$context = $payout ? $this->affiliate_context( $payout->affiliate_id ) : null;
		if ( ! $payout || ! $context ) {
			return null;
		}
		$context['amount']    = number_format_i18n( (float) $payout->amount, 2 );
		$context['currency']  = (string) $payout->currency;
		$context['batch']     = (string) $payout->batch_key;
		$context['reference'] = ! empty( $payout->reference ) ? (string) $payout->reference : __( 'Not provided', 'dreamax-affiliates' );
		$context['status']    = (string) $payout->status;
		return $context;
	}

	/** @return array<string,string>|null */
	private function payout_request_context( $request_id ) {
		$request = affilio()->payout_requests_db->get( absint( $request_id ) );
		$context = $request ? $this->affiliate_context( $request->affiliate_id ) : null;
		if ( ! $request || ! $context ) {
			return null;
		}
		$context['amount']   = number_format_i18n( (float) $request->amount, 2 );
		$context['currency'] = (string) $request->currency;
		$context['status']   = (string) $request->status;
		$context['reason']   = ! empty( $request->admin_note ) ? (string) $request->admin_note : __( 'No reason was provided.', 'dreamax-affiliates' );
		return $context;
	}

	/**
	 * Returns the sender display name used only by Dreamax Affiliates emails.
	 *
	 * @return string
	 */
	private static function from_name() {
		$name = sanitize_text_field( (string) apply_filters( 'affilio_email_from_name', __( 'Dreamax Affiliates', 'dreamax-affiliates' ) ) );

		return '' !== $name ? $name : __( 'Dreamax Affiliates', 'dreamax-affiliates' );
	}

	/**
	 * Returns a same-domain sender address for Dreamax Affiliates emails.
	 *
	 * On dreamaxsoft.com this resolves to info@dreamaxsoft.com. Stores can
	 * override it with the affilio_email_from_address filter when required.
	 *
	 * @return string
	 */
	private static function from_email() {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./i', '', $host );
		$host = preg_replace( '/[^a-z0-9.-]/', '', $host );
		$email = $host ? 'info@' . $host : '';

		if ( ! is_email( $email ) ) {
			$email = sanitize_email( get_option( 'admin_email' ) );
		}

		$filtered = sanitize_email( (string) apply_filters( 'affilio_email_from_address', $email ) );

		return is_email( $filtered ) ? $filtered : $email;
	}

	/** @return string */
	private static function admin_email() {
		return sanitize_email( get_option( 'admin_email' ) );
	}
}
