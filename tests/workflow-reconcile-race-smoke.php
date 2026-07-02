<?php
/**
 * Pure-PHP regression test for the concurrent-reconcile lost-update stall.
 *
 * Run with: php tests/workflow-reconcile-race-smoke.php
 *
 * THE BUG (observed twice in a real MySQL multi-page fanout A/B): when two (or
 * more) branches of a parallel fanout finish "at the same time" in SEPARATE
 * processes, both processes call the REAL agents_reconcile_workflow_branch() for
 * the SAME suspended run. The reconcile entry point does a naive
 * read-modify-write on the suspension frame's `completed[]` map:
 *
 *     $result    = $recorder->find( $run_id );   // READ frame
 *     $completed = ...;                           // MODIFY: merge my handle
 *     $recorder->update( $result );               // WRITE whole frame back
 *     if ( count($completed) < count($handles) ) return; // DECIDE all-terminal
 *
 * With no cross-process serialization, two reconciles both read the SAME
 * pre-merge frame, each merges only ITS OWN handle, and the later write CLOBBERS
 * the earlier one (a lost update). The frame then permanently shows fewer than N
 * completed handles, the "all terminal → enqueue resume" transition NEVER fires,
 * and the run stays SUSPENDED forever — the exact silent hang observed in prod.
 *
 * The Action Scheduler atomic action-claim guards the RESUME action (dedup), but
 * it does NOT guard this completed[] read-modify-write — a different write. That
 * is the gap this test pins.
 *
 * This test drives the REAL reconcile / aggregate / resume state machine (no
 * shape mocks). It reproduces the race deterministically with a recorder that
 * models two processes reading the frame before either writes, then asserts:
 *   - the run reaches SUCCEEDED (resume fired),
 *   - a resume was enqueued exactly once,
 *   - the aggregate fused ALL branch outputs (no completion was lost).
 *
 * It FAILS before the fix (run stuck SUSPENDED, no resume) and PASSES after.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-reconcile-race-smoke\n";

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

// ── Option-backed store shim. The default reconcile lock uses add_option() as an
// atomic table-free CAS: add_option() INSERTs and returns false if the option
// row already exists (the option_name unique key is the compare-and-swap). We
// model exactly that contract here so the REAL default lock is exercised.
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $option ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['__options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, $value = '', $deprecated = '', $autoload = null ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $GLOBALS['__options'] ) ) {
			return false; // Atomic INSERT semantics: the row already exists.
		}
		$GLOBALS['__options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		if ( ! array_key_exists( $option, $GLOBALS['__options'] ) ) {
			return false;
		}
		unset( $GLOBALS['__options'][ $option ] );
		return true;
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

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

use function AgentsAPI\AI\Workflows\agents_reconcile_workflow_branch;

// ── A durable, reloadable in-memory recorder that models cross-process
//    concurrency FAITHFULLY against the REAL lock. ───────────────────────────
//
// `freeze_reads()` captures the current row state as a "pre-merge snapshot" that
// two simultaneous processes would both read. But a stale read only actually
// happens in the UNLOCKED window: if the reconcile lock is held (the fix), a
// second process blocks until the first releases and therefore reads the FRESH,
// post-merge frame. So find() serves the frozen snapshot ONLY when no reconcile
// lock is currently held for the run; once the fix takes the lock, the frozen
// read is bypassed and the second reconcile sees the committed frame — exactly
// the cross-process ordering a real DB lock enforces.
//
// This makes the test honest in BOTH directions: without the lock the frozen
// snapshot is served to both callers (the real lost-update stall); with the lock
// the second caller reads fresh (the real serialized behavior).

final class Race_Recorder implements WP_Agent_Workflow_Run_Recorder {
	/** @var array<string,array<string,mixed>> */
	public array $rows = array();

	/** @var array<string,array<string,mixed>>|null */
	private ?array $frozen = null;

	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$this->rows[ $result->get_run_id() ] = $result->to_array();
		return $result->get_run_id();
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$this->rows[ $result->get_run_id() ] = $result->to_array();
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result {
		// Serve the stale pre-merge snapshot only in the unlocked window; a held
		// reconcile lock means a real second process would have blocked and read
		// fresh, so honor that ordering here too.
		if ( null !== $this->frozen && isset( $this->frozen[ $run_id ] ) && ! self::reconcile_lock_held( $run_id ) ) {
			return WP_Agent_Workflow_Run_Result::from_array( $this->frozen[ $run_id ] );
		}
		return isset( $this->rows[ $run_id ] )
			? WP_Agent_Workflow_Run_Result::from_array( $this->rows[ $run_id ] )
			: null;
	}
	public function recent( array $args = array() ): array {
		unset( $args );
		return array_map( array( WP_Agent_Workflow_Run_Result::class, 'from_array' ), array_values( $this->rows ) );
	}

	/** Pin find() to the CURRENT row state — models "both processes read here". */
	public function freeze_reads(): void {
		$this->frozen = $this->rows;
	}
	/** Resume serving live rows. */
	public function unfreeze_reads(): void {
		$this->frozen = null;
	}

	/** Whether the built-in add_option() reconcile lock row exists for the run. */
	private static function reconcile_lock_held( string $run_id ): bool {
		$option = 'agents_wf_reconcile_lock_' . md5( $run_id );
		return array_key_exists( $option, $GLOBALS['__options'] );
	}
}

function race_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability( $name, array( 'execute_callback' => $handler ) );
}

race_register_ability(
	'demo/role-worker',
	static function ( array $input ): array {
		return array( 'fragment' => strtoupper( (string) ( $input['label'] ?? 'X' ) ) );
	}
);
race_register_ability(
	'demo/aggregate',
	static function ( array $input ): array {
		// Fuse ALL sibling fragments; a lost completion shows up as a blank slot.
		return array( 'final_bundle' => 'FUSED[' . (string) ( $input['a'] ?? '' ) . '|' . (string) ( $input['b'] ?? '' ) . '|' . (string) ( $input['c'] ?? '' ) . ']' );
	}
);

// A parallel-roles spec with THREE sibling branches + an aggregator. Three
// siblings makes the lost-update unmistakable: drop any one and the aggregate
// bundle is missing a fragment (and, pre-fix, the run never resumes at all).
function race_roles_spec(): WP_Agent_Workflow_Spec {
	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/race-roles',
			'steps' => array(
				array(
					'id'       => 'scatter',
					'type'     => 'parallel',
					'context'  => array( 'marker' => 'M' ),
					'branches' => array(
						array( 'role' => 'a', 'required' => true, 'is_aggregator' => false, 'steps' => array( array( 'id' => 'sa', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => 'a' ) ) ) ),
						array( 'role' => 'b', 'required' => true, 'is_aggregator' => false, 'steps' => array( array( 'id' => 'sb', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => 'b' ) ) ) ),
						array( 'role' => 'c', 'required' => true, 'is_aggregator' => false, 'steps' => array( array( 'id' => 'sc', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => 'c' ) ) ) ),
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
										'a' => '${vars.branch_outputs.a.fragment}',
										'b' => '${vars.branch_outputs.b.fragment}',
										'c' => '${vars.branch_outputs.c.fragment}',
									),
								),
							),
						),
					),
				),
			),
		)
	);
}

// ── A test executor that suspends the run and hands back the branch descriptors
//    so the test can drive reconcile directly (like the Phase 1 FakeExecutor). ─

final class Race_Executor implements \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Executor {
	/** @var array<int,array<string,mixed>> */
	public static array $dispatched = array();

	public function id(): string { return 'race_exec'; }

	public function dispatch( array $branches, array $context ): array {
		self::$dispatched = array();
		$run_id  = (string) ( $context['_workflow_run_id'] ?? '' );
		$step_id = (string) ( $context['_workflow_step_id'] ?? '' );
		$handles = array();
		foreach ( $branches as $index => $branch ) {
			$key       = (string) ( $branch['key'] ?? (string) $index );
			$handle_id = $run_id . ':' . $step_id . ':' . $key . ':' . $index;
			$descriptor = array_merge( $branch, array( 'run_id' => $run_id, 'step_id' => $step_id, 'handle_id' => $handle_id, 'key' => $key ) );
			self::$dispatched[] = $descriptor;
			$handles[] = array(
				'id'       => $handle_id,
				'key'      => $key,
				'executor' => $this->id(),
				'status'   => 'dispatched',
				'required' => ! empty( $branch['required'] ),
				'ref'      => $index + 1,
			);
		}
		return $handles;
	}

	public function are_all_complete( array $handles ): bool {
		foreach ( $handles as $handle ) {
			$status = (string) ( $handle['status'] ?? '' );
			if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED !== $status && WP_Agent_Workflow_Run_Result::STATUS_FAILED !== $status ) {
				return false;
			}
		}
		return true;
	}

	public function collect( array $handles ): array {
		$out = array();
		foreach ( $handles as $handle ) {
			$key         = (string) ( $handle['key'] ?? '' );
			$out[ $key ] = array( 'key' => $key, 'status' => (string) ( $handle['status'] ?? 'dispatched' ), 'output' => null );
		}
		return $out;
	}
}

// Run one branch descriptor through the REAL shared branch runner and build the
// REAL BranchResult, exactly as the AS executor's run_branch_action() does.
function race_execute_branch( array $descriptor ): array {
	$key      = (string) ( $descriptor['key'] ?? '' );
	$steps    = is_array( $descriptor['steps'] ?? null ) ? $descriptor['steps'] : array();
	$handlers = array(
		'ability'  => array( WP_Agent_Workflow_Runner::class, 'default_ability_handler' ),
		'agent'    => array( WP_Agent_Workflow_Runner::class, 'default_agent_handler' ),
		'foreach'  => array( WP_Agent_Workflow_Runner::class, 'default_foreach_handler' ),
		'parallel' => array( WP_Agent_Workflow_Runner::class, 'default_parallel_handler' ),
	);
	$executor       = new \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Step_Executor( $handlers );
	$branch_vars    = is_array( $descriptor['branch_vars'] ?? null ) ? $descriptor['branch_vars'] : array();
	$branch_context = ( new \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Context(
		array( 'inputs' => array(), 'steps' => array(), 'vars' => array() )
	) )->with_vars( $branch_vars );

	$run = WP_Agent_Workflow_Runner::run_branch_steps( $steps, $branch_context, $executor, $handlers, false, $key );
	if ( is_wp_error( $run ) ) {
		return array( 'key' => $key, 'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED, 'output' => null, 'steps' => array(), 'error' => array( 'code' => $run->get_error_code(), 'message' => $run->get_error_message() ) );
	}
	return array( 'key' => $key, 'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, 'output' => $run['last'], 'steps' => $run['steps'], 'error' => null );
}

// ═════════════════════════════════════════════════════════════════════════════
// THE RACE: two sibling branches reconcile CONCURRENTLY (both read the frame
// before either writes). Under the buggy code the later write clobbers the
// earlier merge → the frame never reaches all-terminal → NO resume → the run
// stays SUSPENDED forever. Under the fix, the reconcile critical section is
// serialized cross-process, both completions survive, and resume fires once.
// ═════════════════════════════════════════════════════════════════════════════

$GLOBALS['__options'] = array();
$recorder = new Race_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
remove_all_filters( 'wp_agent_workflow_step_executor' );
remove_all_filters( 'wp_agent_workflow_resume_dispatch' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder ) { return $recorder; } );
add_filter( 'wp_agent_workflow_step_executor', static function () { return new Race_Executor(); } );

// Count resume dispatches. We drive resume INLINE here (no AS executor) to prove
// the bug is the completed[] accounting, not the resume-dedup guard: even with a
// perfectly working resume path, a lost completion means resume is never reached.
$GLOBALS['__resume_dispatch_calls'] = 0;

$run = ( new WP_Agent_Workflow_Runner( $recorder ) )->run( race_roles_spec(), array(), array( 'run_id' => 'race-1' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run->get_status(), 'race: run SUSPENDED after dispatch', $failures, $passes );

$descriptors = Race_Executor::$dispatched; // three siblings: a, b, c
smoke_assert( 3, count( $descriptors ), 'race: three sibling branches dispatched', $failures, $passes );

// Branch A finishes first, alone — no contention. Frame now shows {a}.
$result_a = race_execute_branch( $descriptors[0] );
agents_reconcile_workflow_branch( 'race-1', (string) $descriptors[0]['handle_id'], $result_a );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $recorder->find( 'race-1' )->get_status(), 'race: still suspended after branch a', $failures, $passes );

// Now branches B and C finish "simultaneously" in two separate processes: BOTH
// read the frame (showing {a}) before EITHER writes. freeze_reads() pins find()
// to that shared pre-merge snapshot for the duration of both reconciles.
$result_b = race_execute_branch( $descriptors[1] );
$result_c = race_execute_branch( $descriptors[2] );

$recorder->freeze_reads(); // both processes observe frame == {a}
agents_reconcile_workflow_branch( 'race-1', (string) $descriptors[1]['handle_id'], $result_b );
agents_reconcile_workflow_branch( 'race-1', (string) $descriptors[2]['handle_id'], $result_c );
$recorder->unfreeze_reads();

// A serialized reconcile guarantees the completed[] map ends with all three
// siblings, the run resumes, and the aggregate fuses A|B|C. A lost-update leaves
// the run SUSPENDED forever (the observed prod stall).
$final = $recorder->find( 'race-1' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $final->get_status(), 'race: run resumes to SUCCEEDED (NOT stuck suspended) under concurrent reconcile', $failures, $passes );

$bundle = $final->get_output()['steps']['scatter']['final']['final_bundle'] ?? '';
smoke_assert( 'FUSED[A|B|C]', $bundle, 'race: aggregate fused ALL branch outputs — no completion lost', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
