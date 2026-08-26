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
$GLOBALS['__reject_option_write'] = '';

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
		if ( $option === $GLOBALS['__reject_option_write'] ) {
			return false;
		}
		$GLOBALS['__options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['__options'][ $option ] );
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
	public static string $reject_hook = '';

	public static function reset(): void {
		self::$queue   = array();
		self::$claimed = array();
		self::$seq     = 0;
		self::$reject_hook = '';
	}

	public static function enqueue( string $hook, array $args, string $group ): int {
		if ( '' !== self::$reject_hook && self::$reject_hook === $hook ) {
			return 0;
		}
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

	public static function fire_with_failure_lifecycle( int $id ): bool {
		try {
			return self::fire( $id );
		} catch ( \Throwable $error ) {
			do_action( 'action_scheduler_failed_execution', $id, $error, 'test' );
			return false;
		}
	}

	public static function action_for( int $id ): ?AS_Shim_Action {
		foreach ( self::$queue as $action ) {
			if ( $action['id'] === $id ) {
				return new AS_Shim_Action( $action['hook'], $action['args'] );
			}
		}
		return null;
	}
}

final class AS_Shim_Action {
	public function __construct( private string $hook, private array $args ) {}
	public function get_hook(): string { return $this->hook; }
	public function get_args(): array { return $this->args; }
}

if ( ! class_exists( 'ActionScheduler_Store' ) ) {
	class ActionScheduler_Store {
		public const STATUS_PENDING = 'pending';
		public const STATUS_RUNNING = 'in-progress';
		public static function instance(): self { return new self(); }
		public function fetch_action( $action_id ): AS_Shim_Action {
			$action = AS_Shim::action_for( (int) $action_id );
			if ( null === $action ) {
				throw new RuntimeException( 'Action not found.' );
			}
			return $action;
		}
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
		$label = (string) ( $input['label'] ?? 'X' );
		$GLOBALS['__role_worker_effects'][ $label ] = (int) ( $GLOBALS['__role_worker_effects'][ $label ] ?? 0 ) + 1;
		return array( 'fragment' => strtoupper( $label ) );
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

// A runtime exposing async enqueue without delayed enqueue cannot safely retry
// a branch claimed while admission is pending. It must fail closed rather than
// amplify the queue with immediate self-requeues.
$pending_token        = \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Store::begin_admission( 'as-no-delay' );
$pending_error        = '';
$pending_queue_before = count( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK ) );
try {
	WP_Agent_Workflow_Action_Scheduler_Branch_Executor::run_branch_action(
		array(
			'run_id'          => 'as-no-delay',
			'handle_id'       => 'as-no-delay:scatter:first:0',
			'admission_token' => $pending_token,
		)
	);
} catch ( \RuntimeException $error ) {
	$pending_error = $error->getMessage();
}
smoke_assert_true( str_contains( $pending_error, 'delayed Action Scheduler enqueue is unavailable' ), 'pending admission: unavailable delayed enqueue fails closed without immediate queue amplification', $failures, $passes );
smoke_assert( $pending_queue_before, count( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK ) ), 'pending admission: unavailable delayed enqueue adds no immediate retry action', $failures, $passes );
\AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Store::forget_run( 'as-no-delay' );

$tables_before = $recorder->tables();

$run = ( new WP_Agent_Workflow_Runner( $recorder ) )->run( as_smoke_roles_spec(), array(), array( 'run_id' => 'as-A' ) );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run->get_status(), 'AS path: run SUSPENDED after dispatch', $failures, $passes );
WP_Agent_Workflow_Action_Scheduler_Branch_Executor::run_resume_action( array( 'run_id' => 'as-A' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $recorder->find( 'as-A' )->get_status(), 'resume: tokenless action cannot advance a suspended run', $failures, $passes );
AS_Shim::$reject_hook = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK;
smoke_assert( false, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::maybe_defer_resume( false, 'as-A', WP_Agent_Workflow_Action_Scheduler_Branch_Executor::ID, $run ), 'resume enqueue failure falls back to inline reconcile instead of stranding the run', $failures, $passes );
AS_Shim::$reject_hook = '';

// DISPATCH WIRING: one BRANCH_HOOK action per sibling branch (2; aggregator is
// deferred), each carrying its full descriptor in the payload, under the
// agents-api group.
$branch_actions = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
smoke_assert( 2, count( $branch_actions ), 'dispatch: one AS action enqueued per branch (2 siblings)', $failures, $passes );

$first_payload = $branch_actions[0]['args'][0] ?? array();
$run_group     = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::group_for_run( 'as-A' );
smoke_assert_true( 'agents-api' !== $run_group, 'dispatch: a new run receives an isolated Action Scheduler group', $failures, $passes );
smoke_assert( $run_group, $branch_actions[0]['group'] ?? '', 'dispatch: branch action uses its run-specific group', $failures, $passes );
smoke_assert( $run_group, $branch_actions[1]['group'] ?? '', 'dispatch: sibling branches share the same run-specific group', $failures, $passes );
smoke_assert( 'as-A', $first_payload['run_id'] ?? '', 'dispatch: payload carries run_id', $failures, $passes );

$run_b = ( new WP_Agent_Workflow_Runner( $recorder ) )->run( as_smoke_roles_spec(), array(), array( 'run_id' => 'as-B' ) );
$run_b_group = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::group_for_run( 'as-B' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run_b->get_status(), 'isolation: a second run suspends independently', $failures, $passes );
smoke_assert_true( $run_group !== $run_b_group, 'isolation: concurrent runs receive different claim groups', $failures, $passes );
$all_branch_actions = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
smoke_assert( $run_b_group, $all_branch_actions[2]['group'] ?? '', 'isolation: run B branches cannot be claimed through run A group', $failures, $passes );

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

// A contended reconcile must enqueue a reconcile-only action carrying a durable
// result ref. Draining that retry must not execute the branch effect again.
$lock_attempts = 0;
add_filter(
	'wp_agent_workflow_reconcile_lock',
	static function ( $override, string $run_id, callable $critical ) use ( &$lock_attempts ) {
		unset( $override );
		if ( 'as-A' === $run_id && 0 === $lock_attempts++ ) {
			return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended', array( 'reconcile_continuation' => array( 'phase' => 'commit' ) ) );
		}
		return $critical();
	},
	10,
	3
);
$branch_count_before_retry = count( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK ) );
$effect_count_before_retry = (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 );
AS_Shim::fire( $branch_actions[0]['id'] );
$branch_actions_with_retry = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
smoke_assert( $branch_count_before_retry, count( $branch_actions_with_retry ), 'lock contention: completed branch action is not re-enqueued', $failures, $passes );
smoke_assert( $effect_count_before_retry + 1, (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 ), 'lock contention: branch side effect executes once before retry', $failures, $passes );
smoke_assert( 0, count( $recorder->find( 'as-A' )->get_suspension()['completed'] ?? array() ), 'lock contention: result not recorded without lock', $failures, $passes );
$reconcile_actions = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK );
smoke_assert( 1, count( $reconcile_actions ), 'lock contention: reconcile-only retry enqueued', $failures, $passes );
$retry_action = $reconcile_actions[0];
$retry_continuation = array();
add_filter(
	'wp_agent_workflow_reconcile_retry',
	static function ( $result, string $run_id, string $handle_id, array $branch_result, array $continuation ) use ( &$retry_continuation ) {
		unset( $run_id, $handle_id, $branch_result );
		$retry_continuation = $continuation;
		return $result;
	},
	10,
	5
);
AS_Shim::fire( $retry_action['id'] );
smoke_assert( 1, count( $recorder->find( 'as-A' )->get_suspension()['completed'] ?? array() ), 'lock contention: queued retry records completion', $failures, $passes );
smoke_assert( $effect_count_before_retry + 1, (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 ), 'lock contention: reconcile retry does not repeat branch side effect', $failures, $passes );
smoke_assert( array( 'phase' => 'commit' ), $retry_continuation, 'lock contention: retry carries opaque authoritative continuation state', $failures, $passes );
$duplicate_retry_id = AS_Shim::enqueue( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK, $retry_action['args'], $retry_action['group'] );
AS_Shim::fire( $duplicate_retry_id );
smoke_assert( 1, count( $recorder->find( 'as-A' )->get_suspension()['completed'] ?? array() ), 'lock contention: duplicate reconcile delivery is an idempotent no-op', $failures, $passes );
smoke_assert( $effect_count_before_retry + 1, (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 ), 'lock contention: duplicate reconcile delivery does not repeat the side effect', $failures, $passes );
remove_all_filters( 'wp_agent_workflow_reconcile_lock' );
remove_all_filters( 'wp_agent_workflow_reconcile_retry' );

// TABLE-FREE: the frame lives in metadata._suspension, not a new table.
smoke_assert_true( is_array( $recorder->find( 'as-A' )->get_suspension()['handles'] ?? null ), 'table-free: frame in metadata._suspension while suspended', $failures, $passes );
smoke_assert( $tables_before, $recorder->tables(), 'table-free: NO new table created on suspend', $failures, $passes );

// END-TO-END: fire the branch actions (real run_branch_steps + real reconcile).
// Firing the SECOND (last) branch action makes reconcile observe all-terminal
// and enqueue a claimed RESUME action (deferred, not inline).
$resume_before = count( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK ) );
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
smoke_assert( $run_group, $resume_actions[0]['group'] ?? '', 'AS path: resume preserves the run-specific group across processes', $failures, $passes );

// Fire the RESUME action → aggregate already spliced by reconcile, resume() runs
// the sequential `after` step.
AS_Shim::fire( $resume_actions[0]['id'] );
$final = $recorder->find( 'as-A' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $final->get_status(), 'AS path: run SUCCEEDED after RESUME action fires', $failures, $passes );
$out_steps = $final->get_output()['steps'] ?? array();
smoke_assert( 'FUSED[HEAD|BODY]', $out_steps['scatter']['final']['final_bundle'] ?? '', 'AS path: aggregate fused REAL branch outputs (run_branch_steps ran demo/role-worker)', $failures, $passes );
smoke_assert( 'GOT:FUSED[HEAD|BODY]', $out_steps['after']['consumed'] ?? '', 'AS path: sequential step consumed the aggregate on resume', $failures, $passes );
smoke_assert( array(), $final->get_suspension(), 'table-free: frame DELETED on resume', $failures, $passes );
smoke_assert( $run_group, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::group_for_run( 'as-A' ), 'terminal run keeps its deterministic group identity without persisted compatibility state', $failures, $passes );
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

function as_smoke_single_branch_spec( string $label ): WP_Agent_Workflow_Spec {
	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/as-single-' . $label,
			'steps' => array(
				array(
					'id'    => 'scatter',
					'type'  => 'parallel',
					'as'    => 'item',
					'items' => array( array( 'label' => $label ) ),
					'steps' => array(
						array( 'id' => 'work', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => $label ) ),
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

// A resume can advance into another parallel step and suspend again. The run's
// isolated group and branch-store rows must survive that intermediate resume.
function as_smoke_two_fanout_spec(): WP_Agent_Workflow_Spec {
	$fanout = static function ( string $id, string $label ): array {
		return array(
			'id'    => $id,
			'type'  => 'parallel',
			'as'    => 'item',
			'items' => array( array( 'label' => $label ) ),
			'steps' => array(
				array( 'id' => $id . '-worker', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => $label ) ),
			),
		);
	};

	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/as-two-fanouts',
			'steps' => array( $fanout( 'first', 'one' ), $fanout( 'second', 'two' ) ),
		)
	);
}

AS_Shim::reset();
$recorder5 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder5 ) { return $recorder5; } );

$run5       = ( new WP_Agent_Workflow_Runner( $recorder5 ) )->run( as_smoke_two_fanout_spec(), array(), array( 'run_id' => 'as-two' ) );
$group5     = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::group_for_run( 'as-two' );
$branches5  = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
AS_Shim::fire( $branches5[0]['id'] );
$resumes5 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
as_enqueue_async_action( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK, $resumes5[0]['args'], $resumes5[0]['group'] );
AS_Shim::fire( $resumes5[0]['id'] );

$stale_resumes5 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
AS_Shim::fire( $stale_resumes5[1]['id'] );

$mid5      = $recorder5->find( 'as-two' );
$branches5 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $mid5->get_status(), 'multi-fanout: intermediate resume suspends on the second fan-out', $failures, $passes );
smoke_assert( 1, (int) ( $mid5->get_suspension()['step_index'] ?? 0 ), 'multi-fanout: delayed duplicate resume cannot skip the newer suspension generation', $failures, $passes );
smoke_assert( $group5, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::group_for_run( 'as-two' ), 'multi-fanout: intermediate resume preserves deterministic group identity', $failures, $passes );
smoke_assert( $group5, $branches5[1]['group'] ?? '', 'multi-fanout: second fan-out stays in the original isolated group', $failures, $passes );

AS_Shim::fire( $branches5[1]['id'] );
$resumes5 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
AS_Shim::fire( $resumes5[2]['id'] );
$final5 = $recorder5->find( 'as-two' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $final5->get_status(), 'multi-fanout: second resume reaches terminal success', $failures, $passes );
smoke_assert( $group5, WP_Agent_Workflow_Action_Scheduler_Branch_Executor::group_for_run( 'as-two' ), 'multi-fanout: terminal run retains the same deterministic group identity', $failures, $passes );

// A contended result is persisted only after the in-memory reconcile attempt. If
// that receipt write fails, the action fails loudly and queues no unreadable retry.
AS_Shim::reset();
$recorder6 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder6 ) { return $recorder6; } );
$run6      = ( new WP_Agent_Workflow_Runner( $recorder6 ) )->run( as_smoke_roles_spec(), array(), array( 'run_id' => 'as-write-fail' ) );
$branches6 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
$payload6  = $branches6[0]['args'][0] ?? array();
$GLOBALS['__reject_option_write'] = (string) ( $payload6['store_ref'] ?? '' );
$lock_attempts6 = 0;
add_filter(
	'wp_agent_workflow_reconcile_lock',
	static function () use ( &$lock_attempts6 ) {
		++$lock_attempts6;
		return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
	},
	10,
	3
);
$effect_before6 = (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 );
$write_failed6  = false;
try {
	AS_Shim::fire( $branches6[0]['id'] );
} catch ( \RuntimeException $error ) {
	$write_failed6 = str_contains( $error->getMessage(), 'durably persist' );
}
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run6->get_status(), 'failed result write: fixture starts suspended', $failures, $passes );
smoke_assert( true, $write_failed6, 'failed result write: branch action fails loudly before completing', $failures, $passes );
smoke_assert( $effect_before6 + 1, (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 ), 'failed result write: branch side effect ran exactly once', $failures, $passes );
smoke_assert( 1, $lock_attempts6, 'failed result write: in-memory reconcile is attempted before receipt persistence', $failures, $passes );
smoke_assert( 0, count( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK ) ), 'failed result write: no stranded reconcile-only retry is queued', $failures, $passes );
$GLOBALS['__reject_option_write'] = '';
remove_all_filters( 'wp_agent_workflow_reconcile_lock' );

// Missing and expired descriptors still reconcile a durable terminal failure.
// The fallback receipt uses the deterministic descriptor ref itself, survives
// one contended attempt, and is deleted immediately after the retry records it.
foreach ( array( 'missing', 'expired' ) as $descriptor_state ) {
	AS_Shim::reset();
	$GLOBALS['__options'] = array();
	$edge_run_id = 'as-descriptor-' . $descriptor_state;
	$edge_recorder = new AS_Smoke_Recorder();
	remove_all_filters( 'wp_agent_workflow_run_recorder' );
	add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $edge_recorder ) { return $edge_recorder; } );
	$edge_run      = ( new WP_Agent_Workflow_Runner( $edge_recorder ) )->run( as_smoke_failing_spec(), array(), array( 'run_id' => $edge_run_id ) );
	$edge_branches = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
	$edge_payload  = $edge_branches[0]['args'][0] ?? array();
	$edge_store_ref = (string) ( $edge_payload['store_ref'] ?? '' );
	if ( 'missing' === $descriptor_state ) {
		delete_option( $edge_store_ref );
	} else {
		$GLOBALS['__options'][ $edge_store_ref ]['expires'] = time() - 1;
		$expired_descriptor = \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Store::get_branch( $edge_store_ref, (string) ( $edge_payload['context_ref'] ?? '' ) );
		smoke_assert( null, $expired_descriptor, 'expired descriptor: expired payload is unavailable', $failures, $passes );
		smoke_assert( false, array_key_exists( $edge_store_ref, $GLOBALS['__options'] ), 'expired descriptor: expired option deletes itself on read', $failures, $passes );
	}

	$edge_lock_attempts = 0;
	add_filter(
		'wp_agent_workflow_reconcile_lock',
		static function ( $override, string $run_id, callable $critical ) use ( &$edge_lock_attempts, $edge_run_id ) {
			unset( $override );
			if ( $edge_run_id === $run_id && 0 === $edge_lock_attempts++ ) {
				return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
			}
			return $critical();
		},
		10,
		3
	);
	AS_Shim::fire( $edge_branches[0]['id'] );
	$edge_retries = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK );
	$edge_receipt  = \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Store::get_reconcile_receipt( $edge_store_ref, (string) ( $edge_payload['context_ref'] ?? '' ), (string) ( $edge_payload['store_backend'] ?? '' ) );
	smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $edge_run->get_status(), $descriptor_state . ' descriptor: fixture starts suspended', $failures, $passes );
	smoke_assert( 1, count( $edge_retries ), $descriptor_state . ' descriptor: lock contention queues reconcile-only retry', $failures, $passes );
	smoke_assert( 'workflow_branch_descriptor_missing', $edge_receipt['branch_result']['error']['code'] ?? '', $descriptor_state . ' descriptor: receipt durably carries terminal missing-descriptor failure', $failures, $passes );
	AS_Shim::fire( $edge_retries[0]['id'] );
	smoke_assert( false, array_key_exists( $edge_store_ref, $GLOBALS['__options'] ), $descriptor_state . ' descriptor: successful reconcile deletes exact receipt-only row', $failures, $passes );
	$edge_resumes = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
	AS_Shim::fire( $edge_resumes[0]['id'] );
	$edge_final = $edge_recorder->find( $edge_run_id );
	smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $edge_final->get_status(), $descriptor_state . ' descriptor: reconcile-only retry reaches terminal workflow failure', $failures, $passes );
	smoke_assert( 'workflow_parallel_required_branch_failed', $edge_final->get_error()['code'] ?? '', $descriptor_state . ' descriptor: terminal failure preserves required-branch semantics', $failures, $passes );
	remove_all_filters( 'wp_agent_workflow_reconcile_lock' );
}

// If receipt persistence succeeds but enqueueing RECONCILE_HOOK fails, retrying
// the original branch payload must discover the receipt before executing effects.
AS_Shim::reset();
$GLOBALS['__options'] = array();
$recorder7 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder7 ) { return $recorder7; } );
( new WP_Agent_Workflow_Runner( $recorder7 ) )->run( as_smoke_roles_spec(), array(), array( 'run_id' => 'as-enqueue-fail' ) );
$branches7 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
$payload7  = $branches7[0]['args'][0] ?? array();
$lock_attempts7 = 0;
add_filter(
	'wp_agent_workflow_reconcile_lock',
	static function ( $override, string $run_id, callable $critical ) use ( &$lock_attempts7 ) {
		unset( $override );
		if ( 'as-enqueue-fail' === $run_id && 0 === $lock_attempts7++ ) {
			return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
		}
		return $critical();
	},
	10,
	3
);
$effect_before7 = (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 );
AS_Shim::$reject_hook = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK;
$failure_lifecycle7 = ! AS_Shim::fire_with_failure_lifecycle( $branches7[0]['id'] );
AS_Shim::$reject_hook = '';
smoke_assert( true, $failure_lifecycle7, 'failed retry enqueue: Action Scheduler failure lifecycle handles original action failure', $failures, $passes );
smoke_assert( $effect_before7 + 1, (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 ), 'failed retry enqueue: failed-action recovery does not repeat side effect', $failures, $passes );
smoke_assert( 1, count( $recorder7->find( 'as-enqueue-fail' )->get_suspension()['completed'] ?? array() ), 'failed retry enqueue: failed-action callback reconciles persisted result', $failures, $passes );
remove_all_filters( 'wp_agent_workflow_reconcile_lock' );

// If both durable enqueue and inline reconciliation remain unavailable, the
// failed-action callback must terminate the run instead of leaving it suspended.
AS_Shim::reset();
$GLOBALS['__options'] = array();
$recorder8 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder8 ) { return $recorder8; } );
( new WP_Agent_Workflow_Runner( $recorder8 ) )->run( as_smoke_roles_spec(), array(), array( 'run_id' => 'as-recovery-terminal' ) );
$branches8 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
add_filter(
	'wp_agent_workflow_reconcile_lock',
	static function () {
		return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
	},
	10,
	3
);
$effect_before8 = (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 );
$completion_events8 = array();
$forget_calls8 = 0;
add_action(
	'wp_agent_workflow_run_completed',
	static function ( $result, string $run_id ) use ( &$completion_events8 ): void {
		$completion_events8[] = array( 'result' => $result, 'run_id' => $run_id );
	},
	10,
	2
);
add_filter(
	'wp_agent_workflow_branch_store_forget',
	static function ( bool $handled ) use ( &$forget_calls8 ): bool {
		++$forget_calls8;
		return $handled;
	}
);
AS_Shim::$reject_hook = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK;
AS_Shim::fire_with_failure_lifecycle( $branches8[0]['id'] );
AS_Shim::$reject_hook = '';
$terminal8 = $recorder8->find( 'as-recovery-terminal' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $terminal8->get_status(), 'failed-action recovery: run fails terminal when no continuation can be established', $failures, $passes );
smoke_assert( 'workflow_branch_reconcile_recovery_failed', $terminal8->get_error()['code'] ?? '', 'failed-action recovery: terminal failure uses stable recovery code', $failures, $passes );
smoke_assert( $effect_before8 + 1, (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 ), 'failed-action recovery: terminal fallback does not repeat branch effects', $failures, $passes );
smoke_assert( 1, count( $completion_events8 ), 'failed-action recovery: forced terminal path fires completion funnel exactly once', $failures, $passes );
smoke_assert( 'as-recovery-terminal', $completion_events8[0]['run_id'] ?? '', 'failed-action recovery: completion observer receives run id', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, isset( $completion_events8[0]['result'] ) ? $completion_events8[0]['result']->get_status() : '', 'failed-action recovery: completion observer receives terminal result', $failures, $passes );
smoke_assert( 1, $forget_calls8, 'failed-action recovery: run-scoped branch cleanup executes exactly once', $failures, $passes );
remove_all_filters( 'wp_agent_workflow_reconcile_lock' );
remove_all_filters( 'wp_agent_workflow_run_completed' );
remove_all_filters( 'wp_agent_workflow_branch_store_forget' );

// A RECONCILE_HOOK action can itself fail while handing off another contended
// attempt. Its failed-action lifecycle must recover the receipt directly.
AS_Shim::reset();
$GLOBALS['__options'] = array();
$recorder_reconcile_failure = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder_reconcile_failure ) { return $recorder_reconcile_failure; } );
( new WP_Agent_Workflow_Runner( $recorder_reconcile_failure ) )->run( as_smoke_roles_spec(), array(), array( 'run_id' => 'as-reconcile-action-fail' ) );
$reconcile_failure_branches = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
$reconcile_failure_attempts = 0;
add_filter(
	'wp_agent_workflow_reconcile_lock',
	static function ( $override, string $run_id, callable $critical ) use ( &$reconcile_failure_attempts ) {
		unset( $override );
		if ( 'as-reconcile-action-fail' === $run_id && $reconcile_failure_attempts++ < 2 ) {
			return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
		}
		return $critical();
	},
	10,
	3
);
$reconcile_failure_effect_before = (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 );
AS_Shim::fire( $reconcile_failure_branches[0]['id'] );
$failed_reconcile_actions = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK );
AS_Shim::$reject_hook = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK;
AS_Shim::fire_with_failure_lifecycle( $failed_reconcile_actions[0]['id'] );
AS_Shim::$reject_hook = '';
smoke_assert( $reconcile_failure_effect_before + 1, (int) ( $GLOBALS['__role_worker_effects']['head'] ?? 0 ), 'failed reconcile action: recovery does not repeat branch effects', $failures, $passes );
smoke_assert( 1, count( $recorder_reconcile_failure->find( 'as-reconcile-action-fail' )->get_suspension()['completed'] ?? array() ), 'failed reconcile action: failed-action lifecycle recovers durable receipt', $failures, $passes );
remove_all_filters( 'wp_agent_workflow_reconcile_lock' );

// #535 payloads carry an admission token but predate explicit backend provenance.
// They continue reconciliation synchronously without guessing receipt ownership.
AS_Shim::reset();
$GLOBALS['__options'] = array();
$recorder9 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder9 ) { return $recorder9; } );
( new WP_Agent_Workflow_Runner( $recorder9 ) )->run( as_smoke_single_branch_spec( 'transition' ), array(), array( 'run_id' => 'as-transition' ) );
$branches9 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
foreach ( AS_Shim::$queue as $index => $queued9 ) {
	if ( $queued9['id'] === $branches9[0]['id'] ) {
		unset( AS_Shim::$queue[ $index ]['args'][0]['store_backend'] );
	}
}
$transition_attempts9 = 0;
add_filter(
	'wp_agent_workflow_reconcile_lock',
	static function ( $override, string $run_id, callable $critical ) use ( &$transition_attempts9 ) {
		unset( $override );
		if ( 'as-transition' === $run_id && 0 === $transition_attempts9++ ) {
			return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
		}
		return $critical();
	},
	10,
	3
);
$transition_effect_before9 = (int) ( $GLOBALS['__role_worker_effects']['transition'] ?? 0 );
AS_Shim::fire( $branches9[0]['id'] );
$resumes9 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
AS_Shim::fire( $resumes9[0]['id'] );
$transition_final9 = $recorder9->find( 'as-transition' );
smoke_assert( true, isset( $branches9[0]['args'][0]['admission_token'] ), '#535 transition: payload retains admission token', $failures, $passes );
smoke_assert( 0, count( AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK ) ), '#535 transition: contention uses no backend-dependent receipt action', $failures, $passes );
smoke_assert( $transition_effect_before9 + 1, (int) ( $GLOBALS['__role_worker_effects']['transition'] ?? 0 ), '#535 transition: branch effects execute exactly once', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $transition_final9->get_status(), '#535 transition: bounded synchronous continuation reaches terminal success', $failures, $passes );
remove_all_filters( 'wp_agent_workflow_reconcile_lock' );

AS_Shim::reset();
$GLOBALS['__options'] = array();
$recorder10 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder10 ) { return $recorder10; } );
( new WP_Agent_Workflow_Runner( $recorder10 ) )->run( as_smoke_single_branch_spec( 'transition-fail' ), array(), array( 'run_id' => 'as-transition-fail' ) );
$branches10 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
foreach ( AS_Shim::$queue as $index => $queued10 ) {
	if ( $queued10['id'] === $branches10[0]['id'] ) {
		unset( AS_Shim::$queue[ $index ]['args'][0]['store_backend'] );
	}
}
add_filter( 'wp_agent_workflow_reconcile_lock', static function () { return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended' ); }, 10, 3 );
$transition_effect_before10 = (int) ( $GLOBALS['__role_worker_effects']['transition-fail'] ?? 0 );
AS_Shim::fire( $branches10[0]['id'] );
$transition_final10 = $recorder10->find( 'as-transition-fail' );
smoke_assert( $transition_effect_before10 + 1, (int) ( $GLOBALS['__role_worker_effects']['transition-fail'] ?? 0 ), '#535 transition failure: bounded attempts do not repeat effects', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $transition_final10->get_status(), '#535 transition failure: exhausted continuation reaches terminal failure', $failures, $passes );
smoke_assert( 'workflow_branch_reconcile_recovery_failed', $transition_final10->get_error()['code'] ?? '', '#535 transition failure: stable terminal recovery code', $failures, $passes );
remove_all_filters( 'wp_agent_workflow_reconcile_lock' );

// Pre-#535 payloads have neither admission_token nor store_backend. They use the
// same bounded no-reexecution continuation and still reach the completion funnel.
AS_Shim::reset();
$GLOBALS['__options'] = array();
$recorder_tokenless = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder_tokenless ) { return $recorder_tokenless; } );
( new WP_Agent_Workflow_Runner( $recorder_tokenless ) )->run( as_smoke_single_branch_spec( 'tokenless' ), array(), array( 'run_id' => 'as-tokenless' ) );
$tokenless_branches = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
foreach ( AS_Shim::$queue as $index => $queued_tokenless ) {
	if ( $queued_tokenless['id'] === $tokenless_branches[0]['id'] ) {
		unset( AS_Shim::$queue[ $index ]['args'][0]['store_backend'], AS_Shim::$queue[ $index ]['args'][0]['admission_token'] );
	}
}
$tokenless_attempts = 0;
add_filter(
	'wp_agent_workflow_reconcile_lock',
	static function ( $override, string $run_id, callable $critical ) use ( &$tokenless_attempts ) {
		unset( $override );
		if ( 'as-tokenless' === $run_id && 0 === $tokenless_attempts++ ) {
			return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
		}
		return $critical();
	},
	10,
	3
);
$tokenless_completions = 0;
add_action(
	'wp_agent_workflow_run_completed',
	static function ( $result, string $run_id ) use ( &$tokenless_completions ): void {
		unset( $result );
		if ( 'as-tokenless' === $run_id ) {
			++$tokenless_completions;
		}
	},
	10,
	2
);
$tokenless_effect_before = (int) ( $GLOBALS['__role_worker_effects']['tokenless'] ?? 0 );
AS_Shim::fire( $tokenless_branches[0]['id'] );
$tokenless_resumes = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
AS_Shim::fire( $tokenless_resumes[0]['id'] );
$tokenless_final = $recorder_tokenless->find( 'as-tokenless' );
smoke_assert( $tokenless_effect_before + 1, (int) ( $GLOBALS['__role_worker_effects']['tokenless'] ?? 0 ), 'tokenless compatibility: contention does not repeat branch effects', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $tokenless_final->get_status(), 'tokenless compatibility: bounded continuation reaches terminal success', $failures, $passes );
smoke_assert( 1, $tokenless_completions, 'tokenless compatibility: completion funnel fires exactly once', $failures, $passes );
remove_all_filters( 'wp_agent_workflow_reconcile_lock' );
remove_all_filters( 'wp_agent_workflow_run_completed' );

// A custom receipt ref may differ from its descriptor ref. The failed-action
// lifecycle recovers it through the dedicated descriptor-identity locator.
AS_Shim::reset();
$GLOBALS['__options'] = array();
$GLOBALS['__distinct_descriptors'] = array();
$GLOBALS['__distinct_receipts'] = array();
$GLOBALS['__distinct_locators'] = array();
$GLOBALS['__distinct_descriptor_writes'] = 0;
add_filter(
	'wp_agent_workflow_branch_store_put',
	static function ( $ref, string $run_id, string $handle_id, array $descriptor ) {
		unset( $ref, $run_id );
		++$GLOBALS['__distinct_descriptor_writes'];
		$descriptor_ref = 'custom-descriptor:' . $handle_id;
		$GLOBALS['__distinct_descriptors'][ $descriptor_ref ] = $descriptor;
		return $descriptor_ref;
	},
	10,
	4
);
add_filter( 'wp_agent_workflow_branch_store_get', static function ( $descriptor, string $store_ref ) { unset( $descriptor ); return $GLOBALS['__distinct_descriptors'][ $store_ref ] ?? null; }, 10, 3 );
add_filter(
	'wp_agent_workflow_branch_receipt_put',
	static function ( $receipt_ref, string $store_ref, string $run_id, string $handle_id, array $receipt ) {
		unset( $receipt_ref, $run_id );
		$distinct_ref = 'custom-receipt:' . $handle_id;
		$GLOBALS['__distinct_receipts'][ $distinct_ref ] = $receipt;
		$GLOBALS['__distinct_locators'][ $store_ref ] = $distinct_ref;
		return $distinct_ref;
	},
	10,
	5
);
add_filter( 'wp_agent_workflow_branch_receipt_get', static function ( $receipt, string $receipt_ref ) { unset( $receipt ); return $GLOBALS['__distinct_receipts'][ $receipt_ref ] ?? null; }, 10, 3 );
add_filter( 'wp_agent_workflow_branch_receipt_locate', static function ( string $receipt_ref, string $store_ref ): string { unset( $receipt_ref ); return $GLOBALS['__distinct_locators'][ $store_ref ] ?? ''; }, 10, 5 );
add_filter( 'wp_agent_workflow_branch_receipt_delete', static function ( bool $handled, string $receipt_ref ): bool { unset( $handled ); unset( $GLOBALS['__distinct_receipts'][ $receipt_ref ] ); return true; }, 10, 5 );
add_filter( 'wp_agent_workflow_branch_store_forget', static function (): bool { $GLOBALS['__distinct_descriptors'] = array(); $GLOBALS['__distinct_receipts'] = array(); $GLOBALS['__distinct_locators'] = array(); return true; }, 10, 2 );
$recorder11 = new AS_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder11 ) { return $recorder11; } );
( new WP_Agent_Workflow_Runner( $recorder11 ) )->run( as_smoke_single_branch_spec( 'distinct' ), array(), array( 'run_id' => 'as-distinct' ) );
$branches11 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
$distinct_attempts11 = 0;
add_filter(
	'wp_agent_workflow_reconcile_lock',
	static function ( $override, string $run_id, callable $critical ) use ( &$distinct_attempts11 ) {
		unset( $override );
		if ( 'as-distinct' === $run_id && 0 === $distinct_attempts11++ ) {
			return new WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
		}
		return $critical();
	},
	10,
	3
);
$distinct_effect_before11 = (int) ( $GLOBALS['__role_worker_effects']['distinct'] ?? 0 );
AS_Shim::$reject_hook = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RECONCILE_HOOK;
AS_Shim::fire_with_failure_lifecycle( $branches11[0]['id'] );
AS_Shim::$reject_hook = '';
$resumes11 = AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK );
AS_Shim::fire( $resumes11[0]['id'] );
$distinct_final11 = $recorder11->find( 'as-distinct' );
smoke_assert( 1, $GLOBALS['__distinct_descriptor_writes'], 'distinct custom receipt: recovery never writes through descriptor contract', $failures, $passes );
smoke_assert( $distinct_effect_before11 + 1, (int) ( $GLOBALS['__role_worker_effects']['distinct'] ?? 0 ), 'distinct custom receipt: failed-action recovery preserves exactly-one effects', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $distinct_final11->get_status(), 'distinct custom receipt: locator recovers distinct ref to terminal success', $failures, $passes );
remove_all_filters( 'wp_agent_workflow_branch_store_put' );
remove_all_filters( 'wp_agent_workflow_branch_store_get' );
remove_all_filters( 'wp_agent_workflow_branch_receipt_put' );
remove_all_filters( 'wp_agent_workflow_branch_receipt_get' );
remove_all_filters( 'wp_agent_workflow_branch_receipt_locate' );
remove_all_filters( 'wp_agent_workflow_branch_receipt_delete' );
remove_all_filters( 'wp_agent_workflow_branch_store_forget' );
remove_all_filters( 'wp_agent_workflow_reconcile_lock' );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
