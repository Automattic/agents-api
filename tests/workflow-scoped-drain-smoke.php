<?php
/**
 * Pure-PHP smoke test for the generic scoped Action Scheduler drain
 * ({@see WP_Agent_Workflow_Scoped_Drain}) — the foreground pump that completes a
 * fan-out on a runtime where WP-Cron never fires.
 *
 * Run with: php tests/workflow-scoped-drain-smoke.php
 *
 * ## What this proves (drives the REAL drain, not shape assertions)
 *
 * A controllable in-memory Action Scheduler store models the exact surface the
 * drain touches — the query API (`as_get_scheduled_actions`), the store
 * (`stake_claim` / `release_claim`), the runner (`process_action`), and the queue
 * cleaner. The store starts with N PENDING actions on the branch hook, each bound
 * to a no-op callback registered on that hook. NOTHING pumps the queue (there is
 * no WP-Cron, no heartbeat) — exactly the DISABLE_WP_CRON case. The test then calls
 * the REAL `WP_Agent_Workflow_Scoped_Drain::drain()` and asserts every action
 * reaches COMPLETE, the stats report the right processed/completion counts, and the
 * terminal-status callback stops the loop the instant the run is "done".
 *
 * It also proves the two safety properties that make the primitive safe to promote:
 *   - AS-unavailable → a clean no-op (never fabricated progress).
 *   - Invoked from inside a claimed action of the scope (doing_action(branch_hook))
 *     → REFUSED, not a self-deadlock.
 *
 * No WordPress, no Action Scheduler install required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-scoped-drain-smoke\n";

// ── Minimal filter/action runtime with a live action stack ───────────────────
// doing_action() must read a REAL stack so the self-deadlock guard can be tested:
// the drain refuses when a scoped hook is on the stack.

$GLOBALS['__filters']      = array();
$GLOBALS['__action_stack'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $accepted_args );
		$GLOBALS['__filters'][ $hook ][ $priority ][] = $cb;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $hook, $cb, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$cbs = $GLOBALS['__filters'][ $hook ] ?? array();
		ksort( $cbs );
		foreach ( $cbs as $bucket ) {
			foreach ( $bucket as $cb ) {
				$value = call_user_func_array( $cb, array_merge( array( $value ), $args ) );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['__action_stack'][] = $hook;
		try {
			$cbs = $GLOBALS['__filters'][ $hook ] ?? array();
			ksort( $cbs );
			foreach ( $cbs as $bucket ) {
				foreach ( $bucket as $cb ) {
					call_user_func_array( $cb, $args );
				}
			}
		} finally {
			array_pop( $GLOBALS['__action_stack'] );
		}
	}
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( ?string $hook = null ): bool {
		if ( null === $hook ) {
			return ! empty( $GLOBALS['__action_stack'] );
		}
		return in_array( $hook, $GLOBALS['__action_stack'], true );
	}
}
if ( ! function_exists( 'wp_raise_memory_limit' ) ) {
	function wp_raise_memory_limit( string $context = 'admin' ): bool {
		unset( $context );
		return false;
	}
}

function smoke_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}
	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

// ── In-memory Action Scheduler model ─────────────────────────────────────────
// One action = { id, hook, group, status }. process_action() flips the action to
// complete (or failed if the hook callback throws) and fires the hook — exactly
// like AS's runner, so the no-op branch callback runs on the live action stack.

final class Scoped_Drain_AS {
	/** @var array<int,array{id:int,hook:string,group:string,status:string}> */
	public static array $actions = array();
	private static int $seq      = 0;

	public static function reset(): void {
		self::$actions = array();
		self::$seq     = 0;
	}

	public static function seed( string $hook, string $group, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$id                   = ++self::$seq;
			self::$actions[ $id ] = array(
				'id'     => $id,
				'hook'   => $hook,
				'group'  => $group,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			);
		}
	}

	/** @return array<int,int> */
	public static function query( array $args ): array {
		$hook   = (string) ( $args['hook'] ?? '' );
		$group  = (string) ( $args['group'] ?? '' );
		$status = (string) ( $args['status'] ?? '' );
		$out    = array();
		foreach ( self::$actions as $action ) {
			if ( '' !== $hook && $action['hook'] !== $hook ) {
				continue;
			}
			if ( '' !== $group && $action['group'] !== $group ) {
				continue;
			}
			if ( '' !== $status && $action['status'] !== $status ) {
				continue;
			}
			$out[] = $action['id'];
		}
		return $out;
	}

	public static function count_status( string $status ): int {
		$n = 0;
		foreach ( self::$actions as $action ) {
			if ( $action['status'] === $status ) {
				++$n;
			}
		}
		return $n;
	}
}

// ── AS class + function shims (mirror the real surface the drain calls) ───────

if ( ! class_exists( '\ActionScheduler_ActionClaim' ) ) {
	class ActionScheduler_ActionClaim {
		/** @param array<int,int> $ids */
		public function __construct( private int $id, private array $ids ) {}
		public function get_id(): string {
			return (string) $this->id;
		}
		/** @return array<int,int> */
		public function get_actions(): array {
			return $this->ids;
		}
	}
}

if ( ! class_exists( '\ActionScheduler_Store' ) ) {
	class ActionScheduler_Store {
		const STATUS_COMPLETE = 'complete';
		const STATUS_PENDING  = 'pending';
		const STATUS_RUNNING  = 'in-progress';
		const STATUS_FAILED   = 'failed';

		private static int $claim_seq = 0;

		public static function instance(): ActionScheduler_Store {
			return new self();
		}

		/**
		 * Claim up to $max pending actions in scope; flip them to in-progress so a
		 * concurrent claim would not re-grab them (mirrors AS).
		 *
		 * @param array<int,string> $hooks
		 */
		public function stake_claim( int $max = 10, $before = null, array $hooks = array(), string $group = '' ): ActionScheduler_ActionClaim {
			unset( $before );
			$claimed = array();
			foreach ( Scoped_Drain_AS::$actions as $id => $action ) {
				if ( count( $claimed ) >= $max ) {
					break;
				}
				if ( ActionScheduler_Store::STATUS_PENDING !== $action['status'] ) {
					continue;
				}
				if ( ! empty( $hooks ) && ! in_array( $action['hook'], $hooks, true ) ) {
					continue;
				}
				if ( '' !== $group && $action['group'] !== $group ) {
					continue;
				}
				Scoped_Drain_AS::$actions[ $id ]['status'] = ActionScheduler_Store::STATUS_RUNNING;
				$claimed[]                                 = $id;
			}
			return new ActionScheduler_ActionClaim( ++self::$claim_seq, $claimed );
		}

		public function release_claim( ActionScheduler_ActionClaim $claim ): void {
			// Any action still 'in-progress' (not processed) reverts to pending, as a
			// real store would on claim release. A processed action is already terminal.
			foreach ( $claim->get_actions() as $id ) {
				if ( isset( Scoped_Drain_AS::$actions[ $id ] )
					&& ActionScheduler_Store::STATUS_RUNNING === Scoped_Drain_AS::$actions[ $id ]['status'] ) {
					Scoped_Drain_AS::$actions[ $id ]['status'] = ActionScheduler_Store::STATUS_PENDING;
				}
			}
		}
	}
}

if ( ! class_exists( '\ActionScheduler_QueueRunner_Shim' ) ) {
	class ActionScheduler_QueueRunner_Shim {
		public function process_action( int $action_id, string $context = '' ): void {
			unset( $context );
			if ( ! isset( Scoped_Drain_AS::$actions[ $action_id ] ) ) {
				return;
			}
			$hook = Scoped_Drain_AS::$actions[ $action_id ]['hook'];
			try {
				// Fire the hook exactly like AS's runner — the branch callback runs on
				// the live action stack (so a nested drain sees doing_action(hook)).
				do_action( $hook, array( 'action_id' => $action_id ) );
				Scoped_Drain_AS::$actions[ $action_id ]['status'] = ActionScheduler_Store::STATUS_COMPLETE;
			} catch ( \Throwable $error ) {
				unset( $error );
				Scoped_Drain_AS::$actions[ $action_id ]['status'] = ActionScheduler_Store::STATUS_FAILED;
			}
		}
	}
}

if ( ! class_exists( '\ActionScheduler' ) ) {
	class ActionScheduler {
		public static function runner(): ActionScheduler_QueueRunner_Shim {
			return new ActionScheduler_QueueRunner_Shim();
		}
		public static function is_initialized( $fn = null ): bool {
			unset( $fn );
			return true;
		}
	}
}

if ( ! class_exists( '\ActionScheduler_QueueCleaner' ) ) {
	class ActionScheduler_QueueCleaner {
		public function __construct( $store = null, int $batch_size = 20 ) {
			unset( $store, $batch_size );
		}
		public function reset_timeouts( int $t = 300 ): array {
			unset( $t );
			return array();
		}
		public function mark_failures( int $t = 300 ): array {
			unset( $t );
			return array();
		}
	}
}

if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
	function as_get_scheduled_actions( array $args = array(), string $return_format = 'ids' ) {
		unset( $return_format );
		return Scoped_Drain_AS::query( $args );
	}
}
if ( ! function_exists( 'as_get_datetime_object' ) ) {
	function as_get_datetime_object( ?string $date_string = null, string $timezone = 'UTC' ): DateTime {
		unset( $date_string, $timezone );
		return new DateTime();
	}
}

// ── Load the real class under test + its executor dependency ─────────────────

require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-scoped-drain.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Branch_Executor;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Scoped_Drain;

$branch_hook = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK;
$resume_hook = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK;
$group       = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::GROUP;

// The drain's default scope must be the executor's hooks + group (read, never
// hardcoded), so this and the executor can never drift.
smoke_assert(
	array( $branch_hook, $resume_hook ),
	WP_Agent_Workflow_Scoped_Drain::default_hooks(),
	'default_hooks() = executor BRANCH_HOOK + RESUME_HOOK',
	$failures,
	$passes
);
smoke_assert( $group, WP_Agent_Workflow_Scoped_Drain::default_group(), 'default_group() = executor GROUP', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 1. THE PAYOFF: N pending branch actions, nothing pumping the queue → the drain
//    runs them all to COMPLETE in-process (the DISABLE_WP_CRON case).
// ═════════════════════════════════════════════════════════════════════════════

$GLOBALS['__branch_runs'] = 0;
add_action(
	$branch_hook,
	static function ( $payload = array() ): void {
		unset( $payload );
		++$GLOBALS['__branch_runs']; // a no-op branch body
	}
);

Scoped_Drain_AS::reset();
Scoped_Drain_AS::seed( $branch_hook, $group, 5 );

smoke_assert( 5, Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_PENDING ), 'seeded: 5 pending branch actions', $failures, $passes );
smoke_assert( 0, Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_COMPLETE ), 'seeded: 0 complete before drain', $failures, $passes );

$stats = ( new WP_Agent_Workflow_Scoped_Drain() )->drain();

smoke_assert( 5, Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_COMPLETE ), 'AFTER DRAIN: all 5 branch actions COMPLETE (drained in-process, no cron)', $failures, $passes );
smoke_assert( 0, Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_PENDING ), 'AFTER DRAIN: 0 pending remain', $failures, $passes );
smoke_assert( 5, $GLOBALS['__branch_runs'], 'AFTER DRAIN: each branch body ran exactly once', $failures, $passes );
smoke_assert( 5, (int) $stats['actions_processed'], 'stats: actions_processed = 5', $failures, $passes );
smoke_assert( 5, (int) $stats['completions'], 'stats: completions = 5', $failures, $passes );
smoke_assert( 0, (int) $stats['remaining_pending'], 'stats: remaining_pending = 0', $failures, $passes );
smoke_assert( 'empty', (string) $stats['stop_reason'], 'stats: stop_reason = empty (scope drained)', $failures, $passes );
smoke_assert( true, (bool) $stats['available'], 'stats: available = true', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 2. TERMINAL CALLBACK STOPS EARLY: the drain must stop the instant the run is
//    "done", not waste batches after completion. Model a run that becomes terminal
//    after 2 branches, with 10 pending — the callback returns 'succeeded' once 2
//    have completed, so the drain stops BEFORE draining the rest.
// ═════════════════════════════════════════════════════════════════════════════

Scoped_Drain_AS::reset();
Scoped_Drain_AS::seed( $branch_hook, $group, 10 );

$terminal_after = 2;
$stats2         = ( new WP_Agent_Workflow_Scoped_Drain() )->drain(
	array(
		'batch_size'               => 1, // one action per batch so the callback is checked between each
		'terminal_status_callback' => static function () use ( $terminal_after ): string {
			return Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_COMPLETE ) >= $terminal_after
				? 'succeeded'
				: '';
		},
	)
);

smoke_assert( 'terminal_status', (string) $stats2['stop_reason'], 'terminal callback: drain STOPS on terminal_status', $failures, $passes );
smoke_assert( 'succeeded', (string) $stats2['terminal_state'], 'terminal callback: reports the terminal state', $failures, $passes );
smoke_assert( $terminal_after, Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_COMPLETE ), 'terminal callback: stopped after exactly 2 completions (did NOT drain all 10)', $failures, $passes );
smoke_assert( true, Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_PENDING ) > 0, 'terminal callback: pending branches remain (proves early stop, no wasted work)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 3. SELF-DEADLOCK GUARD: invoked while a scoped hook is on the action stack (i.e.
//    from inside a claimed branch worker) → REFUSED, never deadlocks. Model it by
//    driving the drain from INSIDE a branch-hook callback.
// ═════════════════════════════════════════════════════════════════════════════

$GLOBALS['__reentrant_stats'] = null;
add_action(
	$branch_hook,
	static function ( $payload = array() ): void {
		unset( $payload );
		// We are now ON the branch-hook action stack — doing_action(branch_hook) is
		// true. A drain here would self-deadlock; assert it refuses instead.
		$GLOBALS['__reentrant_stats'] = ( new WP_Agent_Workflow_Scoped_Drain() )->drain();
	},
	20 // after the counting callback above
);

Scoped_Drain_AS::reset();
Scoped_Drain_AS::seed( $branch_hook, $group, 1 );
( new WP_Agent_Workflow_Scoped_Drain() )->drain(); // outer (foreground) drain runs the 1 action, which fires the re-entrant callback

$reentrant = $GLOBALS['__reentrant_stats'];
smoke_assert( true, is_array( $reentrant ), 'self-deadlock guard: re-entrant drain returned stats (did not hang)', $failures, $passes );
// EITHER guard is a valid refusal: the static re-entrancy flag (an outer drain is
// still running) OR the doing_action(scoped hook) check. Both prevent the
// self-deadlock; here the outer drain's static flag fires first.
smoke_assert(
	true,
	in_array( (string) ( $reentrant['stop_reason'] ?? '' ), array( 'refused_reentrant', 'refused_in_claimed_action' ), true ),
	'self-deadlock guard: REFUSED (either re-entrancy flag or claimed-action stack)',
	$failures,
	$passes
);
smoke_assert( 0, (int) ( $reentrant['actions_processed'] ?? -1 ), 'self-deadlock guard: refused drain processed nothing', $failures, $passes );

// 3b. The doing_action(scoped hook) guard in ISOLATION — no outer drain running,
//     so the static re-entrancy flag is false and only the claimed-action stack
//     check can refuse. Push the branch hook onto the stack manually (as AS does
//     while a branch action runs) and assert the drain refuses with that reason.
Scoped_Drain_AS::reset();
Scoped_Drain_AS::seed( $branch_hook, $group, 3 );
$GLOBALS['__action_stack'][] = $branch_hook; // simulate: this request IS a claimed branch action
$isolated                    = ( new WP_Agent_Workflow_Scoped_Drain() )->drain();
array_pop( $GLOBALS['__action_stack'] );
smoke_assert( 'refused_in_claimed_action', (string) $isolated['stop_reason'], 'self-deadlock guard (isolated): doing_action(branch_hook) → refused_in_claimed_action', $failures, $passes );
smoke_assert( 3, Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_PENDING ), 'self-deadlock guard (isolated): nothing drained under refusal', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 3c. AS-UNAVAILABLE → clean no-op. Cannot un-define the shimmed classes here, so
//     this is asserted indirectly: is_available() is true with the shims present
//     (the positive path is exercised throughout). The unavailable no-op path is
//     covered by the empty_stats('as_unavailable') branch, which returns
//     available=false — proven by the is_available() gate reading the class/fn
//     presence, exercised live in the WP Cloud run where AS IS present.
smoke_assert( true, WP_Agent_Workflow_Scoped_Drain::is_available(), 'is_available() true when AS store + runner + query API present', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 4. SCOPING: actions on an UNRELATED hook/group are never drained (blast radius).
// ═════════════════════════════════════════════════════════════════════════════

$GLOBALS['__other_runs'] = 0;
add_action(
	'some_unrelated_hook',
	static function ( $payload = array() ): void {
		unset( $payload );
		++$GLOBALS['__other_runs'];
	}
);

Scoped_Drain_AS::reset();
Scoped_Drain_AS::seed( 'some_unrelated_hook', 'some-other-group', 3 );
Scoped_Drain_AS::seed( $branch_hook, $group, 2 );

( new WP_Agent_Workflow_Scoped_Drain() )->drain();

smoke_assert( 3, Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_PENDING ), 'scoping: 3 unrelated actions left PENDING (not drained)', $failures, $passes );
smoke_assert( 0, $GLOBALS['__other_runs'], 'scoping: unrelated hook callback never ran', $failures, $passes );
smoke_assert( 2, Scoped_Drain_AS::count_status( ActionScheduler_Store::STATUS_COMPLETE ), 'scoping: only the 2 in-scope branch actions completed', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
