<?php
/**
 * Data access for the referrals table — one row per attributed
 * order/commission.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
/*
 * This class is a custom-table data-access layer (Dreamax Affiliates' own referrals table, not a
 * WordPress core table), so every method here necessarily runs a "direct database query" that
 * WordPress core object-cache groups (built around posts/users/terms) do not cover — there is
 * no core caching API this class could call into instead. Live admin screens (affiliate/referral
 * lists, payout eligibility, commission totals) also need current, not cached-stale, data,
 * particularly right after a status change. Higher-value read paths (reports/analytics
 * aggregates) already go through Affilio\Infrastructure\WordPress\PerformanceCache instead of
 * being handled here.
 *
 * "Unescaped" table-name parameters below are never user input: every {$table} comes only from
 * Affilio_DB::get_table_name() ($wpdb->prefix + a hardcoded per-class suffix declared just below).
 * Every actual value (IDs, amounts, statuses, dates, etc.) is still bound through
 * $wpdb->prepare() with %s/%d/%f placeholders throughout this file.
 */
class Affilio_DB_Referrals extends Affilio_DB {

	/**
	 * @var string
	 */
	protected $table_suffix = 'referrals';

	/**
	 * Creates or upgrades the referrals table.
	 *
	 * The unique order_id key provides database-level duplicate protection
	 * when order-status hooks are triggered more than once.
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$table           = $this->get_table_name();
		$charset_collate = esc_sql( $wpdb->get_charset_collate() ); // Defensive, same reasoning as get_table_name()'s esc_sql() wrap.

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes only from get_table_name(), never user input.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			visit_id BIGINT UNSIGNED DEFAULT NULL,
			payout_id BIGINT UNSIGNED DEFAULT NULL,
			amount DECIMAL(15,4) NOT NULL DEFAULT 0,
			commission_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
			currency VARCHAR(10) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			source VARCHAR(20) NOT NULL DEFAULT 'link',
			coupon_code VARCHAR(100) DEFAULT NULL,
			campaign VARCHAR(100) DEFAULT NULL,
			manual_reference VARCHAR(64) DEFAULT NULL,
			description TEXT NULL,
			status_reason TEXT NULL,
			date_eligible DATETIME DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			updated_by BIGINT UNSIGNED DEFAULT NULL,
			date_created DATETIME NOT NULL,
			date_paid DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY affiliate_id (affiliate_id),
			KEY affiliate_date (affiliate_id, date_created),
			KEY payout_eligibility (affiliate_id, status, payout_id, currency),
			KEY payout_id (payout_id),
			KEY status (status),
			KEY status_date (status, date_created),
			KEY release_queue (status, date_eligible, id),
			KEY source (source),
			KEY campaign (campaign),
			KEY campaign_date (campaign, date_created),
			KEY coupon_date (coupon_code, date_created),
			KEY manual_reference (manual_reference),
			KEY date_eligible (date_eligible),
			KEY date_created (date_created)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * All referrals belonging to one affiliate, most recent first.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return array
	 */
	public function get_by_affiliate( $affiliate_id, $limit = 200, $offset = 0 ) {
		global $wpdb;
		$table  = $this->get_table_name();
		$limit  = max( 1, min( 500, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE affiliate_id = %d ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d',
				$table,
				absint( $affiliate_id ),
				$limit,
				$offset
			)
		);
	}

	/**
	 * Look up a referral by the order it's attached to, so an integration
	 * can check whether an order has already been recorded (avoids
	 * double-crediting on repeated status-change webhooks/hooks).
	 *
	 * @param int|string $order_id Order ID.
	 * @return object|null
	 */
	public function get_by_order_id( $order_id ) {
		global $wpdb;
		$table    = $this->get_table_name();
		$order_id = \Affilio\Infrastructure\WordPress\UnsignedBigint::normalize( $order_id );

		if ( '' === $order_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %s', $table, $order_id )
		);
	}

	/**
	 * All referrals, most recent first. Used by the admin list table
	 * (Affilio_Referrals_List_Table).
	 *
	 * @return object[]
	 */
	public function get_all() {
		$limit = (int) apply_filters( 'affilio_legacy_referral_get_all_limit', 5000 );
		return $this->query(
			array(
				'number' => max( 1, min( 5000, $limit ) ),
			)
		);
	}


	/**
	 * Fetches referrals by primary-key list.
	 *
	 * @param int[] $ids Referral IDs.
	 * @return object[]
	 */
	public function get_by_ids( array $ids ) {
		global $wpdb;
		$table = $this->get_table_name();
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns referrals attached to a payout.
	 *
	 * @param int $payout_id Payout ID.
	 * @return object[]
	 */
	public function get_by_payout( $payout_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE payout_id = %d ORDER BY date_created ASC', $table, $payout_id )
		);
	}


	/**
	 * Returns and locks processing referrals inside a payout transaction.
	 *
	 * @param int $payout_id Payout ID.
	 * @return object[]
	 */
	public function get_processing_by_payout_for_update( $payout_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE payout_id = %d AND status = 'processing' ORDER BY id ASC FOR UPDATE",
				$table,
				absint( $payout_id )
			)
		);
	}

	/**
	 * Locks eligible referrals into a payout.
	 *
	 * @param int[] $ids       Referral IDs.
	 * @param int   $payout_id Payout ID.
	 * @return int|false
	 */
	public function assign_to_payout( array $ids, $payout_id ) {
		global $wpdb;
		$table = $this->get_table_name();
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) || ! $payout_id ) {
			return false;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "UPDATE {$table} SET payout_id = %d, status = 'processing' WHERE id IN ({$placeholders}) AND payout_id IS NULL AND status = 'unpaid'"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params       = array_merge( array( absint( $payout_id ) ), $ids );

		return $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Marks every referral in a payout as paid.
	 *
	 * @param int    $payout_id Payout ID.
	 * @param string $paid_at   MySQL datetime.
	 * @return int|false
	 */
	public function mark_paid_by_payout( $payout_id, $paid_at ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'paid', date_paid = %s WHERE payout_id = %d AND status = 'processing'",
				$table,
				$paid_at,
				$payout_id
			)
		);
	}


	/**
	 * Best-effort recovery helper used if a payout completion fails on a
	 * non-transactional database engine.
	 *
	 * @param int $payout_id Payout ID.
	 * @return int|false
	 */
	public function restore_processing_by_payout( $payout_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'processing', date_paid = NULL WHERE payout_id = %d AND status = 'paid'",
				$table,
				$payout_id
			)
		);
	}

	/**
	 * Releases referrals from a cancelled payout.
	 *
	 * @param int $payout_id Payout ID.
	 * @return int|false
	 */
	public function release_from_payout( $payout_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET payout_id = NULL, status = 'unpaid', date_paid = NULL WHERE payout_id = %d AND status = 'processing'",
				$table,
				$payout_id
			)
		);
	}


	/**
	 * Adjusts one referral only while it is still open and not assigned to a
	 * payout. The conditional write closes the read-then-update race with payout
	 * batch creation.
	 *
	 * @param int   $referral_id Referral ID.
	 * @param float $amount      New commissionable amount.
	 * @param float $commission  New commission amount.
	 * @return int|false
	 */
	public function adjust_open_referral( $referral_id, $amount, $commission ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET amount = %f, commission_amount = %f WHERE id = %d AND payout_id IS NULL AND status IN ('pending', 'unpaid')",
				$table,
				max( 0, (float) $amount ),
				max( 0, (float) $commission ),
				absint( $referral_id )
			)
		);
	}



	/**
	 * Moves an open payout-eligible referral back to pending when its order
	 * leaves the configured qualifying statuses.
	 *
	 * Only an unpaid, unassigned row may be changed. This prevents a concurrent
	 * payout claim from being silently altered.
	 *
	 * @param int    $referral_id Referral ID.
	 * @param string $reason      Status reason.
	 * @param int    $updated_by  WordPress user ID, or zero for automation.
	 * @return int|false
	 */
	public function move_open_referral_to_pending( $referral_id, $reason, $updated_by = 0 ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'pending', date_eligible = NULL, status_reason = %s, updated_by = %d WHERE id = %d AND payout_id IS NULL AND status = 'unpaid'",
				$table,
				(string) $reason,
				absint( $updated_by ),
				absint( $referral_id )
			)
		);
	}

	/**
	 * Promotes a pending referral to payout-eligible unpaid state and refreshes
	 * its financial values from the current WooCommerce order.
	 *
	 * @param int   $referral_id Referral ID.
	 * @param float $amount      Current commissionable amount.
	 * @param float $commission  Current commission amount.
	 * @param int   $updated_by  WordPress user ID, or zero for automation.
	 * @return int|false
	 */
	public function activate_pending_referral( $referral_id, $amount, $commission, $updated_by = 0 ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET amount = %f, commission_amount = %f, status = 'unpaid', date_eligible = NULL, status_reason = NULL, updated_by = %d WHERE id = %d AND payout_id IS NULL AND status = 'pending'",
				$table,
				max( 0, (float) $amount ),
				max( 0, (float) $commission ),
				absint( $updated_by ),
				absint( $referral_id )
			)
		);
	}

	/**
	 * Restores a cancelled referral to an open commission state.
	 *
	 * The conditional write is intentionally narrow: only a cancelled row that
	 * is not assigned to a payout can be restored. This preserves the unique
	 * order row and prevents a concurrent payout workflow from being modified.
	 *
	 * @param int         $referral_id Referral ID.
	 * @param float       $amount      Recalculated commissionable amount.
	 * @param float       $commission  Recalculated commission amount.
	 * @param string      $status      Open referral status, pending or unpaid.
	 * @param string|null $eligible_at Optional eligibility datetime.
	 * @param int         $updated_by  WordPress user ID, or zero for automation.
	 * @return int|false
	 */
	public function restore_cancelled_referral( $referral_id, $amount, $commission, $status = 'unpaid', $eligible_at = null, $updated_by = 0 ) {
		global $wpdb;
		$table  = $this->get_table_name();
		$status = sanitize_key( $status );

		if ( ! in_array( $status, array( 'pending', 'unpaid' ), true ) ) {
			$status = 'unpaid';
		}

		if ( null === $eligible_at || '' === $eligible_at ) {
			return $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET amount = %f, commission_amount = %f, status = %s, payout_id = NULL, date_paid = NULL, date_eligible = NULL, status_reason = NULL, updated_by = %d WHERE id = %d AND payout_id IS NULL AND status = 'cancelled'",
					$table,
					max( 0, (float) $amount ),
					max( 0, (float) $commission ),
					$status,
					absint( $updated_by ),
					absint( $referral_id )
				)
			);
		}

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET amount = %f, commission_amount = %f, status = %s, payout_id = NULL, date_paid = NULL, date_eligible = %s, status_reason = NULL, updated_by = %d WHERE id = %d AND payout_id IS NULL AND status = 'cancelled'",
				$table,
				max( 0, (float) $amount ),
				max( 0, (float) $commission ),
				$status,
				sanitize_text_field( (string) $eligible_at ),
				absint( $updated_by ),
				absint( $referral_id )
			)
		);
	}

	/**
	 * Cancels one referral only while it is still open and not assigned to a
	 * payout. A concurrent payout assignment therefore cannot leave a cancelled
	 * referral linked to a stale payout total.
	 *
	 * @param int    $referral_id Referral ID.
	 * @param string $reason      Cancellation reason.
	 * @param int    $updated_by  WordPress user ID, or zero for an automated event.
	 * @return int|false
	 */
	public function cancel_open_referral( $referral_id, $reason, $updated_by = 0 ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', payout_id = NULL, date_paid = NULL, date_eligible = NULL, status_reason = %s, updated_by = %d WHERE id = %d AND payout_id IS NULL AND status IN ('pending', 'unpaid')",
				$table,
				(string) $reason,
				absint( $updated_by ),
				absint( $referral_id )
			)
		);
	}

	/**
	 * Cancels one referral only if it is still processing in the expected
	 * payout. This protects against a simultaneous payout completion.
	 *
	 * @param int $referral_id Referral ID.
	 * @param int $payout_id   Payout ID.
	 * @return int|false
	 */
	public function cancel_processing_referral( $referral_id, $payout_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', payout_id = NULL WHERE id = %d AND payout_id = %d AND status = 'processing'",
				$table,
				$referral_id,
				$payout_id
			)
		);
	}


	/**
	 * Restores one cancellation only when the row still has the rollback state.
	 *
	 * @param int $referral_id Referral ID.
	 * @param int $payout_id   Payout ID.
	 * @return int|false
	 */
	public function restore_cancelled_to_processing( $referral_id, $payout_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'processing', payout_id = %d WHERE id = %d AND payout_id IS NULL AND status = 'cancelled'",
				$table,
				absint( $payout_id ),
				absint( $referral_id )
			)
		);
	}

	/**
	 * Adjusts the monetary values of one referral while it remains locked in
	 * the expected processing payout.
	 *
	 * @param int   $referral_id Referral ID.
	 * @param int   $payout_id   Payout ID.
	 * @param float $amount      New commissionable amount.
	 * @param float $commission  New commission amount.
	 * @return int|false
	 */
	public function adjust_processing_referral( $referral_id, $payout_id, $amount, $commission ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET amount = %f, commission_amount = %f WHERE id = %d AND payout_id = %d AND status = 'processing'",
				$table,
				max( 0, (float) $amount ),
				max( 0, (float) $commission ),
				absint( $referral_id ),
				absint( $payout_id )
			)
		);
	}

	/**
	 * Calculates the remaining processing commission inside a payout.
	 *
	 * @param int $payout_id Payout ID.
	 * @return float
	 */
	public function get_total_for_payout( $payout_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(commission_amount), 0) FROM %i WHERE payout_id = %d AND status = 'processing'",
				$table,
				$payout_id
			)
		);
	}

	/**
	 * Sums unpaid/pending commission per currency (never blindly across
	 * currencies — see the matching fix and explanation in
	 * Affilio_Dashboard::calculate_totals()) for the admin summary total.
	 *
	 * @return array<string,float> Currency code (or '' if a row has none) => total.
	 */
	public function get_total_owed() {
		global $wpdb;
		$table = $this->get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT currency, SUM(commission_amount) as total FROM %i WHERE status IN (%s, %s) GROUP BY currency',
				$table,
				'pending',
				'unpaid'
			)
		);

		$totals = array();

		foreach ( $rows as $row ) {
			$currency            = $row->currency ? $row->currency : '';
			$totals[ $currency ] = (float) $row->total;
		}

		return $totals;
	}

	/**
	 * Returns a paginated referral result set with report/list filters.
	 *
	 * @param array $args Query arguments.
	 * @return object[]
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$table = $this->get_table_name();
		$where = $this->build_where_sql( $args );

		$allowed_orderby = array( 'id', 'affiliate_id', 'order_id', 'amount', 'commission_amount', 'currency', 'status', 'source', 'coupon_code', 'campaign', 'date_created', 'date_paid' );
		$orderby         = isset( $args['orderby'] ) && in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order           = isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$number          = isset( $args['number'] ) ? max( 1, min( 5000, absint( $args['number'] ) ) ) : 20;
		$offset          = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;

		$sql    = "SELECT * FROM {$table} {$where['sql']} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params = array_merge( $where['params'], array( $number, $offset ) );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns the next keyset-paginated export batch.
	 *
	 * @param array $args     Report filters.
	 * @param int   $after_id Last exported primary key.
	 * @param int   $number   Maximum rows.
	 * @return object[]
	 */
	public function query_after_id( array $args, $after_id = 0, $number = 500 ) {
		global $wpdb;
		$table      = $this->get_table_name();
		$where      = $this->build_where_sql( $args );
		$after_id   = max( 0, absint( $after_id ) );
		$number     = max( 1, min( 1000, absint( $number ) ) );
		$sql_where  = $where['sql'];
		$sql_where .= $sql_where ? ' AND id > %d' : 'WHERE id > %d';
		$params     = array_merge( $where['params'], array( $after_id, $number ) );
		$sql        = "SELECT * FROM {$table} {$sql_where} ORDER BY id ASC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Counts referrals matching a filter set.
	 *
	 * @param array $args Filters.
	 * @return int
	 */
	public function count( array $args = array() ) {
		global $wpdb;
		$table = $this->get_table_name();
		$where = $this->build_where_sql( $args );
		$sql   = "SELECT COUNT(*) FROM {$table} {$where['sql']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) ( empty( $where['params'] )
			? $wpdb->get_var( $sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $sql, $where['params'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns referral/commission totals grouped safely by currency.
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public function get_report_summary( array $args = array() ) {
		$resolver = function () use ( $args ) {
			global $wpdb;
			$table   = $this->get_table_name();
			$where   = $this->build_where_sql( $args );
			$sql     = "SELECT currency, status, COUNT(*) AS referral_count, COALESCE(SUM(amount), 0) AS order_total, COALESCE(SUM(commission_amount), 0) AS commission_total FROM {$table} {$where['sql']} GROUP BY currency, status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows    = empty( $where['params'] ) ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, $where['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$summary = array(
				'count'             => 0,
				'order_totals'      => array(),
				'commission_totals' => array(),
				'paid_totals'       => array(),
				'open_totals'       => array(),
				'cancelled_totals'  => array(),
			);

			foreach ( $rows as $row ) {
				$currency                                  = $row->currency ? strtoupper( $row->currency ) : '';
				$status                                    = sanitize_key( $row->status );
				$count                                     = (int) $row->referral_count;
				$order                                     = (float) $row->order_total;
				$commission                                = (float) $row->commission_total;
				$summary['count']                         += $count;
				$summary['order_totals'][ $currency ]      = ( $summary['order_totals'][ $currency ] ?? 0 ) + $order;
				$summary['commission_totals'][ $currency ] = ( $summary['commission_totals'][ $currency ] ?? 0 ) + $commission;
				if ( 'paid' === $status ) {
					$summary['paid_totals'][ $currency ] = ( $summary['paid_totals'][ $currency ] ?? 0 ) + $commission;
				} elseif ( in_array( $status, array( 'pending', 'unpaid', 'processing' ), true ) ) {
					$summary['open_totals'][ $currency ] = ( $summary['open_totals'][ $currency ] ?? 0 ) + $commission;
				} elseif ( 'cancelled' === $status ) {
					$summary['cancelled_totals'][ $currency ] = ( $summary['cancelled_totals'][ $currency ] ?? 0 ) + $commission;
				}
			}
			return $summary;
		};

		return isset( affilio()->performance_cache )
			? affilio()->performance_cache->remember( 'referral-summary-v2', $args, $resolver, 300 )
			: $resolver();
	}

	/**
	 * Returns currency codes currently present in referral data.
	 *
	 * @return string[]
	 */
	public function get_currencies() {
		$resolver = function () {
			global $wpdb;
			$table = $this->get_table_name();
			$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT currency FROM %i WHERE currency IS NOT NULL AND currency <> '' ORDER BY currency ASC", $table ) );
			return array_values( array_filter( array_map( 'sanitize_text_field', $rows ) ) );
		};

		return isset( affilio()->performance_cache )
			? affilio()->performance_cache->remember( 'referral-currencies', array(), $resolver, 600 )
			: $resolver();
	}

	/**
	 * Builds referral report WHERE SQL.
	 *
	 * @param array $args Query filters.
	 * @return array{sql:string,params:array}
	 */
	private function build_where_sql( array $args ) {
		global $wpdb;

		$clauses = array();
		$params  = array();

		if ( ! empty( $args['affiliate_id'] ) ) {
			$clauses[] = 'affiliate_id = %d';
			$params[]  = absint( $args['affiliate_id'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$allowed_statuses = \Affilio\Domain\Referral\ReferralStatus::all();
			$status           = sanitize_key( $args['status'] );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$clauses[] = 'status = %s';
				$params[]  = $status;
			}
		}

		if ( ! empty( $args['currency'] ) ) {
			$clauses[] = 'currency = %s';
			$params[]  = strtoupper( sanitize_text_field( $args['currency'] ) );
		}

		if ( ! empty( $args['source'] ) ) {
			$source = sanitize_key( $args['source'] );
			if ( in_array( $source, array( 'link', 'coupon', 'manual', 'import' ), true ) ) {
				$clauses[] = 'source = %s';
				$params[]  = $source;
			}
		}

		if ( ! empty( $args['campaign'] ) ) {
			$clauses[] = 'campaign = %s';
			$params[]  = substr( sanitize_text_field( $args['campaign'] ), 0, 100 );
		}

		/*
		 * A referral is, by definition, a recorded conversion. The Reports screen
		 * applies its Conversion filter to both the click log and the summary cards.
		 * Therefore a "Not converted" report must not include referral rows or
		 * commission totals. Converted reports need no extra referral predicate: every
		 * referral row already represents a conversion, while the remaining filters
		 * (affiliate, campaign, and date range) continue to scope the dataset.
		 */
		if ( isset( $args['converted'] ) && '' !== (string) $args['converted'] && 0 === (int) $args['converted'] ) {
			$clauses[] = '1 = 0';
		}

		if ( ! empty( $args['coupon_code'] ) ) {
			$clauses[] = 'coupon_code = %s';
			$params[]  = substr( sanitize_text_field( $args['coupon_code'] ), 0, 100 );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$clauses[] = 'date_created >= %s';
			$params[]  = sanitize_text_field( $args['date_from'] );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$clauses[] = 'date_created <= %s';
			$params[]  = sanitize_text_field( $args['date_to'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$search = trim( sanitize_text_field( (string) $args['search'] ) );

			if ( '' !== $search ) {
				$status_search = sanitize_key( strtolower( $search ) );
				$status_map    = array(
					'pending'    => 'pending',
					'unpaid'     => 'unpaid',
					'processing' => 'processing',
					'paid'       => 'paid',
					'cancelled'  => 'cancelled',
					'canceled'   => 'cancelled',
				);

				/*
				 * Status terms are intentionally exact. A partial LIKE comparison would
				 * make "paid" match "unpaid", which is misleading on a financial
				 * administration screen.
				 */
				if ( isset( $status_map[ $status_search ] ) ) {
					$clauses[] = 'status = %s';
					$params[]  = $status_map[ $status_search ];
				} else {
					$affiliate_table = affilio()->affiliates_db->get_table_name();
					$like            = '%' . $wpdb->esc_like( $search ) . '%';
					$search_clauses  = array(
						'manual_reference LIKE %s',
						'coupon_code LIKE %s',
						'campaign LIKE %s',
						'currency LIKE %s',
						'source LIKE %s',
						"affiliate_id IN (
							SELECT a.id
							FROM {$affiliate_table} a
							LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
							WHERE a.referral_code LIKE %s
							   OR a.payout_email LIKE %s
							   OR u.user_login LIKE %s
							   OR u.user_email LIKE %s
							   OR u.display_name LIKE %s
						)",
					);

					$params = array_merge( $params, array_fill( 0, 10, $like ) );

					// WordPress users naturally search orders as either "60" or "#60".
					$numeric_search = ltrim( $search, "# \t\n\r\0\x0B" );
					$order_id       = \Affilio\Infrastructure\WordPress\UnsignedBigint::normalize( $numeric_search );

					if ( '' !== $order_id ) {
						$search_clauses[] = 'order_id = %s';
						$params[]         = $order_id;
						$search_clauses[] = 'id = %s';
						$params[]         = $order_id;
					}

					// Exact money searches such as 100 or 20.00 are useful on commission lists.
					if ( is_numeric( $numeric_search ) ) {
						$numeric_amount   = (float) $numeric_search;
						$search_clauses[] = 'amount = %f';
						$params[]         = $numeric_amount;
						$search_clauses[] = 'commission_amount = %f';
						$params[]         = $numeric_amount;
					}

					$clauses[] = '(' . implode( ' OR ', $search_clauses ) . ')';
				}
			}
		}

		return array(
			'sql'    => empty( $clauses ) ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}


	/**
	 * Returns eligible unpaid commission totals grouped by currency.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return array<string,float>
	 */
	public function get_unpaid_totals_by_currency( $affiliate_id ) {
		global $wpdb;
		$table  = $this->get_table_name();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT currency, COALESCE(SUM(commission_amount), 0) AS total FROM %i WHERE affiliate_id = %d AND status = 'unpaid' AND payout_id IS NULL GROUP BY currency",
				$table,
				absint( $affiliate_id )
			)
		);
		$totals = array();

		foreach ( $rows as $row ) {
			$currency = strtoupper( substr( sanitize_text_field( (string) $row->currency ), 0, 10 ) );
			if ( '' !== $currency ) {
				$totals[ $currency ] = (float) $row->total;
			}
		}

		return $totals;
	}

	/**
	 * Returns eligible unpaid referrals for a payout request.
	 *
	 * @param int    $affiliate_id Affiliate ID.
	 * @param string $currency     Currency code.
	 * @param int    $limit        Maximum rows.
	 * @return object[]
	 */
	public function get_unpaid_for_affiliate_currency( $affiliate_id, $currency, $limit = 5000 ) {
		global $wpdb;
		$table = $this->get_table_name();
		$limit = max( 1, min( 5000, absint( $limit ) ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE affiliate_id = %d AND currency = %s AND status = 'unpaid' AND payout_id IS NULL ORDER BY date_created ASC, id ASC LIMIT %d",
				$table,
				absint( $affiliate_id ),
				strtoupper( substr( sanitize_text_field( $currency ), 0, 10 ) ),
				$limit
			)
		);
	}

	/**
	 * Makes legacy held referrals immediately eligible for the Free tier.
	 *
	 * The original date_eligible value is intentionally preserved so a future
	 * add-on or audit can see the historical hold date. Only unlocked pending
	 * referrals that were created with an eligibility date are changed.
	 *
	 * @return int Number of rows released.
	 */
	public function release_legacy_held_referrals_for_free_tier() {
		global $wpdb;
		$table  = $this->get_table_name();
		$reason = __( 'Released when Dreamax Affiliates Free removed holding-period automation.', 'dreamax-affiliates' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'unpaid', status_reason = %s, updated_by = NULL WHERE status = 'pending' AND payout_id IS NULL AND date_eligible IS NOT NULL",
				$table,
				$reason
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared

		return false === $updated ? 0 : absint( $updated );
	}

	/**
	 * Releases held referrals whose eligibility date has arrived.
	 *
	 * @param int $limit Maximum rows per run.
	 * @return int Number of rows released.
	 */
	public function release_eligible( $limit = 500 ) {
		return count( affilio()->commission_approval->release_eligible( $limit ) );
	}

	/**
	 * Returns a bounded list of referral IDs whose hold has expired.
	 *
	 * @param int $limit Maximum rows per run.
	 * @return int[]
	 */
	public function get_eligible_ids( $limit = 500 ) {
		global $wpdb;
		$table = $this->get_table_name();
		$limit = max( 1, min( 2000, absint( $limit ) ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE status = 'pending' AND payout_id IS NULL AND date_eligible IS NOT NULL AND date_eligible <= %s ORDER BY date_eligible ASC, id ASC LIMIT %d",
				$table,
				current_time( 'mysql' ),
				$limit
			)
		);

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Returns the next unreleased hold date for site-local handoff scheduling.
	 *
	 * @return string|null
	 */
	public function get_next_held_eligibility() {
		global $wpdb;
		$table = $this->get_table_name();
		$date  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT date_eligible FROM %i WHERE status = 'pending' AND payout_id IS NULL AND date_eligible IS NOT NULL ORDER BY date_eligible ASC, id ASC LIMIT 1",
				$table
			)
		);

		return is_string( $date ) && '' !== $date ? $date : null;
	}

	/**
	 * Conditionally releases a known ID list and returns only rows changed by
	 * this execution. A short-lived marker prevents audit/email events from
	 * being emitted for rows released by another concurrent worker.
	 *
	 * @param int[] $ids Referral IDs selected by the current worker.
	 * @return int[] Released referral IDs.
	 */
	public function release_eligible_ids( array $ids ) {
		global $wpdb;
		$table = $this->get_table_name();
		$ids   = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 2000 );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$marker       = 'affilio-release:' . wp_generate_uuid4();
		$now          = current_time( 'mysql' );
		$sql          = "UPDATE {$table} SET status = 'unpaid', status_reason = %s, updated_by = NULL WHERE status = 'pending' AND payout_id IS NULL AND date_eligible IS NOT NULL AND date_eligible <= %s AND id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params       = array_merge( array( $marker, $now ), $ids );
		$updated      = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $updated ) {
			return array();
		}

		// No IN (...) here: $marker is a fresh UUID unique to this single call, so any row
		// carrying it was necessarily just set by the UPDATE above, which already restricted
		// the update to `id IN ($ids)`. Re-filtering by $ids here would be redundant — every
		// row this can match is already guaranteed to be one of $ids.
		$released = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE status_reason = %s ORDER BY id ASC', $table, $marker )
		);
		$released = array_values( array_filter( array_map( 'absint', (array) $released ) ) );

		if ( ! empty( $released ) ) {
			$released_placeholders = implode( ',', array_fill( 0, count( $released ), '%d' ) );
			$normalize_params      = array_merge(
				array( __( 'Commission holding period completed.', 'dreamax-affiliates' ), $marker ),
				$released
			);
			// Built as a $sql variable (rather than an inline string literal) to match this
			// file's other dynamic-IN-clause queries above: the linter only attempts to count
			// prepare() placeholders against an inline string literal, and its counter does not
			// evaluate the dynamically-built {$released_placeholders} list, which is what
			// produced the false "wrong number of replacements" report on the inline form.
			// The real counts match: 2 %s + count($released) %d placeholders in $sql, against
			// 2 + count($released) elements in $normalize_params.
			$sql = "UPDATE {$table} SET status_reason = %s WHERE status_reason = %s AND id IN ({$released_placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( $sql, $normalize_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $released;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
