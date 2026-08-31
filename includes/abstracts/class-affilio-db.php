<?php
/**
 * Abstract base class for all custom-table data access.
 *
 * Each concrete child only needs to set $table_suffix, implement
 * create_table(), and add whatever entity-specific lookup methods it
 * needs — get()/insert()/update()/delete() are shared here so that logic
 * is not repeated across database classes.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
/*
 * Shared base class for all of Dreamax Affiliates' custom-table data access (see get() below); every
 * concrete child (Affilio_DB_Affiliates, _Referrals, _Visits, _Payouts, _Events,
 * _Payout_Requests) extends this. See the matching comment in class-affilio-db-referrals.php
 * for the full reasoning: these tables are not WordPress core tables, so core object-cache
 * groups do not apply, and every {$table}/{$this->primary_key} interpolation below comes only
 * from get_table_name() and the hardcoded $primary_key property, never from user input.
 */
abstract class Affilio_DB {

	/**
	 * Table name suffix, e.g. 'affiliates'. Set by each child class.
	 *
	 * @var string
	 */
	protected $table_suffix = '';

	/**
	 * Primary key column name. Shared by the current tables, but
	 * kept overridable per child class rather than assumed.
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Full, prefixed table name for this entity.
	 *
	 * esc_sql() here is defensive, not a fix for a real risk — $table_suffix is a hardcoded
	 * per-class string literal and $wpdb->prefix is WordPress's own configured prefix, so the
	 * result is always a plain identifier with no characters esc_sql() would change. It also
	 * gives every {$table} interpolation throughout the database layer a WPCS-recognized
	 * escaping function to point to.
	 *
	 * @return string
	 */
	public function get_table_name() {
		global $wpdb;
		return esc_sql( $wpdb->prefix . 'affilio_' . $this->table_suffix );
	}

	/**
	 * Fetch a single row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = $this->get_table_name();

		// Table name is built only from get_table_name() above (prefix + a
		// suffix hardcoded in each child class) — never from user input —
		// so interpolating it here is safe even though $wpdb->prepare()
		// can't parameterise identifiers.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$this->primary_key} = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Insert a new row.
	 *
	 * @param array $data    Column => value pairs.
	 * @param array $formats Optional. $wpdb->insert() format specifiers.
	 * @return int|false New row ID, or false on failure.
	 */
	public function insert( array $data, array $formats = array() ) {
		global $wpdb;

		$inserted = $wpdb->insert( $this->get_table_name(), $data, $formats ? $formats : null );

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing row by primary key.
	 *
	 * @param int   $id      Row ID.
	 * @param array $data    Column => value pairs to update.
	 * @param array $formats Optional. $wpdb->update() format specifiers.
	 * @return int|false Number of rows updated, or false on failure.
	 */
	public function update( $id, array $data, array $formats = array() ) {
		global $wpdb;

		return $wpdb->update(
			$this->get_table_name(),
			$data,
			array( $this->primary_key => $id ),
			$formats ? $formats : null,
			array( '%d' )
		);
	}

	/**
	 * Delete a row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public function delete( $id ) {
		global $wpdb;

		return $wpdb->delete(
			$this->get_table_name(),
			array( $this->primary_key => $id ),
			array( '%d' )
		);
	}

	/**
	 * Each child class must define its own dbDelta() CREATE TABLE statement.
	 *
	 * @return void
	 */
	abstract public function create_table();
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
