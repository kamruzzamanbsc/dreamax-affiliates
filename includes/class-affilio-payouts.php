<?php
/**
 * Manual payout workflow: payout profiles, payout batch creation,
 * referral locking, payout completion, cancellation, and notices.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// This class runs explicit transactions and row-locking (BEGIN/COMMIT/ROLLBACK, SELECT ... FOR
// UPDATE) directly against Dreamax Affiliates' own custom tables to keep a payout batch's totals correct
// under concurrent admin actions; WordPress core object-cache groups do not apply to this table
// or to transaction-control statements. WordPress.DB.DirectDatabaseQuery.DirectQuery is already
// justified per-line below (existing phpcs:ignore comments) for the same reason.
class Affilio_Payouts {

	/**
	 * User-specific admin notice transient prefix.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT_PREFIX = 'affilio_payout_notice_';
	const MAX_BATCH_REFERRALS     = 5000;

	public function __construct() {
		add_action( 'admin_init', array( $this, 'handle_referral_bulk_action' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );

		add_action( 'admin_post_affilio_mark_payout_paid', array( $this, 'handle_mark_paid' ) );
		add_action( 'admin_post_affilio_cancel_payout', array( $this, 'handle_cancel_payout' ) );
		add_action( 'admin_post_affilio_mark_payout_failed', array( $this, 'handle_mark_failed' ) );
		add_action( 'admin_post_affilio_save_payout_profile', array( $this, 'handle_frontend_profile_save' ) );
		add_action( 'admin_post_affilio_admin_save_payout_profile', array( $this, 'handle_admin_profile_save' ) );
	}

	/**
	 * Supported payout methods. Developers may add methods through the filter.
	 *
	 * @return array<string,string>
	 */
	public function get_payout_methods() {
		$methods = array(
			'paypal'        => __( 'PayPal', 'dreamax-affiliates' ),
			'bank_transfer' => __( 'Bank transfer', 'dreamax-affiliates' ),
			'other'         => __( 'Other / manual', 'dreamax-affiliates' ),
		);

		/**
		 * Filters available payout methods.
		 *
		 * @param array<string,string> $methods Method slug => label.
		 */
		return (array) apply_filters( 'affilio_payout_methods', $methods );
	}

	/**
	 * Creates one batch from selected unpaid referrals. Referrals are grouped
	 * by affiliate and currency so currencies are never mixed in one payout.
	 *
	 * @param int[] $referral_ids Referral IDs.
	 * @param int   $created_by   WordPress user ID that created the batch.
	 * @return array|WP_Error
	 */
	public function create_batch( array $referral_ids, $created_by = 0 ) {
		global $wpdb;

		$referral_ids = array_values( array_unique( array_filter( array_map( 'absint', $referral_ids ) ) ) );

		if ( empty( $referral_ids ) ) {
			return new WP_Error( 'affilio_no_referrals', __( 'Select at least one unpaid referral.', 'dreamax-affiliates' ) );
		}

		if ( count( $referral_ids ) > self::MAX_BATCH_REFERRALS ) {
			return new WP_Error( 'affilio_too_many_referrals', __( 'Create payout batches with no more than 5,000 referrals at a time.', 'dreamax-affiliates' ) );
		}

		$referrals = affilio()->referrals_db->get_by_ids( $referral_ids );

		if ( count( $referrals ) !== count( $referral_ids ) ) {
			return new WP_Error( 'affilio_referrals_changed', __( 'One or more selected referrals no longer exist. Refresh the page and try again.', 'dreamax-affiliates' ) );
		}

		$groups = array();

		foreach ( $referrals as $referral ) {
			if ( 'unpaid' !== $referral->status || ! empty( $referral->payout_id ) ) {
				return new WP_Error( 'affilio_referral_unavailable', __( 'One or more selected referrals are already paid, cancelled, or included in another payout.', 'dreamax-affiliates' ) );
			}

			if ( (float) $referral->commission_amount <= 0 ) {
				return new WP_Error( 'affilio_invalid_commission', __( 'Payout batches can only include referrals with a positive commission amount.', 'dreamax-affiliates' ) );
			}

			$affiliate = affilio()->affiliates_db->get( $referral->affiliate_id );

			if ( ! $affiliate ) {
				return new WP_Error( 'affilio_missing_affiliate', __( 'A selected referral is linked to a missing affiliate.', 'dreamax-affiliates' ) );
			}

			$currency = $referral->currency ? strtoupper( sanitize_key( $referral->currency ) ) : '';
			$key      = (int) $referral->affiliate_id . '|' . $currency;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'affiliate'   => $affiliate,
					'currency'    => $currency,
					'amount'      => 0.0,
					'referral_ids' => array(),
				);
			}

			$groups[ $key ]['amount']        += (float) $referral->commission_amount;
			$groups[ $key ]['referral_ids'][] = (int) $referral->id;
		}

		$batch_key  = $this->generate_batch_key();
		$payout_ids = array();
		$now        = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		try {
			foreach ( $groups as $group ) {
				$affiliate   = $group['affiliate'];
				$method      = $this->sanitize_payout_method( $affiliate->payout_method ?? 'paypal' );
				$destination = $this->get_affiliate_destination( $affiliate, $method );

				if ( '' === $destination ) {
					$user = get_userdata( $affiliate->user_id );
					$name = $user ? $user->display_name : '#' . (int) $affiliate->id;
					throw new RuntimeException(
						sprintf(
							/* translators: %s: affiliate name. */
							__( 'Payout details are missing for %s. Update the affiliate profile before creating this batch.', 'dreamax-affiliates' ),
							$name
						)
					);
				}

				$payout_id = affilio()->payouts_db->insert(
					array(
						'batch_key'            => $batch_key,
						'affiliate_id'          => (int) $affiliate->id,
						'amount'                => round( (float) $group['amount'], 4 ),
						'currency'              => $group['currency'],
						'payment_method'        => $method,
						'payment_destination'   => $destination,
						'status'                => 'processing',
						'created_by'            => absint( $created_by ),
						'date_created'          => $now,
					),
					array( '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%d', '%s' )
				);

				if ( ! $payout_id ) {
					throw new RuntimeException( __( 'The payout record could not be created.', 'dreamax-affiliates' ) );
				}

				$payout_ids[] = (int) $payout_id;
				$assigned     = affilio()->referrals_db->assign_to_payout( $group['referral_ids'], $payout_id );

				if ( count( $group['referral_ids'] ) !== (int) $assigned ) {
					throw new RuntimeException( __( 'The selected referrals changed while the payout was being created. No changes were saved.', 'dreamax-affiliates' ) );
				}

			}

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			// Best-effort cleanup for hosts using non-transactional table engines.
			foreach ( $payout_ids as $payout_id ) {
				affilio()->referrals_db->release_from_payout( $payout_id );
				affilio()->payouts_db->delete( $payout_id );
			}

			return new WP_Error( 'affilio_payout_failed', $exception->getMessage() );
		}

		/**
		 * Fires after a payout batch is created and referrals are locked.
		 *
		 * @param string $batch_key   Batch key.
		 * @param int[]  $payout_ids  Payout row IDs.
		 * @param int[]  $referral_ids Referral IDs.
		 */
		do_action( 'affilio_payout_batch_created', $batch_key, $payout_ids, $referral_ids );

		return array(
			'batch_key'  => $batch_key,
			'payout_ids' => $payout_ids,
		);
	}

	/**
	 * Marks a processing payout as paid and closes all linked referrals.
	 *
	 * @param int    $payout_id Payout ID.
	 * @param string $reference External payment reference.
	 * @param string $notes     Internal notes.
	 * @return true|WP_Error
	 */
	public function mark_paid( $payout_id, $reference = '', $notes = '' ) {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$payout = affilio()->payouts_db->get_for_update( absint( $payout_id ) );

		if ( ! $payout ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_invalid_payout', __( 'Invalid payout.', 'dreamax-affiliates' ) );
		}

		if ( 'processing' !== $payout->status ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_payout_not_open', __( 'Only processing payouts can be marked as paid.', 'dreamax-affiliates' ) );
		}

		$linked = affilio()->referrals_db->get_processing_by_payout_for_update( $payout->id );

		if ( empty( $linked ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_empty_payout', __( 'This payout has no linked referrals.', 'dreamax-affiliates' ) );
		}

		$final_amount = round(
			array_sum(
				array_map(
					static function ( $referral ) {
						return max( 0, (float) $referral->commission_amount );
					},
					$linked
				)
			),
			4
		);

		if ( $final_amount <= 0 ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_empty_payout', __( 'This payout has no positive linked commission.', 'dreamax-affiliates' ) );
		}

		$paid_at = current_time( 'mysql' );
		$updated_payout = affilio()->payouts_db->update_if_status(
			$payout->id,
			'processing',
			array(
				'amount'    => $final_amount,
				'status'    => 'paid',
				'reference' => sanitize_text_field( $reference ),
				'notes'     => sanitize_textarea_field( $notes ),
				'date_paid' => $paid_at,
			),
			array( '%f', '%s', '%s', '%s', '%s' )
		);

		if ( 1 !== (int) $updated_payout ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_payout_changed', __( 'This payout changed before it could be completed. Refresh and try again.', 'dreamax-affiliates' ) );
		}

		$updated_referrals = affilio()->referrals_db->mark_paid_by_payout( $payout->id, $paid_at );

		if ( count( $linked ) !== (int) $updated_referrals ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			// Best-effort recovery for non-transactional table engines.
			affilio()->payouts_db->update(
				$payout->id,
				array(
					'amount'    => (float) $payout->amount,
					'status'    => 'processing',
					'reference' => $payout->reference,
					'notes'     => $payout->notes,
					'date_paid' => $payout->date_paid,
				),
				array( '%f', '%s', '%s', '%s', '%s' )
			);
			affilio()->referrals_db->restore_processing_by_payout( $payout->id );

			return new WP_Error( 'affilio_payout_update_failed', __( 'The payout could not be completed. No changes were saved.', 'dreamax-affiliates' ) );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		affilio()->audit->record( 'payout', (int) $payout->id, 'paid', '', array( 'amount' => $final_amount, 'currency' => $payout->currency ), 0 );
		do_action( 'affilio_payout_paid', (int) $payout->id );

		return true;
	}

	/**
	 * Cancels a processing payout and returns its referrals to unpaid status.
	 *
	 * @param int $payout_id Payout ID.
	 * @return true|WP_Error
	 */
	public function cancel_payout( $payout_id ) {
		return $this->close_processing_payout( $payout_id, 'cancelled', '' );
	}

	/**
	 * Marks a processing payout as failed and releases referrals for retry.
	 *
	 * @param int    $payout_id Payout ID.
	 * @param string $notes     Failure reason.
	 * @return true|WP_Error
	 */
	public function mark_failed( $payout_id, $notes ) {
		$notes = sanitize_textarea_field( $notes );
		if ( '' === trim( $notes ) ) {
			return new WP_Error( 'affilio_failure_reason_required', __( 'Enter a failure reason before marking a payout as failed.', 'dreamax-affiliates' ) );
		}

		return $this->close_processing_payout( $payout_id, 'failed', $notes );
	}

	/**
	 * Closes an open payout and atomically releases its referrals.
	 *
	 * @param int    $payout_id Payout ID.
	 * @param string $status    cancelled or failed.
	 * @param string $notes     Optional notes.
	 * @return true|WP_Error
	 */
	private function close_processing_payout( $payout_id, $status, $notes ) {
		global $wpdb;

		$status = sanitize_key( $status );
		if ( ! in_array( $status, array( 'cancelled', 'failed' ), true ) ) {
			return new WP_Error( 'affilio_invalid_payout_status', __( 'Invalid payout status transition.', 'dreamax-affiliates' ) );
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$payout = affilio()->payouts_db->get_for_update( absint( $payout_id ) );

		if ( ! $payout ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_invalid_payout', __( 'Invalid payout.', 'dreamax-affiliates' ) );
		}

		if ( 'processing' !== $payout->status ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_payout_not_open', __( 'Only processing payouts can be closed.', 'dreamax-affiliates' ) );
		}

		$linked  = affilio()->referrals_db->get_processing_by_payout_for_update( $payout->id );
		$data    = array( 'status' => $status );
		$formats = array( '%s' );
		if ( '' !== $notes ) {
			$data['notes'] = $notes;
			$formats[]     = '%s';
		}
		$updated = affilio()->payouts_db->update_if_status( $payout->id, 'processing', $data, $formats );

		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_payout_changed', __( 'This payout changed before it could be closed. Refresh and try again.', 'dreamax-affiliates' ) );
		}

		$released = affilio()->referrals_db->release_from_payout( $payout->id );
		if ( false === $released || count( $linked ) !== (int) $released ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			// Best-effort recovery for non-transactional table engines.
			if ( ! empty( $linked ) ) {
				affilio()->referrals_db->assign_to_payout( wp_list_pluck( $linked, 'id' ), $payout->id );
			}
			affilio()->payouts_db->update(
				$payout->id,
				array(
					'status' => 'processing',
					'notes'  => $payout->notes,
				),
				array( '%s', '%s' )
			);
			return new WP_Error( 'affilio_close_failed', __( 'The payout could not be closed. No changes were saved.', 'dreamax-affiliates' ) );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		affilio()->audit->record( 'payout', (int) $payout->id, $status, $notes, array( 'released_referrals' => count( $linked ) ), 0 );
		// Both branches of this ternary are fixed, already-prefixed literal hook names
		// ('affilio_payout_failed' / 'affilio_payout_cancelled'); the linter's static parser
		// cannot evaluate the ternary and mis-reports a fragment of the expression as the hook name.
		do_action( 'failed' === $status ? 'affilio_payout_failed' : 'affilio_payout_cancelled', (int) $payout->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return true;
	}

	/**
	 * Adjusts a referral inside an open payout after a partial refund and
	 * recalculates the payout total under a row lock.
	 *
	 * @param int   $referral_id Referral ID.
	 * @param float $amount      New commissionable amount.
	 * @param float $commission  New commission amount.
	 * @return true|WP_Error
	 */
	public function adjust_processing_referral( $referral_id, $amount, $commission ) {
		global $wpdb;

		$referral = affilio()->referrals_db->get( absint( $referral_id ) );
		if ( ! $referral || empty( $referral->payout_id ) || 'processing' !== $referral->status ) {
			return new WP_Error( 'affilio_referral_not_processing', __( 'The referral is not inside an open payout.', 'dreamax-affiliates' ) );
		}

		$amount     = max( 0, (float) $amount );
		$commission = max( 0, (float) $commission );
		if ( $amount <= 0 || $commission <= 0 ) {
			$this->handle_referral_cancellation( $referral->id );
			return true;
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$payout = affilio()->payouts_db->get_for_update( $referral->payout_id );

		if ( ! $payout || 'processing' !== $payout->status ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_payout_not_open', __( 'The linked payout is no longer open.', 'dreamax-affiliates' ) );
		}

		$updated = affilio()->referrals_db->adjust_processing_referral(
			$referral->id,
			$payout->id,
			$amount,
			$commission
		);

		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'affilio_referral_changed', __( 'The referral changed before it could be adjusted.', 'dreamax-affiliates' ) );
		}

		$new_total = affilio()->referrals_db->get_total_for_payout( $payout->id );

		// A flat commission may leave the payout total unchanged even though
		// the referral's commissionable amount changed. In that case no payout
		// row write is required, and $wpdb->update() returning zero must not be
		// mistaken for a failure.
		$payout_updated = round( (float) $payout->amount, 4 ) === round( $new_total, 4 )
			? 1
			: affilio()->payouts_db->update_if_status(
				$payout->id,
				'processing',
				array( 'amount' => $new_total ),
				array( '%f' )
			);

		if ( 1 !== (int) $payout_updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			// Best-effort recovery for a non-transactional database engine.
			affilio()->referrals_db->adjust_processing_referral(
				$referral->id,
				$payout->id,
				$referral->amount,
				$referral->commission_amount
			);
			return new WP_Error( 'affilio_payout_adjust_failed', __( 'The payout total could not be recalculated.', 'dreamax-affiliates' ) );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		do_action( 'affilio_processing_referral_adjusted', (int) $referral->id, (int) $payout->id, $amount, $commission );

		return true;
	}

	/**
	 * Removes a refunded/cancelled referral from an open payout and
	 * recalculates the payout total.
	 *
	 * @param int $referral_id Referral ID.
	 * @return void
	 */
	public function handle_referral_cancellation( $referral_id ) {
		global $wpdb;

		$referral = affilio()->referrals_db->get( absint( $referral_id ) );

		if ( ! $referral || empty( $referral->payout_id ) ) {
			return;
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$payout = affilio()->payouts_db->get_for_update( $referral->payout_id );

		if ( ! $payout || 'processing' !== $payout->status ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return;
		}

		$updated = affilio()->referrals_db->cancel_processing_referral( $referral->id, $payout->id );

		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return;
		}

		$remaining = affilio()->referrals_db->get_total_for_payout( $payout->id );
		$data      = $remaining <= 0 ? array( 'status' => 'cancelled', 'amount' => 0 ) : array( 'amount' => $remaining );
		$formats   = $remaining <= 0 ? array( '%s', '%f' ) : array( '%f' );
		$payout_updated = affilio()->payouts_db->update_if_status( $payout->id, 'processing', $data, $formats );

		if ( 1 !== (int) $payout_updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			// Best-effort recovery for non-transactional table engines.
			affilio()->referrals_db->restore_cancelled_to_processing( $referral->id, $payout->id );
			return;
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Processes the Referrals list-table bulk action before admin output.
	 *
	 * @return void
	 */
	public function handle_referral_bulk_action() {
		if ( ! is_admin() || 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return;
		}

		$page = isset( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : '';

		if ( 'affilio-referrals' !== $page ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';
		}

		if ( 'create_payout_batch' !== $action ) {
			return;
		}

		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to manage payouts.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bulk-referrals' );

		// $selected is an array of referral row IDs; create_batch() below absint()s each one
		// before use (see its definition) rather than trusting the raw cast here.
		$selected = isset( $_POST['referral'] ) ? (array) wp_unslash( $_POST['referral'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result   = $this->create_batch( $selected, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->set_admin_notice( $result->get_error_message(), 'error' );
			$this->redirect( admin_url( 'admin.php?page=affilio-referrals' ) );
		}

		$this->set_admin_notice(
			sprintf(
				/* translators: %s: payout batch key. */
				__( 'Payout batch %s was created. Review each payout and mark it paid after sending the funds.', 'dreamax-affiliates' ),
				$result['batch_key']
			),
			'success'
		);

		$this->redirect( admin_url( 'admin.php?page=affilio-payouts' ) );
	}

	/**
	 * Admin-post handler for completing a payout.
	 *
	 * @return void
	 */
	public function handle_mark_paid() {
		$this->require_admin_capability();
		$payout_id = isset( $_POST['payout_id'] ) ? absint( $_POST['payout_id'] ) : 0;
		check_admin_referer( 'affilio_mark_payout_paid_' . $payout_id );

		$reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
		$notes     = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$result    = $this->mark_paid( $payout_id, $reference, $notes );

		$this->set_admin_notice(
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Payout marked as paid and linked referrals updated.', 'dreamax-affiliates' ),
			is_wp_error( $result ) ? 'error' : 'success'
		);

		$this->redirect( admin_url( 'admin.php?page=affilio-payouts&payout_id=' . $payout_id ) );
	}

	/**
	 * Admin-post handler for cancelling a payout.
	 *
	 * @return void
	 */
	public function handle_cancel_payout() {
		$this->require_admin_capability();
		$payout_id = isset( $_POST['payout_id'] ) ? absint( $_POST['payout_id'] ) : 0;
		check_admin_referer( 'affilio_cancel_payout_' . $payout_id );
		$result = $this->cancel_payout( $payout_id );

		$this->set_admin_notice(
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Payout cancelled. Linked referrals are unpaid again.', 'dreamax-affiliates' ),
			is_wp_error( $result ) ? 'error' : 'success'
		);

		$this->redirect( admin_url( 'admin.php?page=affilio-payouts&payout_id=' . $payout_id ) );
	}


	/**
	 * Admin-post handler for a failed external payout attempt.
	 *
	 * @return void
	 */
	public function handle_mark_failed() {
		$this->require_admin_capability();
		$payout_id = isset( $_POST['payout_id'] ) ? absint( $_POST['payout_id'] ) : 0;
		check_admin_referer( 'affilio_mark_payout_failed_' . $payout_id );
		$notes  = isset( $_POST['failure_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['failure_reason'] ) ) : '';
		$result = $this->mark_failed( $payout_id, $notes );

		$this->set_admin_notice(
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Payout marked as failed. Linked referrals are unpaid and can be included in a new payout.', 'dreamax-affiliates' ),
			is_wp_error( $result ) ? 'error' : 'success'
		);

		$this->redirect( admin_url( 'admin.php?page=affilio-payouts&payout_id=' . $payout_id ) );
	}

	/**
	 * Saves the logged-in affiliate's payout details.
	 *
	 * @return void
	 */
	public function handle_frontend_profile_save() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in first.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'affilio_save_payout_profile' );
		$affiliate = affilio()->affiliates_db->get_by_user_id( get_current_user_id() );

		if ( ! $affiliate ) {
			wp_die( esc_html__( 'Affiliate profile not found.', 'dreamax-affiliates' ), '', array( 'response' => 404 ) );
		}

		$result = $this->save_profile_from_request( $affiliate->id );
		$url    = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$url    = remove_query_arg( array( 'affilio_payout_updated', 'affilio_payout_error' ), $url );

		if ( is_wp_error( $result ) ) {
			set_transient( 'affilio_payout_front_notice_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
			$url = add_query_arg( 'affilio_payout_error', '1', $url );
		} else {
			$url = add_query_arg( 'affilio_payout_updated', '1', $url );
		}

		$this->redirect( $url );
	}

	/**
	 * Saves an affiliate payout profile from the admin edit screen.
	 *
	 * @return void
	 */
	public function handle_admin_profile_save() {
		$this->require_admin_capability();
		$affiliate_id = isset( $_POST['affiliate_id'] ) ? absint( $_POST['affiliate_id'] ) : 0;
		check_admin_referer( 'affilio_admin_save_payout_profile_' . $affiliate_id );
		$result = $this->save_profile_from_request( $affiliate_id, true );

		$this->set_admin_notice(
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Affiliate details updated.', 'dreamax-affiliates' ),
			is_wp_error( $result ) ? 'error' : 'success'
		);

		$this->redirect( admin_url( 'admin.php?page=affilio-affiliates&view=edit&affiliate_id=' . $affiliate_id ) );
	}

	/**
	 * Shared payout-profile validation and update.
	 *
	 * @param int  $affiliate_id     Affiliate ID.
	 * @param bool $include_commission Whether to save admin-only commission fields.
	 * @return true|WP_Error
	 */
	private function save_profile_from_request( $affiliate_id, $include_commission = false ) {
		$affiliate = affilio()->affiliates_db->get( absint( $affiliate_id ) );

		if ( ! $affiliate ) {
			return new WP_Error( 'affilio_invalid_affiliate', __( 'Invalid affiliate.', 'dreamax-affiliates' ) );
		}

		// Only called from handle_frontend_profile_save() and its admin-side counterpart above,
		// both of which call check_admin_referer() before reaching this method.
		// sanitize_payout_method() (below) is a sanitizer the linter does not recognize by name.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$method  = isset( $_POST['payout_method'] ) ? $this->sanitize_payout_method( wp_unslash( $_POST['payout_method'] ) ) : 'paypal';
		$email   = isset( $_POST['payout_email'] ) ? sanitize_email( wp_unslash( $_POST['payout_email'] ) ) : '';
		$details = isset( $_POST['payout_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payout_details'] ) ) : '';

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'affilio_invalid_payout_email', __( 'Enter a valid payout/contact email address.', 'dreamax-affiliates' ) );
		}

		if ( 'paypal' !== $method && '' === $details ) {
			return new WP_Error( 'affilio_missing_payout_details', __( 'Enter the payout account details or instructions for the selected method.', 'dreamax-affiliates' ) );
		}

		$data = array(
			'payout_method'  => $method,
			'payout_email'   => $email,
			'payout_details' => $details,
		);
		$formats = array( '%s', '%s', '%s' );

		if ( $include_commission ) {
			$type = isset( $_POST['commission_type'] ) ? sanitize_key( wp_unslash( $_POST['commission_type'] ) ) : 'inherit';
			$type = in_array( $type, array( 'inherit', 'percentage', 'flat' ), true ) ? $type : 'inherit';

			if ( 'inherit' === $type ) {
				$data['commission_type'] = null;
				$data['commission_rate'] = null;
				$formats[]               = '%s';
				$formats[]               = '%f';
			} else {
				$raw_rate = isset( $_POST['commission_rate'] ) ? wp_unslash( $_POST['commission_rate'] ) : '';
				if ( '' === trim( (string) $raw_rate ) || ! is_numeric( $raw_rate ) ) {
					return new WP_Error( 'affilio_invalid_commission_rate', __( 'Enter a valid non-negative commission rate.', 'dreamax-affiliates' ) );
				}

				$rate = max( 0, (float) $raw_rate );
				$data['commission_type'] = $type;
				$data['commission_rate'] = $rate;
				$formats[]               = '%s';
				$formats[]               = '%f';
			}
		}

		$updated = affilio()->affiliates_db->update( $affiliate->id, $data, $formats );

		return false === $updated ? new WP_Error( 'affilio_profile_update_failed', __( 'Affiliate details could not be saved.', 'dreamax-affiliates' ) ) : true;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Sanitizes a payout method against the registered method list.
	 *
	 * @param mixed $method Raw method.
	 * @return string
	 */
	public function sanitize_payout_method( $method ) {
		$method  = sanitize_key( (string) $method );
		$methods = $this->get_payout_methods();
		return isset( $methods[ $method ] ) ? $method : 'paypal';
	}

	/**
	 * Gets the destination snapshot to store on a new payout.
	 *
	 * @param object $affiliate Affiliate row.
	 * @param string $method    Payout method.
	 * @return string
	 */
	private function get_affiliate_destination( $affiliate, $method ) {
		if ( 'paypal' === $method ) {
			return is_email( $affiliate->payout_email ) ? sanitize_email( $affiliate->payout_email ) : '';
		}

		return isset( $affiliate->payout_details ) ? sanitize_textarea_field( $affiliate->payout_details ) : '';
	}

	/**
	 * Creates a human-readable, collision-resistant batch key.
	 *
	 * @return string
	 */
	private function generate_batch_key() {
		do {
			$key = 'AFF-' . gmdate( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 8, false, false ) );
		} while ( affilio()->payouts_db->batch_key_exists( $key ) );

		return $key;
	}

	/**
	 * Capability check shared by admin-post handlers.
	 *
	 * @return void
	 */
	private function require_admin_capability() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You do not have permission to manage payouts.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Stores one admin notice per manager.
	 *
	 * @param string $message Notice message.
	 * @param string $type    success, warning, error, or info.
	 * @return void
	 */
	private function set_admin_notice( $message, $type = 'success' ) {
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'message' => sanitize_text_field( $message ),
				'type'    => in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'info',
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Displays and clears the current manager's payout notice.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		if ( ! current_user_can( Affilio_Capabilities::MANAGE_AFFILIATES ) || ! affilio_is_plugin_admin_notice_screen() ) {
			return;
		}

		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Safe redirect helper.
	 *
	 * @param string $url Destination URL.
	 * @return void
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
