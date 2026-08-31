<?php
/**
 * Bounded multisite initialization and site-lifecycle coordination.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Closure;
use Throwable;

/**
 * Keeps network-activated installations synchronized without looping through
 * an entire network during plugin activation or ordinary page requests.
 */
final class MultisiteManager {

	/** Network option containing the version synchronized across all sites. */
	public const NETWORK_VERSION_OPTION = 'affilio_network_version';

	/** Network option containing bounded synchronization progress. */
	public const NETWORK_STATE_OPTION = 'affilio_network_sync_state';

	/** Cron hook used to continue network synchronization in small batches. */
	public const NETWORK_SYNC_HOOK = 'affilio_network_sync_sites';

	/** Number of sites processed in one request. */
	private const BATCH_SIZE = 10;

	/** Delay before a successful batch continues. */
	private const NEXT_BATCH_DELAY = 30;

	/** Delay before a failed site is retried. */
	private const RETRY_DELAY = 300;

	/**
	 * Synchronizes the current site's schema, defaults, capabilities, and jobs.
	 *
	 * @var Closure
	 */
	private Closure $synchronize_current_site;

	/**
	 * Permanently cleans the current site's Dreamax Affiliates-owned state.
	 *
	 * @var Closure
	 */
	private Closure $cleanup_current_site;

	/**
	 * @param callable $synchronize_current_site Current-site synchronization callback.
	 * @param callable $cleanup_current_site     Current-site destructive cleanup callback.
	 */
	public function __construct( callable $synchronize_current_site, callable $cleanup_current_site ) {
		$this->synchronize_current_site = Closure::fromCallable( $synchronize_current_site );
		$this->cleanup_current_site     = Closure::fromCallable( $cleanup_current_site );
	}

	/**
	 * Registers multisite-only hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'wp_initialize_site', array( $this, 'initialize_new_site' ), 20, 2 );
		add_action( 'wp_uninitialize_site', array( $this, 'cleanup_deleted_site' ), 5, 1 );
		add_action( self::NETWORK_SYNC_HOOK, array( $this, 'process_network_sync_batch' ), 10, 1 );
		add_action( 'network_admin_init', array( $this, 'ensure_network_sync_scheduled' ) );
		add_action( 'network_admin_notices', array( $this, 'render_network_sync_notice' ) );
		add_action( 'admin_post_affilio_network_sync_batch', array( $this, 'handle_manual_network_sync' ) );
	}

	/**
	 * Handles network activation without synchronously traversing every site.
	 *
	 * The current site is synchronized immediately. Existing sites are queued
	 * for bounded cron/manual batches, and future sites are covered by the
	 * wp_initialize_site hook.
	 *
	 * @return void
	 */
	public function activate_network(): void {
		$network_id = $this->current_network_id();
		$this->synchronize_site( get_current_blog_id() );
		$this->reset_network_state( $network_id );
		$this->schedule_network_sync( $network_id, 1 );
	}

	/**
	 * Removes network synchronization state when the plugin is network-deactivated.
	 *
	 * Per-site data intentionally remains. Scheduled site jobs are inert while
	 * the plugin is inactive and remain available for a later reactivation.
	 *
	 * @return void
	 */
	public function deactivate_network(): void {
		$network_id = $this->current_network_id();
		$this->clear_network_sync_event( $network_id );
		delete_network_option( $network_id, self::NETWORK_STATE_OPTION );
		delete_network_option( $network_id, self::NETWORK_VERSION_OPTION );
	}

	/**
	 * Schedules a network-wide version synchronization after plugin updates.
	 *
	 * @return void
	 */
	public function ensure_network_sync_scheduled(): void {
		if ( ! current_user_can( 'manage_network_plugins' ) ) {
			return;
		}

		$network_id = $this->current_network_id();
		if ( ! $this->is_network_active( $network_id ) ) {
			return;
		}

		$state           = $this->get_network_state( $network_id );
		$network_version = (string) get_network_option( $network_id, self::NETWORK_VERSION_OPTION, '' );
		$state_target    = isset( $state['target_version'] ) ? (string) $state['target_version'] : '';

		if ( AFFILIO_VERSION === $network_version && ( empty( $state ) || 'complete' === ( $state['status'] ?? '' ) ) ) {
			return;
		}

		if ( AFFILIO_VERSION !== $state_target ) {
			$this->reset_network_state( $network_id );
		}

		$this->schedule_network_sync( $network_id, self::NEXT_BATCH_DELAY );
	}

	/**
	 * Initializes a newly-created site when Dreamax Affiliates is network active.
	 *
	 * @param \WP_Site $new_site New site object.
	 * @param array    $args     Site initialization arguments.
	 * @return void
	 */
	public function initialize_new_site( $new_site, $args = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required hook signature.
		$site_id    = isset( $new_site->blog_id ) ? absint( $new_site->blog_id ) : 0;
		$network_id = isset( $new_site->network_id ) ? absint( $new_site->network_id ) : $this->current_network_id();

		if ( ! $site_id || ! $this->is_network_active( $network_id ) ) {
			return;
		}

		$this->synchronize_site( $site_id );
	}

	/**
	 * Cleans custom tables and plugin-owned data before WordPress drops a site.
	 *
	 * @param \WP_Site $old_site Deleted site object.
	 * @return void
	 */
	public function cleanup_deleted_site( $old_site ): void {
		$site_id    = isset( $old_site->blog_id ) ? absint( $old_site->blog_id ) : 0;
		$network_id = isset( $old_site->network_id ) ? absint( $old_site->network_id ) : $this->current_network_id();

		if ( ! $site_id || ! $this->is_network_active( $network_id ) ) {
			return;
		}

		$this->run_on_site(
			$site_id,
			function (): void {
				( $this->cleanup_current_site )();
			}
		);
	}

	/**
	 * Processes one bounded batch of sites for the requested network.
	 *
	 * @param int $network_id Network ID supplied by cron/manual execution.
	 * @return array{processed:int,remaining:int,status:string,failed_site_id:int}
	 */
	public function process_network_sync_batch( $network_id = 0 ): array {
		$network_id = absint( $network_id ) ?: $this->current_network_id();
		$result     = array(
			'processed'      => 0,
			'remaining'      => 0,
			'status'         => 'inactive',
			'failed_site_id' => 0,
		);

		if ( ! is_multisite() || ! $this->is_network_active( $network_id ) ) {
			return $result;
		}

		$state = $this->get_network_state( $network_id );
		if ( AFFILIO_VERSION !== ( $state['target_version'] ?? '' ) ) {
			$state = $this->reset_network_state( $network_id );
		}

		$offset   = max( 0, absint( $state['offset'] ?? 0 ) );
		$total    = max( 0, absint( $state['total'] ?? 0 ) );
		$site_ids = get_sites(
			array(
				'network_id' => $network_id,
				'number'     => self::BATCH_SIZE,
				'offset'     => $offset,
				'fields'     => 'ids',
				'orderby'    => 'id',
				'order'      => 'ASC',
				'deleted'    => 0,
				'archived'   => 0,
				'spam'       => 0,
			)
		);

		$processed = 0;
		$failed_id = 0;

		foreach ( $site_ids as $site_id ) {
			try {
				$this->synchronize_site( absint( $site_id ) );
				++$processed;
			} catch ( Throwable $throwable ) {
				$failed_id = absint( $site_id );
				break;
			}
		}

		$offset += $processed;
		$state   = array(
			'target_version' => AFFILIO_VERSION,
			'network_id'     => $network_id,
			'offset'         => $offset,
			'total'          => $total,
			'status'         => $failed_id ? 'retry' : 'pending',
			'failed_site_id' => $failed_id,
			'updated_at'     => time(),
		);

		if ( ! $failed_id && count( $site_ids ) < self::BATCH_SIZE ) {
			$state['status']         = 'complete';
			$state['failed_site_id'] = 0;
			update_network_option( $network_id, self::NETWORK_VERSION_OPTION, AFFILIO_VERSION );
			$this->clear_network_sync_event( $network_id );
		} else {
			$this->schedule_network_sync( $network_id, $failed_id ? self::RETRY_DELAY : self::NEXT_BATCH_DELAY );
		}

		update_network_option( $network_id, self::NETWORK_STATE_OPTION, $state );

		$result['processed']      = $processed;
		$result['remaining']      = max( 0, $total - $offset );
		$result['status']         = $state['status'];
		$result['failed_site_id'] = $failed_id;

		return $result;
	}

	/**
	 * Runs one protected batch from Network Admin.
	 *
	 * @return void
	 */
	public function handle_manual_network_sync(): void {
		if ( ! current_user_can( 'manage_network_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to synchronize Dreamax Affiliates across this network.', 'dreamax-affiliates' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'affilio_network_sync_batch' );
		$this->process_network_sync_batch( $this->current_network_id() );
		wp_safe_redirect( network_admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Shows bounded synchronization progress to network administrators.
	 *
	 * @return void
	 */
	public function render_network_sync_notice(): void {
		if ( ! current_user_can( 'manage_network_plugins' ) || ! \affilio_is_plugin_admin_notice_screen() ) {
			return;
		}

		$network_id = $this->current_network_id();
		if ( ! $this->is_network_active( $network_id ) ) {
			return;
		}

		$state = $this->get_network_state( $network_id );
		if ( empty( $state ) || 'complete' === ( $state['status'] ?? '' ) ) {
			return;
		}

		$processed = min( absint( $state['offset'] ?? 0 ), absint( $state['total'] ?? 0 ) );
		$total     = absint( $state['total'] ?? 0 );
		$url       = wp_nonce_url(
			add_query_arg( 'action', 'affilio_network_sync_batch', network_admin_url( 'admin-post.php' ) ),
			'affilio_network_sync_batch'
		);

		echo '<div class="notice notice-info"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: processed site count, 2: total site count. */
				__( 'Dreamax Affiliates network synchronization is in progress: %1$d of %2$d sites processed.', 'dreamax-affiliates' ),
				$processed,
				$total
			)
		);
		echo ' <a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Process next batch', 'dreamax-affiliates' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Returns whether Dreamax Affiliates is network active for a specific network.
	 *
	 * @param int $network_id Network ID. Zero uses the current network.
	 * @return bool
	 */
	public function is_network_active( $network_id = 0 ): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$network_id = absint( $network_id ) ?: $this->current_network_id();
		$plugins    = (array) get_network_option( $network_id, 'active_sitewide_plugins', array() );

		return array_key_exists( AFFILIO_PLUGIN_BASENAME, $plugins );
	}

	/**
	 * Returns current network synchronization state.
	 *
	 * @param int $network_id Network ID.
	 * @return array<string,mixed>
	 */
	public function get_network_state( $network_id = 0 ): array {
		$network_id = absint( $network_id ) ?: $this->current_network_id();
		$state      = get_network_option( $network_id, self::NETWORK_STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Resets progress for a new plugin target version.
	 *
	 * @param int $network_id Network ID.
	 * @return array<string,mixed>
	 */
	private function reset_network_state( $network_id ): array {
		$total = (int) get_sites(
			array(
				'network_id' => $network_id,
				'count'      => true,
				'deleted'    => 0,
				'archived'   => 0,
				'spam'       => 0,
			)
		);

		$state = array(
			'target_version' => AFFILIO_VERSION,
			'network_id'     => absint( $network_id ),
			'offset'         => 0,
			'total'          => max( 0, $total ),
			'status'         => 'pending',
			'failed_site_id' => 0,
			'updated_at'     => time(),
		);

		update_network_option( $network_id, self::NETWORK_STATE_OPTION, $state );
		return $state;
	}

	/**
	 * Synchronizes a site inside a guaranteed switch/restore boundary.
	 *
	 * @param int $site_id Site ID.
	 * @return void
	 */
	private function synchronize_site( $site_id ): void {
		if ( ! $site_id ) {
			return;
		}

		$this->run_on_site(
			$site_id,
			function (): void {
				( $this->synchronize_current_site )();
			}
		);
	}

	/**
	 * Runs a callback within one site and always restores the original context.
	 *
	 * @param int      $site_id  Site ID.
	 * @param callable $callback Callback.
	 * @return void
	 */
	private function run_on_site( $site_id, callable $callback ): void {
		$site_id     = absint( $site_id );
		$current_id  = get_current_blog_id();
		$must_switch = $site_id && $site_id !== $current_id;

		if ( $must_switch && ! switch_to_blog( $site_id ) ) {
			return;
		}

		try {
			$callback();
		} finally {
			if ( $must_switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Schedules the next batch on the network's main site.
	 *
	 * @param int $network_id Network ID.
	 * @param int $delay      Delay in seconds.
	 * @return void
	 */
	private function schedule_network_sync( $network_id, $delay ): void {
		$main_site_id = function_exists( 'get_main_site_id' ) ? absint( get_main_site_id( $network_id ) ) : get_current_blog_id();
		$this->run_on_site(
			$main_site_id,
			static function () use ( $network_id, $delay ): void {
				$args = array( absint( $network_id ) );
				if ( ! wp_next_scheduled( self::NETWORK_SYNC_HOOK, $args ) ) {
					wp_schedule_single_event( time() + max( 1, absint( $delay ) ), self::NETWORK_SYNC_HOOK, $args );
				}
			}
		);
	}

	/**
	 * Clears the scheduled batch event from the network's main site.
	 *
	 * @param int $network_id Network ID.
	 * @return void
	 */
	private function clear_network_sync_event( $network_id ): void {
		$main_site_id = function_exists( 'get_main_site_id' ) ? absint( get_main_site_id( $network_id ) ) : get_current_blog_id();
		$this->run_on_site(
			$main_site_id,
			static function () use ( $network_id ): void {
				wp_clear_scheduled_hook( self::NETWORK_SYNC_HOOK, array( absint( $network_id ) ) );
			}
		);
	}

	/**
	 * Returns the current network ID with a safe single-network fallback.
	 *
	 * @return int
	 */
	private function current_network_id(): int {
		return function_exists( 'get_current_network_id' ) ? absint( get_current_network_id() ) : 1;
	}
}
