<?php
/**
 * In-memory registry of code-defined routines.
 *
 * Mirrors {@see WP_Agent_Workflow_Registry}: plugins call
 * {@see wp_register_routine()} during boot, the substrate keeps the
 * resolved Routine in process memory for the duration of the request, and
 * the resolved scheduling backend (separate files) reads the registry to
 * (re-)register cron schedules on each plugin load.
 *
 * Like the workflow registry, this is stateless across requests — not a
 * cache. Persistence (DB-backed routines) is a consumer concern.
 *
 * @package AgentsAPI
 * @since   0.105.0
 */

namespace AgentsAPI\AI\Routines;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Routine_Registry {

	/**
	 * Option name of the reconcile compare-and-set lock.
	 */
	private const RECONCILE_LOCK_OPTION = 'agents_routine_reconcile_lock';

	/**
	 * Seconds after which a held reconcile lock is treated as stale and may
	 * be taken over (a crashed holder must not strand future reconciles).
	 */
	private const RECONCILE_LOCK_TTL = 300;

	/**
	 * @var array<string,WP_Agent_Routine>
	 */
	private static array $routines = array();

	/**
	 * The routine scheduling backend, resolved once per request by
	 * {@see backend()}.
	 */
	private static ?WP_Agent_Routine_Backend $backend = null;

	/**
	 * Whether {@see backend()} has resolved (the resolved value may be null).
	 */
	private static bool $backend_resolved = false;

	/**
	 * @param array<string,mixed> $args See {@see WP_Agent_Routine::__construct()}.
	 * @return WP_Agent_Routine|WP_Error
	 */
	public static function register( string $id, array $args ) {
		try {
			$routine = new WP_Agent_Routine( $id, $args );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'invalid_routine', $e->getMessage() );
		}

		self::$routines[ $routine->get_id() ] = $routine;

		/**
		 * Fires after a routine is added to the in-memory registry. The
		 * resolved scheduling backend subscribes to this hook to
		 * (re-)register the cron schedule.
		 *
		 * @since 0.105.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_registered', $routine );

		return $routine;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function unregister( string $routine_id ) {
		if ( ! isset( self::$routines[ $routine_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no routine registered with id `%s`', $routine_id )
			);
		}
		$routine = self::$routines[ $routine_id ];
		unset( self::$routines[ $routine_id ] );

		/**
		 * Fires after a routine is removed from the in-memory registry. The
		 * resolved backend subscribes to cancel the matching schedule.
		 *
		 * @since 0.105.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_unregistered', $routine );

		return true;
	}

	/**
	 * Pause a registered routine's schedule without unregistering the routine
	 * itself. The value object stays in the registry; the cron schedule is
	 * cancelled. Use {@see resume()} to re-establish it later.
	 *
	 * The backend records paused ids durably so {@see reconcile()} treats a
	 * paused routine as intentionally unscheduled rather than missing. The
	 * value object and registry stay stateless; consumers that need a
	 * "paused" UI read it through the backend.
	 *
	 * @since 0.106.0
	 *
	 * @return true|WP_Error
	 */
	public static function pause( string $routine_id ) {
		if ( ! isset( self::$routines[ $routine_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no routine registered with id `%s`', $routine_id )
			);
		}
		$routine = self::$routines[ $routine_id ];

		/**
		 * Fires when a caller requests pausing a routine. The resolved
		 * backend listens to cancel the schedule (without unregistering).
		 *
		 * @since 0.106.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_paused', $routine );

		return true;
	}

	/**
	 * Resume a previously-paused routine by re-establishing its schedule.
	 *
	 * Idempotent: resuming a routine whose schedule is still active just
	 * re-fires `wp_agent_routine_resumed`. The backend's register call is
	 * already idempotent (unschedules first), so the net effect is safe.
	 *
	 * @since 0.106.0
	 *
	 * @return true|WP_Error
	 */
	public static function resume( string $routine_id ) {
		if ( ! isset( self::$routines[ $routine_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no routine registered with id `%s`', $routine_id )
			);
		}
		$routine = self::$routines[ $routine_id ];

		/**
		 * Fires when a caller requests resuming a paused routine. The
		 * resolved backend listens to re-register the recurring/cron
		 * schedule.
		 *
		 * @since 0.106.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_resumed', $routine );

		return true;
	}

	/**
	 * Trigger an immediate one-shot wake of the routine, in addition to its
	 * recurring schedule. The next scheduled wake is unaffected.
	 *
	 * Useful for "Run now" buttons in admin UIs and for testing — the routine
	 * fires through the same listener as any scheduled wake, so the agent's
	 * conversation session, prompt, and tool surface are identical.
	 *
	 * @since 0.106.0
	 *
	 * @return true|WP_Error
	 */
	public static function run_now( string $routine_id ) {
		if ( ! isset( self::$routines[ $routine_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no routine registered with id `%s`', $routine_id )
			);
		}
		$routine = self::$routines[ $routine_id ];

		/**
		 * Fires when a caller requests an immediate one-shot wake of a
		 * routine. The resolved backend listens to enqueue a single-action
		 * job for the same scheduled hook the recurring schedule uses.
		 *
		 * @since 0.106.0
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_run_now_requested', $routine );

		return true;
	}

	public static function find( string $routine_id ): ?WP_Agent_Routine {
		return self::$routines[ $routine_id ] ?? null;
	}

	/**
	 * The resolved routine scheduling backend, or null when none is
	 * available.
	 *
	 * Resolved once per request: the default is the Action Scheduler bridge
	 * when Action Scheduler is present, and null otherwise. Consumers can
	 * substitute any {@see WP_Agent_Routine_Backend} implementation through
	 * the `wp_agent_routine_backend` filter; a filter return that is not a
	 * backend falls back to the default.
	 *
	 * @since 0.11.0
	 */
	public static function backend(): ?WP_Agent_Routine_Backend {
		if ( ! self::$backend_resolved ) {
			$default = WP_Agent_Routine_Action_Scheduler_Bridge::instance()->is_available()
				? WP_Agent_Routine_Action_Scheduler_Bridge::instance()
				: null;

			/**
			 * Filters the routine scheduling backend.
			 *
			 * @since 0.11.0
			 *
			 * @param WP_Agent_Routine_Backend|null $default The default backend (the Action Scheduler bridge when available), or null.
			 */
			$filtered               = apply_filters( 'wp_agent_routine_backend', $default );
			self::$backend          = $filtered instanceof WP_Agent_Routine_Backend ? $filtered : $default;
			self::$backend_resolved = true;
		}

		return self::$backend;
	}

	/**
	 * Test-only: forget the resolved backend so the next {@see backend()}
	 * call re-resolves it (re-running the `wp_agent_routine_backend` filter).
	 *
	 * @since 0.11.0
	 */
	public static function reset_backend(): void {
		self::$backend          = null;
		self::$backend_resolved = false;
	}

	/**
	 * The routine's current schedule generation as persisted by the active
	 * backend, or null when none exists.
	 */
	public static function current_generation( string $routine_id ): ?string {
		return self::backend()?->current_generation( $routine_id );
	}

	/**
	 * Reconcile the in-memory registry against the scheduling backend.
	 *
	 * Registry state and backend state drift: the backend's store can be
	 * pruned, a site can be restored from backup, scheduled work can be
	 * manually deleted. For every registered, non-paused routine this checks
	 * pending-handle coverage by logical identity (routine id) and registers
	 * a fresh schedule when coverage is missing; pending handles whose
	 * logical routine_id is not registered (or is durably paused) are
	 * cancelled as orphans.
	 *
	 * The whole run is serialized through an add_option() compare-and-set
	 * lock (`agents_routine_reconcile_lock`); a lock older than five minutes
	 * is treated as stale and taken over. Dry runs report the same shape
	 * without writing anything (and without taking the lock).
	 *
	 * @param array<string,mixed> $opts Recognised keys: `dry_run` (bool).
	 * @return array{enqueued:string[],removed:string[],unchanged:string[],errors:array<string,string>}
	 */
	public static function reconcile( array $opts = array() ): array {
		$dry_run = ! empty( $opts['dry_run'] );

		if ( null === self::backend() ) {
			return array(
				'enqueued'  => array(),
				'removed'   => array(),
				'unchanged' => array(),
				'errors'    => array( '_scheduler' => 'No routine scheduling backend is available.' ),
			);
		}

		if ( $dry_run ) {
			return self::reconcile_unlocked( true );
		}

		if ( ! self::acquire_reconcile_lock() ) {
			return array(
				'enqueued'  => array(),
				'removed'   => array(),
				'unchanged' => array(),
				'errors'    => array( '_lock' => 'Another routine reconcile is already running.' ),
			);
		}

		try {
			return self::reconcile_unlocked( false );
		} finally {
			self::release_reconcile_lock();
		}
	}

	/**
	 * @return array{enqueued:string[],removed:string[],unchanged:string[],errors:array<string,string>}
	 */
	private static function reconcile_unlocked( bool $dry_run ): array {
		$enqueued  = array();
		$removed   = array();
		$unchanged = array();
		$errors    = array();

		$backend = self::backend();
		if ( null === $backend ) {
			return array(
				'enqueued'  => array(),
				'removed'   => array(),
				'unchanged' => array(),
				'errors'    => array( '_scheduler' => 'No routine scheduling backend is available.' ),
			);
		}

		$pending = $backend->pending_by_routine();

		foreach ( self::$routines as $routine_id => $routine ) {
			if ( $backend->is_paused( $routine_id ) ) {
				continue;
			}

			if ( ! empty( $pending[ $routine_id ] ) ) {
				$unchanged[] = $routine_id;
				continue;
			}

			if ( $dry_run ) {
				$enqueued[] = $routine_id;
				continue;
			}

			if ( $backend->register( $routine ) ) {
				$enqueued[] = $routine_id;
			} else {
				$errors[ $routine_id ] = 'Failed to enqueue the missing routine schedule.';
			}
		}

		foreach ( $pending as $routine_id => $handles ) {
			$orphan = ! isset( self::$routines[ $routine_id ] )
				|| $backend->is_paused( $routine_id );
			if ( ! $orphan ) {
				continue;
			}

			if ( $dry_run ) {
				$removed[] = $routine_id;
				continue;
			}

			$cancelled_all = true;
			foreach ( $handles as $handle ) {
				if ( ! $backend->cancel( (int) $handle ) ) {
					$cancelled_all = false;
				}
			}

			if ( $cancelled_all ) {
				$removed[] = $routine_id;
			} else {
				$errors[ $routine_id ] = 'Failed to remove the orphaned routine schedule.';
			}
		}

		return array(
			'enqueued'  => $enqueued,
			'removed'   => array_values( array_unique( $removed ) ),
			'unchanged' => $unchanged,
			'errors'    => $errors,
		);
	}

	/**
	 * Acquire the reconcile lock via an add_option() compare-and-set. A lock
	 * older than five minutes is stale and taken over. When there is no
	 * option layer (non-WordPress harness) callers are already
	 * single-process and the lock is a no-op success.
	 */
	private static function acquire_reconcile_lock(): bool {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
			return true;
		}

		if ( add_option( self::RECONCILE_LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$existing = get_option( self::RECONCILE_LOCK_OPTION );
		if ( is_numeric( $existing ) && (int) $existing > time() - self::RECONCILE_LOCK_TTL ) {
			return false;
		}

		delete_option( self::RECONCILE_LOCK_OPTION );
		return add_option( self::RECONCILE_LOCK_OPTION, time(), '', false );
	}

	private static function release_reconcile_lock(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::RECONCILE_LOCK_OPTION );
		}
	}

	/**
	 * @return WP_Agent_Routine[]
	 */
	public static function all(): array {
		return array_values( self::$routines );
	}

	/**
	 * Test-only: clear the in-memory registry.
	 *
	 * @since 0.105.0
	 */
	public static function reset(): void {
		self::$routines = array();
	}
}
