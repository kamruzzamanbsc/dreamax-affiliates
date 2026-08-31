<?php
/**
 * Data access for the visits table — one row per tracked click, whether
 * or not it converted into a referral.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
/*
 * This class is a custom-table data-access layer (Dreamax Affiliates' own visits table (click/visit tracking), not a WordPress
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
class Affilio_DB_Visits extends Affilio_DB {

	/**
	 * @var string
	 */
	protected $table_suffix = 'visits';

	/**
	 * Creates/upgrades the visits table.
	 *
	 * visitor_hash is a one-way HMAC used only for privacy-conscious unique
	 * visitor reporting. The raw IP address and user agent are never stored in
	 * that column and the value is cleared by the same retention job that clears
	 * retained IP addresses.
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
			referral_code VARCHAR(32) DEFAULT NULL,
			token VARCHAR(64) DEFAULT NULL,
			visitor_hash VARCHAR(64) DEFAULT NULL,
			campaign VARCHAR(100) DEFAULT NULL,
			landing_page VARCHAR(255) DEFAULT NULL,
			referrer_url VARCHAR(255) DEFAULT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			converted TINYINT(1) NOT NULL DEFAULT 0,
			date_created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY affiliate_date (affiliate_id, date_created),
			KEY visitor_hash (visitor_hash),
			KEY campaign (campaign),
			KEY campaign_date (campaign, date_created),
			KEY converted (converted),
			KEY converted_date (converted, date_created),
			KEY ip_address (ip_address),
			KEY ip_date (ip_address, date_created),
			KEY date_created (date_created),
			UNIQUE KEY token (token)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Looks up a visit by its opaque tracking token.
	 *
	 * @param string $token Token value.
	 * @return object|null
	 */
	public function get_by_token( $token ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE token = %s", $table, $token )
		);
	}

	/**
	 * Deletes visits belonging to one exact diagnostic campaign.
	 *
	 * @param string $campaign Exact campaign identifier.
	 * @return int|false Number of deleted rows, or false on failure.
	 */
	public function delete_by_campaign( $campaign ) {
		global $wpdb;

		$campaign = sanitize_text_field( (string) $campaign );
		if ( '' === $campaign ) {
			return 0;
		}

		$deleted = $wpdb->delete(
			$this->get_table_name(),
			array( 'campaign' => $campaign ),
			array( '%s' )
		);

		if ( false !== $deleted && isset( affilio()->performance_cache ) ) {
			affilio()->performance_cache->invalidate();
		}

		return $deleted;
	}

	/**
	 * Returns report totals for a filter set.
	 *
	 * @param array $args Filters.
	 * @return array{clicks:int,unique_visitors:int,conversions:int,conversion_rate:float}
	 */
	public function get_report_summary( array $args = array() ) {
		$resolver = function () use ( $args ) {
			global $wpdb;
			$table = $this->get_table_name();
			$where = $this->build_where_sql( $args );
			$sql   = "SELECT
				COUNT(*) AS clicks,
				COUNT(DISTINCT CASE
					WHEN visitor_hash IS NOT NULL AND visitor_hash <> '' THEN visitor_hash
					WHEN token IS NOT NULL AND token <> '' THEN token
					ELSE CONCAT('visit-', id)
				END) AS unique_visitors,
				COALESCE(SUM(converted), 0) AS conversions
				FROM {$table} {$where['sql']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row   = empty( $where['params'] ) ? $wpdb->get_row( $sql ) : $wpdb->get_row( $wpdb->prepare( $sql, $where['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$clicks      = $row ? (int) $row->clicks : 0;
			$conversions = $row ? (int) $row->conversions : 0;
			return array(
				'clicks'          => $clicks,
				'unique_visitors' => $row ? (int) $row->unique_visitors : 0,
				'conversions'     => $conversions,
				'conversion_rate' => $clicks > 0 ? round( ( $conversions / $clicks ) * 100, 2 ) : 0.0,
			);
		};

		return isset( affilio()->performance_cache )
			? affilio()->performance_cache->remember( 'visit-summary', $args, $resolver, 120 )
			: $resolver();
	}

	/**
	 * Returns a paginated visit result set.
	 *
	 * @param array $args Query arguments.
	 * @return object[]
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$table = $this->get_table_name();
		$where = $this->build_where_sql( $args );

		$allowed_orderby = array( 'id', 'affiliate_id', 'campaign', 'converted', 'date_created' );
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
		$table     = $this->get_table_name();
		$where     = $this->build_where_sql( $args );
		$after_id  = max( 0, absint( $after_id ) );
		$number    = max( 1, min( 1000, absint( $number ) ) );
		$sql_where = $where['sql'];
		$sql_where .= $sql_where ? ' AND id > %d' : 'WHERE id > %d';
		$params    = array_merge( $where['params'], array( $after_id, $number ) );
		$sql       = "SELECT * FROM {$table} {$sql_where} ORDER BY id ASC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Counts visits matching a filter set.
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
	 * Counts recent visits from one IP address.
	 *
	 * @param string $ip_address  IP address to check.
	 * @param int    $since_hours How far back to look, in hours.
	 * @return int
	 */
	public function count_recent_by_ip( $ip_address, $since_hours = 24 ) {
		global $wpdb;
		$table = $this->get_table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ip_address = %s AND date_created >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
				$table,
				$ip_address,
				$since_hours
			)
		);
	}

	/**
	 * Marks a visit as converted after a referral is recorded.
	 *
	 * @param int $visit_id Visit row ID.
	 * @return int|false
	 */
	public function mark_converted( $visit_id ) {
		return $this->update( $visit_id, array( 'converted' => 1 ), array( '%d' ) );
	}

	/**
	 * Removes retained IP and pseudonymous visitor values from old visits.
	 *
	 * @param int $retention_days Retention period in days.
	 * @return int|false Number of rows updated, or false on failure.
	 */
	public function anonymize_expired_ips( $retention_days ) {
		global $wpdb;
		$table          = $this->get_table_name();
		$retention_days = max( 1, absint( $retention_days ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $retention_days * DAY_IN_SECONDS ) );

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET ip_address = NULL, visitor_hash = NULL WHERE (ip_address IS NOT NULL OR visitor_hash IS NOT NULL) AND date_created < %s",
				$table,
				$cutoff
			)
		);
	}

	/**
	 * Clears stored privacy identifiers for one affiliate's visits.
	 *
	 * @param int $affiliate_id Affiliate row ID.
	 * @return int|false
	 */
	public function anonymize_by_affiliate( $affiliate_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET ip_address = NULL, visitor_hash = NULL WHERE affiliate_id = %d",
				$table,
				absint( $affiliate_id )
			)
		);
	}

	/**
	 * Builds the WHERE clause shared by reporting queries.
	 *
	 * @param array $args Query filters.
	 * @return array{sql:string,params:array}
	 */
	private function build_where_sql( array $args ) {
		$clauses = array();
		$params  = array();

		if ( ! empty( $args['affiliate_id'] ) ) {
			$clauses[] = 'affiliate_id = %d';
			$params[]  = absint( $args['affiliate_id'] );
		}

		if ( isset( $args['converted'] ) && '' !== (string) $args['converted'] ) {
			$clauses[] = 'converted = %d';
			$params[]  = (int) (bool) $args['converted'];
		}

		if ( ! empty( $args['campaign'] ) ) {
			$clauses[] = 'campaign = %s';
			$params[]  = sanitize_text_field( $args['campaign'] );
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
			global $wpdb;
			$like      = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$clauses[] = '(landing_page LIKE %s OR referrer_url LIKE %s OR referral_code LIKE %s OR campaign LIKE %s)';
			$params    = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		return array(
			'sql'    => empty( $clauses ) ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
