<?php
/**
 * WooCommerce integration for checkout attribution, referral creation,
 * cancellations, and partial-refund commission adjustments.
 *
 * All order access uses WooCommerce CRUD APIs for HPOS compatibility.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Affilio_Integration_WooCommerce extends Affilio_Integration {

	const ATTRIBUTION_META_KEY           = '_affilio_affiliate_id';
	const ATTRIBUTION_VISIT_META_KEY     = '_affilio_visit_id';
	const ATTRIBUTION_SOURCE_META_KEY    = '_affilio_attribution_source';
	const ATTRIBUTION_COUPON_META_KEY    = '_affilio_coupon_code';
	const ATTRIBUTION_CAMPAIGN_META_KEY  = '_affilio_campaign';
	const ATTRIBUTION_CAPTURED_META_KEY  = '_affilio_attribution_captured';
	const REFUND_PROCESSED_META_KEY      = '_affilio_refund_processed_referral_id';
	const RECONCILIATION_EVENTS_META_KEY = '_affilio_paid_reconciliation_events';

	/**
	 * @inheritDoc
	 */
	public function init() {
		add_action( 'woocommerce_checkout_order_created', array( $this, 'capture_attribution' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'capture_attribution' ) );

		foreach ( Affilio_Commission_Approval::get_qualifying_statuses() as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'record_referral' ) );
		}

		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_referral' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'cancel_referral' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_referral' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'adjust_referral_for_refund' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_referral_qualification' ), 20, 4 );
	}

	/**
	 * Stores the resolved tracked affiliate and visit on the order while the
	 * customer request and referral cookie are still available.
	 *
	 * @param WC_Order $order Newly created order.
	 * @return void
	 */
	public function capture_attribution( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Classic and Store API checkout paths may both invoke attribution
		// callbacks. Preserve the first completed decision instead of allowing a
		// later duplicate hook to overwrite it.
		if ( $order->get_meta( self::ATTRIBUTION_CAPTURED_META_KEY ) || absint( $order->get_meta( self::ATTRIBUTION_META_KEY ) ) ) {
			return;
		}

		$visit             = Affilio_Tracking::resolve_tracked_visit();
		$coupon_match      = affilio()->coupons ? affilio()->coupons->resolve_order_attribution( $order ) : null;
		$priority          = get_option( 'affilio_coupon_attribution_priority', 'coupon_first' );
		$coupon_conflicted = is_wp_error( $coupon_match );

		if ( $coupon_conflicted && ( ! $visit || 'coupon_first' === $priority ) ) {
			$order->update_meta_data( self::ATTRIBUTION_CAPTURED_META_KEY, 'coupon_conflict' );
			$order->add_order_note( __( 'Dreamax Affiliates: Affiliate attribution was skipped because coupons assigned to different affiliates were used on this order.', 'dreamax-affiliates' ) );
			$order->save();
			return;
		}

		$selected = null;
		if ( ! $coupon_conflicted && is_array( $coupon_match ) && 'coupon_first' === $priority ) {
			$selected = array(
				'affiliate_id' => (int) $coupon_match['affiliate_id'],
				'visit_id'     => $visit && (int) $visit->affiliate_id === (int) $coupon_match['affiliate_id'] ? (int) $visit->id : 0,
				'source'       => 'coupon',
				'coupon_code'  => $coupon_match['coupon_code'],
				'campaign'     => $coupon_match['campaign'],
			);
		} elseif ( $visit ) {
			$selected = array(
				'affiliate_id' => (int) $visit->affiliate_id,
				'visit_id'     => (int) $visit->id,
				'source'       => 'link',
				'coupon_code'  => '',
				'campaign'     => isset( $visit->campaign ) ? (string) $visit->campaign : '',
			);
		} elseif ( ! $coupon_conflicted && is_array( $coupon_match ) ) {
			$selected = array(
				'affiliate_id' => (int) $coupon_match['affiliate_id'],
				'visit_id'     => 0,
				'source'       => 'coupon',
				'coupon_code'  => $coupon_match['coupon_code'],
				'campaign'     => $coupon_match['campaign'],
			);
		}

		if ( ! $selected ) {
			return;
		}

		$affiliate = affilio()->affiliates_db->get( $selected['affiliate_id'] );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			return;
		}

		if ( Affilio_Fraud_Prevention::is_self_referral( $affiliate->user_id, $order->get_customer_id() ) ) {
			return;
		}

		$order->update_meta_data( self::ATTRIBUTION_META_KEY, (int) $affiliate->id );
		$order->update_meta_data( self::ATTRIBUTION_VISIT_META_KEY, (int) $selected['visit_id'] );
		$order->update_meta_data( self::ATTRIBUTION_SOURCE_META_KEY, sanitize_key( $selected['source'] ) );
		$order->update_meta_data( self::ATTRIBUTION_COUPON_META_KEY, substr( sanitize_text_field( $selected['coupon_code'] ), 0, 100 ) );
		$order->update_meta_data( self::ATTRIBUTION_CAMPAIGN_META_KEY, substr( sanitize_text_field( $selected['campaign'] ), 0, 100 ) );
		$order->update_meta_data( self::ATTRIBUTION_CAPTURED_META_KEY, 'captured' );
		$order->save();
	}

	/**
	 * Creates one unpaid referral when an attributed order completes.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function record_referral( $order_id ) {
		$existing = affilio()->referrals_db->get_by_order_id( $order_id );

		if ( $existing ) {
			if ( 'cancelled' === $existing->status ) {
				$this->restore_cancelled_referral( $existing, $order_id );
			} elseif ( 'pending' === $existing->status ) {
				$this->activate_pending_referral( $existing, $order_id );
			}
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$affiliate_id = (int) $order->get_meta( self::ATTRIBUTION_META_KEY );
		$visit_id     = (int) $order->get_meta( self::ATTRIBUTION_VISIT_META_KEY );
		$source       = sanitize_key( (string) $order->get_meta( self::ATTRIBUTION_SOURCE_META_KEY ) );
		$coupon_code  = substr( sanitize_text_field( (string) $order->get_meta( self::ATTRIBUTION_COUPON_META_KEY ) ), 0, 100 );
		$campaign     = substr( sanitize_text_field( (string) $order->get_meta( self::ATTRIBUTION_CAMPAIGN_META_KEY ) ), 0, 100 );
		$source       = in_array( $source, array( 'link', 'coupon' ), true ) ? $source : 'link';
		if ( ! $affiliate_id ) {
			return;
		}

		$affiliate = affilio()->affiliates_db->get( $affiliate_id );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			return;
		}

		if ( Affilio_Fraud_Prevention::is_self_referral( $affiliate->user_id, $order->get_customer_id() ) ) {
			return;
		}

		$calculation = Affilio_Commission::calculate_for_order( $order, $affiliate );
		if ( $calculation['amount'] <= 0 || $calculation['commission'] <= 0 ) {
			return;
		}

		$qualified_at  = current_time( 'mysql' );
		$initial_state = Affilio_Commission_Approval::get_initial_state(
			array(
				'event'        => 'creation',
				'order_id'     => (int) $order_id,
				'affiliate_id' => (int) $affiliate->id,
				'visit_id'     => $visit_id,
				'source'       => $source,
				'qualified_at' => $qualified_at,
			)
		);

		$referral_id = affilio()->referrals_db->insert(
			array(
				'affiliate_id'      => (int) $affiliate->id,
				'order_id'          => (int) $order_id,
				'visit_id'          => $visit_id ? $visit_id : null,
				'amount'            => $calculation['amount'],
				'commission_amount' => $calculation['commission'],
				'currency'          => $order->get_currency(),
				'status'            => $initial_state['status'],
				'source'            => $source,
				'coupon_code'       => $coupon_code,
				'campaign'          => $campaign,
				'date_eligible'     => $initial_state['date_eligible'],
				'created_by'        => 0,
				'updated_by'        => 0,
				'date_created'      => $qualified_at,
			)
		);

		if ( ! $referral_id ) {
			return;
		}

		if ( $visit_id ) {
			affilio()->visits_db->mark_converted( $visit_id );
		}

		affilio()->audit->record(
			'referral',
			$referral_id,
			'order_referral_created',
			'',
			array(
				'order_id'     => (int) $order_id,
				'affiliate_id' => (int) $affiliate->id,
				'status'       => $initial_state['status'],
				'commission'   => (float) $calculation['commission'],
				'currency'     => (string) $order->get_currency(),
			),
			0
		);

		do_action( 'affilio_referral_created', $referral_id, $affiliate->id, $order_id );
	}



	/**
	 * Keeps an existing referral aligned with the configured qualifying
	 * WooCommerce order statuses.
	 *
	 * Qualifying statuses are payout-eligible in the Free plugin. If an order
	 * that already earned an unpaid referral moves to a supported but
	 * non-qualifying status, the referral becomes pending (not payout-eligible)
	 * rather than remaining unpaid. Returning to a qualifying status promotes
	 * the same row back to unpaid, preserving the unique order/referral audit
	 * trail and preventing duplicate commissions.
	 *
	 * @param int      $order_id    WooCommerce order ID.
	 * @param string   $from_status Previous WooCommerce status.
	 * @param string   $to_status   New WooCommerce status.
	 * @param WC_Order $order       WooCommerce order.
	 * @return void
	 */
	public function sync_referral_qualification( $order_id, $from_status, $to_status, $order ) {
		$to_status   = sanitize_key( preg_replace( '/^wc-/', '', (string) $to_status ) );
		$from_status = sanitize_key( preg_replace( '/^wc-/', '', (string) $from_status ) );

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		// Terminal statuses are owned by the dedicated cancellation/refund hooks.
		if ( in_array( $to_status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			return;
		}

		$supported = array( 'pending', 'processing', 'on-hold', 'completed' );
		if ( ! in_array( $to_status, $supported, true ) ) {
			return;
		}

		$qualifying = Affilio_Commission_Approval::get_qualifying_statuses();

		if ( in_array( $to_status, $qualifying, true ) ) {
			// Idempotent: creates, restores, or promotes the one order referral.
			$this->record_referral( $order_id );
			return;
		}

		$referral = affilio()->referrals_db->get_by_order_id( $order_id );
		if ( ! $referral || 'pending' === $referral->status || 'cancelled' === $referral->status ) {
			return;
		}

		$reason = sprintf(
			/* translators: %s: WooCommerce order status. */
			__( 'The WooCommerce order moved to the non-qualifying status "%s".', 'dreamax-affiliates' ),
			wc_get_order_status_name( 'wc-' . $to_status )
		);

		if ( 'paid' === $referral->status ) {
			$this->record_paid_reconciliation(
				$order,
				'nonqualifying:' . $to_status,
				__( 'Dreamax Affiliates: This order moved to a non-qualifying commission status after the affiliate commission had been paid. Review the payout manually.', 'dreamax-affiliates' ),
				'affilio_paid_referral_order_nonqualifying',
				array( (int) $referral->id, (int) $order_id, $from_status, $to_status )
			);
			return;
		}

		if ( ! empty( $referral->payout_id ) && 'processing' === $referral->status ) {
			// Financial safety first: remove it from the open payout. If the
			// order later qualifies again, the cancelled row can be restored.
			affilio()->payouts->handle_referral_cancellation( $referral->id );
			return;
		}

		$updated = affilio()->referrals_db->move_open_referral_to_pending( $referral->id, $reason, 0 );
		if ( 1 !== (int) $updated ) {
			return;
		}

		$order->add_order_note(
			__( 'Dreamax Affiliates: The affiliate referral is pending because the order is not currently in a configured qualifying status.', 'dreamax-affiliates' )
		);

		affilio()->audit->record(
			'referral',
			(int) $referral->id,
			'order_referral_made_pending',
			$reason,
			array(
				'order_id'        => (int) $order_id,
				'from_status'     => $from_status,
				'to_status'       => $to_status,
				'referral_status' => 'pending',
			),
			0
		);

		do_action(
			'affilio_referral_status_changed',
			(int) $referral->id,
			'pending',
			(string) $referral->status,
			$reason
		);
	}

	/**
	 * Promotes an existing pending referral when its order enters a configured
	 * qualifying status.
	 *
	 * @param object $referral Existing pending referral.
	 * @param int    $order_id WooCommerce order ID.
	 * @return void
	 */
	private function activate_pending_referral( $referral, $order_id ) {
		if ( Affilio_Commission_Approval::is_active_hold( $referral ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_status(), Affilio_Commission_Approval::get_qualifying_statuses(), true ) ) {
			return;
		}

		$affiliate = affilio()->affiliates_db->get( $referral->affiliate_id );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			return;
		}

		if ( Affilio_Fraud_Prevention::is_self_referral( $affiliate->user_id, $order->get_customer_id() ) ) {
			return;
		}

		$calculation = Affilio_Commission::calculate_for_order( $order, $affiliate );
		if ( $calculation['amount'] <= 0 || $calculation['commission'] <= 0 ) {
			return;
		}

		$reason  = __( 'The WooCommerce order entered a configured qualifying affiliate-commission status.', 'dreamax-affiliates' );
		$updated = affilio()->referrals_db->activate_pending_referral(
			$referral->id,
			$calculation['amount'],
			$calculation['commission'],
			0
		);

		if ( 1 !== (int) $updated ) {
			return;
		}

		$order->add_order_note(
			__( 'Dreamax Affiliates: The pending affiliate referral is now payout-eligible because the order entered a configured qualifying status.', 'dreamax-affiliates' )
		);

		affilio()->audit->record(
			'referral',
			(int) $referral->id,
			'order_referral_qualified',
			$reason,
			array(
				'order_id'   => (int) $order_id,
				'old_status' => 'pending',
				'new_status' => 'unpaid',
				'amount'     => (float) $calculation['amount'],
				'commission' => (float) $calculation['commission'],
				'currency'   => (string) $order->get_currency(),
			),
			0
		);

		do_action(
			'affilio_referral_status_changed',
			(int) $referral->id,
			'unpaid',
			'pending',
			$reason
		);
	}

	/**
	 * Restores a previously cancelled referral when its WooCommerce order
	 * returns to a qualifying status.
	 *
	 * The original referral row is reused so order_id remains unique and no
	 * duplicate commission can be created. Monetary values are recalculated
	 * from the order's current net value, which also prevents a fully refunded
	 * order from being restored merely by changing its status back to completed.
	 *
	 * @param object $referral Existing cancelled referral row.
	 * @param int    $order_id WooCommerce order ID.
	 * @return void
	 */
	private function restore_cancelled_referral( $referral, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_status(), Affilio_Commission_Approval::get_qualifying_statuses(), true ) ) {
			return;
		}

		$affiliate = affilio()->affiliates_db->get( $referral->affiliate_id );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			return;
		}

		if ( Affilio_Fraud_Prevention::is_self_referral( $affiliate->user_id, $order->get_customer_id() ) ) {
			return;
		}

		$calculation = Affilio_Commission::calculate_for_order( $order, $affiliate );
		if ( $calculation['amount'] <= 0 || $calculation['commission'] <= 0 ) {
			return;
		}

		$qualified_at  = current_time( 'mysql' );
		$initial_state = Affilio_Commission_Approval::get_initial_state(
			array(
				'event'        => 'restoration',
				'order_id'     => (int) $order_id,
				'affiliate_id' => (int) $affiliate->id,
				'visit_id'     => (int) ( $referral->visit_id ?? 0 ),
				'source'       => (string) ( $referral->source ?? 'link' ),
				'qualified_at' => $qualified_at,
			)
		);
		$reason        = __( 'The WooCommerce order returned to a qualifying affiliate-commission status.', 'dreamax-affiliates' );
		$updated       = affilio()->referrals_db->restore_cancelled_referral(
			$referral->id,
			$calculation['amount'],
			$calculation['commission'],
			$initial_state['status'],
			$initial_state['date_eligible'],
			0
		);

		if ( 1 !== (int) $updated ) {
			return;
		}

		$order->add_order_note( __( 'Dreamax Affiliates: The cancelled affiliate referral was restored because the order returned to a qualifying status.', 'dreamax-affiliates' ) );

		affilio()->audit->record(
			'referral',
			(int) $referral->id,
			'order_referral_restored',
			$reason,
			array(
				'order_id'   => (int) $order_id,
				'old_status' => 'cancelled',
				'new_status' => (string) $initial_state['status'],
				'amount'     => (float) $calculation['amount'],
				'commission' => (float) $calculation['commission'],
				'currency'   => (string) $order->get_currency(),
			),
			0
		);

		do_action(
			'affilio_referral_status_changed',
			(int) $referral->id,
			(string) $initial_state['status'],
			'cancelled',
			$reason
		);
	}

	/**
	 * Recalculates an unpaid/processing referral after a partial refund.
	 * Paid referrals are retained and flagged for manual reconciliation.
	 *
	 * @param int $order_id  Parent order ID.
	 * @param int $refund_id Refund order ID.
	 * @return void
	 */
	public function adjust_referral_for_refund( $order_id, $refund_id ) {
		$refund = wc_get_order( $refund_id );
		if ( ! $refund || ! is_a( $refund, 'WC_Order_Refund' ) ) {
			return;
		}

		if ( absint( $refund->get_meta( self::REFUND_PROCESSED_META_KEY ) ) ) {
			return;
		}

		$lock_name = 'woocommerce-refund:' . absint( $refund_id );
		if ( ! \Affilio\Infrastructure\WordPress\AtomicLock::acquire( $lock_name, 5 * MINUTE_IN_SECONDS ) ) {
			return;
		}

		try {
			$referral = affilio()->referrals_db->get_by_order_id( $order_id );
			if ( ! $referral || 'cancelled' === $referral->status ) {
				return;
			}

			if ( $this->process_refund( $referral, $order_id, $refund_id ) ) {
				$refund->update_meta_data( self::REFUND_PROCESSED_META_KEY, (int) $referral->id );
				$refund->save();
			}
		} finally {
			\Affilio\Infrastructure\WordPress\AtomicLock::release( $lock_name );
		}
	}

	/**
	 * Applies one refund event. Returning false leaves the event retryable.
	 *
	 * @param object $referral Referral row.
	 * @param int    $order_id Parent order ID.
	 * @param int    $refund_id Refund order ID.
	 * @return bool
	 */
	private function process_refund( $referral, $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		if ( 'paid' === $referral->status ) {
			$this->record_paid_reconciliation(
				$order,
				'refund:' . absint( $refund_id ),
				sprintf(
					/* translators: %d: refund ID. */
					__( 'Dreamax Affiliates: Refund #%d was created after the affiliate commission had been paid. Review the payout manually.', 'dreamax-affiliates' ),
					absint( $refund_id )
				),
				'affilio_paid_referral_refunded',
				array( (int) $referral->id, (int) $order_id, (int) $refund_id )
			);
			return true;
		}

		$affiliate = affilio()->affiliates_db->get( $referral->affiliate_id );
		if ( ! $affiliate ) {
			return false;
		}

		$calculation    = Affilio_Commission::calculate_for_order( $order, $affiliate );
		$old_amount     = (float) $referral->amount;
		$old_commission = (float) $referral->commission_amount;

		if ( round( $old_amount, 4 ) === round( (float) $calculation['amount'], 4 ) && round( $old_commission, 2 ) === round( (float) $calculation['commission'], 2 ) ) {
			return true;
		}

		if ( $calculation['amount'] <= 0 || $calculation['commission'] <= 0 ) {
			$this->cancel_referral( $order_id );
			$current = affilio()->referrals_db->get( $referral->id );
			if ( $current && in_array( $current->status, array( 'cancelled', 'paid' ), true ) ) {
				$order->add_order_note( __( 'Dreamax Affiliates: The unpaid affiliate referral was cancelled because the refund removed all commissionable value.', 'dreamax-affiliates' ) );
				return true;
			}
			return false;
		}

		if ( ! empty( $referral->payout_id ) && 'processing' === $referral->status ) {
			$result = affilio()->payouts->adjust_processing_referral( $referral->id, $calculation['amount'], $calculation['commission'] );
			if ( is_wp_error( $result ) ) {
				$order->add_order_note( __( 'Dreamax Affiliates: The partial-refund commission could not be updated automatically. Review the open payout manually.', 'dreamax-affiliates' ) );
				return false;
			}
		} else {
			$updated = affilio()->referrals_db->adjust_open_referral( $referral->id, $calculation['amount'], $calculation['commission'] );
			if ( 1 !== (int) $updated ) {
				$current = affilio()->referrals_db->get( $referral->id );
				if ( $current && ! empty( $current->payout_id ) && 'processing' === $current->status ) {
					$result = affilio()->payouts->adjust_processing_referral( $current->id, $calculation['amount'], $calculation['commission'] );
					if ( is_wp_error( $result ) ) {
						$order->add_order_note( __( 'Dreamax Affiliates: The partial-refund commission could not be updated automatically. Review the open payout manually.', 'dreamax-affiliates' ) );
						return false;
					}
				} elseif ( $current && 'paid' === $current->status ) {
					$this->record_paid_reconciliation(
						$order,
						'refund:' . absint( $refund_id ),
						sprintf(
							/* translators: %d: refund ID. */
							__( 'Dreamax Affiliates: Refund #%d arrived while the affiliate payout was completing. The commission is already paid; review the payout manually.', 'dreamax-affiliates' ),
							absint( $refund_id )
						),
						'affilio_paid_referral_refunded',
						array( (int) $current->id, (int) $order_id, (int) $refund_id )
					);
					return true;
				} elseif (
					$current
					&& empty( $current->payout_id )
					&& in_array( $current->status, array( 'pending', 'unpaid' ), true )
					&& round( (float) $current->amount, 4 ) === round( (float) $calculation['amount'], 4 )
					&& round( (float) $current->commission_amount, 2 ) === round( (float) $calculation['commission'], 2 )
				) {
					return true;
				} else {
					return false;
				}
			}
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: old commission, 2: new commission, 3: currency. */
				__( 'Dreamax Affiliates: Partial refund adjusted affiliate commission from %1$s to %2$s %3$s.', 'dreamax-affiliates' ),
				number_format_i18n( $old_commission, 2 ),
				number_format_i18n( $calculation['commission'], 2 ),
				$order->get_currency()
			)
		);

		affilio()->audit->record(
			'referral',
			(int) $referral->id,
			'refund_adjusted',
			'',
			array(
				'order_id'       => (int) $order_id,
				'refund_id'      => (int) $refund_id,
				'old_commission' => $old_commission,
				'new_commission' => (float) $calculation['commission'],
			),
			0
		);

		do_action( 'affilio_referral_refund_adjusted', (int) $referral->id, (int) $order_id, (int) $refund_id, $old_commission, (float) $calculation['commission'] );
		return true;
	}

	/**
	 * Cancels an unpaid referral after a cancellation/full refund.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function cancel_referral( $order_id ) {
		$referral = affilio()->referrals_db->get_by_order_id( $order_id );

		if ( ! $referral || 'cancelled' === $referral->status ) {
			return;
		}

		if ( 'paid' === $referral->status ) {
			$this->flag_paid_referral_cancellation( $referral, $order_id );
			return;
		}

		if ( ! empty( $referral->payout_id ) && 'processing' === $referral->status ) {
			affilio()->payouts->handle_referral_cancellation( $referral->id );
			return;
		}

		$reason  = __( 'The WooCommerce order was cancelled, failed, or fully refunded.', 'dreamax-affiliates' );
		$updated = affilio()->referrals_db->cancel_open_referral( $referral->id, $reason, 0 );

		if ( 1 !== (int) $updated ) {
			$current = affilio()->referrals_db->get( $referral->id );

			// A concurrent payout batch may have claimed the row after the first
			// read. Remove it through the payout service so the payout total and
			// referral link remain consistent.
			if ( $current && ! empty( $current->payout_id ) && 'processing' === $current->status ) {
				affilio()->payouts->handle_referral_cancellation( $current->id );
			} elseif ( $current && 'paid' === $current->status ) {
				$this->flag_paid_referral_cancellation( $current, $order_id );
			}
			return;
		}

		affilio()->audit->record( 'referral', (int) $referral->id, 'order_cancelled', $reason, array( 'order_id' => (int) $order_id ), 0 );
		do_action( 'affilio_referral_status_changed', (int) $referral->id, 'cancelled', (string) $referral->status, $reason );
	}

	/**
	 * Flags a cancellation/full-refund that occurs after commission payment.
	 *
	 * Paid ledger rows remain immutable. The order note and action give store
	 * owners and extensions a reliable manual-reconciliation signal.
	 *
	 * @param object $referral Referral row.
	 * @param int    $order_id WooCommerce order ID.
	 * @return void
	 */
	private function flag_paid_referral_cancellation( $referral, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$events = $this->get_reconciliation_events( $order );
		if ( 'refunded' === $order->get_status() ) {
			foreach ( $events as $event ) {
				if ( 0 === strpos( $event, 'refund:' ) ) {
					return;
				}
			}
		}

		$this->record_paid_reconciliation(
			$order,
			'status:' . sanitize_key( $order->get_status() ),
			__( 'Dreamax Affiliates: This order was cancelled or fully refunded after the affiliate commission had been paid. Review the payout manually.', 'dreamax-affiliates' ),
			'affilio_paid_referral_order_cancelled',
			array( (int) $referral->id, (int) $order_id )
		);
	}

	/**
	 * Records one bounded reconciliation signal and fires its public action once.
	 *
	 * @param WC_Order $order     Parent order.
	 * @param string   $event_key Event key.
	 * @param string   $note      Order note.
	 * @param string   $action    Action name.
	 * @param array    $args      Action arguments.
	 * @return bool True when newly recorded.
	 */
	private function record_paid_reconciliation( $order, $event_key, $note, $action, array $args ) {
		$events    = $this->get_reconciliation_events( $order );
		$event_key = substr( sanitize_text_field( $event_key ), 0, 100 );
		if ( in_array( $event_key, $events, true ) ) {
			return false;
		}

		$events[] = $event_key;
		$events   = array_slice( array_values( array_unique( $events ) ), -20 );
		$order->update_meta_data( self::RECONCILIATION_EVENTS_META_KEY, $events );
		$order->add_order_note( $note );
		$order->save();
		// $action is always one of two Dreamax Affiliates-prefixed literal strings passed by this method's
		// three private callers ('affilio_paid_referral_refunded' or
		// 'affilio_paid_referral_order_cancelled') — never a dynamic or user-influenced value.
		do_action_ref_array( $action, $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		return true;
	}

	/**
	 * Returns normalized reconciliation event keys stored on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private function get_reconciliation_events( $order ) {
		$events = $order->get_meta( self::RECONCILIATION_EVENTS_META_KEY );
		if ( ! is_array( $events ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_text_field', $events ) ) );
	}
}
