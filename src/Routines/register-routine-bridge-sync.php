<?php
/**
 * Sync the in-memory routine registry to the resolved routine backend.
 *
 * When `wp_register_routine()` succeeds, the registry fires
 * `wp_agent_routine_registered`. We listen here and ask the resolved
 * backend ({@see WP_Agent_Routine_Registry::backend()}) to (re-)schedule
 * the routine. Every listener no-ops cleanly when no backend is resolved
 * — the routine stays registered but nothing is scheduled.
 *
 * The same listeners handle unregister (cancelling the matching schedule),
 * pause, resume, and run-now requests.
 *
 * The filename keeps its historical `bridge-sync` name to avoid churn; it
 * syncs to whichever backend the `wp_agent_routine_backend` filter
 * resolves, not to any specific scheduler.
 *
 * @package AgentsAPI
 * @since   0.105.0
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_agent_routine_registered',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->register( $routine );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_unregistered',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->unregister( $routine->get_id() );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_paused',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->pause( $routine->get_id() );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_resumed',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->resume( $routine );
	},
	10,
	1
);

add_action(
	'wp_agent_routine_run_now_requested',
	static function ( WP_Agent_Routine $routine ): void {
		WP_Agent_Routine_Registry::backend()?->run_now( $routine );
	},
	10,
	1
);

// The fence hooks listen to Action Scheduler's store lifecycle so fetched
// routine actions are generation-fenced and stale recurrence successors are
// cancelled. This is Action-Scheduler-specific plumbing owned by the
// default backend; the callbacks no-op when Action Scheduler is absent.
WP_Agent_Routine_Action_Scheduler_Bridge::register_generation_fence();

// The substrate-owned periodic authoritative repair: one recurring Action
// Scheduler action (hook `wp_agent_routine_periodic_reconcile`, group
// `agents-api`, hourly) whose callback runs the registry's lock-protected
// reconcile() in apply mode. Plain declarations trust the schedule record,
// so this loop is what keeps a chain lost out of band (pruned store,
// restored backup, an action Action Scheduler stops rescheduling) from
// staying lost: it is repaired within at most one interval of its loss.
add_action(
	WP_Agent_Routine_Action_Scheduler_Bridge::PERIODIC_RECONCILE_HOOK,
	array( WP_Agent_Routine_Action_Scheduler_Bridge::class, 'run_periodic_reconcile' ),
	10,
	0
);

// Declaring the maintenance chain is idempotent and cheap: it fast-paths on
// the chain's own schedule record (zero scheduler queries while the record
// is fresh — every actual run stamps a liveness timestamp into it) and only
// reaches the store when the record is missing, mismatched, or older than
// one interval, i.e. at most once per interval, to revalidate — and if
// needed re-create — the chain.
add_action(
	'init',
	static function (): void {
		WP_Agent_Routine_Action_Scheduler_Bridge::instance()->ensure_periodic_reconcile();
	},
	20
);
