<?php
/**
 * Administrator workflow for manually created and corrected referrals.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Manual_Referrals {

	/**
	 * Registers protected actions.
	 */
	public function __construct() {
		add_action( 'admin_post_affilio_create_manual_referral', array( $this, 'handle_create' ) );
		add_action( 'admin_post_affilio_update_referral', array( $this, 'handle_update' ) );
		add_action( 'admin_post_affilio_change_referral_status', array( $this, 'handle_status_change' ) );
	}

	/**
	 * Creates a manual referral.
	 *
	 * @return void
	 */
	public function handle_create() {
		$this->require_capability();
		check_admin_referer( 'affilio_create_manual_referral' );

		$data = $this->sanitize_request();

		if ( is_wp_error( $data ) ) {
			$this->redirect_with_notice( $data->get_error_message(), 'error', 'add' );
		}

		$affiliate = affilio()->affiliates_db->get( $data['affiliate_id'] );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			$this->redirect_with_notice( __( 'Select an eligible affiliate.', 'dreamax-affiliates' ), 'error', 'add' );
		}

		$order_id = $data['order_id'];
		if ( $order_id ) {
			if ( affilio()->referrals_db->get_by_order_id( $order_id ) ) {
				$this->redirect_with_notice( __( 'A referral already exists for that order ID.', 'dreamax-affiliates' ), 'error', 'add' );
			}
		} else {
			$order_id = $this->generate_manual_order_id();
		}

		$status        = $data['status'];
		$date_eligible = null;

		$manual_reference = $data['order_id'] ? null : 'MAN-' . gmdate( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 6, false, false ) );
		$referral_id      = affilio()->referrals_db->insert(
			array(
				'affiliate_id'      => $data['affiliate_id'],
				'order_id'          => $order_id,
				'visit_id'          => null,
				'payout_id'         => null,
				'amount'            => $data['amount'],
				'commission_amount' => $data['commission_amount'],
				'currency'          => $data['currency'],
				'status'            => $status,
				'source'            => 'manual',
				'coupon_code'       => '',
				'campaign'          => '',
				'manual_reference'  => $manual_reference,
				'description'       => $data['description'],
				'status_reason'     => $data['reason'],
				'date_eligible'     => $date_eligible,
				'created_by'        => get_current_user_id(),
				'updated_by'        => get_current_user_id(),
				'date_created'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( ! $referral_id ) {
			$this->redirect_with_notice( __( 'The manual referral could not be created.', 'dreamax-affiliates' ), 'error', 'add' );
		}

		affilio()->audit->record(
			'referral',
			$referral_id,
			'manual_created',
			$data['reason'],
			array(
				'affiliate_id' => $data['affiliate_id'],
				'amount'       => $data['amount'],
				'commission'   => $data['commission_amount'],
				'currency'     => $data['currency'],
				'status'       => $status,
			)
		);
		do_action( 'affilio_referral_created', $referral_id, $data['affiliate_id'], $data['order_id'] ? $data['order_id'] : 0 );
		$this->redirect_with_notice( __( 'Manual referral created.', 'dreamax-affiliates' ), 'success', 'edit', $referral_id );
	}

	/**
	 * Updates a referral that is not locked by payout processing.
	 *
	 * @return void
	 */
	public function handle_update() {
		$this->require_capability();
		$referral_id = isset( $_POST['referral_id'] ) ? absint( $_POST['referral_id'] ) : 0;
		check_admin_referer( 'affilio_update_referral_' . $referral_id );

		$referral = affilio()->referrals_db->get( $referral_id );
		if ( ! $referral ) {
			wp_die( esc_html__( 'Referral not found.', 'dreamax-affiliates' ) );
		}
		if ( in_array( $referral->status, array( 'processing', 'paid' ), true ) ) {
			$this->redirect_with_notice( __( 'Processing or paid referrals cannot be edited.', 'dreamax-affiliates' ), 'error', 'edit', $referral_id );
		}

		$data = $this->sanitize_request();
		if ( is_wp_error( $data ) ) {
			$this->redirect_with_notice( $data->get_error_message(), 'error', 'edit', $referral_id );
		}
		$affiliate = affilio()->affiliates_db->get( $data['affiliate_id'] );
		if ( ! $affiliate || ( (int) $data['affiliate_id'] !== (int) $referral->affiliate_id && 'active' !== $affiliate->status ) ) {
			$this->redirect_with_notice( __( 'Select an active affiliate. An existing referral may remain assigned if the account was later suspended.', 'dreamax-affiliates' ), 'error', 'edit', $referral_id );
		}
		if ( ! \Affilio\Domain\Referral\ReferralStatus::can_transition( $referral->status, $data['status'] ) ) {
			$this->redirect_with_notice( __( 'That referral status transition is not allowed.', 'dreamax-affiliates' ), 'error', 'edit', $referral_id );
		}
		if ( $data['order_id'] && (string) $data['order_id'] !== (string) $referral->order_id ) {
			$duplicate = affilio()->referrals_db->get_by_order_id( $data['order_id'] );
			if ( $duplicate && (int) $duplicate->id !== $referral_id ) {
				$this->redirect_with_notice( __( 'A referral already exists for that order ID.', 'dreamax-affiliates' ), 'error', 'edit', $referral_id );
			}
		}

		$update = array(
			'affiliate_id'      => $data['affiliate_id'],
			'amount'            => $data['amount'],
			'commission_amount' => $data['commission_amount'],
			'currency'          => $data['currency'],
			'status'            => $data['status'],
			'description'       => $data['description'],
			'status_reason'     => $data['reason'],
			'updated_by'        => get_current_user_id(),
		);

		if ( 'manual' === $referral->source && $data['order_id'] ) {
			$update['order_id'] = $data['order_id'];
		}
		$update['date_eligible'] = null;

		$updated = affilio()->referrals_db->update( $referral_id, $update );
		if ( false === $updated ) {
			$this->redirect_with_notice( __( 'The referral could not be updated.', 'dreamax-affiliates' ), 'error', 'edit', $referral_id );
		}

		affilio()->audit->record(
			'referral',
			$referral_id,
			'updated',
			$data['reason'],
			array(
				'from_status' => $referral->status,
				'to_status'   => $data['status'],
				'affiliate_id'=> $data['affiliate_id'],
				'commission'  => $data['commission_amount'],
			)
		);
		if ( $referral->status !== $data['status'] ) {
			do_action( 'affilio_referral_status_changed', $referral_id, $data['status'], $referral->status, $data['reason'] );
		}
		$this->redirect_with_notice( __( 'Referral updated.', 'dreamax-affiliates' ), 'success', 'edit', $referral_id );
	}

	/**
	 * Changes one referral status through a protected row action.
	 *
	 * @return void
	 */
	public function handle_status_change() {
		$this->require_capability();
		$referral_id = isset( $_REQUEST['referral_id'] ) ? absint( $_REQUEST['referral_id'] ) : 0;
		$status      = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		check_admin_referer( 'affilio_referral_status_' . $status . '_' . $referral_id );

		$referral = affilio()->referrals_db->get( $referral_id );
		if ( ! $referral || in_array( $referral->status, array( 'processing', 'paid' ), true ) || ! \Affilio\Domain\Referral\ReferralStatus::is_valid( $status ) || ! \Affilio\Domain\Referral\ReferralStatus::can_transition( $referral->status, $status ) ) {
			$this->redirect_with_notice( __( 'The referral status could not be changed.', 'dreamax-affiliates' ), 'error' );
		}

		$reason = isset( $_REQUEST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['reason'] ) ) : '';
		if ( 'cancelled' === $status && '' === trim( $reason ) ) {
			$this->redirect_with_notice( __( 'Open the referral and enter a reason before cancelling it.', 'dreamax-affiliates' ), 'error', 'edit', $referral_id );
		}

		$updated = affilio()->referrals_db->update(
			$referral_id,
			array(
				'status'        => $status,
				'status_reason' => $reason,
				'date_eligible' => null,
				'updated_by'    => get_current_user_id(),
			)
		);
		if ( false === $updated ) {
			$this->redirect_with_notice( __( 'The referral status could not be changed.', 'dreamax-affiliates' ), 'error', 'edit', $referral_id );
		}
		affilio()->audit->record( 'referral', $referral_id, 'status_changed', $reason, array( 'from' => $referral->status, 'to' => $status ) );
		do_action( 'affilio_referral_status_changed', $referral_id, $status, $referral->status, $reason );
		$this->redirect_with_notice( __( 'Referral status updated.', 'dreamax-affiliates' ), 'success' );
	}

	/**
	 * Sanitizes a create/edit request.
	 *
	 * @return array|WP_Error
	 */
	private function sanitize_request() {
		// Only called from handle_create() and handle_update() above, both of which call
		// check_admin_referer() before reaching this method. $amount/$commission_amount's
		// (float) casts are sanitizers the linter does not recognize by name.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$affiliate_id = isset( $_POST['affiliate_id'] ) ? absint( $_POST['affiliate_id'] ) : 0;
		$order_id_raw = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
		$order_id     = '';
		if ( '' !== trim( $order_id_raw ) ) {
			$order_id = \Affilio\Infrastructure\WordPress\UnsignedBigint::normalize( $order_id_raw );
			if ( '' === $order_id ) {
				return new WP_Error( 'affilio_invalid_order_id', __( 'Enter a valid positive WooCommerce order ID within the supported database range.', 'dreamax-affiliates' ) );
			}
		}
		$amount_raw     = isset( $_POST['amount'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : '';
		$commission_raw = isset( $_POST['commission_amount'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['commission_amount'] ) ) ) : '';
		$amount         = is_numeric( $amount_raw ) ? (float) $amount_raw : NAN;
		$commission     = is_numeric( $commission_raw ) ? (float) $commission_raw : NAN;
		$currency       = isset( $_POST['currency'] ) ? strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['currency'] ) ), 0, 10 ) ) : '';
		$status       = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'unpaid';
		$description  = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$reason       = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		$maximum_amount = 99999999999.9999;
		if (
			! $affiliate_id
			|| '' === $amount_raw
			|| '' === $commission_raw
			|| ! is_finite( $amount )
			|| ! is_finite( $commission )
			|| $amount < 0
			|| $commission <= 0
			|| $amount > $maximum_amount
			|| $commission > $maximum_amount
			|| ! preg_match( '/^[A-Z0-9]{3,10}$/', $currency )
		) {
			return new WP_Error( 'affilio_invalid_manual_referral', __( 'Enter a valid affiliate, currency, commissionable amount, and positive commission within the supported range.', 'dreamax-affiliates' ) );
		}
		if ( ! in_array( $status, array( 'pending', 'unpaid', 'cancelled' ), true ) ) {
			return new WP_Error( 'affilio_invalid_manual_status', __( 'Choose a valid editable referral status.', 'dreamax-affiliates' ) );
		}
		if ( 'cancelled' === $status && '' === trim( $reason ) ) {
			return new WP_Error( 'affilio_missing_referral_reason', __( 'A reason is required for a cancelled referral.', 'dreamax-affiliates' ) );
		}

		return array(
			'affiliate_id'      => $affiliate_id,
			'order_id'          => $order_id,
			'amount'            => max( 0, $amount ),
			'commission_amount' => max( 0, $commission ),
			'currency'          => $currency,
			'status'            => $status,
			'description'       => $description,
			'reason'            => $reason,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Generates a high-range internal order reference for a referral that is
	 * not attached to a WooCommerce order.
	 *
	 * @return string
	 */
	private function generate_manual_order_id() {
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$id = '8';
			for ( $digit = 0; $digit < 15; $digit++ ) {
				$id .= (string) wp_rand( 0, 9 );
			}

			if ( ! affilio()->referrals_db->get_by_order_id( $id ) ) {
				return $id;
			}
		}

		return '8999999' . str_pad( (string) wp_rand( 1, 999999999 ), 9, '0', STR_PAD_LEFT );
	}

	/**
	 * Requires the program-management capability.
	 *
	 * @return void
	 */
	private function require_capability() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to manage referrals.', 'dreamax-affiliates' ) );
		}
	}

	/**
	 * Redirects with a one-time notice.
	 *
	 * @param string $message     Notice message.
	 * @param string $type        Notice type.
	 * @param string $view        View slug.
	 * @param int    $referral_id Referral ID.
	 * @return never
	 */
	private function redirect_with_notice( $message, $type = 'success', $view = '', $referral_id = 0 ) {
		set_transient(
			'affilio_referral_admin_notice_' . get_current_user_id(),
			array( 'message' => sanitize_text_field( $message ), 'type' => sanitize_key( $type ) ),
			MINUTE_IN_SECONDS
		);
		$args = array( 'page' => 'affilio-referrals' );
		if ( $view ) {
			$args['view'] = $view;
		}
		if ( $referral_id ) {
			$args['referral_id'] = absint( $referral_id );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
