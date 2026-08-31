<?php
/**
 * Data access for immutable Dreamax Affiliates audit events.
 *
 * @package Dreamax_Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
/*
 * This class is a custom-table data-access layer (Dreamax Affiliates' own audit/events table, not a WordPress
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
class Affilio_DB_Events extends Affilio_DB {

	/**
	 * @var string
	 */
	protected $table_suffix = 'events';

	/**
	 * Creates or upgrades the audit-event table.
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
			object_type VARCHAR(32) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(64) NOT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			reason TEXT NULL,
			context_json LONGTEXT NULL,
			date_created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY object_lookup (object_type, object_id, date_created),
			KEY event_type (event_type),
			KEY event_date (event_type, date_created),
			KEY actor_user_id (actor_user_id),
			KEY date_created (date_created)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Records one bounded audit event.
	 *
	 * @param string $object_type Object family.
	 * @param int    $object_id   Object ID.
	 * @param string $event_type  Event name.
	 * @param int    $actor_id    WordPress user ID, or zero for system.
	 * @param string $reason      Human-provided reason.
	 * @param array  $context     Non-secret structured context.
	 * @return int|false
	 */
	public function record( $object_type, $object_id, $event_type, $actor_id = 0, $reason = '', array $context = array() ) {
		$object_type = substr( sanitize_key( $object_type ), 0, 32 );
		$event_type  = substr( sanitize_key( $event_type ), 0, 64 );
		$reason      = $this->truncate( sanitize_textarea_field( $reason ), 2000 );
		$context     = $this->sanitize_context( $context );

		if ( '' === $object_type || '' === $event_type || ! absint( $object_id ) ) {
			return false;
		}

		return $this->insert(
			array(
				'object_type'  => $object_type,
				'object_id'    => absint( $object_id ),
				'event_type'   => $event_type,
				'actor_user_id'=> absint( $actor_id ) ?: null,
				'reason'       => $reason,
				'context_json' => empty( $context ) ? null : wp_json_encode( $context ),
				'date_created' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns recent events for one object.
	 *
	 * @param string $object_type Object family.
	 * @param int    $object_id   Object ID.
	 * @param int    $limit       Maximum events.
	 * @param int    $offset      Number of rows to skip.
	 * @return object[]
	 */
	public function get_for_object( $object_type, $object_id, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table  = $this->get_table_name();
		$limit  = max( 1, min( 200, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE object_type = %s AND object_id = %d ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d",
				$table,
				substr( sanitize_key( $object_type ), 0, 32 ),
				absint( $object_id ),
				$limit,
				$offset
			)
		);
	}

	/**
	 * Removes unsafe or unbounded context values before storage.
	 *
	 * @param array $context Raw context.
	 * @return array
	 */
	private function sanitize_context( array $context ) {
		$clean = array();
		$count = 0;

		foreach ( $context as $key => $value ) {
			if ( $count >= 30 ) {
				break;
			}

			$key = substr( sanitize_key( (string) $key ), 0, 64 );
			if ( '' === $key || preg_match( '/password|secret|token|nonce|cookie|authorization/i', $key ) ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$clean[ $key ] = $this->truncate( sanitize_text_field( (string) $value ), 500 );
			}

			++$count;
		}

		return $clean;
	}

	/**
	 * Truncates text without requiring the optional mbstring extension.
	 *
	 * @param string $value  Text value.
	 * @param int    $length Maximum characters/bytes.
	 * @return string
	 */
	private function truncate( $value, $length ) {
		$value  = (string) $value;
		$length = max( 0, absint( $length ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	/**
	 * Removes personal detail from retained audit events for one object.
	 *
	 * Event type and timestamp remain available for program-integrity history,
	 * while actor, free-text reason, and structured context are removed.
	 *
	 * @param string $object_type Object family.
	 * @param int    $object_id   Object ID.
	 * @return int|false
	 */
	public function anonymize_for_object( $object_type, $object_id ) {
		global $wpdb;
		$table = $this->get_table_name();

		return $wpdb->update(
			$table,
			array(
				'actor_user_id' => null,
				'reason'        => '',
				'context_json'  => null,
			),
			array(
				'object_type' => substr( sanitize_key( $object_type ), 0, 32 ),
				'object_id'   => absint( $object_id ),
			),
			array( '%d', '%s', '%s' ),
			array( '%s', '%d' )
		);
	}

}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
