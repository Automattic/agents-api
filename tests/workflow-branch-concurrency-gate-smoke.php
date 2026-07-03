<?php
/**
 * Pure-PHP smoke test for the Action Scheduler concurrency GATE that scopes the
 * parallel branch fan-out — specifically that the gate stays OPEN while branches
 * are IN-PROGRESS, not just while they are pending.
 *
 * Run with: php tests/workflow-branch-concurrency-gate-smoke.php
 *
 * ## What this proves (the regression this test locks down)
 *
 * The two filters in {@see register-workflow-branch-executor.php} raise
 * `action_scheduler_queue_runner_concurrent_batches` to the in-flight branch
 * count and pin `action_scheduler_queue_runner_batch_size` to 1 while a fan-out
 * is in flight. Both gate on
 * {@see WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count()}.
 *
 * The bug this guards against: the gate USED to key off the PENDING count alone.
 * The instant workers claim their branches, those actions transition
 * PENDING -> in-progress, so a pending-only count collapses toward 0 the moment
 * claiming starts. Once it hit 0, BOTH filters reverted to the AS defaults
 * (concurrent_batches 1, batch_size 25), and AS's has_maximum_concurrent_batches()
 * (get_claim_count 1 >= concurrent_batches 1) then blocked any further worker from
 * claiming the still-pending branches while the first ran its multi-minute AI
 * call — the fan-out drained SERIALLY.
 *
 * The fix counts pending + in-progress, so the ceiling stays raised to cover
 * every branch still in flight. The assertions below drive the two filters
 * against a modeled AS action set at each phase of a fan-out and prove the
 * ceiling stays open while branches are in-progress.
 *
 * This test shims Action Scheduler's QUERY api (`as_get_scheduled_actions` +
 * `ActionScheduler_Store`) with a controllable in-memory action list, then
 * applies the REAL filters registered by register-workflow-branch-executor.php.
 * No WordPress, no Action Scheduler install required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-branch-concurrency-gate-smoke\n";

// ── Minimal filter runtime ───────────────────────────────────────────────────

$GLOBALS['__filters'] = array();

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

// ── Action Scheduler QUERY shim ──────────────────────────────────────────────
// A controllable in-memory model of AS's action store, scoped to the branch hook.
// The test seeds a set of branch actions each with a status and drives the real
// gate against it. `as_get_scheduled_actions` OR-matches an array of statuses
// exactly like AS's own DBStore (which builds `status IN (...)`), so the real
// branch_inflight_count() query — { status: [PENDING, RUNNING] } — resolves here
// the same way it would against a live AS install.

if ( ! class_exists( '\ActionScheduler_Store' ) ) {
	// A minimal stand-in carrying only the status constants the gate reads.
	class ActionScheduler_Store {
		const STATUS_PENDING  = 'pending';
		const STATUS_RUNNING  = 'in-progress';
		const STATUS_COMPLETE = 'complete';
		const STATUS_FAILED   = 'failed';
	}
}

final class AS_Query_Shim {
	/** @var array<int,array{hook:string,status:string}> */
	public static array $actions = array();

	public static function reset(): void {
		self::$actions = array();
	}

	/** Seed N actions on a hook with a given status. */
	public static function add( string $hook, string $status, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			self::$actions[] = array(
				'hook'   => $hook,
				'status' => $status,
			);
		}
	}

	/**
	 * Model as_get_scheduled_actions( { hook, status, per_page }, 'ids' ). `status`
	 * may be a single value or an array (OR-match), mirroring AS DBStore's
	 * `status IN (...)`. Returns synthetic ids bounded by per_page.
	 *
	 * @param array<string,mixed> $query
	 * @return array<int,int>
	 */
	public static function query( array $query ): array {
		$hook     = (string) ( $query['hook'] ?? '' );
		$statuses = $query['status'] ?? array();
		$statuses = is_array( $statuses ) ? $statuses : array( $statuses );
		$per_page = isset( $query['per_page'] ) ? (int) $query['per_page'] : PHP_INT_MAX;

		$matched = array();
		$id      = 0;
		foreach ( self::$actions as $action ) {
			++$id;
			if ( $action['hook'] === $hook && in_array( $action['status'], $statuses, true ) ) {
				$matched[] = $id;
				if ( count( $matched ) >= $per_page ) {
					break;
				}
			}
		}
		return $matched;
	}
}

if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
	function as_get_scheduled_actions( array $query = array(), string $return_format = 'ids' ) {
		unset( $return_format );
		return AS_Query_Shim::query( $query );
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

// ── Load the real executor + the real gate filters ───────────────────────────

require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/register-workflow-branch-executor.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Branch_Executor;

$branch_hook = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK;
$resume_hook = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK;
$max         = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::MAX_BRANCH_CONCURRENCY;

/** Apply the real concurrent_batches filter against the incoming default (AS default 1). */
$concurrent_batches = static function (): int {
	return (int) apply_filters( 'action_scheduler_queue_runner_concurrent_batches', 1 );
};
/** Apply the real batch_size filter against the incoming default (AS default 25). */
$batch_size = static function (): int {
	return (int) apply_filters( 'action_scheduler_queue_runner_batch_size', 25 );
};

// ═════════════════════════════════════════════════════════════════════════════
// 1. No fan-out in flight → both filters pass through UNCHANGED (blast radius).
// ═════════════════════════════════════════════════════════════════════════════

AS_Query_Shim::reset();
smoke_assert( 0, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count(), 'inflight=0 when no branch actions exist', $failures, $passes );
smoke_assert( 1, $concurrent_batches(), 'no fan-out: concurrent_batches passes through AS default (1)', $failures, $passes );
smoke_assert( 25, $batch_size(), 'no fan-out: batch_size passes through AS default (25)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 2. Fan-out just enqueued: all 4 branches PENDING, none claimed yet.
// ═════════════════════════════════════════════════════════════════════════════

AS_Query_Shim::reset();
AS_Query_Shim::add( $branch_hook, ActionScheduler_Store::STATUS_PENDING, 4 );
smoke_assert( 4, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count(), 'inflight=4 with 4 pending branches', $failures, $passes );
smoke_assert( 4, $concurrent_batches(), 'all pending: concurrent_batches RAISED to 4', $failures, $passes );
smoke_assert( 1, $batch_size(), 'all pending: batch_size pinned to 1', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 3. THE REGRESSION. Claiming has started — 1 branch is IN-PROGRESS (running a
//    multi-minute AI call), 3 still PENDING. Under the OLD pending-only gate the
//    count here would be 3 (still fine); the killer case is when MORE than one has
//    been claimed. Model the worst case: 3 IN-PROGRESS, only 1 PENDING left.
//
//    Old behavior (pending-only): inflight would read 1 → concurrent_batches
//    collapses toward 1, and with 3 already claimed, has_maximum_concurrent_batches
//    (get_claim_count 3 >= concurrent_batches 1) would BLOCK the 4th branch from
//    being claimed until an in-progress one finishes → serial drain.
//
//    Fixed behavior (pending + in-progress): inflight reads 4 → concurrent_batches
//    stays 4, so with 3 claimed the AS gate (3 < 4) still lets a worker claim the
//    4th while the others run. The ceiling stays OPEN.
// ═════════════════════════════════════════════════════════════════════════════

AS_Query_Shim::reset();
AS_Query_Shim::add( $branch_hook, ActionScheduler_Store::STATUS_RUNNING, 3 );
AS_Query_Shim::add( $branch_hook, ActionScheduler_Store::STATUS_PENDING, 1 );
smoke_assert( 4, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count(), 'inflight=4 counts pending + in-progress (3 running + 1 pending)', $failures, $passes );
smoke_assert( 4, $concurrent_batches(), 'REGRESSION GUARD: ceiling STAYS raised to 4 while 3 branches are in-progress (not collapsed to 1)', $failures, $passes );
smoke_assert( 1, $batch_size(), 'REGRESSION GUARD: batch_size STAYS pinned to 1 while branches are in-progress', $failures, $passes );

// The AS claim gate math the raised ceiling enables: a 4th worker CAN claim while
// 3 are in-progress because get_claim_count (3) < concurrent_batches (4). Assert
// the ceiling exceeds the already-claimed count so AS admits the next worker.
$already_claimed = 3;
smoke_assert( true, $concurrent_batches() > $already_claimed, 'AS gate math: concurrent_batches (4) > get_claim_count (3) → next worker admitted', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 4. All branches claimed, ZERO pending, all IN-PROGRESS. Under the OLD gate this
//    was the collapse point (pending=0 → filters revert). The fix keeps them open.
// ═════════════════════════════════════════════════════════════════════════════

AS_Query_Shim::reset();
AS_Query_Shim::add( $branch_hook, ActionScheduler_Store::STATUS_RUNNING, 4 );
smoke_assert( 4, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count(), 'inflight=4 with ALL branches in-progress, zero pending', $failures, $passes );
smoke_assert( 4, $concurrent_batches(), 'all in-progress: ceiling STAYS raised (old pending-only gate would collapse to 1 here)', $failures, $passes );
smoke_assert( 1, $batch_size(), 'all in-progress: batch_size STAYS pinned to 1 (old gate would revert to 25)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 5. Cap: a pathological in-flight BRANCH count is bounded by MAX_BRANCH_CONCURRENCY
//    (no resume in flight adds no headroom).
// ═════════════════════════════════════════════════════════════════════════════

AS_Query_Shim::reset();
AS_Query_Shim::add( $branch_hook, ActionScheduler_Store::STATUS_PENDING, $max + 5 );
AS_Query_Shim::add( $branch_hook, ActionScheduler_Store::STATUS_RUNNING, $max + 5 );
smoke_assert( $max, $concurrent_batches(), 'cap: branch ceiling never exceeds MAX_BRANCH_CONCURRENCY', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 6. Only-ever-RAISE: a higher incoming ceiling from another plugin is preserved.
// ═════════════════════════════════════════════════════════════════════════════

AS_Query_Shim::reset();
AS_Query_Shim::add( $branch_hook, ActionScheduler_Store::STATUS_RUNNING, 2 );
$raised = (int) apply_filters( 'action_scheduler_queue_runner_concurrent_batches', 6 );
smoke_assert( 6, $raised, 'only-ever-RAISE: a higher incoming ceiling (6) is never lowered to inflight (2)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 7. Terminal branches (complete/failed) are NOT in flight — fan-out is done.
// ═════════════════════════════════════════════════════════════════════════════

AS_Query_Shim::reset();
AS_Query_Shim::add( $branch_hook, ActionScheduler_Store::STATUS_COMPLETE, 4 );
smoke_assert( 0, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count(), 'inflight=0 once every branch is terminal (complete)', $failures, $passes );
smoke_assert( 1, $concurrent_batches(), 'fan-out done: concurrent_batches reverts to AS default (1)', $failures, $passes );
smoke_assert( 25, $batch_size(), 'fan-out done: batch_size reverts to AS default (25)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 8. Scoping: actions on a DIFFERENT hook never open the gate (blast radius).
// ═════════════════════════════════════════════════════════════════════════════

AS_Query_Shim::reset();
AS_Query_Shim::add( 'some_other_unrelated_hook', ActionScheduler_Store::STATUS_RUNNING, 8 );
smoke_assert( 0, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count(), 'inflight=0 — unrelated AS hooks never open the branch gate', $failures, $passes );
smoke_assert( 1, $concurrent_batches(), 'scoping: unrelated AS workload keeps stock concurrent_batches (1)', $failures, $passes );
smoke_assert( 25, $batch_size(), 'scoping: unrelated AS workload keeps stock batch_size (25)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 9. THE RESUME-STARVATION FIX. When every branch has completed, a fan-out ends
//    with ONE resume action that drives the run to terminal. The resume is NOT a
//    branch, so branch_inflight_count() drops to 0 — but resume_inflight_count()
//    keeps the ceiling raised by one so the resume gets a claim slot. Without this,
//    a lone due resume was starved whenever ANY unrelated claim lingered (observed
//    ~51 min behind AS's long-branch reaper window).
// ═════════════════════════════════════════════════════════════════════════════

AS_Query_Shim::reset();
AS_Query_Shim::add( $resume_hook, ActionScheduler_Store::STATUS_PENDING, 1 );
smoke_assert( 0, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count(), 'branches done: branch_inflight=0', $failures, $passes );
smoke_assert( 1, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::resume_inflight_count(), 'resume pending: resume_inflight=1', $failures, $passes );
smoke_assert( 2, $concurrent_batches(), 'RESUME GUARD: ceiling raised to 2 (incoming default 1 + resume slot) so a due resume can win a claim', $failures, $passes );

// The production starvation case, modeled exactly: the 5-page run finished (its
// branches complete), the resume is pending and DUE, but TWO stale in-progress
// branches from an UNRELATED earlier fan-out still hold claims (reaper window).
// get_claim_count() (global) = 2; the OLD ceiling (branch_inflight only) was also 2
// (those two stale in-progress branches), so has_maximum_concurrent_batches
// (2 >= 2) stayed shut and the resume was stranded. With the resume counted, the
// ceiling is 3, so 2 (claimed) < 3 → AS admits the runner and the resume drains.
AS_Query_Shim::reset();
AS_Query_Shim::add( $branch_hook, ActionScheduler_Store::STATUS_RUNNING, 2 ); // stale unrelated branches holding claims
AS_Query_Shim::add( $resume_hook, ActionScheduler_Store::STATUS_PENDING, 1 ); // our due, stranded resume
$stale_claims = 2;
smoke_assert( 3, $concurrent_batches(), 'STARVATION REPRO: ceiling = 2 stale branches + 1 resume slot = 3', $failures, $passes );
smoke_assert( true, $concurrent_batches() > $stale_claims, 'STARVATION FIX: ceiling (3) > global claim count (2) → AS gate opens, resume claimable', $failures, $passes );

// A resume already IN-PROGRESS also counts (its own worker holds the slot); it must
// not collapse the ceiling mid-drain.
AS_Query_Shim::reset();
AS_Query_Shim::add( $resume_hook, ActionScheduler_Store::STATUS_RUNNING, 1 );
smoke_assert( 1, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::resume_inflight_count(), 'resume in-progress also counts as in flight', $failures, $passes );

// Scoping: a resume in flight does NOT pin batch_size (that gate is branch-only —
// the resume is a single quick reconcile action, not one of N parallel branches).
AS_Query_Shim::reset();
AS_Query_Shim::add( $resume_hook, ActionScheduler_Store::STATUS_PENDING, 1 );
smoke_assert( 25, $batch_size(), 'resume-only: batch_size stays at AS default (resume is not a fanned-out branch)', $failures, $passes );

// No resume, no branches → resume slot never opens (blast radius).
AS_Query_Shim::reset();
smoke_assert( 0, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::resume_inflight_count(), 'no fan-out: resume_inflight=0', $failures, $passes );
smoke_assert( 1, $concurrent_batches(), 'no fan-out: no resume slot added, stock ceiling (1)', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
