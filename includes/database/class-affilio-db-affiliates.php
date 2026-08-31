<?php
/**
 * Data access for the affiliates table — one row per affiliate account.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
/*
 * This class is a custom-table data-access layer (Dreamax Affiliates' own affiliates table, not a WordPress
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
class Affilio_DB_Affiliates extends Affilio_DB {

	/**
	 * @var string
	 */
	protected $table_suffix = 'affiliates';

	/**
	 * Creates or upgrades the affiliates table.
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$table           = $this->get_table_name();
		$charset_collate = esc_sql( $wpdb->get_charset_collate() ); // Defensive, same reasoning as get_table_name()'s esc_sql() wrap.

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes only from get_table_name().
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			referral_code VARCHAR(32) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payout_method VARCHAR(30) NOT NULL DEFAULT 'paypal',
			payout_email VARCHAR(100) DEFAULT NULL,
			payout_details TEXT NULL,
			website_url VARCHAR(255) DEFAULT NULL,
			promotion_method TEXT NULL,
			social_profile VARCHAR(255) DEFAULT NULL,
			application_message TEXT NULL,
			status_reason TEXT NULL,
			commission_type VARCHAR(20) DEFAULT NULL,
			commission_rate DECIMAL(10,4) DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			date_registered DATETIME NOT NULL,
			date_approved DATETIME DEFAULT NULL,
			date_status_changed DATETIME DEFAULT NULL,
			updated_by BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY referral_code (referral_code),
			KEY status (status),
			KEY date_registered (date_registered),
			KEY status_registered (status, date_registered)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Looks up an affiliate by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return object|null
	 */
	public function get_by_user_id( $user_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d", $table, $user_id )
		);
	}

	/**
	 * Looks up an affiliate by referral code.
	 *
	 * @param string $code Referral code.
	 * @return object|null
	 */
	public function get_by_referral_code( $code ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE referral_code = %s", $table, $code )
		);
	}

	/**
	 * Retrieves affiliates with database-level filtering and pagination.
	 *
	 * @param array $args Query arguments.
	 * @return object[]
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'  => '',
				'search'  => '',
				'orderby' => 'date_registered',
				'order'   => 'DESC',
				'number'  => 20,
				'offset'  => 0,
			)
		);

		$table          = $this->get_table_name();
		$allowed_status = \Affilio\Domain\Affiliate\AffiliateStatus::all();
		$allowed_order  = array(
			'id'              => 'a.id',
			'name'            => 'u.display_name',
			'email'           => 'u.user_email',
			'referral_code'   => 'a.referral_code',
			'payout_method'   => 'a.payout_method',
			'status'          => 'a.status',
			'date_registered' => 'a.date_registered',
		);

		$status  = in_array( $args['status'], $allowed_status, true ) ? $args['status'] : '';
		$search  = sanitize_text_field( (string) $args['search'] );
		$orderby = isset( $allowed_order[ $args['orderby'] ] ) ? $allowed_order[ $args['orderby'] ] : $allowed_order['date_registered'];
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$number  = max( 1, min( 5000, absint( $args['number'] ) ) );
		$offset  = max( 0, absint( $args['offset'] ) );
		$where   = array( '1=1' );
		$params  = array();

		if ( $status ) {
			$where[]  = 'a.status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(a.referral_code LIKE %s OR a.payout_email LIKE %s OR a.website_url LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
			$params  = array_merge( $params, array_fill( 0, 6, $like ) );
		}

		$sql = "SELECT a.*, u.display_name, u.user_email FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE " . implode( ' AND ', $where ) . " ORDER BY {$orderby} {$order}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$sql     .= ' LIMIT %d OFFSET %d';
		$params[] = $number;
		$params[] = $offset;

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Counts affiliates matching the supplied filters.
	 *
	 * @param array $args Query arguments. Supports status and search.
	 * @return int
	 */
	public function count( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status' => '',
				'search' => '',
			)
		);

		$table          = $this->get_table_name();
		$allowed_status = \Affilio\Domain\Affiliate\AffiliateStatus::all();
		$status         = in_array( $args['status'], $allowed_status, true ) ? $args['status'] : '';
		$search         = sanitize_text_field( (string) $args['search'] );
		$where          = array( '1=1' );
		$params         = array();

		if ( $status ) {
			$where[]  = 'a.status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(a.referral_code LIKE %s OR a.payout_email LIKE %s OR a.website_url LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
			$params  = array_merge( $params, array_fill( 0, 6, $like ) );
		}

		$sql = "SELECT COUNT(*) FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE " . implode( ' AND ', $where ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Retrieves all affiliates, optionally filtered by status.
	 *
	 * Intended for bounded selector/export workflows. Large admin tables use
	 * query() and count() instead.
	 *
	 * @param string $status Optional status.
	 * @return object[]
	 */
	public function get_all( $status = '' ) {
		$limit = (int) apply_filters( 'affilio_legacy_affiliate_get_all_limit', 5000 );
		$limit = max( 1, min( 5000, $limit ) );

		return $this->query(
			array(
				'status' => $status,
				'number' => $limit,
			)
		);
	}

	/**
	 * Retrieves bounded affiliate choices with WordPress display data in one
	 * query, avoiding one get_userdata() call per option.
	 *
	 * @param string $status Optional affiliate status.
	 * @param int    $limit  Maximum choices.
	 * @return object[]
	 */
	public function query_for_selector( $status = '', $limit = 2000 ) {
		global $wpdb;

		$table           = $this->get_table_name();
		$allowed_status  = \Affilio\Domain\Affiliate\AffiliateStatus::all();
		$status          = in_array( $status, $allowed_status, true ) ? $status : '';
		$limit           = max( 1, min( 5000, absint( $limit ) ) );
		$where           = $status ? 'WHERE a.status = %s' : '';
		$params          = $status ? array( $status, $limit ) : array( $limit );
		$sql             = "SELECT a.id, a.user_id, a.referral_code, a.status, u.display_name, u.user_email FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id {$where} ORDER BY u.display_name ASC, a.id ASC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns display names for affiliate IDs in one joined query.
	 *
	 * @param int[] $ids Affiliate row IDs.
	 * @return array<int,string>
	 */
	public function get_display_names_by_ids( array $ids ) {
		global $wpdb;

		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 1000 );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $this->get_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT a.id, u.display_name FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE a.id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$names        = array();

		foreach ( (array) $rows as $row ) {
			$names[ (int) $row->id ] = '' !== (string) $row->display_name ? (string) $row->display_name : '#' . (int) $row->id;
		}

		return $names;
	}

	/**
	 * Returns payout/contact emails for affiliate IDs in one bounded query.
	 *
	 * Payout requests intentionally keep the payment destination separate from
	 * the profile contact email. The admin queue uses this helper to show both
	 * without performing one database query per request row.
	 *
	 * @param int[] $ids Affiliate row IDs.
	 * @return array<int,string>
	 */
	public function get_payout_emails_by_ids( array $ids ) {
		global $wpdb;

		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 1000 );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $this->get_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT id, payout_email FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$emails       = array();

		foreach ( (array) $rows as $row ) {
			$email = is_email( (string) $row->payout_email ) ? sanitize_email( (string) $row->payout_email ) : '';
			$emails[ (int) $row->id ] = $email;
		}

		return $emails;
	}

	/**
	 * Retrieves a bounded affiliate export batch with the WordPress user email
	 * in the same query, avoiding one user lookup per CSV row.
	 *
	 * @param int $number Maximum rows.
	 * @param int $offset Row offset.
	 * @return object[]
	 */
	public function query_for_export( $number = 500, $offset = 0 ) {
		global $wpdb;

		$table  = $this->get_table_name();
		$number = max( 1, min( 1000, absint( $number ) ) );
		$offset = max( 0, absint( $offset ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, u.user_email FROM %i a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id ORDER BY a.id ASC LIMIT %d OFFSET %d",
				$table,
				$number,
				$offset
			)
		);
	}

	/**
	 * Retrieves an affiliate export batch after a stable row ID.
	 *
	 * Keyset pagination keeps later CSV batches efficient as the table grows
	 * and avoids rows being skipped when records change during an export.
	 *
	 * @param int $after_id Last exported affiliate ID.
	 * @param int $number   Maximum rows.
	 * @return object[]
	 */
	public function query_for_export_after_id( $after_id = 0, $number = 500 ) {
		global $wpdb;

		$table    = $this->get_table_name();
		$after_id = max( 0, absint( $after_id ) );
		$number   = max( 1, min( 1000, absint( $number ) ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, u.user_email FROM %i a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE a.id > %d ORDER BY a.id ASC LIMIT %d",
				$table,
				$after_id,
				$number
			)
		);
	}

	/**
	 * Counts affiliates per status for filter views.
	 *
	 * @return array<string,int>
	 */
	public function get_status_counts() {
		$resolver = function () {
			global $wpdb;
			$table  = $this->get_table_name();
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS total FROM %i GROUP BY status", $table ) );
			$counts = array_fill_keys( \Affilio\Domain\Affiliate\AffiliateStatus::all(), 0 );

			foreach ( $rows as $row ) {
				if ( isset( $counts[ $row->status ] ) ) {
					$counts[ $row->status ] = (int) $row->total;
				}
			}

			return $counts;
		};

		return isset( affilio()->performance_cache )
			? affilio()->performance_cache->remember( 'affiliate-status-counts', array(), $resolver, 300 )
			: $resolver();
	}

	/**
	 * Returns whether an affiliate has financial history that must be retained.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return bool
	 */
	public function has_financial_history( $affiliate_id ) {
		global $wpdb;
		$referrals = $wpdb->prefix . 'affilio_referrals';
		$payouts   = $wpdb->prefix . 'affilio_payouts';

		$referral_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE affiliate_id = %d", $referrals, absint( $affiliate_id ) )
		);
		if ( $referral_count > 0 ) {
			return true;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE affiliate_id = %d", $payouts, absint( $affiliate_id ) )
		) > 0;
	}

}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
