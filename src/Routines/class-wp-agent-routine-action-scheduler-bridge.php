<?php
/**
 * Optional Action Scheduler bridge for routines.
 *
 * Mirrors {@see \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Bridge}:
 * agents-api does not require Action Scheduler. When AS is available we
 * register one recurring (or cron-expression) action per routine with a
 * stable logical args array so the listener can resolve the routine on wake.
 *
 * Durability behaviors layered on top:
 *
 *  - Generation fencing: every register() mints a generation, persists it in
 *    `agents_routine_generation_<id>`, and stamps it into the scheduled
 *    action's args. Fetched actions are wrapped in
 *    {@see WP_Agent_Generation_Fenced_Action}, which refuses to execute (and
 *    refuses to spawn a recurrence successor) once the stamped generation is
 *    no longer current.
 *  - Stagger: interval routines offset their first run by a deterministic,
 *    id-derived offset so co-scheduled routines do not all fire at the same
 *    second.
 *  - Paused state: pause()/resume() maintain a durable paused-id list so
 *    {@see WP_Agent_Routine_Registry::reconcile()} can tell "unscheduled
 *    on purpose" from "missing by drift".
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Routine_Action_Scheduler_Bridge {

	public const SCHEDULED_HOOK = 'wp_agent_routine_run_scheduled';

	public const GROUP = 'agents-api';

	private const GENERATION_OPTION_PREFIX = 'agents_routine_generation_';
	private const PAUSED_OPTION            = 'agents_routine_paused';

	private static bool $fence_registered = false;

	public static function is_available(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_schedule_cron_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Stable wp_option name holding the routine's current schedule generation.
	 */
	public static function generation_option_name( string $routine_id ): string {
		return self::GENERATION_OPTION_PREFIX . $routine_id;
	}

	/**
	 * The routine's current schedule generation, or null when none has been
	 * minted (or the option layer is absent).
	 */
	public static function current_generation( string $routine_id ): ?string {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$value = get_option( self::generation_option_name( $routine_id ), '' );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Whether the routine was durably paused via {@see pause()}.
	 */
	public static function is_paused( string $routine_id ): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$paused = get_option( self::PAUSED_OPTION, array() );
		return is_array( $paused ) && in_array( $routine_id, $paused, true );
	}

	/**
	 * Register the routine's schedule with Action Scheduler. Existing
	 * schedules for the same routine are unscheduled first to make this
	 * idempotent — call freely on every plugin boot.
	 *
	 * Each call mints a fresh schedule generation BEFORE the old chain is
	 * unscheduled, so any previously-claimed instance of the old chain fences
	 * itself at execution time.
	 *
	 * @return bool True when a schedule was registered (or the
	 *              `wp_agent_routine_schedule_requested` hook was fired
	 *              even without AS); false on no-op.
	 */
	public static function register( WP_Agent_Routine $routine ): bool {
		/**
		 * Fires whenever the bridge would schedule a routine, regardless
		 * of whether Action Scheduler is loaded. Custom schedulers can
		 * hook this to take over.
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_schedule_requested', $routine );

		// Registration implies the routine is active.
		self::set_paused( $routine->get_id(), false );

		if ( ! self::is_available() ) {
			return false;
		}

		$routine_id   = $routine->get_id();
		$logical_args = array( 'routine_id' => $routine_id );

		// Mint the new generation before mutating stored actions: an in-flight
		// instance of the superseded chain fences itself against the new value.
		$generation = null;
		if ( self::has_option_layer() ) {
			$generation = self::mint_generation();
			update_option( self::generation_option_name( $routine_id ), $generation, false );
		}

		// Unschedule prior occurrences for idempotency.
		self::unschedule_logical( $logical_args );

		$args = null !== $generation
			? WP_Agent_Routine_Action_Identity::with_generation( $logical_args, $generation )
			: $logical_args;

		if ( WP_Agent_Routine::TRIGGER_EXPRESSION === $routine->get_trigger_type() ) {
			return ! empty( as_schedule_cron_action(
				time(),
				$routine->get_expression(),
				self::SCHEDULED_HOOK,
				$args,
				self::GROUP
			) );
		}

		return ! empty( as_schedule_recurring_action(
			time() + $routine->stagger_offset(),
			$routine->get_interval_seconds(),
			self::SCHEDULED_HOOK,
			$args,
			self::GROUP
		) );
	}

	/**
	 * Cancel every scheduled action this bridge owns for the given routine,
	 * remove its generation tombstone, and clear any paused marker.
	 */
	public static function unregister( string $routine_id ): void {
		if ( self::has_option_layer() ) {
			delete_option( self::generation_option_name( $routine_id ) );
		}
		self::set_paused( $routine_id, false );

		if ( ! self::is_available() ) {
			return;
		}
		self::unschedule_logical( array( 'routine_id' => $routine_id ) );
	}

	/**
	 * Cancel the recurring/cron schedule without removing the routine from
	 * the registry. The pause is recorded durably so
	 * {@see WP_Agent_Routine_Registry::reconcile()} does not re-enqueue a
	 * deliberately-paused routine.
	 */
	public static function pause( string $routine_id ): void {
		self::set_paused( $routine_id, true );
		if ( ! self::is_available() ) {
			return;
		}
		self::unschedule_logical( array( 'routine_id' => $routine_id ) );
	}

	/**
	 * Re-establish the recurring/cron schedule for a previously-paused
	 * routine. Idempotent — calling on a routine whose schedule is still
	 * active simply re-registers (the underlying register call unschedules
	 * first).
	 */
	public static function resume( WP_Agent_Routine $routine ): bool {
		return self::register( $routine );
	}

	/**
	 * Enqueue a single-shot action for the routine, in addition to its
	 * recurring schedule. The one-shot is stamped with the routine's current
	 * generation when one exists, so the fetched-action fence applies to it
	 * exactly like the recurring chain.
	 */
	public static function run_now( WP_Agent_Routine $routine ): bool {
		if ( ! self::is_available() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		$logical_args = array( 'routine_id' => $routine->get_id() );
		$generation   = self::current_generation( $routine->get_id() );
		$args         = null !== $generation
			? WP_Agent_Routine_Action_Identity::with_generation( $logical_args, $generation )
			: $logical_args;

		as_enqueue_async_action(
			self::SCHEDULED_HOOK,
			$args,
			self::GROUP
		);
		return true;
	}

	/**
	 * All pending actions under the routine hook/group, hydrated from the
	 * store. Identity work (coverage, orphan scans) compares
	 * {@see WP_Agent_Routine_Action_Identity::logical_args()} of each action
	 * because Action Scheduler's own args matching is exact-equality and
	 * cannot see through the generation stamp.
	 *
	 * @return array<int,\ActionScheduler_Action> Pending actions keyed by action id.
	 */
	public static function pending_routine_actions(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return array();
		}

		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::SCHEDULED_HOOK,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$actions = array();
		foreach ( $ids as $id ) {
			$action_id = is_numeric( $id ) ? (int) $id : 0;
			if ( $action_id <= 0 ) {
				continue;
			}

			try {
				$actions[ $action_id ] = \ActionScheduler_Store::instance()->fetch_action( $action_id );
			} catch ( \Throwable $error ) {
				unset( $error );
				continue;
			}
		}

		return $actions;
	}

	/**
	 * Cancel one stored action by id. Returns true when the cancel call
	 * succeeded (or at least did not throw).
	 */
	public static function cancel_action_by_id( int $action_id ): bool {
		if ( $action_id <= 0 || ! class_exists( '\ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			\ActionScheduler_Store::instance()->cancel_action( $action_id );
		} catch ( \Throwable $error ) {
			unset( $error );
			return false;
		}

		return true;
	}

	/**
	 * Register the fetched-action fence and the stored-successor reconciler.
	 * Idempotent; the hooks only ever fire when Action Scheduler runs, and the
	 * callbacks additionally guard on AS classes being loaded.
	 */
	public static function register_generation_fence(): void {
		if ( self::$fence_registered ) {
			return;
		}
		self::$fence_registered = true;

		add_filter( 'action_scheduler_stored_action_instance', array( self::class, 'fence_stored_action' ), 10, 6 );
		add_action( 'action_scheduler_stored_action', array( self::class, 'reconcile_stored_successor' ), PHP_INT_MAX, 1 );
	}

	/**
	 * Fetched-action fence: wrap routine actions in a generation-fenced
	 * decorator whose execute() no-ops (and whose schedule reports canceled)
	 * once the stamped generation stops being current.
	 *
	 * Unstamped (pre-fencing) routine actions are wrapped with an empty
	 * expected generation, which never matches a live generation — they drain
	 * as no-ops instead of double-firing beside the stamped replacement chain
	 * that register() persists on every boot.
	 *
	 * @param mixed                       $action   Instantiated action.
	 * @param string                      $hook     Action hook.
	 * @param array<array-key,mixed>      $args     Stored (possibly stamped) args.
	 * @param mixed                       $schedule Action schedule.
	 * @param string                      $group    Action group.
	 * @param int                         $priority Action priority.
	 * @return mixed Original or fenced action.
	 */
	public static function fence_stored_action( $action, string $hook, array $args, $schedule, string $group, int $priority ) {
		if ( ! $action instanceof \ActionScheduler_Action
			|| self::SCHEDULED_HOOK !== $hook
			|| self::GROUP !== $group
			|| ! $schedule instanceof \ActionScheduler_Schedule
		) {
			return $action;
		}

		$logical_args = WP_Agent_Routine_Action_Identity::logical_args( $args );
		$routine_id   = isset( $logical_args['routine_id'] ) && is_string( $logical_args['routine_id'] )
			? $logical_args['routine_id']
			: '';
		if ( '' === $routine_id || ! class_exists( __NAMESPACE__ . '\\WP_Agent_Generation_Fenced_Action' ) ) {
			return $action;
		}

		$expected = WP_Agent_Routine_Action_Identity::generation_from_args( $args ) ?? '';
		$fenced   = new WP_Agent_Generation_Fenced_Action( $hook, $args, $schedule, $group, $routine_id, $expected );
		$fenced->set_priority( $priority );
		return $fenced;
	}

	/**
	 * Cancel a recurrence successor that was stored carrying a stale
	 * generation.
	 *
	 * Action Scheduler clones successors from the executed action's args.
	 * Between a routine's re-registration (new generation, fresh chain) and an
	 * in-flight old-chain action finishing, AS's `repeat()` can store a
	 * successor stamped with the superseded generation. The register() call
	 * that advanced the generation already owns the live chain, so the clone
	 * is safe to cancel; without this the duplicate stale chain would linger
	 * fenced-but-present until the worker drained it.
	 *
	 * @param int|string $action_id Stored action id.
	 */
	public static function reconcile_stored_successor( $action_id ): void {
		if ( ! is_numeric( $action_id ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return;
		}
		$action_id = (int) $action_id;

		try {
			$action = \ActionScheduler_Store::instance()->fetch_action( $action_id );
		} catch ( \Throwable $error ) {
			unset( $error );
			return;
		}

		if ( self::SCHEDULED_HOOK !== $action->get_hook()
			|| self::GROUP !== $action->get_group()
		) {
			return;
		}

		// NOTE: do not gate on the action's schedule here. The fetched action
		// is already wrapped by the fence filter, whose get_schedule() reports
		// a canceled (non-recurring) schedule for exactly the stale actions
		// this reconciler exists to cancel — gating on is_recurring() would
		// make the fence swallow the stale-successor cleanup.
		$stamped = WP_Agent_Routine_Action_Identity::generation_from_args( $action->get_args() );
		if ( null === $stamped ) {
			return; // Legacy unstamped chain; the boot-time re-register owns cleanup.
		}

		$logical_args = WP_Agent_Routine_Action_Identity::logical_args( $action->get_args() );
		$routine_id   = isset( $logical_args['routine_id'] ) && is_string( $logical_args['routine_id'] )
			? $logical_args['routine_id']
			: '';
		if ( '' === $routine_id ) {
			return;
		}

		$current = self::current_generation( $routine_id );
		if ( null !== $current && hash_equals( $stamped, $current ) ) {
			return; // Successor carries the current generation: the chain is live.
		}

		self::cancel_action_by_id( $action_id );
	}

	/**
	 * Cancel every pending action whose logical args match, and fall back to
	 * Action Scheduler's exact-args bulk cancel when the granular store API is
	 * unavailable (plain-code harnesses).
	 *
	 * @param array<array-key,mixed> $logical_args Logical routine args.
	 */
	private static function unschedule_logical( array $logical_args ): void {
		if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( '\ActionScheduler_Store' ) ) {
			foreach ( self::pending_routine_actions() as $action_id => $action ) {
				if ( WP_Agent_Routine_Action_Identity::logical_args( $action->get_args() ) === $logical_args ) {
					self::cancel_action_by_id( (int) $action_id );
				}
			}
			return;
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SCHEDULED_HOOK, $logical_args, self::GROUP );
		}
	}

	private static function has_option_layer(): bool {
		return function_exists( 'add_option' ) && function_exists( 'get_option' ) && function_exists( 'update_option' ) && function_exists( 'delete_option' );
	}

	private static function mint_generation(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return uniqid( 'routine_gen_', true );
		}
	}

	private static function set_paused( string $routine_id, bool $pause ): void {
		if ( ! self::has_option_layer() ) {
			return;
		}

		$current = get_option( self::PAUSED_OPTION, array() );
		$current = is_array( $current ) ? $current : array();

		$normalized = array();
		foreach ( $current as $id ) {
			if ( is_string( $id ) && '' !== $id && ! in_array( $id, $normalized, true ) ) {
				$normalized[] = $id;
			}
		}

		$has = in_array( $routine_id, $normalized, true );
		if ( $pause === $has ) {
			return;
		}

		if ( $pause ) {
			$normalized[] = $routine_id;
		} else {
			$normalized = array_values( array_diff( $normalized, array( $routine_id ) ) );
		}

		update_option( self::PAUSED_OPTION, $normalized, false );
	}
}
