<?php
/**
 * Centralized activation, deactivation, and uninstall cleanup primitives.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
/*
 * This class performs bounded activation/deactivation/uninstall maintenance (clearing
 * transients, deleting Dreamax Affiliates' own options/tables, batched cleanup) directly against the
 * database, not through cacheable WordPress core APIs — the same reasoning as the custom-table
 * data-access classes in includes/database/ (see the comment there). These are one-off admin
 * lifecycle operations, not repeated content queries, so caching does not apply, and every
 * interpolated identifier below is a fixed, internal name (option/table names owned by this
 * plugin), never user input.
 */
/**
 * Owns the complete list of Dreamax Affiliates runtime state and permanent data keys.
 */
final class PluginLifecycle {

	/**
	 * Plugin cron hooks.
	 *
	 * @var string[]
	 */
	private const CRON_HOOKS = array(
		'affilio_cleanup_expired_visit_ips',
		'affilio_release_eligible_referrals',
		'affilio_network_sync_sites',
	);

	/**
	 * Plugin-owned options removed only after explicit destructive consent.
	 *
	 * @var string[]
	 */
	private const DATA_OPTIONS = array(
		'affilio_version',
		'affilio_db_version',
		'affilio_auto_approve_affiliates',
		'affilio_default_commission_type',
		'affilio_default_commission_rate',
		'affilio_blocked_domains',
		'affilio_cookie_duration_days',
		'affilio_attribution_model',
		'affilio_coupon_attribution_priority',
		'affilio_anonymize_ip_addresses',
		'affilio_ip_retention_days',
		'affilio_ip_velocity_threshold',
		'affilio_ip_velocity_window_hours',
		'affilio_registration_page_id',
		'affilio_dashboard_page_id',
		'affilio_enable_my_account_tab',
		'affilio_setup_completed',
		'affilio_delete_data_on_uninstall',
		'affilio_cache_generation',
		'affilio_registration_fields',
		'affilio_qualifying_order_statuses',
		'affilio_commission_holding_days',
		'affilio_commission_handoff',
		'affilio_enable_payout_requests',
		'affilio_minimum_payout_threshold',
		'affilio_email_templates',
		'affilio_email_notifications',
		FreeTierDataPreserver::MARKER_OPTION,
	);

	/**
	 * Short-lived state that is always safe to remove.
	 *
	 * @var string[]
	 */
	private const TEMPORARY_OPTIONS = array(
		'affilio_setup_redirect',
		'affilio_rewrite_flush_required',
		'affilio_upgrade_pending',
		'affilio_upgrade_lock',
	);

	/**
	 * Plugin-owned custom table suffixes in dependency-safe drop order.
	 *
	 * @var string[]
	 */
	private const TABLE_SUFFIXES = array(
		'affilio_payout_requests',
		'affilio_events',
		'affilio_payouts',
		'affilio_referrals',
		'affilio_visits',
		'affilio_affiliates',
	);

	/** Network option used to resume bounded network uninstall cleanup. */
	private const NETWORK_UNINSTALL_STATE_OPTION = 'affilio_network_uninstall_state';

	/** Number of sites queried per network-uninstall batch. */
	private const NETWORK_UNINSTALL_BATCH_SIZE = 10;

	/**
	 * Adds lifecycle defaults without overwriting administrator choices.
	 *
	 * @return void
	 */
	public static function ensure_defaults() {
		add_option( 'affilio_delete_data_on_uninstall', 0, '', false );
		add_option( 'affilio_cache_generation', 1, '', false );
		add_option( 'affilio_registration_fields', array(), '', false );
		add_option( 'affilio_email_notifications', array(), '', false );
	}

	/**
	 * Keeps potentially large configuration arrays out of the alloptions cache.
	 *
	 * WordPress 6.6+ exposes a public bulk helper. Earlier supported versions
	 * use one bounded direct update because update_option() cannot change the
	 * autoload flag of an existing row reliably.
	 *
	 * @return void
	 */
	public static function normalize_large_option_autoloading() {
		$options = array(
			'affilio_registration_fields'        => false,
			'affilio_blocked_domains'            => false,
			'affilio_email_templates'            => false,
			'affilio_email_notifications'        => false,
			FreeTierDataPreserver::MARKER_OPTION => false,
		);

		// wp_set_option_autoload_values() requires WP 6.4; Dreamax Affiliates supports back to WP 6.0. The
		// function_exists() guard below is the standard, safe WordPress pattern for this: on
		// 6.0-6.3 the guard is false and the direct-query fallback beneath runs instead, so this
		// call never executes on a WordPress version that lacks it. Plugin Check's version-
		// compatibility check cannot see through this guard and will keep flagging this line
		// (as an ERROR) until "Requires at least" in the plugin header is raised to 6.4 or later
		// — that is a product decision (dropping official 6.0-6.3 support) rather than a code fix.
		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( $options );
			return;
		}

		global $wpdb;
		$names        = array_keys( $options );
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$sql          = "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $names ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Returns all cron hooks owned by the plugin.
	 *
	 * @return string[]
	 */
	public static function cron_hooks() {
		return self::CRON_HOOKS;
	}

	/**
	 * Returns all persistent option names owned by the plugin.
	 *
	 * @return string[]
	 */
	public static function data_options() {
		return self::DATA_OPTIONS;
	}

	/**
	 * Clears all scheduled Dreamax Affiliates jobs for the current site.
	 *
	 * @return void
	 */
	public static function clear_scheduled_events() {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Clears non-permanent runtime state for deactivation or uninstall.
	 *
	 * Migration progress is preserved during ordinary deactivation so an
	 * interrupted upgrade can resume after reactivation. Uninstall explicitly
	 * opts into clearing it because the code being removed can no longer resume.
	 *
	 * @param bool $clear_migration_state Whether to remove persisted migration progress.
	 * @return void
	 */
	public static function clear_runtime_state( $clear_migration_state = false ) {
		PerformanceCache::invalidate_current_site();

		foreach ( self::TEMPORARY_OPTIONS as $option ) {
			delete_option( $option );
		}

		if ( $clear_migration_state ) {
			MigrationRunner::clear_state();
		}

		self::delete_plugin_transients();
		self::delete_plugin_locks();
	}

	/**
	 * Performs all current-site uninstall cleanup.
	 *
	 * @param bool $delete_data Whether permanent program data should be deleted.
	 * @return void
	 */
	public static function uninstall_current_site( $delete_data ) {
		self::clear_scheduled_events();
		self::clear_runtime_state( true );

		if ( ! $delete_data ) {
			return;
		}

		self::delete_creatives();
		self::delete_plugin_metadata();
		self::drop_custom_tables();

		foreach ( self::DATA_OPTIONS as $option ) {
			delete_option( $option );
		}
	}


	/**
	 * Performs resumable network uninstall cleanup within a bounded time budget.
	 *
	 * WordPress executes uninstall.php once before deleting plugin files. On a
	 * large network this method persists progress after every site and returns
	 * false before the request approaches a timeout. Re-running plugin deletion
	 * resumes at the next unprocessed site instead of starting over.
	 *
	 * Each site keeps its own delete-data preference. Capabilities are removed
	 * through the optional callback regardless of whether program data is kept.
	 *
	 * @param int           $network_id        Network ID.
	 * @param int           $time_budget       Maximum seconds spent in one request.
	 * @param callable|null $after_site_cleanup Optional per-site callback.
	 * @return bool True when every site is complete; false when another request is required.
	 */
	public static function uninstall_network( $network_id, $time_budget = 12, $after_site_cleanup = null ) {
		if ( ! is_multisite() ) {
			self::uninstall_current_site( (bool) get_option( 'affilio_delete_data_on_uninstall', false ) );
			if ( is_callable( $after_site_cleanup ) ) {
				$after_site_cleanup();
			}
			return true;
		}

		$network_id  = absint( $network_id );
		$time_budget = max( 3, min( 25, absint( $time_budget ) ) );
		$state       = get_network_option( $network_id, self::NETWORK_UNINSTALL_STATE_OPTION, array() );
		$offset      = is_array( $state ) ? max( 0, absint( $state['offset'] ?? 0 ) ) : 0;
		$started_at  = microtime( true );

		do {
			$site_ids = get_sites(
				array(
					'network_id' => $network_id,
					'number'     => self::NETWORK_UNINSTALL_BATCH_SIZE,
					'offset'     => $offset,
					'fields'     => 'ids',
					'orderby'    => 'id',
					'order'      => 'ASC',
				)
			);

			if ( empty( $site_ids ) ) {
				delete_network_option( $network_id, self::NETWORK_UNINSTALL_STATE_OPTION );
				delete_network_option( $network_id, MultisiteManager::NETWORK_STATE_OPTION );
				delete_network_option( $network_id, MultisiteManager::NETWORK_VERSION_OPTION );
				return true;
			}

			foreach ( $site_ids as $site_id ) {
				$site_id     = absint( $site_id );
				$current_id  = get_current_blog_id();
				$must_switch = $site_id && $site_id !== $current_id;

				if ( $must_switch && ! switch_to_blog( $site_id ) ) {
					return false;
				}

				try {
					$delete_data = (bool) get_option( 'affilio_delete_data_on_uninstall', false );
					self::uninstall_current_site( $delete_data );
					if ( is_callable( $after_site_cleanup ) ) {
						$after_site_cleanup();
					}
				} finally {
					if ( $must_switch ) {
						restore_current_blog();
					}
				}

				++$offset;
				update_network_option(
					$network_id,
					self::NETWORK_UNINSTALL_STATE_OPTION,
					array(
						'offset'     => $offset,
						'updated_at' => time(),
					)
				);

				if ( microtime( true ) - $started_at >= $time_budget ) {
					return false;
				}
			}
		} while ( count( $site_ids ) === self::NETWORK_UNINSTALL_BATCH_SIZE );

		delete_network_option( $network_id, self::NETWORK_UNINSTALL_STATE_OPTION );
		delete_network_option( $network_id, MultisiteManager::NETWORK_STATE_OPTION );
		delete_network_option( $network_id, MultisiteManager::NETWORK_VERSION_OPTION );
		return true;
	}

	/**
	 * Removes all Dreamax Affiliates transients without loading each key into memory.
	 *
	 * @return void
	 */
	private static function delete_plugin_transients() {
		global $wpdb;

		$patterns = array(
			$wpdb->esc_like( '_transient_affilio_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_affilio_' ) . '%',
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$patterns[0],
				$patterns[1]
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Removes database-backed mutexes, including legacy upgrade locks.
	 *
	 * @return void
	 */
	private static function delete_plugin_locks() {
		global $wpdb;

		$lock_pattern = $wpdb->esc_like( 'affilio_lock_' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s",
				$lock_pattern,
				'affilio_upgrade_lock'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Deletes private creative posts in bounded batches.
	 *
	 * @return void
	 */
	private static function delete_creatives() {
		do {
			$creative_ids = get_posts(
				array(
					'post_type'              => 'affilio_creative',
					'post_status'            => 'any',
					'posts_per_page'         => 100,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					// Intentional: this is a complete-deletion pass during opt-in uninstall, not a
					// public content query. Leaving filters active here risks a language/visibility
					// plugin silently scoping the query and leaving orphaned affilio_creative posts
					// behind after the user asked for a full cleanup, which would be worse than the
					// advisory this triggers.
					'suppress_filters'       => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$deleted = 0;
			foreach ( $creative_ids as $creative_id ) {
				if ( wp_delete_post( absint( $creative_id ), true ) ) {
					++$deleted;
				}
			}

			// A database or filesystem failure must not trap uninstall in an
			// infinite loop over the same undeletable batch.
			if ( ! empty( $creative_ids ) && 0 === $deleted ) {
				break;
			}
		} while ( 100 === count( $creative_ids ) );
	}

	/**
	 * Removes only metadata keys with Dreamax Affiliates' unique prefixes.
	 *
	 * @return void
	 */
	private static function delete_plugin_metadata() {
		global $wpdb;

		$post_meta_pattern = $wpdb->esc_like( '_affilio_' ) . '%';
		$term_meta_pattern = $wpdb->esc_like( 'affilio_commission_' ) . '%';

		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $post_meta_pattern ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( isset( $wpdb->termmeta ) ) {
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $term_meta_pattern ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
		$table_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( $hpos_meta_table === $table_exists ) {
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$hpos_meta_table} WHERE meta_key LIKE %s", $post_meta_pattern ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
	}

	/**
	 * Drops the current site's custom tables.
	 *
	 * @return void
	 */
	private static function drop_custom_tables() {
		global $wpdb;

		foreach ( self::TABLE_SUFFIXES as $suffix ) {
			$table_name = $wpdb->prefix . $suffix;
			// Dropping Dreamax Affiliates' own custom tables is the intended effect of opt-in "Delete data on
			// uninstall", not an incidental schema change; $table_name comes only from this
			// class's own fixed table list, never from user input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
