<?php
/**
 * Data access for payout records.
 *
 * Each row represents one affiliate/currency payment inside a payout batch.
 * Referral rows are linked through referrals.payout_id.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
/*
 * This class is a custom-table data-access layer (Dreamax Affiliates' own manual payout batches table, not a WordPress
 * core table), so every method here necessarily runs a "direct database query" that WordPress
 * core object-cache groups (built around posts/users/terms) do not cover. Live admin screens
 * also need current, not cached-stale, data. Higher-value read paths (reports/analytics
 * aggregates) already go through Affilio\Infrastructure\WordPress\PerformanceCache instead of
 * being handled here.
 *
 * "Unescaped" table-name parameters below are never user input: every {$table} comes only from
 * Affilio_DB::get_table_name() ($wpdb->prefix + a hardcoded per-class suffix declared just below).
 * Every actual value (IDs, amounts, statuses, dates, etc.) is still bound through
 * $wpdb->prepare() with %s/%d/%f placeholders throughout this file.
 */
class Affilio_DB_Payouts extends Affilio_DB {

	/**
	 * @var string
	 */
	protected $table_suffix = 'payouts';

	/**
	 * Creates/upgrades the payouts table.
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$table           = $this->get_table_name();
		$charset_collate = esc_sql( $wpdb->get_charset_collate() ); // Defensive, same reasoning as get_table_name()'s esc_sql() wrap.

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is generated internally.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_key VARCHAR(40) NOT NULL,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(15,4) NOT NULL DEFAULT 0,
			currency VARCHAR(10) DEFAULT NULL,
			payment_method VARCHAR(30) NOT NULL DEFAULT 'paypal',
			payment_destination TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'processing',
			reference VARCHAR(190) DEFAULT NULL,
			notes TEXT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			date_created DATETIME NOT NULL,
			date_paid DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY batch_key (batch_key),
			KEY affiliate_id (affiliate_id),
			KEY affiliate_date (affiliate_id, date_created),
			KEY status (status),
			KEY status_date (status, date_created),
			KEY date_created (date_created)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}



	/**
	 * Fetches and locks a payout row for the current database transaction.
	 * On transactional engines this prevents payout completion/cancellation
	 * races. On non-transactional engines the conditional updates below are
	 * still the final guard.
	 *
	 * @param int $id Payout ID.
	 * @return object|null
	 */
	public function get_for_update( $id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d FOR UPDATE", $table, $id )
		);
	}

	/**
	 * Updates a payout only while it still has the expected status.
	 *
	 * @param int    $id              Payout ID.
	 * @param string $expected_status Required current status.
	 * @param array  $data            Column/value pairs.
	 * @param array  $formats         Value formats.
	 * @return int|false
	 */
	public function update_if_status( $id, $expected_status, array $data, array $formats = array() ) {
		global $wpdb;

		return $wpdb->update(
			$this->get_table_name(),
			$data,
			array(
				'id'     => absint( $id ),
				'status' => sanitize_key( $expected_status ),
			),
			$formats ? $formats : null,
			array( '%d', '%s' )
		);
	}

	/**
	 * Checks whether a batch key already exists.
	 *
	 * @param string $batch_key Batch key.
	 * @return bool
	 */
	public function batch_key_exists( $batch_key ) {
		global $wpdb;
		$table = $this->get_table_name();

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM %i WHERE batch_key = %s LIMIT 1", $table, $batch_key )
		);
	}

	/**
	 * Returns payout batch keys for IDs in one bounded query.
	 *
	 * @param int[] $ids Payout IDs.
	 * @return array<int,string>
	 */
	public function get_batch_keys_by_ids( array $ids ) {
		global $wpdb;

		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 1000 );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $this->get_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT id, batch_key FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$keys         = array();

		foreach ( (array) $rows as $row ) {
			$keys[ (int) $row->id ] = (string) $row->batch_key;
		}

		return $keys;
	}

	/**
	 * Returns payouts for an affiliate, newest first.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @param int $limit        Maximum rows.
	 * @param int $offset       Number of rows to skip.
	 * @return object[]
	 */
	public function get_by_affiliate( $affiliate_id, $limit = 20, $offset = 0 ) {
		global $wpdb;
		$table  = $this->get_table_name();
		$limit  = max( 1, min( 200, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE affiliate_id = %d ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d",
				$table,
				absint( $affiliate_id ),
				$limit,
				$offset
			)
		);
	}

	/**
	 * Builds the shared WHERE/JOIN fragments used by the admin payout list
	 * and count queries so search results and pagination stay consistent.
	 *
	 * Search intentionally mirrors the visible payout-table data:
	 * Batch, Affiliate, Amount, Method, Destination, Status, Created and Paid.
	 * Existing payment-reference search is retained for backwards compatibility.
	 *
	 * @param array $args Query arguments.
	 * @return array{0:string,1:array,2:string}
	 */
	private function build_admin_query_parts( array $args ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();
		$joins  = '';

		if ( in_array( $args['status'], \Affilio\Domain\Payout\PayoutStatus::all(), true ) ) {
			$where[]  = 'p.status = %s';
			$params[] = $args['status'];
		}

		$search = trim( sanitize_text_field( (string) $args['s'] ) );

		if ( '' === $search ) {
			return array( implode( ' AND ', $where ), $params, $joins );
		}

		$normalized_status = sanitize_key( strtolower( $search ) );

		// Exact status search avoids ambiguous substring matching.
		if ( in_array( $normalized_status, \Affilio\Domain\Payout\PayoutStatus::all(), true ) ) {
			$where[]  = 'p.status = %s';
			$params[] = $normalized_status;

			return array( implode( ' AND ', $where ), $params, $joins );
		}

		// A plain numeric search is treated as an exact displayed amount.
		// This prevents values such as "60" from matching the 202608 portion
		// of every batch key.
		if ( preg_match( '/^\d+(?:\.\d+)?$/', $search ) ) {
			$where[]  = 'p.amount = %f';
			$params[] = (float) $search;

			return array( implode( ' AND ', $where ), $params, $joins );
		}

		// Also support the visible "CURRENCY 60.00" amount format.
		if ( preg_match( '/^([A-Za-z]{2,10})\s+(\d+(?:\.\d+)?)$/', $search, $amount_match ) ) {
			$where[]  = '(p.currency = %s AND p.amount = %f)';
			$params[] = strtoupper( $amount_match[1] );
			$params[] = (float) $amount_match[2];

			return array( implode( ' AND ', $where ), $params, $joins );
		}

		// If the search clearly looks like a date, match both Created and Paid
		// dates using the underlying YYYY-MM-DD value.
		$search_date = $this->normalize_admin_search_date( $search );
		if ( $search_date ) {
			$where[]  = '(DATE(p.date_created) = %s OR DATE(p.date_paid) = %s)';
			$params[] = $search_date;
			$params[] = $search_date;

			return array( implode( ' AND ', $where ), $params, $joins );
		}

		// General text search. Join affiliate/user data only when needed.
		$affiliate_table = affilio()->affiliates_db->get_table_name();
		$joins           = " LEFT JOIN {$affiliate_table} a ON a.id = p.affiliate_id LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$like            = '%' . $wpdb->esc_like( $search ) . '%';

		$where[] = "(
			p.batch_key LIKE %s
			OR u.display_name LIKE %s
			OR p.currency LIKE %s
			OR REPLACE(REPLACE(p.payment_method, '_', ' '), '-', ' ') LIKE %s
			OR p.payment_destination LIKE %s
			OR p.status LIKE %s
			OR p.date_created LIKE %s
			OR p.date_paid LIKE %s
			OR p.reference LIKE %s
		)";
		$params  = array_merge( $params, array_fill( 0, 9, $like ) );

		return array( implode( ' AND ', $where ), $params, $joins );
	}

	/**
	 * Normalizes clearly date-like admin search text to YYYY-MM-DD.
	 *
	 * @param string $search Search text.
	 * @return string
	 */
	private function normalize_admin_search_date( $search ) {
		$looks_like_date = (bool) preg_match(
			'/^(?:\d{4}[-\/]\d{1,2}[-\/]\d{1,2}|\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})$/',
			$search
		);

		if ( ! $looks_like_date ) {
			$looks_like_date = (bool) preg_match(
				'/\b(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b/i',
				$search
			);
		}

		if ( ! $looks_like_date ) {
			return '';
		}

		$timestamp = strtotime( $search );
		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Returns a paginated payout list for the admin table.
	 *
	 * @param array $args Query arguments.
	 * @return object[]
	 */
	public function get_paged( array $args = array() ) {
		global $wpdb;
		$table = $this->get_table_name();
		$args  = wp_parse_args(
			$args,
			array(
				'number' => 20,
				'offset' => 0,
				'status' => '',
				's'      => '',
			)
		);

		list( $where_sql, $params, $joins ) = $this->build_admin_query_parts( $args );

		$number   = max( 1, min( 200, absint( $args['number'] ) ) );
		$offset   = max( 0, absint( $args['offset'] ) );
		$params[] = $number;
		$params[] = $offset;

		$sql = "SELECT p.* FROM {$table} p{$joins} WHERE {$where_sql} ORDER BY p.date_created DESC, p.id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Counts rows matching the admin payout filters.
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public function count( array $args = array() ) {
		global $wpdb;
		$table = $this->get_table_name();
		$args  = wp_parse_args( $args, array( 'status' => '', 's' => '' ) );

		list( $where_sql, $params, $joins ) = $this->build_admin_query_parts( $args );

		$sql = "SELECT COUNT(*) FROM {$table} p{$joins} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Counts payouts per status.
	 *
	 * @return array<string,int>
	 */
	public function get_status_counts() {
		$resolver = function () {
			global $wpdb;
			$table  = $this->get_table_name();
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS total FROM %i GROUP BY status", $table ) );
			$counts = array(
				'processing' => 0,
				'paid'       => 0,
				'cancelled'  => 0,
				'failed'     => 0,
			);

			foreach ( $rows as $row ) {
				if ( isset( $counts[ $row->status ] ) ) {
					$counts[ $row->status ] = (int) $row->total;
				}
			}

			return $counts;
		};

		return isset( affilio()->performance_cache )
			? affilio()->performance_cache->remember( 'payout-status-counts', array(), $resolver, 300 )
			: $resolver();
	}

	/**
	 * Removes personal payout destination snapshots for one affiliate while
	 * retaining accounting totals and statuses.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return int|false
	 */
	public function anonymize_by_affiliate( $affiliate_id ) {
		global $wpdb;

		return $wpdb->update(
			$this->get_table_name(),
			array( 'payment_destination' => '' ),
			array( 'affiliate_id' => absint( $affiliate_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
