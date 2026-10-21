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
 * This file also owns the hourly drift repair: it declares ONE WordPress
 * cron event (`wp_agent_routines_reconcile`, hourly) on `init`, guarded by
 * `wp_next_scheduled()` so repeat declarations schedule exactly one event.
 * Its callback runs the authoritative
 * {@see WP_Agent_Routine_Registry::reconcile()} (apply mode, the
 * lock-protected path) and reports through
 * `wp_agent_routines_reconcile_completed`. WP-Cron is deliberate: the guard
 * reads the autoloaded `cron` option, so the per-request cost is a single
 * array lookup in an already-loaded option, and the repair lives outside
 * Action Scheduler — a lost Action Scheduler chain cannot also take out the
 * mechanism that restores it.
 *
 * The event is deliberately not unscheduled on a deactivation hook:
 * agents-api ships far more often as a Composer-embedded library inside a
 * consumer plugin than as its own activatable plugin, so there is no
 * deactivation path that would fire for most real installs. A leftover
 * hourly event after removal is inert (the substrate is absent, nothing
 * registers the callback) and self-heals on reinstall.
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

const WP_AGENT_ROUTINES_RECONCILE_HOOK = 'wp_agent_routines_reconcile';

add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		// One event per site, ever: the guard reads the autoloaded `cron`
		// option, so this costs no queries per request once scheduled.
		if ( false !== wp_next_scheduled( WP_AGENT_ROUTINES_RECONCILE_HOOK ) ) {
			return;
		}
		wp_schedule_event( time(), 'hourly', WP_AGENT_ROUTINES_RECONCILE_HOOK );
	},
	10,
	0
);

add_action(
	WP_AGENT_ROUTINES_RECONCILE_HOOK,
	static function (): void {
		$report = WP_Agent_Routine_Registry::reconcile();

		/**
		 * Fires after the scheduled hourly routine reconcile ran. Consumers
		 * can log or alert on the report (enqueued/removed/unchanged/errors
		 * — the same shape {@see WP_Agent_Routine_Registry::reconcile()}
		 * returns).
		 *
		 * @since 0.17.0
		 *
		 * @param array{enqueued:string[],removed:string[],unchanged:string[],errors:array<string,string>} $report The reconcile report.
		 */
		do_action( 'wp_agent_routines_reconcile_completed', $report );
	},
	10,
	0
);

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
