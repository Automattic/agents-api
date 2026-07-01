<?php
/**
 * Phase 2 wiring for the Action Scheduler branch executor.
 *
 * This is the soft registration that turns the dormant Phase 1 state machine
 * into real concurrency WHEN — and only when — Action Scheduler's async enqueue
 * is present. It does three things, all present-only:
 *
 *   1. Selects the AS executor. The core selector
 *      ({@see register-workflow-step-executor.php}) returns `null` (→ sync) by
 *      default; this hook — at a priority ABOVE that core default but BELOW a
 *      caller override — returns the AS executor when
 *      `as_enqueue_async_action` exists. So: caller override (10+) wins; else
 *      AS (this, priority 6); else null → v0.5.0 sync. An install without AS is
 *      byte-for-byte Phase 1.
 *
 *   2. Registers the per-branch action callback ({@see BRANCH_HOOK}) that
 *      rehydrates a branch from its payload, runs it through the SHARED
 *      `run_branch_steps()`, and drives the REAL reconcile.
 *
 *   3. Registers the resume action callback ({@see RESUME_HOOK}) and the
 *      deferred-resume seam ({@see wp_agent_workflow_resume_dispatch}) so the
 *      "all branches terminal → resume" transition is performed as ONE
 *      atomically-claimed AS action instead of resuming inline. AS's claim is
 *      the cross-process guard that makes resume exactly-once — no lock, no
 *      table.
 *
 * Everything here no-ops cleanly without Action Scheduler; the state machine,
 * interface, and reconcile entry point stay dormant on a no-AS install.
 *
 * @package AgentsAPI
 * @since   0.5.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

// 1. Selector: return the AS executor when AS async enqueue is present. Priority
//    6 runs AFTER the core priority-5 null default (so we can override its null)
//    and BEFORE a caller override at 10+ (which still wins by returning its own
//    executor, respected by the `instanceof` short-circuit here).
add_filter(
	'wp_agent_workflow_step_executor',
	/**
	 * @param mixed                $executor Executor resolved so far.
	 * @param array<string,mixed>  $step     The parallel step being dispatched.
	 * @param array<string,mixed>  $context  Resolution context.
	 * @return WP_Agent_Workflow_Branch_Executor|null
	 */
	static function ( $executor, $step, $context ) {
		unset( $step, $context );

		if ( $executor instanceof WP_Agent_Workflow_Branch_Executor ) {
			return $executor;
		}

		if ( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::is_available() ) {
			return new WP_Agent_Workflow_Action_Scheduler_Branch_Executor();
		}

		return $executor;
	},
	6,
	3
);

// 2. Per-branch action: rehydrate → run via shared run_branch_steps() → reconcile.
add_action(
	WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK,
	/**
	 * @param array<string,mixed> $payload Action payload: { run_id, handle_id, branch }.
	 */
	static function ( $payload = array() ): void {
		WP_Agent_Workflow_Action_Scheduler_Branch_Executor::run_branch_action( is_array( $payload ) ? $payload : array() );
	},
	10,
	1
);

// 3a. Resume action: AS claimed it exactly once → re-check SUSPENDED → resume.
add_action(
	WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK,
	/**
	 * @param array<string,mixed> $payload Action payload: { run_id }.
	 */
	static function ( $payload = array() ): void {
		WP_Agent_Workflow_Action_Scheduler_Branch_Executor::run_resume_action( is_array( $payload ) ? $payload : array() );
	},
	10,
	1
);

// 3b. Deferred-resume seam: enqueue a claimed RESUME action for AS-owned runs
//     instead of resuming inline in the reconcile request.
add_filter(
	'wp_agent_workflow_resume_dispatch',
	/**
	 * @param bool   $deferred    Whether resume is already deferred.
	 * @param string $run_id      The suspended run id.
	 * @param string $executor_id The frame's owning executor id.
	 * @return bool
	 */
	static function ( $deferred, $run_id, $executor_id ) {
		return WP_Agent_Workflow_Action_Scheduler_Branch_Executor::maybe_defer_resume(
			(bool) $deferred,
			is_string( $run_id ) ? $run_id : '',
			is_string( $executor_id ) ? $executor_id : ''
		);
	},
	10,
	3
);
