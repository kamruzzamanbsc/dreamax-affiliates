<?php
/**
 * Affiliate payout-request workflow.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Payout_Requests {

	/**
	 * Registers affiliate and administrator actions.
	 */
	public function __construct() {
		add_action( 'admin_post_affilio_request_payout', array( $this, 'handle_request' ) );
		add_action( 'admin_post_affilio_approve_payout_request', array( $this, 'handle_approve' ) );
		add_action( 'admin_post_affilio_reject_payout_request', array( $this, 'handle_reject' ) );
		add_action( 'admin_post_affilio_cancel_payout_request', array( $this, 'handle_cancel' ) );
	}

	/**
	 * Returns unpaid totals by currency for an affiliate.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return array<string,float>
	 */
	public function get_available_totals( $affiliate_id ) {
		return affilio()->referrals_db->get_unpaid_totals_by_currency( absint( $affiliate_id ) );
	}

	/**
	 * Handles an affiliate request.
	 *
	 * @return void
	 */
	public function handle_request() {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		check_admin_referer( 'affilio_request_payout' );
		if ( ! get_option( 'affilio_enable_payout_requests', true ) ) {
			$this->redirect_front( __( 'Payout requests are currently disabled.', 'dreamax-affiliates' ), 'error' );
		}

		$affiliate = affilio()->affiliates_db->get_by_user_id( get_current_user_id() );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			$this->redirect_front( __( 'Only active affiliates can request a payout.', 'dreamax-affiliates' ), 'error' );
		}

		$currency = isset( $_POST['currency'] ) ? strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['currency'] ) ), 0, 10 ) ) : '';
		$note     = isset( $_POST['request_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['request_note'] ) ) : '';
		$totals   = $this->get_available_totals( $affiliate->id );
		$amount   = isset( $totals[ $currency ] ) ? (float) $totals[ $currency ] : 0;
		$minimum  = max( 0, (float) get_option( 'affilio_minimum_payout_threshold', 50 ) );

		if ( '' === $currency || $amount <= 0 || $amount + 0.0001 < $minimum ) {
			$this->redirect_front(
				sprintf(
					/* translators: 1: minimum amount, 2: currency. */
					__( 'Your available balance must be at least %1$s %2$s before requesting payment.', 'dreamax-affiliates' ),
					number_format_i18n( $minimum, 2 ),
					$currency
				),
				'error'
			);
		}
		if ( affilio()->payout_requests_db->get_open( $affiliate->id, $currency ) ) {
			$this->redirect_front( __( 'You already have an open payout request for this currency.', 'dreamax-affiliates' ), 'error' );
		}

		$method      = affilio()->payouts->sanitize_payout_method( $affiliate->payout_method ?? 'paypal' );
		$destination = $this->get_destination( $affiliate, $method );
		if ( '' === $destination ) {
			if ( 'bank_transfer' === $method ) {
				$this->redirect_front( __( 'Add valid bank transfer account details in Profile & Settings before requesting payment.', 'dreamax-affiliates' ), 'error' );
			}
			$this->redirect_front( __( 'Save valid payout details before requesting payment.', 'dreamax-affiliates' ), 'error' );
		}

		$request_id = affilio()->payout_requests_db->insert(
			array(
				'affiliate_id'       => (int) $affiliate->id,
				'amount'             => round( $amount, 4 ),
				'currency'           => $currency,
				'status'             => 'requested',
				'open_key'           => affilio()->payout_requests_db->get_open_key( $affiliate->id, $currency ),
				'payment_method'     => $method,
				'payment_destination'=> $destination,
				'request_note'       => $note,
				'created_by'         => get_current_user_id(),
				'date_created'       => current_time( 'mysql' ),
			),
			array( '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $request_id ) {
			$this->redirect_front( __( 'The payout request could not be created. Please try again.', 'dreamax-affiliates' ), 'error' );
		}

		affilio()->audit->record( 'payout_request', $request_id, 'requested', $note, array( 'affiliate_id' => $affiliate->id, 'amount' => $amount, 'currency' => $currency ) );
		do_action( 'affilio_payout_request_created', $request_id );
		$this->redirect_front( __( 'Your payout request was submitted.', 'dreamax-affiliates' ), 'success' );
	}

	/**
	 * Approves a request by creating a locked payout batch from currently
	 * available referrals.
	 *
	 * @return void
	 */
	public function handle_approve() {
		$this->require_capability();
		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( 'affilio_approve_payout_request_' . $request_id );
		$request = affilio()->payout_requests_db->get( $request_id );
		if ( ! $request || 'requested' !== $request->status ) {
			$this->redirect_admin( __( 'That payout request is no longer open.', 'dreamax-affiliates' ), 'error' );
		}

		$referrals = affilio()->referrals_db->get_unpaid_for_affiliate_currency( $request->affiliate_id, $request->currency );
		$ids       = wp_list_pluck( $referrals, 'id' );
		$current_total = array_sum( array_map( static function ( $row ) { return (float) $row->commission_amount; }, $referrals ) );
		$minimum   = max( 0, (float) get_option( 'affilio_minimum_payout_threshold', 50 ) );
		if ( empty( $ids ) || $current_total + 0.0001 < $minimum ) {
			$this->redirect_admin( __( 'The affiliate no longer has enough eligible unpaid commission for this request.', 'dreamax-affiliates' ), 'error' );
		}

		$result = affilio()->payouts->create_batch( $ids, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->redirect_admin( $result->get_error_message(), 'error' );
		}
		$payout_id = ! empty( $result['payout_ids'][0] ) ? absint( $result['payout_ids'][0] ) : 0;
		$note      = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';

		$updated = affilio()->payout_requests_db->update_if_status(
			$request_id,
			'requested',
			array(
				'payout_id'     => $payout_id,
				'amount'        => round( $current_total, 4 ),
				'status'        => 'approved',
				'open_key'      => null,
				'admin_note'    => $note,
				'reviewed_by'   => get_current_user_id(),
				'date_reviewed' => current_time( 'mysql' ),
			),
			array( '%d', '%f', '%s', '%s', '%s', '%d', '%s' )
		);
		if ( 1 !== (int) $updated ) {
			if ( $payout_id ) {
				affilio()->payouts->cancel_payout( $payout_id );
			}
			$this->redirect_admin( __( 'The request changed while it was being approved. The new payout was cancelled.', 'dreamax-affiliates' ), 'error' );
		}

		affilio()->audit->record( 'payout_request', $request_id, 'approved', $note, array( 'payout_id' => $payout_id, 'amount' => $current_total, 'currency' => $request->currency ) );
		do_action( 'affilio_payout_request_status_changed', $request_id, 'approved', 'requested' );
		$this->redirect_admin( __( 'Payout request approved and payout batch created.', 'dreamax-affiliates' ), 'success' );
	}

	/**
	 * Rejects an open request.
	 *
	 * @return void
	 */
	public function handle_reject() {
		$this->review_request( 'rejected' );
	}

	/**
	 * Cancels an affiliate's own open request.
	 *
	 * @return void
	 */
	public function handle_cancel() {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( 'affilio_cancel_payout_request_' . $request_id );
		$request   = affilio()->payout_requests_db->get( $request_id );
		$affiliate = affilio()->affiliates_db->get_by_user_id( get_current_user_id() );
		if ( ! $request || ! $affiliate || (int) $request->affiliate_id !== (int) $affiliate->id || 'requested' !== $request->status ) {
			$this->redirect_front( __( 'That payout request cannot be cancelled.', 'dreamax-affiliates' ), 'error' );
		}

		$updated = affilio()->payout_requests_db->update_if_status(
			$request_id,
			'requested',
			array(
				'status'        => 'cancelled',
				'open_key'      => null,
				'date_reviewed' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);
		if ( 1 !== (int) $updated ) {
			$this->redirect_front( __( 'That payout request is no longer open.', 'dreamax-affiliates' ), 'error' );
		}
		affilio()->audit->record( 'payout_request', $request_id, 'cancelled' );
		do_action( 'affilio_payout_request_status_changed', $request_id, 'cancelled', 'requested' );
		$this->redirect_front( __( 'Payout request cancelled.', 'dreamax-affiliates' ), 'success' );
	}

	/**
	 * Shared reject workflow.
	 *
	 * @param string $status Target status.
	 * @return void
	 */
	private function review_request( $status ) {
		$this->require_capability();
		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( 'affilio_' . $status . '_payout_request_' . $request_id );
		$request = affilio()->payout_requests_db->get( $request_id );
		if ( ! $request || 'requested' !== $request->status ) {
			$this->redirect_admin( __( 'That payout request is no longer open.', 'dreamax-affiliates' ), 'error' );
		}
		$note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';
		if ( '' === trim( $note ) ) {
			$this->redirect_admin( __( 'Enter a reason before rejecting a payout request.', 'dreamax-affiliates' ), 'error' );
		}
		$updated = affilio()->payout_requests_db->update_if_status(
			$request_id,
			'requested',
			array(
				'status'        => $status,
				'open_key'      => null,
				'admin_note'    => $note,
				'reviewed_by'   => get_current_user_id(),
				'date_reviewed' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		if ( 1 !== (int) $updated ) {
			$this->redirect_admin( __( 'That payout request changed before it could be reviewed.', 'dreamax-affiliates' ), 'error' );
		}
		affilio()->audit->record( 'payout_request', $request_id, $status, $note );
		do_action( 'affilio_payout_request_status_changed', $request_id, $status, 'requested' );
		$this->redirect_admin( __( 'Payout request rejected.', 'dreamax-affiliates' ), 'success' );
	}

	/**
	 * Returns the stored payout destination for a profile.
	 *
	 * @param object $affiliate Affiliate row.
	 * @param string $method    Payment method.
	 * @return string
	 */
	public function get_destination( $affiliate, $method ) {
		if ( 'paypal' === $method ) {
			return is_email( $affiliate->payout_email ) ? sanitize_email( $affiliate->payout_email ) : '';
		}

		$details = trim( sanitize_textarea_field( $affiliate->payout_details ?? '' ) );

		if ( 'bank_transfer' === $method ) {
			$normalized_details = strtolower( preg_replace( '/\s+/', ' ', $details ) );
			$contact_email      = is_email( $affiliate->payout_email ?? '' ) ? strtolower( sanitize_email( $affiliate->payout_email ) ) : '';

			$placeholder_values = array(
				'payout account details goes here',
				'bank account details goes here',
				'account details goes here',
				'enter payout account details',
				'enter bank account details',
			);

			if (
				'' === $details
				|| in_array( $normalized_details, $placeholder_values, true )
				|| ( '' !== $contact_email && is_email( $details ) && strtolower( $details ) === $contact_email )
			) {
				return '';
			}
		}

		return $details;
	}

	/**
	 * @return void
	 */
	private function require_capability() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to manage payout requests.', 'dreamax-affiliates' ) );
		}
	}

	/**
	 * Redirects to the affiliate dashboard with a notice.
	 *
	 * @param string $message Message.
	 * @param string $type    Type.
	 * @return never
	 */
	private function redirect_front( $message, $type ) {
		set_transient( 'affilio_payout_request_notice_' . get_current_user_id(), array( 'message' => sanitize_text_field( $message ), 'type' => sanitize_key( $type ) ), MINUTE_IN_SECONDS );
		$url = class_exists( 'Affilio_My_Account' ) ? Affilio_My_Account::get_preferred_dashboard_url() : home_url( '/' );
		$url = preg_replace( '/#.*$/', '', (string) $url );
		wp_safe_redirect( $url . '#affiliate-payouts' );
		exit;
	}

	/**
	 * Redirects to the admin payout-request page with a notice.
	 *
	 * @param string $message Message.
	 * @param string $type    Type.
	 * @return never
	 */
	private function redirect_admin( $message, $type ) {
		set_transient( 'affilio_payout_request_admin_notice_' . get_current_user_id(), array( 'message' => sanitize_text_field( $message ), 'type' => sanitize_key( $type ) ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=affilio-payout-requests' ) );
		exit;
	}
}
