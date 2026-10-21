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
 * `cancel_action_by_id()`, the option helpers, and the fence installer)
 * remain, the first two as thin deprecation shims.
 *
 * Durability behaviors layered on top:
 *
 *  - Declaration is cheap; backend I/O happens only on change. Routines
 *    are declared in memory on every request (like `register_post_type`),
 *    so the bridge keeps ONE persisted record of what it last scheduled:
 *    a single non-autoloaded map option (`agents_routine_schedules`,
 *    routine id → `['signature' => string, 'action_id' => int]`, where the
 *    signature is exactly what {@see recurrence_matches()} compares —
 *    trigger type plus interval seconds or cron expression). An unchanged
 *    declaration matches its record and returns before touching Action
 *    Scheduler: reading the record costs one `get_option()` per request
 *    (object-cache/alloptions-backed), independent of routine count —
 *    a consumer declaring hundreds of routines on every page view pays
 *    zero scheduler queries once every record matches. Only a missing or
 *    changed signature reaches the pending-actions map and the scheduling
 *    calls, serialized through a short single-flight per-routine lock so
 *    two concurrent processes cannot both check-then-schedule (a losing
 *    caller no-ops). {@see repair()} is the authoritative counterpart the
 *    registry's reconcile uses: it never trusts the record, re-reads the
 *    store, and repairs drift the record cannot see (including a recorded
 *    signature whose action was deleted out-of-band).
 *  - Self-healing repair loop: because declarations trust the record, the
 *    substrate itself owns the authoritative repair — ONE recurring
 *    Action Scheduler action ({@see PERIODIC_RECONCILE_HOOK}, group
 *    `agents-api`, hourly) whose callback runs the registry's
 *    lock-protected `WP_Agent_Routine_Registry::reconcile()` in apply
 *    mode. A chain lost out of band is repaired within at most one
 *    interval of its loss. The maintenance action is declared exactly
 *    like a routine: {@see ensure_periodic_reconcile()} fast-paths on the
 *    maintenance chain's own record in the schedules map (zero scheduler
 *    queries per request), every actual run stamps a liveness timestamp
 *    into that record, and once the timestamp is older than one interval
 *    the next declaration revalidates the chain against the store (one
 *    bounded query per revalidation, not per request) and re-creates it
 *    if the maintenance chain itself was lost out of band.
 *  - One chain per routine. Whenever a register()/repair() call reaches
 *    Action Scheduler and finds more than one *matching* pending chain, it
 *    keeps exactly one — the lowest-id chain that the generation/watermark
 *    model considers live (id at or above the current watermark) — and
 *    cancels every other pending action for the routine by id, healing
 *    duplicate chains left by pre-fix versions or crashed requests. An
 *    action already claimed by an in-flight worker is never in the
 *    pending set (Action Scheduler marks claimed actions in-progress), so
 *    a collapse never breaks a running worker. A recurrence successor a
 *    mid-flight worker spawns after the collapse lands above the kept
 *    chain's watermark and can survive one collapse; the next reconcile
 *    collapses it again — the condition stays self-correcting.
 *  - Generation + watermark fencing: a schedule change mints a fresh
 *    generation and records the new chain's first action id as a
 *    *watermark* in the routine's single option row
 *    (`agents_routine_generation_<id>`, holding
 *    `['generation' => string, 'watermark' => int]`). Action Scheduler
 *    action ids are a global, strictly increasing sequence, so every
 *    action in the new chain (the initial insert and every recurrence
 *    successor AS spawns) has an id at or above that watermark, and every
 *    action belonging to a superseded chain — including one already
 *    claimed by an in-flight worker when the supersede happened — has an
 *    id below it. `action_scheduler_before_execute` cancels a fetched
 *    routine action whose id is below the routine's current watermark;
 *    Action Scheduler's own pending-status re-check then skips it. This
 *    needs **zero per-action bookkeeping** — no option row is written when
 *    an action is stored, closing the unbounded
 *    `agents_routine_action_generation_<action_id>` option leak of
 *    pre-fix versions (one row per stored action, deleted only by
 *    `cancel()`, which `register()`'s prior unconditional
 *    `as_unschedule_all_actions()` path never reached).
 *    {@see purge_legacy_action_generation_options()} is the one-shot
 *    cleanup for rows a pre-fix version already left behind.
 *  - Stagger: interval routines offset their first run by a deterministic,
 *    id-derived offset so co-scheduled routines do not all fire at the
 *    same second. The offset only affects the first-run timestamp, so it
 *    never causes an unchanged routine to be treated as a schedule
 *    mismatch on re-register.
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

	private const GENERATION_OPTION_PREFIX = 'agents_routine_generation_';
	private const PAUSED_OPTION            = 'agents_routine_paused';

	/**
	 * One non-autoloaded option holding the bridge's persisted record of
	 * what it last scheduled per routine:
	 * `routine_id => ['signature' => string, 'action_id' => int]`. The
	 * signature is exactly what {@see recurrence_matches()} compares, so a
	 * record match implies a matching pending action without consulting
	 * Action Scheduler at all — the fast path that makes re-declaring
	 * hundreds of routines on every request cost zero scheduler queries.
	 */
	private const SCHEDULES_OPTION = 'agents_routine_schedules';

	/**
	 * Prefix of the per-routine single-flight lock option guarding the
	 * check-then-schedule step ({@see acquire_schedule_lock()}).
	 */
	private const SCHEDULE_LOCK_OPTION_PREFIX = 'agents_routine_schedule_lock_';

	/**
	 * Seconds after which a held schedule lock is treated as stale and may
	 * be taken over (a crashed holder must not strand the routine
	 * unscheduled forever). Scheduling one routine is a handful of queries,
	 * so this is far shorter than the reconcile lock's TTL.
	 */
	private const SCHEDULE_LOCK_TTL = 30;

	/**
	 * Hook of the substrate-owned recurring maintenance action whose
	 * callback runs the registry's lock-protected reconcile() in apply
	 * mode — the authoritative repair loop that keeps a chain lost out of
	 * band from staying lost. Lives in {@see GROUP} so it is visible (and
	 * cancellable) next to the routines it repairs.
	 */
	public const PERIODIC_RECONCILE_HOOK = 'wp_agent_routine_periodic_reconcile';

	/**
	 * Seconds between periodic reconcile runs (one hour): the documented
	 * upper bound on how long a lost chain stays lost.
	 */
	public const PERIODIC_RECONCILE_INTERVAL = 3600;

	/**
	 * The schedules-map key of the maintenance chain's own record. Prefix
	 * `@` cannot occur in a routine id ({@see WP_Agent_Routine} runs ids
	 * through `sanitize_title()`, which strips it), so the key can never
	 * collide with a real routine's entry.
	 */
	private const PERIODIC_RECONCILE_RECORD_KEY = '@periodic-reconcile';

	/**
	 * Seconds the declaration fast path trusts the maintenance record's
	 * liveness timestamp before revalidating the chain against the store.
	 * One interval: a live chain stamps the timestamp on every run, so a
	 * record older than this means the chain (or its record) needs an
	 * authoritative look — at most once per interval, never per request.
	 */
	private const PERIODIC_RECONCILE_REVALIDATE_AFTER = 3600;

	/**
	 * Prefix of the pre-fix per-action option rows
	 * (`agents_routine_action_generation_<action_id>`). No longer written;
	 * kept only so {@see purge_legacy_action_generation_options()} can find
	 * and delete rows a pre-fix version already left behind.
	 */
	private const LEGACY_ACTION_GENERATION_OPTION_PREFIX = 'agents_routine_action_generation_';

	/**
	 * Marker option gating the one-shot legacy purge to a single run per
	 * site (see {@see maybe_purge_legacy_action_generation_options()}).
	 */
	private const LEGACY_PURGE_MARKER_OPTION = 'agents_routine_action_generation_purged';

	private static ?self $instance = null;

	private static bool $fence_registered = false;

	/**
	 * Request-scoped cache of pending routine actions, grouped by logical
	 * routine id. Populated lazily by one bulk `as_get_scheduled_actions()`
	 * call — now only when a routine's schedule record is missing or
	 * mismatched, i.e. only on the path that actually reaches Action
	 * Scheduler; kept in sync by register()/cancel()/unregister()/pause()
	 * rather than invalidated wholesale, so a request that (re-)registers
	 * many routines pays for the bulk fetch once.
	 *
	 * @var array<string, array<int,\ActionScheduler_Action>>|null
	 */
	private ?array $pending_cache = null;

	/**
	 * Request-scoped cache of the persisted schedule record map
	 * ({@see SCHEDULES_OPTION}), populated by the first schedule-record read
	 * in the request and kept in sync by the record writers.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $schedules_cache = null;

	private function __construct() {}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Test-only: forget the request-scoped caches so the next reads re-fetch
	 * from the (fake or real) Action Scheduler store and options table. This
	 * simulates a fresh PHP request: production code never needs it — both
	 * caches' lifetime is correctly bounded by the PHP process/request,
	 * which a persistent test-file singleton spanning multiple simulated
	 * "requests" is not.
	 */
	public function reset_pending_cache_for_tests(): void {
		$this->pending_cache   = null;
		$this->schedules_cache = null;
	}

	public function is_available(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_schedule_cron_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Stable wp_option name holding the routine's current schedule state
	 * (`['generation' => string, 'watermark' => int]`).
	 */
	public static function generation_option_name( string $routine_id ): string {
		return self::GENERATION_OPTION_PREFIX . $routine_id;
	}

	/**
	 * The routine's current schedule generation, or null when none has been
	 * minted (or the option layer is absent).
	 */
	public function current_generation( string $routine_id ): ?string {
		$generation = $this->routine_state( $routine_id )['generation'] ?? null;
		return is_string( $generation ) && '' !== $generation ? $generation : null;
	}

	/**
	 * The action-id watermark fencing the routine's current chain, or null
	 * when none has been recorded (no fence — legacy or never-registered
	 * actions are allowed to run).
	 */
	private function current_watermark( string $routine_id ): ?int {
		$watermark = $this->routine_state( $routine_id )['watermark'] ?? null;
		return is_numeric( $watermark ) ? (int) $watermark : null;
	}

	/**
	 * @return array<array-key,mixed> Recognised keys: `generation` (string),
	 *                                `watermark` (int) — validated by the
	 *                                narrow readers
	 *                                ({@see current_generation()},
	 *                                {@see current_watermark()}), not here.
	 */
	private function routine_state( string $routine_id ): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$value = get_option( self::generation_option_name( $routine_id ), null );
		return is_array( $value ) ? $value : array();
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
	 * Register the routine's schedule with Action Scheduler.
	 *
	 * Idempotent and cheap to call on every plugin boot, even for hundreds
	 * of persisted routines: when the persisted schedule record's signature
	 * (trigger type plus interval seconds or cron expression — exactly what
	 * {@see recurrence_matches()} compares) matches the routine's current
	 * trigger, this returns before touching Action Scheduler at all — zero
	 * `as_get_scheduled_actions()` queries, zero `fetch_action()` calls.
	 * Reading the record costs one `get_option()` per request, independent
	 * of routine count.
	 *
	 * Only a missing or changed signature reaches {@see repair()}, which
	 * verifies real store state under a single-flight per-routine lock,
	 * collapses duplicate chains, and (re)schedules as needed.
	 *
	 * The trade-off is deliberate: a declaration trusts the record, so an
	 * out-of-band deletion (pruned store, restored backup) is invisible to
	 * declarations and is repaired by
	 * {@see WP_Agent_Routine_Registry::reconcile()} — whose repair path
	 * never trusts the record. That repair runs on a schedule: the
	 * substrate's own hourly {@see PERIODIC_RECONCILE_HOOK} maintenance
	 * action invokes it in apply mode, so a lost chain is restored within
	 * at most one interval of its loss.
	 *
	 * @return bool True when a schedule is in place (freshly registered,
	 *             already matching the record, or being scheduled right now
	 *             by the concurrent process that holds this routine's lock);
	 *             false on no-op due to Action Scheduler being unavailable
	 *             or the schedule call failing.
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

		$record = $this->schedule_record( $routine->get_id() );
		if (
			null !== $record
			&& $record['signature'] === self::schedule_signature( $routine )
		) {
			return true; // Unchanged: nothing to do, and nothing to read.
		}

		return $this->repair( $routine );
	}

	/**
	 * Authoritative check-and-repair of one routine's schedule against real
	 * Action Scheduler state — the path a changed or record-less declaration
	 * takes, and the only path {@see WP_Agent_Routine_Registry::reconcile()}
	 * uses.
	 *
	 * Serialized through a short per-routine lock so two concurrent requests
	 * cannot both observe "no matching chain" and both schedule one (the
	 * race that produced the duplicate-chain outage this replaces; a losing
	 * caller no-ops because the winner is scheduling the same routine).
	 * Inside the lock it reads the store through the request-scoped pending
	 * map (which reconcile's authoritative bulk read refreshes), then:
	 *
	 * - a live matching chain exists → adopt it, cancel every other pending
	 *   recurring action for the routine by id (duplicate matching chains
	 *   and stale-recurrence chains alike), and record the signature. Pending
	 *   one-shot `run_now` enqueues are left alone — the schedule did not
	 *   change.
	 * - no live matching chain → schedule a fresh chain, advance the
	 *   generation/watermark fence, cancel every other pending action for
	 *   the routine (superseded chains and pending one-shots alike — the
	 *   schedule changed, so they no longer belong), and record the new
	 *   signature + action id.
	 *
	 * @return bool True when a live schedule is in place after the call.
	 */
	public function repair( WP_Agent_Routine $routine ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		$routine_id = $routine->get_id();

		if ( ! $this->acquire_schedule_lock( $routine_id ) ) {
			// Another process is scheduling this exact routine right now; it
			// will finish the job and record it. No-op cheaply.
			return true;
		}

		try {
			$signature = self::schedule_signature( $routine );
			$existing  = $this->pending_map()[ $routine_id ] ?? array();

			$matching = array();
			foreach ( $existing as $action_id => $action ) {
				if ( self::recurrence_matches( $action, $routine ) ) {
					$matching[ (int) $action_id ] = $action;
				}
			}

			$keep_id = self::chain_to_keep( array_keys( $matching ), $this->current_watermark( $routine_id ) );

			if ( null !== $keep_id ) {
				// A live matching chain exists: adopt it and collapse the rest.
				$this->cancel_superseded( $keep_id, $existing, true );
				$this->record_schedule( $routine_id, $signature, $keep_id );
				return true;
			}

			if ( WP_Agent_Routine::TRIGGER_EXPRESSION === $routine->get_trigger_type() ) {
				$new_id = as_schedule_cron_action(
					time(),
					$routine->get_expression(),
					self::SCHEDULED_HOOK,
					array( 'routine_id' => $routine_id ),
					self::GROUP
				);
			} else {
				$new_id = as_schedule_recurring_action(
					time() + $routine->stagger_offset(),
					$routine->get_interval_seconds(),
					self::SCHEDULED_HOOK,
					array( 'routine_id' => $routine_id ),
					self::GROUP
				);
			}

			if ( empty( $new_id ) ) {
				return false;
			}
			$new_id = (int) $new_id;

			// Advance the watermark to the new chain's first action id before
			// tearing down the superseded chain: every id in the new chain
			// (this one and every recurrence successor AS spawns) is at or
			// above it, and every id in the old chain — including one already
			// claimed by an in-flight worker — is below it.
			if ( self::has_option_layer() ) {
				update_option(
					self::generation_option_name( $routine_id ),
					array(
						'generation' => self::mint_generation(),
						'watermark'  => $new_id,
					),
					false
				);
			}

			// The schedule changed: supersede every other pending action for
			// this routine — old recurring chains, stale-recurrence chains, and
			// pending one-shot `run_now` enqueues (the same supersede the fence
			// applies to any of them that slip through).
			$this->cancel_superseded( $new_id, $existing, false );

			$this->remember_pending_action( $routine_id, $new_id );
			$this->record_schedule( $routine_id, $signature, $new_id );

			return true;
		} finally {
			$this->release_schedule_lock( $routine_id );
		}
	}

	/**
	 * Declare the substrate-owned maintenance chain whose callback runs the
	 * registry's lock-protected reconcile() in apply mode — the periodic
	 * authoritative repair that keeps a chain lost out of band (pruned
	 * store, restored backup, an action Action Scheduler stops
	 * rescheduling) from staying lost.
	 *
	 * Idempotent and cheap to call on every request, exactly like a
	 * routine declaration: while the maintenance chain's own record in the
	 * schedules map ({@see PERIODIC_RECONCILE_RECORD_KEY}) carries the
	 * expected signature and a liveness timestamp younger than one
	 * interval, this returns before issuing a single scheduler query.
	 * Every actual run of the chain stamps that timestamp, so a fresh
	 * record is proof the chain is alive. Only a missing, mismatched, or
	 * stale record reaches {@see repair_periodic_reconcile()} — at most
	 * once per interval, never per request — which revalidates the chain
	 * against the store and re-creates it if the maintenance chain itself
	 * was lost out of band.
	 *
	 * @return bool True when a maintenance chain is in place (freshly
	 *             (re)asserted, already live per the record, or being
	 *             asserted right now by the concurrent process that holds
	 *             the schedule lock); false when Action Scheduler is
	 *             unavailable or the schedule call failed.
	 */
	public function ensure_periodic_reconcile(): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		$record = $this->periodic_record();
		if (
			null !== $record
			&& self::periodic_signature() === $record['signature']
			&& ( time() - $record['checked'] ) < self::PERIODIC_RECONCILE_REVALIDATE_AFTER
		) {
			return true; // Fresh liveness timestamp: the chain ran recently.
		}

		return $this->repair_periodic_reconcile();
	}

	/**
	 * Authoritative check-and-repair of the maintenance chain against real
	 * Action Scheduler state — the path a missing, mismatched, or stale
	 * maintenance record takes.
	 *
	 * Serialized through the same single-flight schedule lock the routine
	 * paths use (keyed on the reserved record id), then:
	 *
	 * - pending maintenance actions exist → keep the lowest-id one whose
	 *   recurrence still matches the expected interval, cancel every other
	 *   pending maintenance action by id (duplicate chains and
	 *   wrong-recurrence chains alike), and record it;
	 * - none pending but an instance is in-progress (claimed by a worker)
	 *   → the chain is live; Action Scheduler spawns its successor when
	 *   the instance completes, so nothing is scheduled and the record is
	 *   written with a fresh liveness timestamp;
	 * - nothing anywhere → schedule a fresh hourly chain (first run
	 *   immediately) and record it.
	 *
	 * @return bool True when a maintenance chain is in place after the call.
	 */
	public function repair_periodic_reconcile(): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		if ( ! $this->acquire_schedule_lock( self::PERIODIC_RECONCILE_RECORD_KEY ) ) {
			// Another process is asserting the maintenance chain right now; it
			// will finish the job and record it. No-op cheaply.
			return true;
		}

		try {
			$pending = $this->periodic_actions( \ActionScheduler_Store::STATUS_PENDING );

			$matching = array();
			foreach ( $pending as $action_id => $action ) {
				if ( self::periodic_recurrence_matches( $action ) ) {
					$matching[ $action_id ] = $action;
					continue;
				}
				// A wrong recurrence (an out-of-band edit or a prior interval):
				// superseded — the chain it belongs to no longer matches.
				$this->cancel( (int) $action_id );
			}

			if ( array() !== $matching ) {
				$keep_id = (int) min( array_keys( $matching ) );
				foreach ( array_keys( $matching ) as $action_id ) {
					if ( (int) $action_id !== $keep_id ) {
						$this->cancel( (int) $action_id );
					}
				}
				$this->record_periodic_schedule( $keep_id );
				return true;
			}

			$running = $this->periodic_actions( \ActionScheduler_Store::STATUS_RUNNING );
			if ( array() !== $running ) {
				// An instance is executing right now; its recurrence successor
				// is spawned when it completes. Record it so concurrent
				// declarations fast-path instead of minting a second chain.
				$this->record_periodic_schedule( (int) min( array_keys( $running ) ) );
				return true;
			}

			$new_id = as_schedule_recurring_action(
				time(),
				self::PERIODIC_RECONCILE_INTERVAL,
				self::PERIODIC_RECONCILE_HOOK,
				array(),
				self::GROUP
			);

			if ( empty( $new_id ) ) {
				return false;
			}

			$this->record_periodic_schedule( (int) $new_id );
			return true;
		} finally {
			$this->release_schedule_lock( self::PERIODIC_RECONCILE_RECORD_KEY );
		}
	}

	/**
	 * The maintenance chain's callback: stamp the record's liveness
	 * timestamp — this run is itself the evidence the chain is alive; the
	 * declaration fast path trusts it until it ages past one interval —
	 * then run the registry's lock-protected reconcile() in apply mode, so
	 * missing routine schedules are re-enqueued, duplicate chains collapse
	 * to one, and orphaned ones are removed within one interval of the
	 * drift appearing.
	 *
	 * Registered on {@see PERIODIC_RECONCILE_HOOK}; Action Scheduler spawns
	 * the next hourly occurrence after this callback completes.
	 */
	public static function run_periodic_reconcile(): void {
		self::instance()->touch_periodic_schedule();

		$report = WP_Agent_Routine_Registry::reconcile();

		/**
		 * Fires after a periodic reconcile run completes. Consumers can wire
		 * up run-recording and alerting on the report here.
		 *
		 * @param array{enqueued:string[],removed:string[],unchanged:string[],errors:array<string,string>} $report
		 */
		do_action( 'wp_agent_routine_periodic_reconcile_completed', $report );
	}

	/**
	 * Cancel every scheduled action this backend owns for the given routine,
	 * remove its generation tombstone and schedule record, and clear any
	 * paused marker.
	 */
	public function unregister( string $routine_id ): void {
		if ( self::has_option_layer() ) {
			delete_option( self::generation_option_name( $routine_id ) );
		}
		self::set_paused( $routine_id, false );
		$this->forget_schedule_record( $routine_id );

		if ( ! $this->is_available() ) {
			return;
		}
		as_unschedule_all_actions( self::SCHEDULED_HOOK, array( 'routine_id' => $routine_id ), self::GROUP );
		$this->forget_pending_routine( $routine_id );
	}

	/**
	 * Cancel the recurring/cron schedule without removing the routine from
	 * the registry. The pause is recorded durably so
	 * {@see WP_Agent_Routine_Registry::reconcile()} does not re-enqueue a
	 * deliberately-paused routine.
	 */
	public function pause( string $routine_id ): void {
		self::set_paused( $routine_id, true );
		// The recorded chain is about to stop existing: drop the record so a
		// later resume() cannot fast-path on a signature whose action is gone.
		$this->forget_schedule_record( $routine_id );
		if ( ! $this->is_available() ) {
			return;
		}
		as_unschedule_all_actions( self::SCHEDULED_HOOK, array( 'routine_id' => $routine_id ), self::GROUP );
		$this->forget_pending_routine( $routine_id );
	}

	/**
	 * Re-establish the recurring/cron schedule for a previously-paused
	 * routine. Idempotent — calling on a routine whose schedule is still
	 * active simply re-registers (the underlying register call is itself a
	 * no-op when the schedule is unchanged).
	 */
	public function resume( WP_Agent_Routine $routine ): bool {
		return $this->register( $routine );
	}

	/**
	 * Enqueue a single-shot action for the routine, in addition to its
	 * recurring schedule. Its id is necessarily higher than the routine's
	 * current watermark (Action Scheduler ids only increase), so it runs
	 * normally unless a later register() call supersedes the chain before
	 * it fires — the same fence the recurring chain is subject to.
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
	 * All pending routine actions, hydrated fresh from the store (never the
	 * request-scoped register() cache — see {@see pending_by_routine()}).
	 *
	 * @deprecated 0.11.0 Use WP_Agent_Routine_Registry::backend()->pending_by_routine().
	 *
	 * @return array<int,\ActionScheduler_Action> Pending actions keyed by action id.
	 */
	public static function pending_routine_actions(): array {
		$flat = array();
		foreach ( self::instance()->fetch_pending_map() as $actions ) {
			foreach ( $actions as $action_id => $action ) {
				$flat[ $action_id ] = $action;
			}
		}
		return $flat;
	}

	/**
	 * Pending backend handles grouped by logical routine id.
	 *
	 * Always forces a fresh bulk fetch rather than reading the request-scoped
	 * cache the scheduling paths ({@see repair()}) walk: this is the one read
	 * {@see WP_Agent_Routine_Registry::reconcile()} uses, and reconcile's
	 * entire purpose is authoritative drift detection — trusting a cache or a
	 * schedule record populated before the drift happened (a pruned Action
	 * Scheduler table, a restored backup, a manually deleted row) would
	 * defeat the point. The fresh read also refreshes the shared cache, so
	 * repair() calls later in the same request see current state without
	 * paying for a second bulk read.
	 *
	 * @return array<string, list<int>> routine_id => pending action ids.
	 */
	public function pending_by_routine(): array {
		$this->pending_cache = $this->fetch_pending_map();

		$out = array();
		foreach ( $this->pending_cache as $routine_id => $actions ) {
			$out[ $routine_id ] = array_keys( $actions );
		}
		return $out;
	}

	/**
	 * The request-scoped pending-actions cache, grouped by routine id.
	 * Populated by exactly one bulk `as_get_scheduled_actions()` call
	 * (hook + group + status = pending only — bounded by the number of
	 * *live* pending actions, not by however large the group's canceled
	 * history has grown) the first time anything needs it in a request.
	 *
	 * @return array<string, array<int,\ActionScheduler_Action>>
	 */
	private function pending_map(): array {
		if ( null === $this->pending_cache ) {
			$this->pending_cache = $this->fetch_pending_map();
		}
		return $this->pending_cache;
	}

	/**
	 * @return array<string, array<int,\ActionScheduler_Action>>
	 */
	private function fetch_pending_map(): array {
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

		$map = array();
		foreach ( $ids as $id ) {
			$action_id = is_numeric( $id ) ? (int) $id : 0;
			if ( $action_id <= 0 ) {
				continue;
			}

			try {
				$action = \ActionScheduler_Store::instance()->fetch_action( $action_id );
			} catch ( \Throwable $error ) {
				unset( $error );
				continue;
			}

			$args       = $action->get_args();
			$routine_id = $args['routine_id'] ?? ( $args[0] ?? '' );
			if ( ! is_string( $routine_id ) || '' === $routine_id ) {
				continue;
			}

			$map[ $routine_id ][ $action_id ] = $action;
		}

		return $map;
	}

	/**
	 * Fold a freshly-scheduled action into the cache directly, avoiding a
	 * full re-fetch on the next register() call in this request.
	 */
	private function remember_pending_action( string $routine_id, int $action_id ): void {
		if ( null === $this->pending_cache ) {
			return; // Not populated; the next read fetches fresh.
		}

		try {
			$action = class_exists( '\ActionScheduler_Store' ) ? \ActionScheduler_Store::instance()->fetch_action( $action_id ) : null;
		} catch ( \Throwable $error ) {
			unset( $error );
			$action = null;
		}

		if ( null === $action ) {
			unset( $this->pending_cache[ $routine_id ] );
			return;
		}

		$this->pending_cache[ $routine_id ] = array( $action_id => $action );
	}

	private function forget_pending_routine( string $routine_id ): void {
		if ( null !== $this->pending_cache ) {
			unset( $this->pending_cache[ $routine_id ] );
		}
	}

	private function forget_pending_action( int $action_id ): void {
		if ( null === $this->pending_cache ) {
			return;
		}
		foreach ( $this->pending_cache as $routine_id => $actions ) {
			if ( isset( $actions[ $action_id ] ) ) {
				unset( $this->pending_cache[ $routine_id ][ $action_id ] );
				if ( array() === $this->pending_cache[ $routine_id ] ) {
					unset( $this->pending_cache[ $routine_id ] );
				}
				return;
			}
		}
	}

	/**
	 * The schedule signature a routine's record must carry: exactly what
	 * {@see recurrence_matches()} compares, minus the action being tested —
	 * trigger type plus interval seconds, or trigger type plus cron
	 * expression. Two routines with equal signatures are interchangeable as
	 * far as the scheduled recurrence is concerned.
	 */
	private static function schedule_signature( WP_Agent_Routine $routine ): string {
		if ( WP_Agent_Routine::TRIGGER_EXPRESSION === $routine->get_trigger_type() ) {
			return 'expression:' . $routine->get_expression();
		}
		return 'interval:' . $routine->get_interval_seconds();
	}

	/**
	 * The persisted schedule-record map ({@see SCHEDULES_OPTION}), read at
	 * most once per request — every later read is served from the cache, so
	 * declaring N routines costs one option read per request regardless of N.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schedules_map(): array {
		if ( null !== $this->schedules_cache ) {
			return $this->schedules_cache;
		}

		if ( ! self::has_option_layer() ) {
			$this->schedules_cache = array();
			return $this->schedules_cache;
		}

		$value = get_option( self::SCHEDULES_OPTION, array() );
		/** @var array<string, array<string, mixed>> $map Row shape is written by record_schedule(); anything else is treated as an empty map. */
		$map                   = is_array( $value ) ? $value : array();
		$this->schedules_cache = $map;
		return $this->schedules_cache;
	}

	/**
	 * The persisted record of what this bridge last scheduled for the
	 * routine, or null when there is none (never scheduled, record pruned,
	 * or an unparseable row).
	 *
	 * @return array{signature:string, action_id:int}|null
	 */
	private function schedule_record( string $routine_id ): ?array {
		$record = $this->schedules_map()[ $routine_id ] ?? null;
		if ( ! is_array( $record ) ) {
			return null;
		}

		$signature = is_string( $record['signature'] ?? null ) ? $record['signature'] : '';
		$action_id = is_numeric( $record['action_id'] ?? null ) ? (int) $record['action_id'] : 0;
		if ( '' === $signature || $action_id <= 0 ) {
			return null;
		}

		return array(
			'signature' => $signature,
			'action_id' => $action_id,
		);
	}

	/**
	 * The schedule signature the maintenance chain's record must carry.
	 */
	private static function periodic_signature(): string {
		return 'interval:' . self::PERIODIC_RECONCILE_INTERVAL;
	}

	/**
	 * Whether a maintenance-chain action's own Action Scheduler recurrence
	 * still matches {@see PERIODIC_RECONCILE_INTERVAL}. Deliberately the
	 * same comparison shape as {@see recurrence_matches()} minus the
	 * routine: only the interval is schedule-significant for the
	 * maintenance chain's idempotency.
	 */
	private static function periodic_recurrence_matches( \ActionScheduler_Action $action ): bool {
		$schedule = $action->get_schedule();
		if ( ! $schedule->is_recurring() ) {
			return false;
		}
		$recurrence = $schedule->get_recurrence();
		return is_numeric( $recurrence ) && (int) $recurrence === self::PERIODIC_RECONCILE_INTERVAL;
	}

	/**
	 * The persisted record of the maintenance chain
	 * ({@see PERIODIC_RECONCILE_RECORD_KEY} in the schedules map), or null
	 * when there is none (never declared, record pruned, or an
	 * unparseable row).
	 *
	 * @return array{signature:string, action_id:int, checked:int}|null
	 */
	private function periodic_record(): ?array {
		$record = $this->schedules_map()[ self::PERIODIC_RECONCILE_RECORD_KEY ] ?? null;
		if ( ! is_array( $record ) ) {
			return null;
		}

		$signature = is_string( $record['signature'] ?? null ) ? $record['signature'] : '';
		$action_id = is_numeric( $record['action_id'] ?? null ) ? (int) $record['action_id'] : 0;
		$checked   = is_numeric( $record['checked'] ?? null ) ? (int) $record['checked'] : 0;
		if ( '' === $signature || $action_id <= 0 || $checked <= 0 ) {
			return null;
		}

		return array(
			'signature' => $signature,
			'action_id' => $action_id,
			'checked'   => $checked,
		);
	}

	/**
	 * Persist the maintenance chain's schedule record (signature + chain
	 * id + a fresh liveness timestamp). Called only from the authoritative
	 * repair path, which has just verified or minted the chain.
	 */
	private function record_periodic_schedule( int $action_id ): void {
		if ( ! self::has_option_layer() ) {
			return;
		}

		$map                                          = $this->schedules_map();
		$map[ self::PERIODIC_RECONCILE_RECORD_KEY ] = array(
			'signature' => self::periodic_signature(),
			'action_id' => $action_id,
			'checked'   => time(),
		);
		update_option( self::SCHEDULES_OPTION, $map, false );
		$this->schedules_cache = $map;
	}

	/**
	 * Stamp the maintenance record's liveness timestamp — the periodic
	 * callback calls this on every run, which is what makes a fresh record
	 * trustworthy enough for the declaration fast path to skip the store
	 * entirely. When no parseable record exists, fall through to the
	 * authoritative repair instead: the callback is itself proof a chain
	 * is live, and repair() finds it in-progress and records it.
	 */
	private function touch_periodic_schedule(): void {
		if ( ! self::has_option_layer() ) {
			return;
		}

		$record = $this->periodic_record();
		if ( null === $record ) {
			$this->repair_periodic_reconcile();
			return;
		}

		$map                                           = $this->schedules_map();
		$map[ self::PERIODIC_RECONCILE_RECORD_KEY ]['checked'] = time();
		update_option( self::SCHEDULES_OPTION, $map, false );
		$this->schedules_cache = $map;
	}

	/**
	 * All maintenance-chain actions currently in the given Action
	 * Scheduler status — pending, or in-progress once a worker has claimed
	 * an instance. A bounded, targeted query (hook + group + status) only
	 * the repair path pays; the declaration fast path never does.
	 *
	 * @return array<int, \ActionScheduler_Action>
	 */
	private function periodic_actions( string $status ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return array();
		}

		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::PERIODIC_RECONCILE_HOOK,
				'group'    => self::GROUP,
				'status'   => $status,
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
	 * Persist the routine's schedule record (signature + chain's first
	 * action id). A no-op write when the record already says exactly this —
	 * reconcile calls this for every healthy routine and must not turn that
	 * into an option write per routine.
	 *
	 * The whole map is read-modify-written, so two processes recording
	 * *different* routines concurrently can lose each other's entry (a plain
	 * lost update). That is tolerable by design: the schedule itself lives in
	 * Action Scheduler, not in the record, and a lost entry only costs one
	 * slow-path re-record on the next declaration.
	 */
	private function record_schedule( string $routine_id, string $signature, int $action_id ): void {
		if ( ! self::has_option_layer() ) {
			return;
		}

		$map     = $this->schedules_map();
		$current = $map[ $routine_id ] ?? null;
		if (
			is_array( $current )
			&& ( $current['signature'] ?? null ) === $signature
			&& ( is_numeric( $current['action_id'] ?? null ) ? (int) $current['action_id'] : 0 ) === $action_id
		) {
			return;
		}

		$map[ $routine_id ] = array(
			'signature' => $signature,
			'action_id' => $action_id,
		);
		update_option( self::SCHEDULES_OPTION, $map, false );
		$this->schedules_cache = $map;
	}

	/**
	 * Drop the routine's schedule record (unregister/pause: the recorded
	 * chain is about to stop existing, and a stale record would let
	 * register() fast-path past a routine that now needs scheduling).
	 */
	private function forget_schedule_record( string $routine_id ): void {
		if ( ! self::has_option_layer() ) {
			return;
		}

		$map = $this->schedules_map();
		if ( ! array_key_exists( $routine_id, $map ) ) {
			return;
		}

		unset( $map[ $routine_id ] );
		update_option( self::SCHEDULES_OPTION, $map, false );
		$this->schedules_cache = $map;
	}

	/**
	 * The one matching chain to keep, consistent with the
	 * generation/watermark model: the lowest-id matching action that is
	 * *live* — at or above the routine's current watermark. Keeping a
	 * below-watermark action would keep a chain the fence is going to cancel
	 * at its next execution attempt, silently stopping the routine; when no
	 * watermark is recorded (legacy or never-re-registered chains) every
	 * matching action is live. Returns null when no matching action is live,
	 * meaning the routine needs a fresh chain.
	 *
	 * @param list<int> $matching_ids Pending matching action ids.
	 */
	private static function chain_to_keep( array $matching_ids, ?int $watermark ): ?int {
		$live = $matching_ids;
		if ( null !== $watermark ) {
			$live = array_values(
				array_filter(
					$matching_ids,
					static fn( int $action_id ): bool => $action_id >= $watermark
				)
			);
		}

		if ( array() === $live ) {
			return null;
		}

		sort( $live );
		return $live[0];
	}

	/**
	 * Cancel every pending action for the routine other than $keep_id.
	 *
	 * With `$recurring_only` the sweep leaves non-recurring actions (pending
	 * one-shot `run_now` enqueues) alone — used when the schedule itself did
	 * not change and a requested one-shot wake must still fire. Without it
	 * (a genuine schedule change) every other pending action is superseded:
	 * old chains, stale-recurrence chains, and pending one-shots alike.
	 *
	 * An action already claimed by an in-flight worker is not touched: it is
	 * not part of the pending set this sweep walks (Action Scheduler marks
	 * claimed actions in-progress), so a collapse never breaks a running
	 * worker.
	 *
	 * @param array<int, \ActionScheduler_Action> $existing Pending actions by id.
	 */
	private function cancel_superseded( int $keep_id, array $existing, bool $recurring_only ): void {
		foreach ( $existing as $action_id => $action ) {
			$action_id = (int) $action_id;
			if ( $action_id === $keep_id ) {
				continue;
			}
			if ( $recurring_only && ! $action->get_schedule()->is_recurring() ) {
				continue;
			}
			$this->cancel( $action_id );
		}
	}

	/**
	 * Acquire the routine's single-flight schedule lock — the check-then-
	 * schedule step must not run concurrently in two processes, or both see
	 * "no matching chain" and both schedule one (the duplicate-chain race).
	 *
	 * One atomic `$wpdb` compare-and-set, identical in shape to the
	 * registry's reconcile lock: `INSERT ... ON DUPLICATE KEY UPDATE`
	 * against `wp_options` directly, never through
	 * `add_option()`/`get_option()`. The single query handles all three
	 * cases atomically against `option_name`'s unique key:
	 *
	 *  - no row exists: plain insert, 1 affected row;
	 *  - row exists and is stale — older than {@see SCHEDULE_LOCK_TTL}, its
	 *    holder crashed mid-schedule — `ON DUPLICATE KEY UPDATE` overwrites
	 *    it, MySQL reports 2 affected rows for a changed row;
	 *  - row exists and is fresh: the `IF()` leaves the value unchanged,
	 *    MySQL reports 0 affected rows for a no-op update.
	 *
	 * The takeover being part of the same compare-and-set is what makes it
	 * single-flight: any number of processes that all observe the same
	 * stale lock are serialized by MySQL's row lock, and only the first
	 * re-checks the stale condition against the fresh value — the rest see
	 * 0 affected rows and no-op. The previous implementation's stale
	 * takeover was an unconditional `update_option()`, so every process
	 * that observed the same stale lock took it over and proceeded —
	 * re-opening the duplicate-chain race exactly when a crashed holder had
	 * left a stale lock behind.
	 *
	 * Reading and writing exclusively through `$wpdb` — with an explicit
	 * `wp_cache_delete()` after every write — also means a stale options-
	 * cache entry can never make the lock unavailable or un-releasable.
	 * `$wpdb->query()` returns the raw affected-rows count for a non-SELECT
	 * query, so `> 0` is "we now hold the lock".
	 *
	 * With no option layer or no `$wpdb` at all (pure-PHP harnesses) callers
	 * are already single-process and the lock is a no-op success.
	 */
	private function acquire_schedule_lock( string $routine_id ): bool {
		if ( ! self::has_option_layer() ) {
			return true;
		}

		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return true;
		}

		$name         = self::SCHEDULE_LOCK_OPTION_PREFIX . $routine_id;
		$now          = time();
		$stale_before = $now - self::SCHEDULE_LOCK_TTL;

		$query = $wpdb->prepare(
			'INSERT INTO %i (option_name, option_value, autoload)
			VALUES (%s, %s, %s)
			ON DUPLICATE KEY UPDATE option_value = IF( CAST( option_value AS UNSIGNED ) <= %d, VALUES(option_value), option_value )',
			$wpdb->options,
			$name,
			(string) $now,
			'no',
			$stale_before
		);
		if ( ! is_string( $query ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is built by $wpdb->prepare() above.
		$affected = $wpdb->query( $query );

		self::flush_schedule_lock_cache( $name );

		return is_int( $affected ) && $affected > 0;
	}

	private function release_schedule_lock( string $routine_id ): void {
		if ( ! self::has_option_layer() ) {
			return;
		}

		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$name = self::SCHEDULE_LOCK_OPTION_PREFIX . $routine_id;

		$query = $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name );
		if ( is_string( $query ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is built by $wpdb->prepare() above.
			$wpdb->query( $query );
		}

		self::flush_schedule_lock_cache( $name );
	}

	/**
	 * Explicitly evict a schedule-lock option from the object cache after
	 * every direct `$wpdb` write, so anything that does happen to read it
	 * via `get_option()` never observes a value the DB no longer has.
	 */
	private static function flush_schedule_lock_cache( string $name ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
		}
	}

	/**
	 * Test-only: expose the single-flight schedule-lock acquisition so a
	 * pure-PHP smoke can model two concurrent processes that both observe
	 * the same stale lock and race for the takeover (production callers
	 * reach it through repair(), where the loser no-ops before scheduling).
	 *
	 * @param string $routine_id Routine id the lock is keyed on.
	 */
	public function acquire_schedule_lock_for_tests( string $routine_id ): bool {
		return $this->acquire_schedule_lock( $routine_id );
	}

	/**
	 * Whether a pending action's own Action Scheduler schedule already
	 * matches the routine's trigger. Deliberately ignores the first-run
	 * timestamp (and therefore the stagger offset) — only the recurrence
	 * itself (interval seconds, or cron expression) is schedule-significant
	 * for idempotency.
	 */
	private static function recurrence_matches( \ActionScheduler_Action $action, WP_Agent_Routine $routine ): bool {
		$schedule = $action->get_schedule();
		if ( ! $schedule->is_recurring() ) {
			return false;
		}
		$recurrence = $schedule->get_recurrence();

		if ( WP_Agent_Routine::TRIGGER_EXPRESSION === $routine->get_trigger_type() ) {
			return is_string( $recurrence ) && hash_equals( $routine->get_expression(), $recurrence );
		}

		return is_numeric( $recurrence ) && (int) $recurrence === $routine->get_interval_seconds();
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

		$this->forget_pending_action( $handle );

		return true;
	}

	/**
	 * Install the generation fence hook once per request, and schedule the
	 * one-shot legacy-option purge.
	 *
	 * `action_scheduler_before_execute` ($action_id) fires before the
	 * runner re-checks that the action is still pending. When the action's
	 * id is below its routine's current watermark, it belongs to a
	 * superseded chain: we cancel it there, so the runner's own status
	 * check skips it and no recurrence successor is spawned.
	 */
	public static function register_generation_fence(): void {
		if ( self::$fence_registered ) {
			return;
		}
		self::$fence_registered = true;
		add_action( 'action_scheduler_before_execute', array( self::class, 'fence_before_execute' ), 0, 1 );
		add_action( 'init', array( self::class, 'maybe_purge_legacy_action_generation_options' ), 20 );
	}

	/**
	 * Cancel a routine action whose id is below the routine's current
	 * watermark (i.e. it belongs to a chain superseded by a later
	 * register() call, including one already claimed by an in-flight
	 * worker when the supersede happened).
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
		$watermark = self::instance()->current_watermark( $routine_id );
		if ( null === $watermark || $action_id >= $watermark ) {
			return; // No fence recorded (legacy/never re-registered this process) or current/newer chain: let it run.
		}
		if ( self::instance()->cancel( $action_id ) ) {
			do_action( 'agents_routine_action_fenced', $routine_id, self::instance()->current_generation( $routine_id ) ?? '', $action_id );
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

	/**
	 * One-shot cleanup of the unbounded per-action option rows written by
	 * pre-fix versions of this bridge
	 * (`agents_routine_action_generation_<action_id>`, one row per stored
	 * action, deleted only by `cancel()` — a path `register()`'s prior
	 * unconditional `as_unschedule_all_actions()` call never reached).
	 * Deletes every matching row in a single query.
	 *
	 * A targeted per-key cache flush is not viable at the observed scale
	 * (hundreds of thousands of rows in production); this flushes the
	 * whole `options` object-cache group when the active object cache
	 * supports group flushing (as Redis Object Cache does), and falls back
	 * to clearing `alloptions` otherwise.
	 *
	 * Safe to call more than once — a repeat call deletes zero rows.
	 * Callers should gate repeat calls behind
	 * {@see maybe_purge_legacy_action_generation_options()} rather than
	 * calling this directly on every request.
	 *
	 * @return int Number of option rows deleted, or 0 when $wpdb is
	 *            unavailable (pure-PHP test harnesses, or WordPress not
	 *            yet bootstrapped).
	 */
	public static function purge_legacy_action_generation_options(): int {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		$like = $wpdb->esc_like( self::LEGACY_ACTION_GENERATION_OPTION_PREFIX ) . '%';

		$query = $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $like );
		if ( ! is_string( $query ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is built by $wpdb->prepare() above.
		$deleted = $wpdb->query( $query );

		if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'options' );
		} elseif ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Run {@see purge_legacy_action_generation_options()} at most once per
	 * site, gated by a persisted marker option rather than a plugin
	 * activation hook.
	 *
	 * agents-api ships far more often as a Composer-embedded library inside
	 * a consumer plugin than as its own activatable WordPress plugin — it
	 * has no versioned db-upgrade routine of its own for the same reason —
	 * so a plugin-activation-hook approach would never fire for most real
	 * installs. A marker-option gate checked on `init` self-heals
	 * regardless of how the substrate is loaded, at the cost of one cheap
	 * `get_option()` read per request after the purge has run.
	 */
	public static function maybe_purge_legacy_action_generation_options(): void {
		if ( ! self::has_option_layer() ) {
			return;
		}
		if ( get_option( self::LEGACY_PURGE_MARKER_OPTION, false ) ) {
			return;
		}
		self::purge_legacy_action_generation_options();
		update_option( self::LEGACY_PURGE_MARKER_OPTION, time(), false );
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
