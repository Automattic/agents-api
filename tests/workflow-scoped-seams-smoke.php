<?php
/**
 * Pure-PHP smoke test for the runtime-scoped workflow seams (#567).
 *
 * Two consumers on one site used to collide: the recorder filter carried no
 * arguments, so the first hook to return a recorder owned EVERY suspended run
 * on the site, and `agents/run-workflow` dispatched "first callable wins".
 *
 * This test drives the REAL runner + REAL AS branch executor + REAL
 * reconcile/aggregate/resume state machine (only Action Scheduler itself is
 * shimmed) against TWO fake recorders registered under TWO runtime keys, with
 * interleaved suspended runs, and asserts:
 *
 *   1. The `runtime` run option is stamped onto the run (metadata._runtime),
 *      the suspension frame, and every AS continuation payload (branch,
 *      aggregate, resume).
 *   2. Each suspended run resolves ONLY its owner's recorder — the other
 *      recorder never sees the foreign run_id — and each run completes on its
 *      own store.
 *   3. A legacy run/payload with NO runtime key still resolves through the old
 *      zero-argument filter contract, so in-flight runs suspended before the
 *      upgrade still reconcile.
 *   4. `agents/run-workflow` routes by the owning runtime: an explicit
 *      `runtime` input or a spec `meta['runtime']` selects exactly that
 *      runtime's handler from `wp_agent_workflow_runtime_handlers`; an
 *      unattributed dispatch requires exactly one registered runtime
 *      (zero is `no_handler`, two-or-more is
 *      `agents_run_workflow_ambiguous_runtime`).
 *
 * Run with: php tests/workflow-scoped-seams-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-scoped-seams-smoke\n";

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
	function get_option( string $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $option ] : $default;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, $value = '', $deprecated = '', $autoload = null ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $GLOBALS['__options'] ) ) {
			return false;
		}
		$GLOBALS['__options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
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
if ( ! function_exists( 'wp_json_encode_shim' ) ) {
	function wp_json_encode_shim( $value ): string {
		$encoded = json_encode( $value );
		return false === $encoded ? '' : $encoded;
	}
}

// ── Action Scheduler shim: durable queue with atomic claim + unique enqueue, ────
// matching the surface the AS branch executor detects at runtime (4-param
// as_enqueue_async_action + as_has_scheduled_action), so the real deferred
// resume path is exercised, not an inline fallback.

final class Scoped_AS_Shim {
	/** @var array<int,array{id:int,hook:string,args:array<mixed>,group:string}> */
	public static array $queue = array();
	/** @var array<int,bool> */
	public static array $claimed = array();
	/** @var array<int,bool> */
	public static array $cancelled = array();
	private static int $seq = 0;

	public static function reset(): void {
		self::$queue     = array();
		self::$claimed   = array();
		self::$cancelled = array();
		self::$seq       = 0;
	}

	public static function enqueue( string $hook, array $args, string $group ): int {
		$id            = ++self::$seq;
		self::$queue[] = array(
			'id'    => $id,
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		);
		return $id;
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

	public static function fire( int $id ): bool {
		if ( ! empty( self::$cancelled[ $id ] ) ) {
			return false;
		}
		if ( ! empty( self::$claimed[ $id ] ) ) {
			return false;
		}
		self::$claimed[ $id ] = true;
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
	function as_enqueue_async_action( string $hook, array $args = array(), string $group = '', bool $unique = false ) {
		if ( $unique ) {
			foreach ( Scoped_AS_Shim::$queue as $action ) {
				if (
					$action['hook'] === $hook
					&& $action['group'] === $group
					&& $action['args'] === $args
					&& empty( Scoped_AS_Shim::$cancelled[ $action['id'] ] )
				) {
					return 0;
				}
			}
		}
		return Scoped_AS_Shim::enqueue( $hook, $args, $group );
	}
}
if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( string $hook, ?array $args = null, string $group = '' ): bool {
		foreach ( Scoped_AS_Shim::$queue as $action ) {
			if ( $action['hook'] !== $hook || $action['group'] !== $group || ! empty( Scoped_AS_Shim::$cancelled[ $action['id'] ] ) ) {
				continue;
			}
			if ( null === $args || $action['args'] === $args ) {
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ) {
		unset( $timestamp );
		return Scoped_AS_Shim::enqueue( $hook, $args, $group );
	}
}
if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( string $hook, ?array $args = array(), string $group = '' ): void {
		foreach ( Scoped_AS_Shim::$queue as $action ) {
			if ( $action['hook'] === $hook && $action['group'] === $group && ( null === $args || $action['args'] === $args ) ) {
				Scoped_AS_Shim::$cancelled[ $action['id'] ] = true;
			}
		}
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
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-type-registry.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-result.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-store.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-recorder.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-registry.php';
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

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Branch_Executor;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Store;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Registry;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

use function AgentsAPI\AI\Workflows\agents_run_workflow_dispatch;
use function AgentsAPI\AI\Workflows\agents_workflow_resolve_recorder;
use function AgentsAPI\AI\Workflows\register_workflow_runtime_handler;

/**
 * A recorder that tracks EVERY find()/start()/update() call so the test can
 * prove a recorder never touched a run it does not own.
 */
final class Scoped_Smoke_Recorder implements WP_Agent_Workflow_Run_Recorder {
	/** @var array<string,array<string,mixed>> */
	public array $rows = array();
	/** @var array<int,array{op:string,run_id:string}> */
	public array $calls = array();

	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$this->calls[] = array( 'op' => 'start', 'run_id' => $result->get_run_id() );
		$this->rows[ $result->get_run_id() ] = $result->to_array();
		return $result->get_run_id();
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$this->calls[] = array( 'op' => 'update', 'run_id' => $result->get_run_id() );
		$this->rows[ $result->get_run_id() ] = $result->to_array();
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result {
		$this->calls[] = array( 'op' => 'find', 'run_id' => $run_id );
		return isset( $this->rows[ $run_id ] )
			? WP_Agent_Workflow_Run_Result::from_array( $this->rows[ $run_id ] )
			: null;
	}
	public function recent( array $args = array() ): array {
		unset( $args );
		return array_map( array( WP_Agent_Workflow_Run_Result::class, 'from_array' ), array_values( $this->rows ) );
	}

	/** @return array<string> Every distinct run_id this recorder was asked about. */
	public function touched_run_ids(): array {
		return array_values( array_unique( array_column( $this->calls, 'run_id' ) ) );
	}
}

function scoped_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability( $name, array( 'execute_callback' => $handler ) );
}

scoped_register_ability(
	'demo/role-worker',
	static function ( array $input ): array {
		return array( 'fragment' => strtoupper( (string) ( $input['label'] ?? 'X' ) ) );
	}
);
scoped_register_ability(
	'demo/aggregate',
	static function ( array $input ): array {
		return array( 'final_bundle' => 'FUSED[' . (string) ( $input['a'] ?? '' ) . '|' . (string) ( $input['b'] ?? '' ) . ']' );
	}
);

function scoped_roles_spec(): WP_Agent_Workflow_Spec {
	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/scoped-roles',
			'steps' => array(
				array(
					'id'       => 'scatter',
					'type'     => 'parallel',
					'branches' => array(
						array( 'role' => 'a', 'required' => true, 'is_aggregator' => false, 'steps' => array( array( 'id' => 'sa', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => 'a' ) ) ) ),
						array( 'role' => 'b', 'required' => true, 'is_aggregator' => false, 'steps' => array( array( 'id' => 'sb', 'type' => 'ability', 'ability' => 'demo/role-worker', 'args' => array( 'label' => 'b' ) ) ) ),
						array(
							'role'          => 'fuse',
							'required'      => true,
							'is_aggregator' => true,
							'steps'         => array(
								array(
									'id'      => 'agg',
									'type'    => 'ability',
									'ability' => 'demo/aggregate',
									'args'    => array(
										'a' => '${vars.branch_outputs.a.fragment}',
										'b' => '${vars.branch_outputs.b.fragment}',
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

/**
 * @param array<int,array{id:int,hook:string,args:array<mixed>,group:string}> $actions
 * @return array<int,array<mixed>> Payloads.
 */
function scoped_payloads( array $actions ): array {
	$out = array();
	foreach ( $actions as $action ) {
		$out[] = is_array( $action['args'][0] ?? null ) ? $action['args'][0] : array();
	}
	return $out;
}

/** Fire every pending action of one hook whose payload run_id matches. */
function scoped_fire_for_run( string $hook, string $run_id ): int {
	$fired = 0;
	foreach ( Scoped_AS_Shim::actions_for( $hook ) as $action ) {
		$payload = is_array( $action['args'][0] ?? null ) ? $action['args'][0] : array();
		if ( ( $payload['run_id'] ?? '' ) === $run_id && Scoped_AS_Shim::fire( $action['id'] ) ) {
			++$fired;
		}
	}
	return $fired;
}

/** Fire exactly ONE pending action of one hook whose payload run_id matches. */
function scoped_fire_one( string $hook, string $run_id ): bool {
	foreach ( Scoped_AS_Shim::actions_for( $hook ) as $action ) {
		if ( ! empty( Scoped_AS_Shim::$claimed[ $action['id'] ] ) ) {
			continue;
		}
		$payload = is_array( $action['args'][0] ?? null ) ? $action['args'][0] : array();
		if ( ( $payload['run_id'] ?? '' ) === $run_id ) {
			return Scoped_AS_Shim::fire( $action['id'] );
		}
	}
	return false;
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. TWO SCOPED RECORDERS, TWO RUNTIME KEYS, INTERLEAVED SUSPENDED RUNS.
// ═════════════════════════════════════════════════════════════════════════════

Scoped_AS_Shim::reset();
$GLOBALS['__options'] = array();
$recorder_a           = new Scoped_Smoke_Recorder();
$recorder_b           = new Scoped_Smoke_Recorder();

remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter(
	'wp_agent_workflow_run_recorder',
	static function ( $recorder, string $runtime, string $run_id ) use ( $recorder_a, $recorder_b ) {
		unset( $run_id );
		if ( 'runtime_a' === $runtime ) {
			return $recorder_a;
		}
		if ( 'runtime_b' === $runtime ) {
			return $recorder_b;
		}
		return $recorder; // null — foreign/unattributed runs are NOT claimed.
	},
	10,
	3
);

// Direct resolution unit: each runtime key resolves its owner, '' resolves none.
smoke_assert( $recorder_a, agents_workflow_resolve_recorder( 'runtime_a', 'any-run' ), 'scoped: runtime_a resolves recorder A', $failures, $passes );
smoke_assert( $recorder_b, agents_workflow_resolve_recorder( 'runtime_b', 'any-run' ), 'scoped: runtime_b resolves recorder B', $failures, $passes );
smoke_assert( null, agents_workflow_resolve_recorder( '', 'any-run' ), 'scoped: unattributed runtime resolves NO scoped recorder', $failures, $passes );

// Run A starts with runtime_a, suspends against its fan-out.
$run_a = ( new WP_Agent_Workflow_Runner( $recorder_a ) )->run( scoped_roles_spec(), array(), array( 'run_id' => 'scope-a', 'runtime' => 'runtime_a' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run_a->get_status(), 'scoped: run A (runtime_a) SUSPENDED', $failures, $passes );

// Run B starts INTERLEAVED with runtime_b, suspends too.
$run_b = ( new WP_Agent_Workflow_Runner( $recorder_b ) )->run( scoped_roles_spec(), array(), array( 'run_id' => 'scope-b', 'runtime' => 'runtime_b' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run_b->get_status(), 'scoped: run B (runtime_b) SUSPENDED', $failures, $passes );

// The runtime key is stamped onto the run record and the suspension frame.
$stored_a = $recorder_a->rows['scope-a'];
smoke_assert( 'runtime_a', $stored_a['metadata']['_runtime'] ?? '', 'scoped: run A metadata carries _runtime', $failures, $passes );
smoke_assert( 'runtime_a', $stored_a['metadata']['_suspension']['runtime'] ?? '', 'scoped: run A suspension frame carries runtime', $failures, $passes );
smoke_assert( 'runtime_b', $recorder_b->rows['scope-b']['metadata']['_runtime'] ?? '', 'scoped: run B metadata carries _runtime', $failures, $passes );

// Every BRANCH payload carries its own run's runtime key.
$branch_payloads = scoped_payloads( Scoped_AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK ) );
smoke_assert( 4, count( $branch_payloads ), 'scoped: two branch actions enqueued per run', $failures, $passes );
$a_payloads = array_filter( $branch_payloads, static fn( $p ) => ( $p['run_id'] ?? '' ) === 'scope-a' );
$b_payloads = array_filter( $branch_payloads, static fn( $p ) => ( $p['run_id'] ?? '' ) === 'scope-b' );
smoke_assert( 2, count( array_filter( $a_payloads, static fn( $p ) => ( $p['runtime'] ?? null ) === 'runtime_a' ) ), 'scoped: run A branch payloads carry runtime_a', $failures, $passes );
smoke_assert( 2, count( array_filter( $b_payloads, static fn( $p ) => ( $p['runtime'] ?? null ) === 'runtime_b' ) ), 'scoped: run B branch payloads carry runtime_b', $failures, $passes );

// INTERLEAVED drain: ONE branch of A, then ONE of B, then the others. Each
// branch action must reconcile through ONLY its owner's recorder.
scoped_fire_one( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK, 'scope-a' );
smoke_assert( 1, count( $recorder_a->rows['scope-a']['metadata']['_suspension']['completed'] ?? array() ), 'scoped: run A first branch merged into recorder A', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $recorder_a->find( 'scope-a' )->get_status(), 'scoped: run A still SUSPENDED after one branch', $failures, $passes );

scoped_fire_one( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK, 'scope-b' );
smoke_assert( 1, count( $recorder_b->rows['scope-b']['metadata']['_suspension']['completed'] ?? array() ), 'scoped: run B first branch merged into recorder B', $failures, $passes );

// Ownership: each recorder was asked ONLY about its own run so far.
smoke_assert( array( 'scope-a' ), $recorder_a->touched_run_ids(), 'scoped: recorder A touched ONLY its own run', $failures, $passes );
smoke_assert( array( 'scope-b' ), $recorder_b->touched_run_ids(), 'scoped: recorder B touched ONLY its own run', $failures, $passes );

// Finish A: last branch → aggregate continuation (runtime_a payload) → resume.
scoped_fire_for_run( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK, 'scope-a' );
$aggregate_payloads = scoped_payloads( Scoped_AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::AGGREGATE_HOOK ) );
smoke_assert( 'runtime_a', $aggregate_payloads[0]['runtime'] ?? '', 'scoped: aggregate payload carries the owning runtime', $failures, $passes );
scoped_fire_for_run( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::AGGREGATE_HOOK, 'scope-a' );
$resume_payloads = scoped_payloads( Scoped_AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK ) );
smoke_assert( 'runtime_a', $resume_payloads[0]['runtime'] ?? '', 'scoped: resume payload carries the owning runtime', $failures, $passes );
scoped_fire_for_run( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK, 'scope-a' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $recorder_a->find( 'scope-a' )->get_status(), 'scoped: run A SUCCEEDED through its OWN recorder', $failures, $passes );
smoke_assert( 'FUSED[A|B]', $recorder_a->find( 'scope-a' )->get_output()['steps']['scatter']['final']['final_bundle'] ?? '', 'scoped: run A aggregate fused both branch outputs', $failures, $passes );

// Finish B the same way, through runtime_b.
scoped_fire_for_run( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK, 'scope-b' );
scoped_fire_for_run( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::AGGREGATE_HOOK, 'scope-b' );
scoped_fire_for_run( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK, 'scope-b' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $recorder_b->find( 'scope-b' )->get_status(), 'scoped: run B SUCCEEDED through its OWN recorder', $failures, $passes );

// Final ownership audit across the WHOLE interleaved lifecycle.
smoke_assert( array( 'scope-a' ), $recorder_a->touched_run_ids(), 'scoped: recorder A NEVER touched run B (no cross-reconcile)', $failures, $passes );
smoke_assert( array( 'scope-b' ), $recorder_b->touched_run_ids(), 'scoped: recorder B NEVER touched run A (no cross-reconcile)', $failures, $passes );
smoke_assert( 0, count( array_diff( array_keys( $recorder_b->rows ), array( 'scope-b' ) ) ), 'scoped: recorder B holds no foreign rows', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 2. LEGACY BACK-COMPAT: a run + payloads with NO runtime key still resolve
//    through the old zero-argument filter contract, so already-suspended
//    in-flight runs from before the upgrade still complete.
// ═════════════════════════════════════════════════════════════════════════════

Scoped_AS_Shim::reset();
$GLOBALS['__options'] = array();
$recorder_legacy      = new Scoped_Smoke_Recorder();

remove_all_filters( 'wp_agent_workflow_run_recorder' );
remove_all_filters( 'wp_agent_workflow_resume_dispatch' );
// The OLD contract: a one-argument hook that owns every run on the site.
add_filter(
	'wp_agent_workflow_run_recorder',
	static function ( $recorder ) use ( $recorder_legacy ) {
		unset( $recorder );
		return $recorder_legacy;
	},
	10,
	1
);

$legacy_run = ( new WP_Agent_Workflow_Runner( $recorder_legacy ) )->run( scoped_roles_spec(), array(), array( 'run_id' => 'legacy-1' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $legacy_run->get_status(), 'legacy: run WITHOUT a runtime option SUSPENDED', $failures, $passes );
smoke_assert( '', $recorder_legacy->rows['legacy-1']['metadata']['_runtime'] ?? '', 'legacy: no _runtime stamped on an unattributed run', $failures, $passes );

$legacy_actions = Scoped_AS_Shim::actions_for( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK );
$legacy_payload = $legacy_actions[0]['args'][0];

// New code always writes the key (as ''). Simulate the PRE-UPGRADE payload
// shape — no runtime key at all — and prove the branch action still reconciles
// through the legacy hook. The branch store stays intact so the action can
// rehydrate the descriptor exactly as it would in production.
unset( $legacy_payload['runtime'] );
smoke_assert( false, array_key_exists( 'runtime', $legacy_payload ), 'legacy: payload reshaped to the pre-upgrade (keyless) form', $failures, $passes );
WP_Agent_Workflow_Action_Scheduler_Branch_Executor::run_branch_action( $legacy_payload );
Scoped_AS_Shim::$claimed[ $legacy_actions[0]['id'] ] = true; // the manual run consumed this action.
smoke_assert( 1, count( $recorder_legacy->rows['legacy-1']['metadata']['_suspension']['completed'] ?? array() ), 'legacy: KEYLESS branch payload reconciles through the old filter contract', $failures, $passes );

scoped_fire_for_run( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK, 'legacy-1' );
scoped_fire_for_run( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::AGGREGATE_HOOK, 'legacy-1' );
scoped_fire_for_run( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK, 'legacy-1' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $recorder_legacy->find( 'legacy-1' )->get_status(), 'legacy: unattributed run SUCCEEDED end-to-end (in-flight upgrade safety)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// 3. HANDLER DISPATCH ROUTES BY OWNING RUNTIME, not first-callable-wins.
// ═════════════════════════════════════════════════════════════════════════════

WP_Agent_Workflow_Registry::reset();
remove_all_filters( 'wp_agent_workflow_runtime_handlers' );

$handled_by = array();
register_workflow_runtime_handler(
	'runtime_a',
	static function ( array $input ) use ( &$handled_by ) {
		$handled_by[] = 'a';
		return array( 'run_id' => 'h-a', 'workflow_id' => (string) ( $input['workflow_id'] ?? '' ), 'status' => 'succeeded', 'runtime' => $input['runtime'] ?? '' );
	}
);
register_workflow_runtime_handler(
	'runtime_b',
	static function ( array $input ) use ( &$handled_by ) {
		$handled_by[] = 'b';
		return array( 'run_id' => 'h-b', 'workflow_id' => (string) ( $input['workflow_id'] ?? '' ), 'status' => 'succeeded', 'runtime' => $input['runtime'] ?? '' );
	}
);

// Explicit runtime input routes to that runtime's handler — NOT the other one.
$handled_by = array();
$result     = agents_run_workflow_dispatch( array( 'workflow_id' => null, 'spec' => array( 'id' => 'x', 'steps' => array() ), 'runtime' => 'runtime_a' ) );
smoke_assert( array( 'a' ), $handled_by, 'dispatch: explicit runtime routes to its OWN handler (sibling handler skipped)', $failures, $passes );
smoke_assert( 'runtime_a', is_array( $result ) ? ( $result['runtime'] ?? '' ) : '', 'dispatch: handler received the canonical input with runtime', $failures, $passes );

$handled_by = array();
agents_run_workflow_dispatch( array( 'workflow_id' => null, 'spec' => array( 'id' => 'x', 'steps' => array() ), 'runtime' => 'runtime_b' ) );
smoke_assert( array( 'b' ), $handled_by, 'dispatch: second runtime routes to ITS handler (no first-callable-wins)', $failures, $passes );

// Spec meta.runtime routes a workflow_id dispatch without an explicit runtime.
WP_Agent_Workflow_Registry::register(
	array(
		'id'   => 'demo/owned-by-a',
		'meta' => array( 'runtime' => 'runtime_a' ),
		'steps' => array( array( 'id' => 's', 'type' => 'ability', 'ability' => 'demo/role-worker' ) ),
	)
);
$handled_by = array();
agents_run_workflow_dispatch( array( 'workflow_id' => 'demo/owned-by-a' ) );
smoke_assert( array( 'a' ), $handled_by, 'dispatch: spec meta.runtime routes the workflow_id to its owner', $failures, $passes );

// Unattributed dispatch (no runtime anywhere) is ambiguous when MORE THAN ONE
// runtime is registered on the site (#572) — no first-callable-wins fallback.
$handled_by         = array();
$ambiguous_dispatch = agents_run_workflow_dispatch( array( 'workflow_id' => 'demo/not-registered', 'spec' => null ) );
smoke_assert( array(), $handled_by, 'dispatch: unattributed dispatch with two registered runtimes calls neither handler', $failures, $passes );
smoke_assert( true, $ambiguous_dispatch instanceof \WP_Error, 'dispatch: unattributed dispatch with two registered runtimes => WP_Error', $failures, $passes );
smoke_assert(
	'agents_run_workflow_ambiguous_runtime',
	$ambiguous_dispatch instanceof \WP_Error ? $ambiguous_dispatch->get_error_code() : '',
	'dispatch: ambiguous runtime error code lists no single winner',
	$failures,
	$passes
);

// Explicit runtime with no scoped entry is a `no_handler` error — no fallback
// to any other registered handler.
$handled_by      = array();
$unknown_runtime = agents_run_workflow_dispatch( array( 'workflow_id' => null, 'spec' => array( 'id' => 'x', 'steps' => array() ), 'runtime' => 'runtime_nobody' ) );
smoke_assert( array(), $handled_by, 'dispatch: unknown runtime calls no handler', $failures, $passes );
smoke_assert(
	'agents_run_workflow_no_handler',
	$unknown_runtime instanceof \WP_Error ? $unknown_runtime->get_error_code() : '',
	'dispatch: unknown runtime => no_handler (no fallback)',
	$failures,
	$passes
);

// Unattributed dispatch with EXACTLY ONE registered runtime dispatches to it.
remove_all_filters( 'wp_agent_workflow_runtime_handlers' );
$handled_by = array();
register_workflow_runtime_handler(
	'runtime_a',
	static function ( array $input ) use ( &$handled_by ) {
		$handled_by[] = 'a';
		return array( 'run_id' => 'h-a-solo', 'workflow_id' => (string) ( $input['workflow_id'] ?? '' ), 'status' => 'succeeded' );
	}
);
agents_run_workflow_dispatch( array( 'workflow_id' => 'demo/not-registered', 'spec' => null ) );
smoke_assert( array( 'a' ), $handled_by, 'dispatch: unattributed dispatch with exactly ONE registered runtime dispatches to it', $failures, $passes );

// Unattributed dispatch with ZERO registered runtimes is `no_handler`.
remove_all_filters( 'wp_agent_workflow_runtime_handlers' );
$zero_result = agents_run_workflow_dispatch( array( 'workflow_id' => 'demo/not-registered', 'spec' => null ) );
smoke_assert(
	'agents_run_workflow_no_handler',
	$zero_result instanceof \WP_Error ? $zero_result->get_error_code() : '',
	'dispatch: unattributed dispatch with zero registered runtimes => no_handler',
	$failures,
	$passes
);

// A properly scoped hook yields its recorder ONLY for its own key.
remove_all_filters( 'wp_agent_workflow_run_recorder' );
add_filter(
	'wp_agent_workflow_run_recorder',
	static function ( $recorder, string $runtime ) use ( $recorder_a ) {
		return 'runtime_a' === $runtime ? $recorder_a : null;
	},
	10,
	2
);
smoke_assert( null, agents_workflow_resolve_recorder( 'runtime_other', 'some-run' ), 'scoped: a scoped hook yields null for foreign runtimes', $failures, $passes );
smoke_assert( $recorder_a, agents_workflow_resolve_recorder( 'runtime_a', 'some-run' ), 'scoped: a scoped hook yields its recorder for its own runtime', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
