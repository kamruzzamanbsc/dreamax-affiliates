<?php
/**
 * Data access for affiliate-initiated payout requests.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
/*
 * This class is a custom-table data-access layer (Dreamax Affiliates' own affiliate payout-requests table, not a WordPress
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
class Affilio_DB_Payout_Requests extends Affilio_DB {

	/**
	 * @var string
	 */
	protected $table_suffix = 'payout_requests';

	/**
	 * Creates or upgrades the payout-request table.
	 *
	 * The nullable unique open_key prevents duplicate open requests for the
	 * same affiliate and currency while allowing unlimited closed history.
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$table           = $this->get_table_name();
		$charset_collate = esc_sql( $wpdb->get_charset_collate() ); // Defensive, same reasoning as get_table_name()'s esc_sql() wrap.

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internally generated table name.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			payout_id BIGINT UNSIGNED DEFAULT NULL,
			amount DECIMAL(15,4) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'requested',
			open_key VARCHAR(80) DEFAULT NULL,
			payment_method VARCHAR(30) NOT NULL DEFAULT 'paypal',
			payment_destination TEXT NULL,
			request_note TEXT NULL,
			admin_note TEXT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			reviewed_by BIGINT UNSIGNED DEFAULT NULL,
			date_created DATETIME NOT NULL,
			date_reviewed DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY open_key (open_key),
			KEY affiliate_id (affiliate_id),
			KEY affiliate_currency_status (affiliate_id, currency, status),
			KEY payout_id (payout_id),
			KEY status (status),
			KEY status_date (status, date_created),
			KEY date_created (date_created)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Returns recent requests for an affiliate.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @param int $limit        Maximum rows.
	 * @param int $offset       Number of rows to skip.
	 * @return object[]
	 */
	public function get_by_affiliate( $affiliate_id, $limit = 20, $offset = 0 ) {
		global $wpdb;
		$table  = $this->get_table_name();
		$limit  = max( 1, min( 100, absint( $limit ) ) );
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
	 * Finds an open request for one affiliate/currency pair.
	 *
	 * @param int    $affiliate_id Affiliate ID.
	 * @param string $currency     Currency code.
	 * @return object|null
	 */
	public function get_open( $affiliate_id, $currency ) {
		global $wpdb;
		$table    = $this->get_table_name();
		$open_key = $this->get_open_key( $affiliate_id, $currency );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE open_key = %s LIMIT 1", $table, $open_key )
		);
	}

	/**
	 * Returns an open-key value.
	 *
	 * @param int    $affiliate_id Affiliate ID.
	 * @param string $currency     Currency code.
	 * @return string
	 */
	public function get_open_key( $affiliate_id, $currency ) {
		return absint( $affiliate_id ) . ':' . strtoupper( substr( sanitize_text_field( $currency ), 0, 10 ) );
	}

	/**
	 * Returns paged requests for admin screens.
	 *
	 * @param array $args Query arguments.
	 * @return object[]
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$table = $this->get_table_name();
		$args  = wp_parse_args(
			$args,
			array(
				'status'       => '',
				'affiliate_id' => 0,
				'number'       => 20,
				'offset'       => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array();
		$status = sanitize_key( $args['status'] );

		if ( in_array( $status, array( 'requested', 'approved', 'rejected', 'cancelled' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( absint( $args['affiliate_id'] ) ) {
			$where[]  = 'affiliate_id = %d';
			$params[] = absint( $args['affiliate_id'] );
		}

		$number   = max( 1, min( 200, absint( $args['number'] ) ) );
		$offset   = max( 0, absint( $args['offset'] ) );
		$params[] = $number;
		$params[] = $offset;

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Counts requests for admin filters.
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public function count( array $args = array() ) {
		global $wpdb;
		$table = $this->get_table_name();
		$args  = wp_parse_args( $args, array( 'status' => '', 'affiliate_id' => 0 ) );
		$where = array( '1=1' );
		$params = array();
		$status = sanitize_key( $args['status'] );

		if ( in_array( $status, array( 'requested', 'approved', 'rejected', 'cancelled' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( absint( $args['affiliate_id'] ) ) {
			$where[]  = 'affiliate_id = %d';
			$params[] = absint( $args['affiliate_id'] );
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) ( empty( $params ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Updates a request only when it remains in the expected state.
	 *
	 * @param int    $id              Request ID.
	 * @param string $expected_status Current status.
	 * @param array  $data            Fields to update.
	 * @param array  $formats         Formats.
	 * @return int|false
	 */
	public function update_if_status( $id, $expected_status, array $data, array $formats = array() ) {
		global $wpdb;

		$allowed_formats = array(
			'payout_id'           => '%d',
			'amount'              => '%f',
			'currency'            => '%s',
			'status'              => '%s',
			'open_key'            => '%s',
			'payment_method'      => '%s',
			'payment_destination' => '%s',
			'request_note'        => '%s',
			'admin_note'          => '%s',
			'created_by'          => '%d',
			'reviewed_by'         => '%d',
			'date_created'        => '%s',
			'date_reviewed'       => '%s',
		);
		$assignments = array();
		$params      = array();

		foreach ( $data as $column => $value ) {
			if ( ! isset( $allowed_formats[ $column ] ) ) {
				continue;
			}

			if ( null === $value ) {
				$assignments[] = "`{$column}` = NULL";
				continue;
			}

			$assignments[] = "`{$column}` = {$allowed_formats[ $column ]}";
			$params[]      = $value;
		}

		if ( empty( $assignments ) ) {
			return false;
		}

		$params[] = absint( $id );
		$params[] = sanitize_key( $expected_status );
		$table    = $this->get_table_name();
		$sql      = "UPDATE {$table} SET " . implode( ', ', $assignments ) . ' WHERE id = %d AND status = %s'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Anonymizes payout destination and free-text notes while retaining ledger status.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return int|false
	 */
	public function anonymize_by_affiliate( $affiliate_id ) {
		global $wpdb;
		return $wpdb->update(
			$this->get_table_name(),
			array(
				'payment_destination' => '',
				'request_note'        => '',
				'admin_note'          => '',
			),
			array( 'affiliate_id' => absint( $affiliate_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
