<?php
/**
 * Action Scheduler backend for routines.
 *
 * The default {@see WP_Agent_Routine_Backend} implementation: agents-api
 * does not require Action Scheduler. When AS is available we register one
 * recurring (or cron-expression) action per routine with a stable logical
 * args array so the listener can resolve the routine on wake. The registry
 * resolves this backend through `WP_Agent_Routine_Registry::backend()`.
 *
 * Deprecated statics: the pre-0.11.0 static facade for the interface
 * methods (register/unregister/pause/resume/run_now/is_available/
 * is_paused/current_generation) had to be removed — PHP cannot carry a
 * static and an instance method of the same name, and the instance forms
 * are required by the interface. Resolve the backend through
 * `WP_Agent_Routine_Registry::backend()` instead. The AS-specific statics
 * that do not collide with the interface (`pending_routine_actions()`,
 * `cancel_action_by_id()`, the option helpers, and the fence installers)
 * remain, the first two as thin deprecation shims.
 *
 * Durability behaviors layered on top:
 *
 *  - Generation fencing: every register() mints a generation and persists it
 *    in `agents_routine_generation_<id>`. Each stored action under the routine
 *    hook is stamped with the generation current at insert time, keyed by
 *    action id (`agents_routine_action_generation_<action_id>`), on
 *    `action_scheduler_stored_action` — which covers both the initial
 *    schedule and every recurrence successor Action Scheduler spawns. On
 *    `action_scheduler_before_execute` a stale stamp cancels the action;
 *    Action Scheduler's own pending-status re-check then skips it. Scheduled
 *    args stay purely logical (`['routine_id' => ...]`), so exact-match
 *    lookups and unschedules remain O(1).
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

final class WP_Agent_Routine_Action_Scheduler_Bridge implements WP_Agent_Routine_Backend {

	public const SCHEDULED_HOOK = 'wp_agent_routine_run_scheduled';

	public const GROUP = 'agents-api';

	private const GENERATION_OPTION_PREFIX        = 'agents_routine_generation_';
	private const ACTION_GENERATION_OPTION_PREFIX = 'agents_routine_action_generation_';
	private const PAUSED_OPTION                   = 'agents_routine_paused';

	private static ?self $instance = null;

	private static bool $fence_registered = false;

	private function __construct() {}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function is_available(): bool {
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
	public function current_generation( string $routine_id ): ?string {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$value = get_option( self::generation_option_name( $routine_id ), '' );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Whether the routine was durably paused via {@see pause()}.
	 */
	public function is_paused( string $routine_id ): bool {
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
	public function register( WP_Agent_Routine $routine ): bool {
		/**
		 * Fires whenever the backend would schedule a routine, regardless
		 * of whether Action Scheduler is loaded. Custom schedulers can
		 * hook this to take over.
		 *
		 * @param WP_Agent_Routine $routine
		 */
		do_action( 'wp_agent_routine_schedule_requested', $routine );

		// Registration implies the routine is active.
		self::set_paused( $routine->get_id(), false );

		if ( ! $this->is_available() ) {
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

		// Unschedule prior occurrences for idempotency. Args are purely
		// logical, so this is an exact-match store query, not a scan.
		as_unschedule_all_actions( self::SCHEDULED_HOOK, $logical_args, self::GROUP );
		$args = $logical_args;

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
	 * Cancel every scheduled action this backend owns for the given routine,
	 * remove its generation tombstone, and clear any paused marker.
	 */
	public function unregister( string $routine_id ): void {
		if ( self::has_option_layer() ) {
			delete_option( self::generation_option_name( $routine_id ) );
		}
		self::set_paused( $routine_id, false );

		if ( ! $this->is_available() ) {
			return;
		}
		as_unschedule_all_actions( self::SCHEDULED_HOOK, array( 'routine_id' => $routine_id ), self::GROUP );
	}

	/**
	 * Cancel the recurring/cron schedule without removing the routine from
	 * the registry. The pause is recorded durably so
	 * {@see WP_Agent_Routine_Registry::reconcile()} does not re-enqueue a
	 * deliberately-paused routine.
	 */
	public function pause( string $routine_id ): void {
		self::set_paused( $routine_id, true );
		if ( ! $this->is_available() ) {
			return;
		}
		as_unschedule_all_actions( self::SCHEDULED_HOOK, array( 'routine_id' => $routine_id ), self::GROUP );
	}

	/**
	 * Re-establish the recurring/cron schedule for a previously-paused
	 * routine. Idempotent — calling on a routine whose schedule is still
	 * active simply re-registers (the underlying register call unschedules
	 * first).
	 */
	public function resume( WP_Agent_Routine $routine ): bool {
		return $this->register( $routine );
	}

	/**
	 * Enqueue a single-shot action for the routine, in addition to its
	 * recurring schedule. The one-shot is stamped with the routine's current
	 * generation when one exists, so the fetched-action fence applies to it
	 * exactly like the recurring chain.
	 */
	public function run_now( WP_Agent_Routine $routine ): bool {
		if ( ! $this->is_available() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		as_enqueue_async_action(
			self::SCHEDULED_HOOK,
			array( 'routine_id' => $routine->get_id() ),
			self::GROUP
		);
		return true;
	}

	/**
	 * All pending routine actions, hydrated from the store.
	 *
	 * @deprecated 0.11.0 Use WP_Agent_Routine_Registry::backend()->pending_by_routine().
	 *
	 * @return array<int,\ActionScheduler_Action> Pending actions keyed by action id.
	 */
	public static function pending_routine_actions(): array {
		return self::instance()->hydrate_pending_actions();
	}

	/**
	 * All pending actions under the routine hook/group, hydrated from the
	 * store. This is the one bulk scan in the module and belongs to
	 * {@see WP_Agent_Routine_Registry::reconcile()} only; register() and
	 * friends use exact-match queries.
	 *
	 * @return array<int,\ActionScheduler_Action> Pending actions keyed by action id.
	 */
	private function hydrate_pending_actions(): array {
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
	 * Pending backend handles grouped by logical routine id.
	 *
	 * @return array<string, list<int>> routine_id => pending action ids.
	 */
	public function pending_by_routine(): array {
		$by_routine = array();
		foreach ( $this->pending_routine_actions() as $action_id => $action ) {
			$args       = $action->get_args();
			$routine_id = $args['routine_id'] ?? ( $args[0] ?? '' );
			if ( ! is_string( $routine_id ) || '' === $routine_id ) {
				continue;
			}
			$by_routine[ $routine_id ][] = (int) $action_id;
		}
		return $by_routine;
	}

	/**
	 * Cancel one stored action by id.
	 *
	 * @deprecated 0.11.0 Use WP_Agent_Routine_Registry::backend()->cancel().
	 *
	 * @param int $action_id Action Scheduler action id.
	 * @return bool
	 */
	public static function cancel_action_by_id( int $action_id ): bool {
		return self::instance()->cancel( $action_id );
	}

	/**
	 * Cancel one pending action handle. Returns true when the cancel call
	 * succeeded (or at least did not throw).
	 *
	 * @param int $handle The opaque backend handle to cancel.
	 * @return bool
	 */
	public function cancel( int $handle ): bool {
		if ( $handle <= 0 || ! class_exists( '\ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			\ActionScheduler_Store::instance()->cancel_action( $handle );
		} catch ( \Throwable $error ) {
			unset( $error );
			return false;
		}

		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::action_generation_option_name( $handle ) );
		}

		return true;
	}

	/**
	 * Install the generation fence hooks once per request.
	 *
	 * Two Action Scheduler hooks carry the whole mechanism:
	 *
	 *  - `action_scheduler_stored_action` ($action_id) fires after every
	 *    insert — the initial schedule and each recurrence successor. We
	 *    stamp the routine's current generation onto the action id.
	 *  - `action_scheduler_before_execute` ($action_id) fires before the
	 *    runner re-checks that the action is still pending. A stale stamp
	 *    cancels the action there, so the runner's own status check skips
	 *    it and no recurrence successor is spawned.
	 */
	public static function register_generation_fence(): void {
		if ( self::$fence_registered ) {
			return;
		}
		self::$fence_registered = true;
		add_action( 'action_scheduler_stored_action', array( self::class, 'stamp_stored_action' ), 10, 1 );
		add_action( 'action_scheduler_before_execute', array( self::class, 'fence_before_execute' ), 0, 1 );
	}

	/**
	 * Option name holding the generation stamped on one stored action.
	 *
	 * @param int $action_id Action Scheduler action id.
	 */
	public static function action_generation_option_name( int $action_id ): string {
		return self::ACTION_GENERATION_OPTION_PREFIX . $action_id;
	}

	/**
	 * Generation stamped on a stored action, or null when unstamped.
	 *
	 * @param int $action_id Action Scheduler action id.
	 */
	public static function action_generation( int $action_id ): ?string {
		if ( $action_id <= 0 || ! function_exists( 'get_option' ) ) {
			return null;
		}
		$value = get_option( self::action_generation_option_name( $action_id ), '' );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Stamp a freshly stored routine action with its routine's current generation.
	 *
	 * @param int|string $action_id Action Scheduler action id.
	 */
	public static function stamp_stored_action( $action_id ): void {
		if ( ! is_numeric( $action_id ) || ! class_exists( '\ActionScheduler_Store' ) || ! self::has_option_layer() ) {
			return;
		}
		$action_id  = (int) $action_id;
		$routine_id = self::routine_id_for_action( $action_id );
		if ( '' === $routine_id ) {
			return;
		}
		$generation = self::instance()->current_generation( $routine_id );
		if ( null === $generation ) {
			return;
		}
		update_option( self::action_generation_option_name( $action_id ), $generation, false );
	}

	/**
	 * Cancel a routine action whose stamped generation is no longer current.
	 *
	 * Runs at priority 0 on `action_scheduler_before_execute`; the runner
	 * then observes the non-pending status and ignores the action.
	 *
	 * @param int|string $action_id Action Scheduler action id.
	 */
	public static function fence_before_execute( $action_id ): void {
		if ( ! is_numeric( $action_id ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return;
		}
		$action_id  = (int) $action_id;
		$routine_id = self::routine_id_for_action( $action_id );
		if ( '' === $routine_id ) {
			return;
		}
		$stamped = self::action_generation( $action_id );
		$current = self::instance()->current_generation( $routine_id );
		if ( null === $stamped || ( null !== $current && hash_equals( $stamped, $current ) ) ) {
			return; // Unstamped (legacy) or current: let it run.
		}
		if ( self::instance()->cancel( $action_id ) ) {
			do_action( 'agents_routine_action_fenced', $routine_id, $stamped, $action_id );
		}
	}

	/**
	 * Resolve the routine id for a stored action, or '' when it is not ours.
	 *
	 * @param int $action_id Action Scheduler action id.
	 */
	private static function routine_id_for_action( int $action_id ): string {
		try {
			$action = \ActionScheduler_Store::instance()->fetch_action( $action_id );
		} catch ( \Throwable $error ) {
			unset( $error );
			return '';
		}
		if ( self::SCHEDULED_HOOK !== $action->get_hook() || self::GROUP !== $action->get_group() ) {
			return '';
		}
		$args = $action->get_args();
		return isset( $args['routine_id'] ) && is_string( $args['routine_id'] ) ? $args['routine_id'] : '';
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
