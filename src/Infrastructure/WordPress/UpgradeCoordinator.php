<?php
/**
 * Bounded and idempotent upgrade orchestration.
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
 * Coordinates migrations while preventing simultaneous execution.
 */
final class UpgradeCoordinator {

	private const LOCK_NAME      = 'database-upgrade';
	private const LEGACY_LOCK    = 'affilio_upgrade_lock';
	private const PENDING_OPTION = 'affilio_upgrade_pending';
	private const LOCK_TTL       = 300;

	/**
	 * Whether an upgrade is required.
	 *
	 * @var Closure
	 */
	private Closure $needs_upgrade;

	/**
	 * Upgrade callback.
	 *
	 * @var Closure
	 */
	private Closure $run_upgrade;

	/**
	 * Creates the coordinator.
	 *
	 * @param callable $needs_upgrade Returns true when migration work is pending.
	 * @param callable $run_upgrade   Executes bounded, idempotent migrations.
	 */
	public function __construct( callable $needs_upgrade, callable $run_upgrade ) {
		$this->needs_upgrade = Closure::fromCallable( $needs_upgrade );
		$this->run_upgrade   = Closure::fromCallable( $run_upgrade );
	}

	/**
	 * Registers lifecycle hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'maybe_run' ) );
		add_action( 'admin_notices', array( $this, 'render_pending_notice' ) );
	}

	/**
	 * Marks the upgrade state without executing migration work during bootstrap.
	 *
	 * @return void
	 */
	public function refresh_pending_state(): void {
		$is_pending     = ( $this->needs_upgrade )();
		$stored_pending = (bool) get_option( self::PENDING_OPTION, false );

		if ( $is_pending && ! $stored_pending ) {
			update_option( self::PENDING_OPTION, 1, false );
		} elseif ( ! $is_pending && $stored_pending ) {
			delete_option( self::PENDING_OPTION );
		}
	}

	/**
	 * Runs one bounded migration batch for an authorized administrator.
	 *
	 * @return void
	 */
	public function maybe_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! ( $this->needs_upgrade )() ) {
			$this->refresh_pending_state();
			return;
		}

		if ( ! AtomicLock::acquire( self::LOCK_NAME, self::LOCK_TTL ) ) {
			return;
		}

		try {
			( $this->run_upgrade )();
		} catch ( Throwable $throwable ) {
			// Keep the pending flag so the versioned runner can resume after the
			// underlying issue is corrected. Detailed exception messages are not
			// persisted or shown because they may contain server paths or data.
			update_option( self::PENDING_OPTION, 1, false );
			return;
		} finally {
			AtomicLock::release( self::LOCK_NAME );
		}

		$this->refresh_pending_state();
	}

	/**
	 * Shows an actionable notice only while an upgrade remains pending.
	 *
	 * @return void
	 */
	public function render_pending_notice(): void {
		if (
			! current_user_can( 'manage_options' )
			|| ! get_option( self::PENDING_OPTION, false )
			|| ! \affilio_is_plugin_admin_notice_screen()
		) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Dreamax Affiliates has a pending database upgrade. Keep this administration page open and refresh once if the notice does not clear.', 'dreamax-affiliates' );
		echo '</p></div>';
	}

	/**
	 * Removes active and legacy upgrade locks.
	 *
	 * @return void
	 */
	public static function clear_lock(): void {
		AtomicLock::clear( self::LOCK_NAME );
		delete_option( self::LEGACY_LOCK );
	}
}
