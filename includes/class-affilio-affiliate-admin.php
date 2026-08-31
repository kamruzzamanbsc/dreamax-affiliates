<?php
/**
 * Complete administrator workflow for affiliate accounts.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Affiliate_Admin {

	/**
	 * Registers protected administrator actions.
	 */
	public function __construct() {
		add_action( 'admin_post_affilio_create_affiliate', array( $this, 'handle_create' ) );
		add_action( 'admin_post_affilio_save_affiliate', array( $this, 'handle_save' ) );
		add_action( 'admin_post_affilio_approve_affiliate', array( $this, 'handle_approve' ) );
		add_action( 'admin_post_affilio_reject_affiliate', array( $this, 'handle_reject' ) );
		add_action( 'admin_post_affilio_change_affiliate_status', array( $this, 'handle_status_action' ) );
		add_action( 'admin_post_affilio_remove_affiliate', array( $this, 'handle_remove' ) );
	}

	/**
	 * Creates an affiliate for an existing or newly created WordPress user.
	 *
	 * @return void
	 */
	public function handle_create() {
		$this->require_capability();
		check_admin_referer( 'affilio_create_affiliate' );

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'pending';
		$reason = isset( $_POST['status_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['status_reason'] ) ) : '';
		if ( ! \Affilio\Domain\Affiliate\AffiliateStatus::is_valid( $status ) ) {
			$status = 'pending';
		}
		if ( in_array( $status, array( 'rejected', 'suspended', 'banned' ), true ) && '' === trim( $reason ) ) {
			$this->redirect_with_notice( __( 'A reason is required when rejecting, suspending, or banning an affiliate.', 'dreamax-affiliates' ), 'error', 'add' );
		}

		$user_mode   = isset( $_POST['user_mode'] ) ? sanitize_key( wp_unslash( $_POST['user_mode'] ) ) : 'existing';
		$created_user = false;

		if ( 'new' === $user_mode ) {
			$email        = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
			$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';

			if ( ! is_email( $email ) || '' === $display_name ) {
				$this->redirect_with_notice( __( 'Enter a valid email address and display name.', 'dreamax-affiliates' ), 'error', 'add' );
			}
			if ( email_exists( $email ) ) {
				$this->redirect_with_notice( __( 'A WordPress account already uses that email. Choose Existing user instead.', 'dreamax-affiliates' ), 'error', 'add' );
			}

			$username = sanitize_user( current( explode( '@', $email ) ), true );
			if ( '' === $username ) {
				$username = 'affiliate';
			}
			$base = $username;
			$i    = 1;
			while ( username_exists( $username ) ) {
				$username = $base . $i;
				++$i;
			}

			$user_id = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_email'   => $email,
					'display_name' => $display_name,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				$this->redirect_with_notice( $user_id->get_error_message(), 'error', 'add' );
			}
			$created_user = true;
		} else {
			$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				$this->redirect_with_notice( __( 'Select a valid existing WordPress user.', 'dreamax-affiliates' ), 'error', 'add' );
			}
		}

		if ( affilio()->affiliates_db->get_by_user_id( $user_id ) ) {
			if ( $created_user ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id );
			}
			$this->redirect_with_notice( __( 'That WordPress user is already an affiliate.', 'dreamax-affiliates' ), 'error', 'add' );
		}

		$data = $this->sanitize_profile_request();
		$now  = current_time( 'mysql' );
		$data = array_merge(
			$data,
			array(
				'user_id'             => $user_id,
				'referral_code'       => $this->generate_unique_referral_code(),
				'status'              => $status,
				'status_reason'       => in_array( $status, array( 'rejected', 'suspended', 'banned' ), true ) ? $reason : '',
				'date_registered'     => $now,
				'date_approved'       => 'active' === $status ? $now : null,
				'date_status_changed' => $now,
				'updated_by'          => get_current_user_id(),
			)
		);

		$affiliate_id = affilio()->affiliates_db->insert( $data );
		if ( ! $affiliate_id ) {
			if ( $created_user ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id );
			}
			$this->redirect_with_notice( __( 'The affiliate could not be created.', 'dreamax-affiliates' ), 'error', 'add' );
		}

		if ( $created_user ) {
			wp_send_new_user_notifications( $user_id, 'user' );
		}

		affilio()->audit->record( 'affiliate', $affiliate_id, 'created', $reason, array( 'status' => $status, 'user_id' => $user_id ) );
		do_action( 'affilio_new_affiliate_registered', $affiliate_id, $status );
		$this->redirect_with_notice( __( 'Affiliate created successfully.', 'dreamax-affiliates' ), 'success', 'edit', $affiliate_id );
	}

	/**
	 * Saves profile, commission, payout, and status fields.
	 *
	 * @return void
	 */
	public function handle_save() {
		$this->require_capability();
		$affiliate_id = isset( $_POST['affiliate_id'] ) ? absint( $_POST['affiliate_id'] ) : 0;
		check_admin_referer( 'affilio_save_affiliate_' . $affiliate_id );

		$affiliate = affilio()->affiliates_db->get( $affiliate_id );
		if ( ! $affiliate ) {
			wp_die( esc_html__( 'Affiliate not found.', 'dreamax-affiliates' ) );
		}

		$data       = $this->sanitize_profile_request( true );
		$new_status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : $affiliate->status;
		$reason     = isset( $_POST['status_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['status_reason'] ) ) : '';

		if ( ! \Affilio\Domain\Affiliate\AffiliateStatus::is_valid( $new_status ) ) {
			$new_status = $affiliate->status;
		}

		$status_changed = $new_status !== $affiliate->status;
		if ( $status_changed && ! \Affilio\Domain\Affiliate\AffiliateStatus::can_transition( $affiliate->status, $new_status ) ) {
			$this->redirect_with_notice( __( 'That affiliate status transition is not allowed.', 'dreamax-affiliates' ), 'error', 'edit', $affiliate_id );
		}
		if ( $status_changed && in_array( $new_status, array( 'rejected', 'suspended', 'banned' ), true ) && '' === trim( $reason ) ) {
			$this->redirect_with_notice( __( 'A reason is required when rejecting, suspending, or banning an affiliate.', 'dreamax-affiliates' ), 'error', 'edit', $affiliate_id );
		}

		$data['updated_by'] = get_current_user_id();
		if ( $status_changed ) {
			$data['status']              = $new_status;
			$data['status_reason']       = $reason;
			$data['date_status_changed'] = current_time( 'mysql' );
			if ( 'active' === $new_status && empty( $affiliate->date_approved ) ) {
				$data['date_approved'] = current_time( 'mysql' );
			}
		}

		$updated = affilio()->affiliates_db->update( $affiliate_id, $data );
		if ( false === $updated ) {
			$this->redirect_with_notice( __( 'The affiliate could not be updated.', 'dreamax-affiliates' ), 'error', 'edit', $affiliate_id );
		}

		if ( $status_changed ) {
			affilio()->audit->record(
				'affiliate',
				$affiliate_id,
				'status_changed',
				$reason,
				array( 'from' => $affiliate->status, 'to' => $new_status )
			);
			do_action( 'affilio_affiliate_status_changed', $affiliate_id, $new_status, $reason );
		} else {
			affilio()->audit->record( 'affiliate', $affiliate_id, 'profile_updated' );
		}

		$this->redirect_with_notice( __( 'Affiliate updated successfully.', 'dreamax-affiliates' ), 'success', 'edit', $affiliate_id );
	}

	/**
	 * Legacy approve action.
	 *
	 * @return void
	 */
	public function handle_approve() {
		$this->handle_simple_status( 'active' );
	}

	/**
	 * Legacy reject action.
	 *
	 * @return void
	 */
	public function handle_reject() {
		$this->handle_simple_status( 'rejected' );
	}

	/**
	 * Handles a row-action status request.
	 *
	 * @return void
	 */
	public function handle_status_action() {
		// $status is read (already sanitize_key()'d) only to select and pass to
		// handle_simple_status(), which verifies a nonce scoped to this exact status value
		// before any write happens — see check_admin_referer() there.
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->handle_simple_status( $status );
	}

	/**
	 * Performs a capability- and nonce-protected single status transition.
	 *
	 * @param string $status Target status.
	 * @return void
	 */
	private function handle_simple_status( $status ) {
		$this->require_capability();

		$status       = sanitize_key( $status );
		$affiliate_id = isset( $_REQUEST['affiliate_id'] ) ? absint( $_REQUEST['affiliate_id'] ) : 0;
		check_admin_referer( 'affilio_affiliate_status_' . $status . '_' . $affiliate_id );

		$affiliate = affilio()->affiliates_db->get( $affiliate_id );
		if ( ! $affiliate || ! \Affilio\Domain\Affiliate\AffiliateStatus::is_valid( $status ) || ! \Affilio\Domain\Affiliate\AffiliateStatus::can_transition( $affiliate->status, $status ) ) {
			$this->redirect_with_notice( __( 'The affiliate status could not be changed.', 'dreamax-affiliates' ), 'error' );
		}

		$reason = isset( $_REQUEST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['reason'] ) ) : '';
		if ( in_array( $status, array( 'rejected', 'suspended', 'banned' ), true ) && '' === trim( $reason ) ) {
			$this->redirect_with_notice( __( 'Open the affiliate record and enter a reason before rejecting, suspending, or banning.', 'dreamax-affiliates' ), 'error', 'edit', $affiliate_id );
		}
		$data   = array(
			'status'              => $status,
			'status_reason'       => $reason,
			'date_status_changed' => current_time( 'mysql' ),
			'updated_by'          => get_current_user_id(),
		);
		if ( 'active' === $status && empty( $affiliate->date_approved ) ) {
			$data['date_approved'] = current_time( 'mysql' );
		}

		$updated = affilio()->affiliates_db->update( $affiliate_id, $data );
		if ( false === $updated ) {
			$this->redirect_with_notice( __( 'The affiliate status could not be changed.', 'dreamax-affiliates' ), 'error', 'edit', $affiliate_id );
		}
		affilio()->audit->record( 'affiliate', $affiliate_id, 'status_changed', $reason, array( 'from' => $affiliate->status, 'to' => $status ) );
		do_action( 'affilio_affiliate_status_changed', $affiliate_id, $status, $reason );
		$this->redirect_with_notice( __( 'Affiliate status updated.', 'dreamax-affiliates' ), 'success' );
	}

	/**
	 * Removes an affiliate row only when no financial history exists.
	 *
	 * @return void
	 */
	public function handle_remove() {
		$this->require_capability();
		$affiliate_id = isset( $_POST['affiliate_id'] ) ? absint( $_POST['affiliate_id'] ) : 0;
		check_admin_referer( 'affilio_remove_affiliate_' . $affiliate_id );

		$affiliate = affilio()->affiliates_db->get( $affiliate_id );
		if ( ! $affiliate ) {
			$this->redirect_with_notice( __( 'Affiliate not found.', 'dreamax-affiliates' ), 'error' );
		}
		if ( affilio()->affiliates_db->has_financial_history( $affiliate_id ) ) {
			$this->redirect_with_notice( __( 'This affiliate has referral or payout history and cannot be deleted. Ban the account instead.', 'dreamax-affiliates' ), 'error', 'edit', $affiliate_id );
		}

		$deleted = affilio()->affiliates_db->delete( $affiliate_id );
		if ( false === $deleted || 0 === (int) $deleted ) {
			$this->redirect_with_notice( __( 'The affiliate record could not be removed.', 'dreamax-affiliates' ), 'error', 'edit', $affiliate_id );
		}
		affilio()->audit->record( 'affiliate', $affiliate_id, 'removed', '', array( 'user_id' => $affiliate->user_id ) );
		$this->redirect_with_notice( __( 'Affiliate record removed. The WordPress user account was preserved.', 'dreamax-affiliates' ), 'success' );
	}

	/**
	 * Applies a bulk status change from the list table.
	 *
	 * @param int[]  $affiliate_ids Affiliate IDs.
	 * @param string $status        Target status.
	 * @param string $reason        Reason.
	 * @return array{updated:int,skipped:int}
	 */
	public function bulk_change_status( array $affiliate_ids, $status, $reason = '' ) {
		$this->require_capability();
		$status  = sanitize_key( $status );
		$reason  = sanitize_textarea_field( $reason );
		$result  = array( 'updated' => 0, 'skipped' => 0 );

		if ( ! \Affilio\Domain\Affiliate\AffiliateStatus::is_valid( $status ) ) {
			return $result;
		}

		$affiliate_ids = array_unique( array_filter( array_map( 'absint', $affiliate_ids ) ) );
		if ( in_array( $status, array( 'rejected', 'suspended', 'banned' ), true ) && '' === trim( $reason ) ) {
			$result['skipped'] = count( $affiliate_ids );
			return $result;
		}

		foreach ( $affiliate_ids as $affiliate_id ) {
			$affiliate = affilio()->affiliates_db->get( $affiliate_id );
			if ( ! $affiliate || ! \Affilio\Domain\Affiliate\AffiliateStatus::can_transition( $affiliate->status, $status ) ) {
				++$result['skipped'];
				continue;
			}

			$data = array(
				'status'              => $status,
				'status_reason'       => $reason,
				'date_status_changed' => current_time( 'mysql' ),
				'updated_by'          => get_current_user_id(),
			);
			if ( 'active' === $status && empty( $affiliate->date_approved ) ) {
				$data['date_approved'] = current_time( 'mysql' );
			}
			$updated = affilio()->affiliates_db->update( $affiliate_id, $data );
			if ( false === $updated ) {
				++$result['skipped'];
				continue;
			}
			affilio()->audit->record( 'affiliate', $affiliate_id, 'bulk_status_changed', $reason, array( 'from' => $affiliate->status, 'to' => $status ) );
			do_action( 'affilio_affiliate_status_changed', $affiliate_id, $status, $reason );
			++$result['updated'];
		}

		return $result;
	}

	/**
	 * Sanitizes shared profile fields.
	 *
	 * @param bool $include_commission Include commission fields.
	 * @return array
	 */
	private function sanitize_profile_request( $include_commission = true ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// This private helper is only ever called by handle_create() and handle_save() above,
		// both of which call check_admin_referer() before reaching this method — the linter
		// cannot see across that method-call boundary. sanitize_payout_method() (below) and the
		// float cast on commission_rate are sanitizers the linter does not recognize by name.
		$payout_method = isset( $_POST['payout_method'] ) ? affilio()->payouts->sanitize_payout_method( wp_unslash( $_POST['payout_method'] ) ) : 'paypal';
		$payout_email  = isset( $_POST['payout_email'] ) ? sanitize_email( wp_unslash( $_POST['payout_email'] ) ) : '';
		if ( ! is_email( $payout_email ) ) {
			$payout_email = '';
		}

		$data = array(
			'website_url'        => isset( $_POST['website_url'] ) ? esc_url_raw( wp_unslash( $_POST['website_url'] ) ) : '',
			'promotion_method'   => isset( $_POST['promotion_method'] ) ? sanitize_textarea_field( wp_unslash( $_POST['promotion_method'] ) ) : '',
			'social_profile'     => isset( $_POST['social_profile'] ) ? esc_url_raw( wp_unslash( $_POST['social_profile'] ) ) : '',
			'application_message'=> isset( $_POST['application_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['application_message'] ) ) : '',
			'payout_method'      => $payout_method,
			'payout_email'       => $payout_email,
			'payout_details'     => isset( $_POST['payout_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payout_details'] ) ) : '',
			'notes'              => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
		);

		if ( $include_commission ) {
			$type = isset( $_POST['commission_type'] ) ? sanitize_key( wp_unslash( $_POST['commission_type'] ) ) : 'inherit';
			if ( 'inherit' === $type ) {
				$data['commission_type'] = null;
				$data['commission_rate'] = null;
			} else {
				$data['commission_type'] = in_array( $type, array( 'percentage', 'flat' ), true ) ? $type : null;
				$data['commission_rate'] = isset( $_POST['commission_rate'] ) ? max( 0, (float) wp_unslash( $_POST['commission_rate'] ) ) : null;
			}
		}

		return $data;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Generates an unused referral code.
	 *
	 * @return string
	 */
	private function generate_unique_referral_code() {
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$code = strtolower( wp_generate_password( 10, false, false ) );
			if ( ! affilio()->affiliates_db->get_by_referral_code( $code ) ) {
				return $code;
			}
		}

		return 'aff' . wp_rand( 100000, 999999 ) . time();
	}

	/**
	 * Requires the program-management capability.
	 *
	 * @return void
	 */
	private function require_capability() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to manage affiliates.', 'dreamax-affiliates' ) );
		}
	}

	/**
	 * Stores a one-time notice and redirects back to the affiliate screen.
	 *
	 * @param string $message      Notice message.
	 * @param string $type         Notice type.
	 * @param string $view         Optional view.
	 * @param int    $affiliate_id Optional affiliate ID.
	 * @return never
	 */
	private function redirect_with_notice( $message, $type = 'success', $view = '', $affiliate_id = 0 ) {
		set_transient(
			'affilio_affiliate_admin_notice_' . get_current_user_id(),
			array( 'message' => sanitize_text_field( $message ), 'type' => sanitize_key( $type ) ),
			MINUTE_IN_SECONDS
		);
		$args = array( 'page' => 'affilio-affiliates' );
		if ( $view ) {
			$args['view'] = $view;
		}
		if ( $affiliate_id ) {
			$args['affiliate_id'] = absint( $affiliate_id );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
