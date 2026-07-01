<?php
/**
 * Pure-PHP end-to-end smoke test for the async suspend/resume workflow model
 * (Phase 1: state machine + interface + sync default, no Action Scheduler).
 *
 * Run with: php tests/workflow-parallel-async-smoke.php
 *
 * This drives the REAL runner suspend → reconcile → resume path — NOT shape
 * assertions. A test-only FakeExecutor is registered through the SAME real
 * `wp_agent_workflow_step_executor` filter a caller executor uses. Its
 * dispatch() records branch descriptors and returns `dispatched` handles
 * WITHOUT running them; a `complete( handle_id, branch_result )` helper calls
 * the REAL `agents_reconcile_workflow_branch()`. Every assertion executes
 * production code: real run(), real `_suspend` handling in the step executor,
 * the real suspend gate, the real recorder round-trip, the real reconcile,
 * the real aggregate, and the real resume().
 *
 * Covered (design §9.2): suspend correctness, reconcile ordering + idempotency,
 * resume continuation (a step AFTER the parallel step consumes the aggregate),
 * required-branch failure, non-required-branch failure, sync-floor parity
 * (selector returns null → v0.5.0), selection rule (override wins),
 * crash-resume durability (discard runner, resume from find()), recorder
 * round-trip preserves metadata._suspension, and idempotent duplicate reconcile.
 *
 * No WordPress required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-parallel-async-smoke\n";

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

function async_smoke_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability(
		$name,
		array( 'execute_callback' => $handler )
	);
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
// Reconcile references agents_run_workflow_output_schema / agents_workflow_string
// / agents_workflow_run_cancel_permission from the abilities file.
require_once __DIR__ . '/../src/Workflows/register-agents-workflow-abilities.php';
require_once __DIR__ . '/../src/Workflows/register-reconcile-workflow-branch.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Executor;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

use function AgentsAPI\AI\Workflows\agents_reconcile_workflow_branch;

// ── A durable, reloadable in-memory recorder ─────────────────────────────────
// Persists the serialized run-result array (round-tripped through to_array /
// from_array). "Durable" enough to prove crash-resume: find() rebuilds from the
// stored array, so a fresh runner loaded from find() sees the exact frame.

final class Async_Smoke_Recorder implements WP_Agent_Workflow_Run_Recorder {
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
}

// ── The FakeExecutor — registered through the REAL filter ────────────────────
// dispatch() records descriptors + returns 'dispatched' handles WITHOUT running
// anything. Completion is scripted by the test via complete().

final class Fake_Branch_Executor implements WP_Agent_Workflow_Branch_Executor {
	/** @var array<string,array<string,mixed>> handle_id => handle */
	public array $handles = array();
	/** @var array<string,array<string,mixed>> handle_id => descriptor */
	public array $descriptors = array();
	private int $seq = 0;

	public function id(): string { return 'fake'; }

	public function dispatch( array $branches, array $context ): array {
		unset( $context );
		$out = array();
		foreach ( $branches as $branch ) {
			$key       = (string) ( $branch['key'] ?? $this->seq );
			$handle_id = 'h_' . ( ++$this->seq ) . '_' . $key;
			$handle    = array(
				'id'       => $handle_id,
				'key'      => $key,
				'executor' => $this->id(),
				'status'   => 'dispatched',
				'required' => ! empty( $branch['required'] ),
				'ref'      => null,
			);
			$this->handles[ $handle_id ]     = $handle;
			$this->descriptors[ $handle_id ] = $branch;
			$out[]                           = $handle;
		}
		return $out;
	}

	public function are_all_complete( array $handles ): bool {
		foreach ( $handles as $handle ) {
			$status = (string) ( $handle['status'] ?? '' );
			if ( 'succeeded' !== $status && 'failed' !== $status ) {
				return false;
			}
		}
		return true;
	}

	public function collect( array $handles ): array {
		$out = array();
		foreach ( $handles as $handle ) {
			$out[ (string) ( $handle['key'] ?? '' ) ] = array();
		}
		return $out;
	}
}

// Register the FakeExecutor through the REAL selection filter (priority 10 wins
// over the core priority-5 default that returns null).
$GLOBALS['__fake_executor'] = null;
add_filter(
	'wp_agent_workflow_step_executor',
	static function ( $executor, $step, $context ) {
		unset( $step, $context );
		if ( $executor instanceof WP_Agent_Workflow_Branch_Executor ) {
			return $executor;
		}
		return $GLOBALS['__fake_executor'];
	},
	10,
	3
);

/**
 * Test helper: simulate a branch finishing by calling the REAL reconcile.
 *
 * @return WP_Agent_Workflow_Run_Result|WP_Error
 */
function async_smoke_complete( string $run_id, string $handle_id, string $key, string $status, $output, $extra = array() ) {
	return agents_reconcile_workflow_branch(
		$run_id,
		$handle_id,
		array_merge(
			array(
				'key'    => $key,
				'status' => $status,
				'output' => $output,
			),
			$extra
		)
	);
}

// ── Stub abilities (aggregator + sequential consumer) ────────────────────────

async_smoke_register_ability(
	'demo/aggregate',
	static function ( array $input ): array {
		return array(
			'final_bundle' => 'FUSED[' . (string) ( $input['headline'] ?? '' ) . '|' . (string) ( $input['body'] ?? '' ) . ']',
		);
	}
);

async_smoke_register_ability(
	'demo/consume',
	static function ( array $input ): array {
		return array( 'consumed' => 'GOT:' . (string) ( $input['bundle'] ?? '' ) );
	}
);

// Role worker used by the sibling branches when they run SYNCHRONOUSLY (the
// sync-floor case). In the async case the FakeExecutor never runs them; the
// test supplies fragments directly through reconcile.
async_smoke_register_ability(
	'demo/role-worker',
	static function ( array $input ): array {
		unset( $input );
		return array( 'fragment' => 'SYNC' );
	}
);

// ── The spec: parallel-roles step FOLLOWED BY a sequential step that consumes
//    the aggregated bundle. This proves resume continuation.

function async_smoke_roles_spec(): WP_Agent_Workflow_Spec {
	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/async-roles',
			'steps' => array(
				array(
					'id'       => 'scatter',
					'type'     => 'parallel',
					'context'  => array( 'marker' => 'M' ),
					'branches' => array(
						array(
							'role'                   => 'headline',
							'required'               => true,
							'can_write_final_bundle' => false,
							'steps'                  => array(
								array( 'id' => 'h', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array() ),
							),
						),
						array(
							'role'                   => 'body',
							'required'               => true,
							'can_write_final_bundle' => false,
							'steps'                  => array(
								array( 'id' => 'b', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array() ),
							),
						),
						array(
							'role'                   => 'fuse',
							'required'               => true,
							'can_write_final_bundle' => true,
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
				// Sequential step AFTER the parallel step — consumes the aggregate.
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
// 1. Suspend correctness + 2. reconcile ordering/idempotency + 3. resume
// ═════════════════════════════════════════════════════════════════════════════

$recorder                   = new Async_Smoke_Recorder();
$GLOBALS['__fake_executor'] = new Fake_Branch_Executor();
$fake                       = $GLOBALS['__fake_executor'];

// Wire the recorder for reconcile/resume through the real filter.
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder ) { return $recorder; } );

$spec   = async_smoke_roles_spec();
$run    = ( new WP_Agent_Workflow_Runner( $recorder ) )->run( $spec, array(), array( 'run_id' => 'run-A' ) );

// SUSPEND CORRECTNESS.
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run->get_status(), 'run returns SUSPENDED when an executor is selected', $failures, $passes );
smoke_assert_true( $run->is_suspended(), 'is_suspended() true on the suspended run', $failures, $passes );

$frame = $run->get_suspension();
smoke_assert( 0, $frame['step_index'] ?? -1, 'frame records the suspended step_index (0)', $failures, $passes );
smoke_assert( 'scatter', $frame['step_id'] ?? '', 'frame records the suspended step_id', $failures, $passes );
smoke_assert( 'fake', $frame['executor_id'] ?? '', 'frame records the owning executor id', $failures, $passes );
smoke_assert( 2, count( $frame['handles'] ?? array() ), 'frame carries N sibling handles (2, aggregator deferred)', $failures, $passes );
smoke_assert( 'roles', $frame['aggregate']['mode'] ?? '', 'frame carries the roles aggregate plan', $failures, $passes );

// The addressable run must NOT be finished on suspend (stays live).
$rc = \AgentsAPI\AI\WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'run-A' );
smoke_assert( 'running', $rc['status'] ?? '', 'addressable run stays RUNNING while suspended (not finished)', $failures, $passes );

// Recorder round-trip preserves metadata._suspension losslessly.
$reloaded = $recorder->find( 'run-A' );
smoke_assert_true( is_array( $reloaded->get_suspension()['handles'] ?? null ), 'recorder round-trip preserves metadata._suspension', $failures, $passes );
smoke_assert( 2, count( $reloaded->get_suspension()['handles'] ), 'reloaded frame preserves handle count', $failures, $passes );

// Identify handle ids for headline + body.
$handle_by_key = array();
foreach ( $frame['handles'] as $h ) {
	$handle_by_key[ $h['key'] ] = $h['id'];
}

// RECONCILE ORDERING: complete body first (out of order), then headline last.
$after_body = async_smoke_complete( 'run-A', $handle_by_key['body'], 'body', 'succeeded', array( 'fragment' => 'BODY' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $after_body->get_status(), 'still SUSPENDED after 1 of 2 branches reconcile', $failures, $passes );

// IDEMPOTENT DUPLICATE RECONCILE: reconciling body again must not resume or double-count.
$dup = async_smoke_complete( 'run-A', $handle_by_key['body'], 'body', 'succeeded', array( 'fragment' => 'BODY-DUP' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $dup->get_status(), 'duplicate reconcile is a no-op (still SUSPENDED)', $failures, $passes );
smoke_assert( 1, count( $dup->get_suspension()['completed'] ?? array() ), 'duplicate reconcile does not double-count completed', $failures, $passes );

// The LAST branch reconciles → aggregate + resume.
$final = async_smoke_complete( 'run-A', $handle_by_key['headline'], 'headline', 'succeeded', array( 'fragment' => 'HEAD' ) );

// RESUME CONTINUATION: run succeeds, the sequential `after` step ran and
// consumed the aggregated bundle.
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $final->get_status(), 'last branch resumes → run SUCCEEDED', $failures, $passes );
$out_steps = $final->get_output()['steps'] ?? array();
smoke_assert( 'FUSED[HEAD|BODY]', $out_steps['scatter']['final']['final_bundle'] ?? '', 'aggregate fused reconciled sibling outputs on resume', $failures, $passes );
smoke_assert( 'GOT:FUSED[HEAD|BODY]', $out_steps['after']['consumed'] ?? '', 'step AFTER the parallel step consumed the aggregated output on resume', $failures, $passes );

// The suspension frame is cleared after resume (table-free row gone).
smoke_assert( array(), $final->get_suspension(), 'suspension frame cleared after resume', $failures, $passes );

// A reconcile after the run already resumed is a harmless no-op.
$late = async_smoke_complete( 'run-A', $handle_by_key['headline'], 'headline', 'succeeded', array( 'fragment' => 'X' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $late->get_status(), 'reconcile after resume is a no-op (run stays SUCCEEDED)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 4. Required-branch failure fails the run on resume
// ═════════════════════════════════════════════════════════════════════════════

$recorder2 = new Async_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder2 ) { return $recorder2; } );
$GLOBALS['__fake_executor'] = new Fake_Branch_Executor();

$run2   = ( new WP_Agent_Workflow_Runner( $recorder2 ) )->run( async_smoke_roles_spec(), array(), array( 'run_id' => 'run-B' ) );
$frame2 = $run2->get_suspension();
$hk2    = array();
foreach ( $frame2['handles'] as $h ) {
	$hk2[ $h['key'] ] = $h['id'];
}
async_smoke_complete( 'run-B', $hk2['headline'], 'headline', 'succeeded', array( 'fragment' => 'HEAD' ) );
$res2 = async_smoke_complete( 'run-B', $hk2['body'], 'body', 'failed', null, array( 'error' => array( 'code' => 'boom', 'message' => 'body exploded' ) ) );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $res2->get_status(), 'required-branch failure → run FAILED on resume', $failures, $passes );
smoke_assert( 'workflow_parallel_required_branch_failed', $res2->get_error()['code'] ?? '', 'required-branch failure surfaces the parallel failure code', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 5. Non-required-branch failure surfaces error but run still succeeds
// ═════════════════════════════════════════════════════════════════════════════

function async_smoke_optional_spec(): WP_Agent_Workflow_Spec {
	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/async-optional',
			'steps' => array(
				array(
					'id'       => 'scatter',
					'type'     => 'parallel',
					'context'  => array( 'marker' => 'M' ),
					'branches' => array(
						array(
							'role'                   => 'flaky',
							'required'               => false,
							'can_write_final_bundle' => false,
							'steps'                  => array( array( 'id' => 'f', 'type' => 'ability', 'ability' => 'demo/aggregate', 'args' => array() ) ),
						),
						array(
							'role'                   => 'fuse',
							'required'               => true,
							'can_write_final_bundle' => true,
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

$recorder3 = new Async_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder3 ) { return $recorder3; } );
$GLOBALS['__fake_executor'] = new Fake_Branch_Executor();

$run3   = ( new WP_Agent_Workflow_Runner( $recorder3 ) )->run( async_smoke_optional_spec(), array(), array( 'run_id' => 'run-C' ) );
$frame3 = $run3->get_suspension();
$hk3    = array();
foreach ( $frame3['handles'] as $h ) {
	$hk3[ $h['key'] ] = $h['id'];
}
$res3 = async_smoke_complete( 'run-C', $hk3['flaky'], 'flaky', 'failed', array( 'error' => array( 'code' => 'flaky_boom', 'message' => 'flaked' ) ), array( 'error' => array( 'code' => 'flaky_boom', 'message' => 'flaked' ) ) );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $res3->get_status(), 'non-required branch failure → run still SUCCEEDED', $failures, $passes );
$out3 = $res3->get_output()['steps']['scatter']['final'] ?? array();
smoke_assert( 'FUSED[x|y]', $out3['final_bundle'] ?? '', 'aggregator still fuses despite a tolerated branch failure', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 6. Crash-resume durability: discard the runner, resume from find()
// ═════════════════════════════════════════════════════════════════════════════

$recorder4 = new Async_Smoke_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder4 ) { return $recorder4; } );
$GLOBALS['__fake_executor'] = new Fake_Branch_Executor();

$run4   = ( new WP_Agent_Workflow_Runner( $recorder4 ) )->run( async_smoke_roles_spec(), array(), array( 'run_id' => 'run-D' ) );
$frame4 = $run4->get_suspension();
$hk4    = array();
foreach ( $frame4['handles'] as $h ) {
	$hk4[ $h['key'] ] = $h['id'];
}
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run4->get_status(), 'crash-resume: run suspends', $failures, $passes );

// Throw away every runner instance. Reconcile resolves a FRESH runner from the
// recorder via the resume-runner filter default — nothing from $run4 survives.
unset( $run4 );

async_smoke_complete( 'run-D', $hk4['body'], 'body', 'succeeded', array( 'fragment' => 'B2' ) );
$res4 = async_smoke_complete( 'run-D', $hk4['headline'], 'headline', 'succeeded', array( 'fragment' => 'H2' ) );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $res4->get_status(), 'crash-resume: fresh runner from find() completes the run', $failures, $passes );
smoke_assert( 'GOT:FUSED[H2|B2]', $res4->get_output()['steps']['after']['consumed'] ?? '', 'crash-resume: sequential step ran after resume through a fresh runner', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 7. Sync-floor parity: selector returns null → NO SUSPENDED, v0.5.0 output
// ═════════════════════════════════════════════════════════════════════════════

$GLOBALS['__fake_executor'] = null; // selector now resolves null → sync loops.
$recorder5                  = new Async_Smoke_Recorder();

$sync_run = ( new WP_Agent_Workflow_Runner( $recorder5 ) )->run( async_smoke_roles_spec(), array(), array( 'run_id' => 'run-E' ) );

smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $sync_run->get_status(), 'sync floor: no executor → single terminal SUCCEEDED (no SUSPENDED)', $failures, $passes );
smoke_assert_true( ! $sync_run->is_suspended(), 'sync floor: never produces SUSPENDED', $failures, $passes );
smoke_assert( array(), $sync_run->get_suspension(), 'sync floor: no suspension frame', $failures, $passes );
// The aggregate ran inline in ONE request and the sequential step consumed it.
smoke_assert( 'roles', $sync_run->get_output()['steps']['scatter']['shape'] ?? '', 'sync floor: parallel-roles shape produced inline', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 8. Selection rule: an override at priority 10 wins over the core null default
// ═════════════════════════════════════════════════════════════════════════════

// With the FakeExecutor registered (priority 10) the run suspends; with it null
// the run is synchronous. Both cases are exercised above (run-A vs run-E),
// proving the override wins over the core priority-5 null default.
$GLOBALS['__fake_executor'] = new Fake_Branch_Executor();
$selected = apply_filters( 'wp_agent_workflow_step_executor', null, array( 'id' => 'x', 'type' => 'parallel' ), array() );
smoke_assert_true( $selected instanceof WP_Agent_Workflow_Branch_Executor, 'selection rule: priority-10 override wins over core null default', $failures, $passes );
$GLOBALS['__fake_executor'] = null;
$selected_null = apply_filters( 'wp_agent_workflow_step_executor', null, array( 'id' => 'x', 'type' => 'parallel' ), array() );
smoke_assert( null, $selected_null, 'selection rule: no override + no AS → null (sync)', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
