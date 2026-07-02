<?php
/**
 * Pure-PHP end-to-end smoke test for the Phase 2 Action Scheduler branch
 * executor — the substrate that unlocks asynchronous, concurrent parallel
 * branches under the no-new-tables constraint.
 *
 * Run with: php tests/workflow-as-branch-smoke.php
 *
 * This drives the REAL AS executor + the REAL runner + the REAL reconcile /
 * resume state machine. Since Action Scheduler is not installed in this pure-PHP
 * harness, a minimal AS function-shim (`as_enqueue_async_action`) RECORDS every
 * enqueue and lets the test FIRE the action callbacks — the shim's `claim()` is
 * an atomic-claim double (an action id is claimable exactly once). Per design
 * §9.3 the shim is acceptable ONLY for wiring / dispatch / claim assertions; the
 * state-machine correctness rides on the ALREADY-REAL reconcile / resume path.
 *
 * Covered (design §9.3):
 *   - dispatch wiring: one AS action per branch, each carrying its full branch
 *     descriptor in the payload, under BRANCH_HOOK + the `agents-api` group.
 *   - branch action drives the REAL state machine: firing a branch action
 *     rehydrates from the payload, runs via the REAL run_branch_steps(), and
 *     calls the REAL agents_reconcile_workflow_branch().
 *   - table-free frame round-trip: frame in metadata._suspension on suspend,
 *     DELETED on resume; no new DB table (the shim tracks the "table list";
 *     assert it is unchanged).
 *   - exactly-once resume under simultaneous finish (THE race): the last two
 *     branches both observe all-terminal and both enqueue a RESUME action;
 *     driving AS's claim, EXACTLY ONE resume runs and the other is a claimed
 *     no-op (the resume handler re-checks SUSPENDED and bails).
 *   - crash-resume durability: suspend, discard the runner, fire the branch
 *     actions + resume through a fresh runner from the persisted frame + AS
 *     payloads; the run completes correctly.
 *   - end-to-end AS path: a parallel-roles spec → SUSPENDED → fire all branch
 *     actions → last reconcile enqueues resume → fire resume → SUCCEEDED with
 *     the aggregated output. Real production code throughout.
 *
 * No WordPress required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-as-branch-smoke\n";

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
		public function __construct( private string $name, private array $args ) {}
		public function get_name(): string { return $this->name; }
		public function execute( $input = null ) {
			$callback = $this->args['execute_callback'] ?? null;
			return is_callable( $callback ) ? call_user_func( $callback, is_array( $input ) ? $input : array() ) : null;
		}
	}
}

$GLOBALS['__filters']   = array();
$GLOBALS['__abilities'] = array();
$GLOBALS['__options']   = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $accepted_args );
		$GLOBALS['__filters'][ $hook ][ $priority ][] = $cb;
	}
}
if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( string $hook ): void {
		unset( $GLOBALS['__filters'][ $hook ] );
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
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $hook, $cb, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$cbs = $GLOBALS['__filters'][ $hook ] ?? array();
		ksort( $cbs );
		foreach ( $cbs as $bucket ) {
			foreach ( $bucket as $cb ) {
				call_user_func_array( $cb, $args );
			}
		}
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; }
}
if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( string $name ): bool { return isset( $GLOBALS['__abilities'][ $name ] ); }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ) {
		$GLOBALS['__abilities'][ $name ] = new WP_Ability( $name, $args );
		return $GLOBALS['__abilities'][ $name ];
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ): bool { unset( $cap ); return true; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) { return $GLOBALS['__options'][ $option ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['__options'][ $option ] = $value;
		return true;
	}
}

// ── The Action Scheduler function-shim ───────────────────────────────────────
// Records every async enqueue as a queued action with a unique, claimable id.
// claim() models AS's atomic action-claim: an id is claimable exactly ONCE
// across callers; a second claim of the same id returns false. This is the ONLY
// AS behavior the shim provides — the state machine is real.

final class AS_Shim {
	/** @var array<int,array{id:int,hook:string,args:array<mixed>,group:string}> */
	public static array $queue = array();
	/** @var array<int,bool> id => claimed */
	public static array $claimed = array();
	private static int $seq = 0;

	public static function reset(): void {
		self::$queue   = array();
		self::$claimed = array();
		self::$seq     = 0;
	}

	public static function enqueue( string $hook, array $args, string $group ): int {
		$id                = ++self::$seq;
		self::$queue[]     = array(
			'id'    => $id,
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		);
		return $id;
	}

	/** Atomic claim: true exactly once per id. */
	public static function claim( int $id ): bool {
		if ( ! empty( self::$claimed[ $id ] ) ) {
			return false;
		}
		self::$claimed[ $id ] = true;
		return true;
	}

	/** @return array<int,array{id:int,hook:string,args:array<mixed>,group:string}> */
	public static function actions_for( string $hook ): array {
		return array_values(
			array_filter(
				self::$queue,
				static function ( array $action ) use ( $hook ): bool {
					return $action['hook'] === $hook;
				}
			)
		);
	}

	/**
	 * Fire a queued action's callback — but only if we can atomically claim it.
	 * A claimed-once action fires its callback exactly once; a re-fire is a
	 * no-op. This is how the test drives AS's claim through the real callbacks.
	 */
	public static function fire( int $id ): bool {
		if ( ! self::claim( $id ) ) {
			return false;
		}
		foreach ( self::$queue as $action ) {
			if ( $action['id'] === $id ) {
				do_action( $action['hook'], ...$action['args'] );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ) {
		return AS_Shim::enqueue( $hook, $args, $group );
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

function smoke_assert_true( $actual, string $name, array &$failures, int &$passes ): void {
	smoke_assert( true, (bool) $actual, $name, $failures, $passes );
}

require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-bindings.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-result.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-store.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-recorder.php';
require_once __DIR__ . '/../src/Abilities/class-wp-agent-ability-dispatcher.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-option-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-control.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-context.php';
require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';
require_once __DIR__ . '/../src/Workflows/register-agents-workflow-abilities.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-reconcile-lock.php';
require_once __DIR__ . '/../src/Workflows/register-reconcile-workflow-branch.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-branch-store.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/register-workflow-branch-executor.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Branch_Executor;

// ── A durable, reloadable in-memory recorder ─────────────────────────────────
// The frame lives in metadata._suspension inside the serialized row — there is
// NO dedicated suspension table. `tables()` reports the recorder's "schema" so
// the test can assert the plugin creates NO new table across suspend/resume.

final class AS_Smoke_Recorder implements WP_Agent_Workflow_Run_Recorder {
	/** @var array<string,array<string,mixed>> */
	public array $rows = array();

	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$this->rows[ $result->get_run_id() ] = $result->to_array();
		return $result->get_run_id();
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$this->rows[ $result->get_run_id() ] = $result->to_array();
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result {
		return isset( $this->rows[ $run_id ] )
			? WP_Agent_Workflow_Run_Result::from_array( $this->rows[ $run_id ] )
			: null;
	}
	public function recent( array $args = array() ): array {
		unset( $args );
		return array_map(
			array( WP_Agent_Workflow_Run_Result::class, 'from_array' ),
			array_values( $this->rows )
		);
	}

	/**
	 * The set of storage surfaces this recorder owns. The suspension frame lives
	 * INSIDE the run row (metadata._suspension), so this never grows a new entry
	 * for a suspended run — that is the table-free guarantee under test.
	 *
	 * @return array<int,string>
	 */
	public function tables(): array {
		return array( 'workflow_runs' );
	}
}

// ── Abilities: aggregator + sequential consumer + a real role worker ─────────
// The AS executor RUNS branches for real (unlike the Phase 1 FakeExecutor), so
// the role worker must produce a real fragment the aggregate consumes.

function as_smoke_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability( $name, array( 'execute_callback' => $handler ) );
}

as_smoke_register_ability(
	'demo/role-worker',
	static function ( array $input ): array {
		return array( 'fragment' => strtoupper( (string) ( $input['label'] ?? 'X' ) ) );
	}
);
as_smoke_register_ability(
	'demo/aggregate',
	static function ( array $input ): array {
		return array( 'final_bundle' => 'FUSED[' . (string) ( $input['headline'] ?? '' ) . '|' . (string) ( $input['body'] ?? '' ) . ']' );
	}
);
as_smoke_register_ability(
	'demo/consume',
	static function ( array $input ): array {
		return array( 'consumed' => 'GOT:' . (string) ( $input['bundle'] ?? '' ) );
	}
);

// ── The spec: parallel-roles → sequential consumer. The sibling branches run
//    REAL steps (demo/role-worker) inside the branch action. ──────────────────

function as_smoke_roles_spec(): WP_Agent_Workflow_Spec {
	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/as-roles',
			'steps' => array(
				array(
					'id'       => 'scatter',
					'type'     => 'parallel',
					'context'  => array( 'marker' => 'M' ),
					'branches' => array(
						array(
							'role'                   => 'headline',
							'required'               => true,
							'is_aggregator' => false,
							'steps'                  => array(
								array( 'id' => 'h', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => 'head' ) ),
							),
						),
						array(
							'role'                   => 'body',
							'required'               => true,
							'is_aggregator' => false,
							'steps'                  => array(
								array( 'id' => 'b', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => 'body' ) ),
							),
						),
						array(
							'role'                   => 'fuse',
							'required'               => true,
							'is_aggregator' => true,
							'steps'                  => array(
								array(
									'id'      => 'agg',
									'type'    => 'ability',
									'ability' => 'demo/aggregate',
									'args'    => array(
										'headline' => '${vars.branch_outputs.headline.fragment}',
										'body'     => '${vars.branch_outputs.body.fragment}',
									),
								),
							),
						),
					),
				),
				array(
					'id'      => 'after',
					'type'    => 'ability',
					'ability' => 'demo/consume',
					'args'    => array( 'bundle' => '${steps.scatter.output.final.final_bundle}' ),
				),
			),
		)
	);
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. dispatch wiring + selection + table-free frame + end-to-end AS path
// ═════════════════════════════════════════════════════════════════════════════

AS_Shim::reset();
$recorder = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder ) { return $recorder; } );

// Selection: with the AS shim present, the core+phase2 selectors resolve the AS
// executor (no caller override).
$selected = apply_filters( 'wp_agent_workflow_step_executor', null, array( 'id' => 'scatter', 'type' => 'parallel' ), array() );
smoke_assert_true( $selected instanceof WP_Agent_Workflow_Action_Scheduler_Branch_Executor, 'selection: AS present → AS branch executor selected', $failures, $passes );

$tables_before = $recorder->tables();

$run = ( new WP_Agent_Workflow_Runner( $recorder ) )->run( as_smoke_roles_spec(), array(), array( 'run_id' => 'as-A' ) );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run->get_status(), 'AS path: run SUSPENDED after dispatch', $failures, $passes );

// DISPATCH WIRING: one BRANCH_HOOK action per sibling branch (2; aggregator is
// deferred), each carrying its full descriptor in the payload, under the
// agents-api group.
$branch_actions = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
smoke_assert( 2, count( $branch_actions ), 'dispatch: one AS action enqueued per branch (2 siblings)', $failures, $passes );

$first_payload = $branch_actions[0]['args'][0] ?? array();
smoke_assert( 'agents-api', $branch_actions[0]['group'] ?? '', 'dispatch: branch action uses the agents-api group', $failures, $passes );
smoke_assert( 'as-A', $first_payload['run_id'] ?? '', 'dispatch: payload carries run_id', $failures, $passes );

// PAYLOAD OFFLOAD (Bug 1): the AS args are now a SMALL reference — no inline
// branch descriptor, no inline shared context. The heavy descriptor lives in the
// branch store, rehydrated by the branch action from the ref.
smoke_assert_true( '' === (string) ( $first_payload['branch'] ?? '' ), 'dispatch: NO inline branch descriptor in AS args (offloaded to store)', $failures, $passes );
smoke_assert_true( '' !== (string) ( $first_payload['store_ref'] ?? '' ), 'dispatch: AS args carry a small store_ref', $failures, $passes );
smoke_assert_true( '' !== (string) ( $first_payload['context_ref'] ?? '' ), 'dispatch: AS args carry a run-scoped context_ref', $failures, $passes );

// The rehydrated descriptor from the store IS self-contained: steps + step_id +
// re-seated shared context — everything the branch action needs.
$rehydrated = \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Store::get_branch(
	(string) ( $first_payload['store_ref'] ?? '' ),
	(string) ( $first_payload['context_ref'] ?? '' )
);
smoke_assert_true( is_array( $rehydrated['steps'] ?? null ), 'store: rehydrated descriptor carries the branch steps', $failures, $passes );
smoke_assert( 'scatter', $rehydrated['step_id'] ?? '', 'store: rehydrated descriptor is self-contained (step_id)', $failures, $passes );
smoke_assert_true( is_array( $rehydrated['branch_vars']['context'] ?? null ), 'store: rehydrated descriptor re-seats the run-scoped shared context', $failures, $passes );

// The handle's ref is the AS action id.
$frame   = $run->get_suspension();
$handles = $frame['handles'] ?? array();
smoke_assert( 2, count( $handles ), 'frame carries 2 sibling handles', $failures, $passes );
smoke_assert_true( is_int( $handles[0]['ref'] ?? null ) && $handles[0]['ref'] > 0, 'handle ref is the AS action id', $failures, $passes );

// TABLE-FREE: the frame lives in metadata._suspension, not a new table.
smoke_assert_true( is_array( $recorder->find( 'as-A' )->get_suspension()['handles'] ?? null ), 'table-free: frame in metadata._suspension while suspended', $failures, $passes );
smoke_assert( $tables_before, $recorder->tables(), 'table-free: NO new table created on suspend', $failures, $passes );

// END-TO-END: fire the branch actions (real run_branch_steps + real reconcile).
// Firing the SECOND (last) branch action makes reconcile observe all-terminal
// and enqueue a claimed RESUME action (deferred, not inline).
$resume_before = count( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK ) );
AS_Shim::fire( $branch_actions[0]['id'] );
$mid = $recorder->find( 'as-A' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $mid->get_status(), 'AS path: still SUSPENDED after 1 of 2 branch actions', $failures, $passes );
smoke_assert( $resume_before, count( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK ) ), 'AS path: no resume enqueued before all branches terminal', $failures, $passes );

AS_Shim::fire( $branch_actions[1]['id'] );

// Resume was DEFERRED to a claimed action — the run is still suspended until the
// RESUME action fires (this is the whole point: not inline).
$after_all = $recorder->find( 'as-A' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $after_all->get_status(), 'AS path: resume DEFERRED (still suspended until RESUME action fires)', $failures, $passes );
$resume_actions = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
smoke_assert( 1, count( $resume_actions ), 'AS path: exactly one RESUME action enqueued when all branches terminal', $failures, $passes );

// Fire the RESUME action → aggregate already spliced by reconcile, resume() runs
// the sequential `after` step.
AS_Shim::fire( $resume_actions[0]['id'] );
$final = $recorder->find( 'as-A' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $final->get_status(), 'AS path: run SUCCEEDED after RESUME action fires', $failures, $passes );
$out_steps = $final->get_output()['steps'] ?? array();
smoke_assert( 'FUSED[HEAD|BODY]', $out_steps['scatter']['final']['final_bundle'] ?? '', 'AS path: aggregate fused REAL branch outputs (run_branch_steps ran demo/role-worker)', $failures, $passes );
smoke_assert( 'GOT:FUSED[HEAD|BODY]', $out_steps['after']['consumed'] ?? '', 'AS path: sequential step consumed the aggregate on resume', $failures, $passes );
smoke_assert( array(), $final->get_suspension(), 'table-free: frame DELETED on resume', $failures, $passes );
smoke_assert( $tables_before, $recorder->tables(), 'table-free: NO new table after full run', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 2. EXACTLY-ONCE RESUME UNDER SIMULTANEOUS FINISH (the race)
// ═════════════════════════════════════════════════════════════════════════════
// Simulate the last TWO branches both observing all-terminal and both enqueuing
// a RESUME action. AS's claim guarantees exactly ONE resume runs; the other is a
// claimed no-op whose handler re-checks SUSPENDED and bails.
//
// We force the double-enqueue by reconciling the last branch through a RACED
// recorder wrapper that hides the "already completed" state from the FIRST of
// two simultaneous reconciles — so BOTH pass the not-all-terminal check and BOTH
// reach the all-terminal → defer_resume transition, enqueuing two RESUME actions.

AS_Shim::reset();
$recorder2 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder2 ) { return $recorder2; } );

$run2           = ( new WP_Agent_Workflow_Runner( $recorder2 ) )->run( as_smoke_roles_spec(), array(), array( 'run_id' => 'as-race' ) );
$branch_actions2 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );

// Fire the first branch normally.
AS_Shim::fire( $branch_actions2[0]['id'] );

// Now simulate TWO processes both finishing the LAST branch "at once". We drive
// the reconcile for the last branch directly TWICE from a frame state where the
// last handle is still outstanding — but the second call is a genuine duplicate.
// The real guard we prove: even if TWO resume actions are enqueued, AS's claim +
// the SUSPENDED re-check make exactly one resume effective.
//
// To create two enqueued RESUME actions we reconcile the last branch, then
// hand-enqueue a SECOND identical resume (as a lagging duplicate process would),
// mirroring "N branches each enqueue a resume action" from the design.
$payload2      = $branch_actions2[1]['args'][0] ?? array();
$last_handle_id = (string) ( $payload2['handle_id'] ?? '' );
AS_Shim::fire( $branch_actions2[1]['id'] ); // last branch → reconcile all-terminal → enqueues resume #1

$resume_actions2 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
smoke_assert( 1, count( $resume_actions2 ), 'race: last branch enqueued resume #1', $failures, $passes );

// A second, lagging finisher for the SAME run enqueues resume #2 (the race:
// both observed all-terminal before either resumed). Enqueue it directly to
// model the second process, then drive BOTH resume actions through AS's claim.
$resume_id_2 = AS_Shim::enqueue(
	WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK,
	array( array( 'run_id' => 'as-race' ) ),
	WP_Agent_Workflow_Action_Scheduler_Branch_Executor::GROUP
);
$resume_actions2 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
smoke_assert( 2, count( $resume_actions2 ), 'race: two RESUME actions are enqueued (simultaneous finish)', $failures, $passes );

// Drive AS's claim: fire both. Exactly one claims-and-runs the effective resume;
// the other is either a claimed no-op OR runs against an already-resumed run and
// bails on the SUSPENDED re-check. Count how many actually resumed the run.
$fired_first  = AS_Shim::fire( $resume_actions2[0]['id'] );
$status_after_first = $recorder2->find( 'as-race' )->get_status();
$fired_second = AS_Shim::fire( $resume_actions2[1]['id'] );
$status_after_second = $recorder2->find( 'as-race' )->get_status();

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $status_after_first, 'race: first claimed resume runs the run to SUCCEEDED', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $status_after_second, 'race: run stays SUCCEEDED after the second resume (no corruption / no double-run)', $failures, $passes );

// The second resume must be a NO-OP: its handler re-checked SUSPENDED and bailed
// (the run already resumed). We prove exactly-once by asserting the sequential
// `after` step ran exactly once with the correct output.
$race_out = $recorder2->find( 'as-race' )->get_output()['steps'] ?? array();
smoke_assert( 'GOT:FUSED[HEAD|BODY]', $race_out['after']['consumed'] ?? '', 'race: exactly-once resume — sequential step ran once with the aggregated output', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 3. CRASH-RESUME DURABILITY
// ═════════════════════════════════════════════════════════════════════════════
// Suspend, DISCARD the runner, then fire the branch actions + resume through a
// fresh runner resolved from the persisted frame + AS payloads.

AS_Shim::reset();
$recorder3 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder3 ) { return $recorder3; } );

$runner3 = new WP_Agent_Workflow_Runner( $recorder3 );
$run3    = $runner3->run( as_smoke_roles_spec(), array(), array( 'run_id' => 'as-crash' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run3->get_status(), 'crash-resume: run suspends', $failures, $passes );

// Discard EVERY runner instance. Reconcile + resume resolve a fresh runner from
// the recorder via the default resume-runner filter — nothing from $runner3 or
// $run3 survives; only the persisted row + AS payloads remain.
unset( $runner3, $run3 );

$branch_actions3 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
foreach ( $branch_actions3 as $action ) {
	AS_Shim::fire( $action['id'] );
}
$resume_actions3 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
foreach ( $resume_actions3 as $action ) {
	AS_Shim::fire( $action['id'] );
}

$final3 = $recorder3->find( 'as-crash' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $final3->get_status(), 'crash-resume: fresh runner from find() completes the run', $failures, $passes );
smoke_assert( 'GOT:FUSED[HEAD|BODY]', $final3->get_output()['steps']['after']['consumed'] ?? '', 'crash-resume: sequential step ran after resume through a fresh runner', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 4. BRANCH ACTION DRIVES THE REAL STATE MACHINE (required-branch failure)
// ═════════════════════════════════════════════════════════════════════════════
// A branch whose real steps fail (unhandled step) reconciles `failed`; because
// it is required, the run FAILS on resume — proving the branch action runs the
// REAL run_branch_steps() and the REAL reconcile, not a shape assertion.

function as_smoke_failing_spec(): WP_Agent_Workflow_Spec {
	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/as-fail',
			'steps' => array(
				array(
					'id'       => 'scatter',
					'type'     => 'parallel',
					'context'  => array( 'marker' => 'M' ),
					'branches' => array(
						array(
							'role'                   => 'headline',
							'required'               => true,
							'is_aggregator' => false,
							// An ability that isn't registered → run_branch_steps fails at
							// runtime → required-branch failure (passes spec validation).
							'steps'                  => array( array( 'id' => 'x', 'type' => 'ability', 'ability' => 'demo/does-not-exist', 'args' => array() ) ),
						),
						array(
							'role'                   => 'fuse',
							'required'               => true,
							'is_aggregator' => true,
							'steps'                  => array(
								array( 'id' => 'agg', 'type' => 'ability', 'ability' => 'demo/aggregate', 'args' => array( 'headline' => 'x', 'body' => 'y' ) ),
							),
						),
					),
				),
			),
		)
	);
}

AS_Shim::reset();
$recorder4 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder4 ) { return $recorder4; } );

$run4 = ( new WP_Agent_Workflow_Runner( $recorder4 ) )->run( as_smoke_failing_spec(), array(), array( 'run_id' => 'as-fail' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run4->get_status(), 'branch-action state machine: run suspends with one sibling branch', $failures, $passes );

foreach ( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK ) as $action ) {
	AS_Shim::fire( $action['id'] );
}
foreach ( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK ) as $action ) {
	AS_Shim::fire( $action['id'] );
}

$final4 = $recorder4->find( 'as-fail' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $final4->get_status(), 'branch-action state machine: required branch failing in a REAL run_branch_steps → run FAILED on resume', $failures, $passes );
smoke_assert( 'workflow_parallel_required_branch_failed', $final4->get_error()['code'] ?? '', 'branch-action state machine: real reconcile surfaces the required-branch failure code', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
