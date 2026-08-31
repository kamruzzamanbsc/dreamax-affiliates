<?php
/**
 * Versioned, bounded, and restartable migration execution.
 *
 * @package Dreamax_Affiliates
 */

namespace Affilio\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use InvalidArgumentException;
use Throwable;

/**
 * Runs an ordered set of idempotent migration steps across bounded requests.
 */
final class MigrationRunner {

	/**
	 * Option used to persist progress between requests.
	 */
	public const STATE_OPTION = 'affilio_migration_state';

	/**
	 * Target plugin version.
	 *
	 * @var string
	 */
	private $target_version;

	/**
	 * Target database schema version.
	 *
	 * @var string
	 */
	private $target_db_version;

	/**
	 * Maximum required steps executed during one request.
	 *
	 * @var int
	 */
	private $max_steps_per_request;

	/**
	 * Creates the migration runner.
	 *
	 * @param string $target_version        Target plugin version.
	 * @param string $target_db_version     Target database schema version.
	 * @param int    $max_steps_per_request Maximum required steps per request.
	 */
	public function __construct( $target_version, $target_db_version, $max_steps_per_request = 2 ) {
		$this->target_version        = (string) $target_version;
		$this->target_db_version     = (string) $target_db_version;
		$this->max_steps_per_request = max( 1, min( 20, absint( $max_steps_per_request ) ) );
	}

	/**
	 * Executes a bounded batch of ordered migration steps.
	 *
	 * Every callback must be idempotent. Progress is persisted immediately
	 * after each successful step, so an interrupted request resumes at the next
	 * incomplete step rather than repeating the entire upgrade.
	 *
	 * Step format:
	 *
	 * array(
	 *     'id'       => 'unique-step-id',
	 *     'required' => true|callable,
	 *     'callback' => callable,
	 * )
	 *
	 * @param array<int,array{id:string,required:bool|callable,callback:callable}> $steps Ordered migration steps.
	 * @return bool True when every step is complete; false when another request is required.
	 * @throws Throwable Re-throws migration failures after recording safe state.
	 */
	public function run( array $steps ) {
		$state          = $this->load_state();
		$required_count = 0;
		$seen_steps     = array();

		foreach ( $steps as $step ) {
			$step_id = isset( $step['id'] ) ? sanitize_key( (string) $step['id'] ) : '';
			if ( '' === $step_id || ! isset( $step['callback'] ) || ! is_callable( $step['callback'] ) ) {
				throw new InvalidArgumentException( 'Every Dreamax Affiliates migration step requires a unique ID and callable callback.' );
			}

			if ( isset( $seen_steps[ $step_id ] ) ) {
				throw new InvalidArgumentException( 'Duplicate Dreamax Affiliates migration step ID: ' . esc_html( $step_id ) );
			}
			$seen_steps[ $step_id ] = true;

			if ( in_array( $step_id, $state['completed_steps'], true ) ) {
				continue;
			}

			$is_required = $step['required'] ?? true;
			$is_required = is_callable( $is_required ) ? (bool) call_user_func( $is_required ) : (bool) $is_required;

			if ( ! $is_required ) {
				$state['completed_steps'][] = $step_id;
				$state['last_step']         = $step_id;
				$state['updated_at']        = time();
				$this->save_state( $state );
				continue;
			}

			if ( $required_count >= $this->max_steps_per_request ) {
				return false;
			}

			$state['attempts']   = absint( $state['attempts'] ) + 1;
			$state['last_step']  = $step_id;
			$state['updated_at'] = time();
			$state['last_error'] = array();
			$this->save_state( $state );

			try {
				call_user_func( $step['callback'] );
			} catch ( Throwable $throwable ) {
				$state['last_error'] = array(
					'step'      => $step_id,
					'class'     => get_class( $throwable ),
					'code'      => (string) $throwable->getCode(),
					'failed_at' => time(),
				);
				$state['updated_at'] = time();
				$this->save_state( $state );
				throw $throwable;
			}

			$state['completed_steps'][] = $step_id;
			$state['completed_steps']   = array_values( array_unique( array_map( 'sanitize_key', $state['completed_steps'] ) ) );
			$state['updated_at']        = time();
			$state['last_error']        = array();
			$this->save_state( $state );
			++$required_count;
		}

		self::clear_state();
		return true;
	}

	/**
	 * Returns whether a resumable migration state currently exists.
	 *
	 * @return bool
	 */
	public static function has_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) && ! empty( $state );
	}

	/**
	 * Removes completed or abandoned migration progress.
	 *
	 * @return void
	 */
	public static function clear_state() {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Loads a valid state for the current target or starts a new one.
	 *
	 * @return array<string,mixed>
	 */
	private function load_state() {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$matches_target = isset( $state['target_version'], $state['target_db_version'] )
			&& $this->target_version === (string) $state['target_version']
			&& $this->target_db_version === (string) $state['target_db_version'];

		if ( ! $matches_target ) {
			$state = array(
				'target_version'    => $this->target_version,
				'target_db_version' => $this->target_db_version,
				'completed_steps'   => array(),
				'last_step'         => '',
				'attempts'          => 0,
				'started_at'        => time(),
				'updated_at'        => time(),
				'last_error'        => array(),
			);
			$this->save_state( $state );
		}

		$state['completed_steps'] = isset( $state['completed_steps'] ) && is_array( $state['completed_steps'] )
			? array_values( array_unique( array_map( 'sanitize_key', $state['completed_steps'] ) ) )
			: array();
		$state['attempts']        = absint( $state['attempts'] ?? 0 );

		return $state;
	}

	/**
	 * Persists migration progress as a non-autoloaded option.
	 *
	 * @param array<string,mixed> $state Migration state.
	 * @return void
	 */
	private function save_state( array $state ) {
		if ( false === get_option( self::STATE_OPTION, false ) ) {
			add_option( self::STATE_OPTION, $state, '', false );
			return;
		}

		update_option( self::STATE_OPTION, $state, false );
	}
}
