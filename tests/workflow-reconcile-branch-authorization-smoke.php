<?php
/**
 * Pure-PHP regression test for two reconcile-branch hardening fixes.
 *
 * Run with: php tests/workflow-reconcile-branch-authorization-smoke.php
 *
 * FINDING 1 — self-asserted branch handle (register-reconcile-workflow-branch.php).
 * agents_reconcile_workflow_branch_locked() used to insert a `completed[handle_id]`
 * entry for ANY caller-supplied handle_id, with no check that the id (or its key)
 * belonged to the run's stored suspension frame. A forged handle id could inflate
 * the completed[] map until count(completed) reached count(handles), tripping the
 * all-terminal gate and resuming the run early with an ATTACKER-chosen aggregate
 * output — while a genuine branch had not yet finished. The fix binds the merge to
 * server-stored state: an unknown handle id (or a key that does not match the
 * stored handle) fails closed with a WP_Error and never touches completed[].
 *
 * FINDING 2 — fail-open reconcile lock (class-wp-agent-workflow-reconcile-lock.php).
 * WP_Agent_Workflow_Reconcile_Lock::with_lock() used to run the reconcile critical
 * section EVEN WHEN the per-run lock could not be acquired (acquire() returned
 * null), re-opening the very lost-update window the lock exists to close. The fix
 * makes it fail closed: when the lock is unavailable it returns a retryable
 * WP_Error and does NOT run the critical section, so the executor re-enqueues the
 * branch instead of performing an unguarded write.
 *
 * Both assertions FAIL against the pre-fix code and PASS after.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

// Neutralize the lock backoff BEFORE the lock class loads/runs so the fail-closed
// acquire loop spins instantly instead of sleeping for the full TTL window.
require_once __DIR__ . '/reconcile-lock-fail-closed-usleep-shim.php';

$failures = array();
$passes   = 0;

echo "workflow-reconcile-branch-authorization-smoke\n";

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

// Option-backed store shim modelling add_option()'s atomic-INSERT CAS contract:
// add_option() returns false when the option row already exists (unique key).
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
			return false;
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
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Reconcile_Lock;

use function AgentsAPI\AI\Workflows\agents_reconcile_workflow_branch;

final class Auth_Recorder implements WP_Agent_Workflow_Run_Recorder {
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
		return array_map( array( WP_Agent_Workflow_Run_Result::class, 'from_array' ), array_values( $this->rows ) );
	}
}

final class Auth_Executor implements \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Branch_Executor {
	/** @var array<int,array<string,mixed>> */
	public static array $dispatched = array();

	public function id(): string { return 'auth_exec'; }

	public function dispatch( array $branches, array $context ): array {
		self::$dispatched = array();
		$run_id  = (string) ( $context['_workflow_run_id'] ?? '' );
		$step_id = (string) ( $context['_workflow_step_id'] ?? '' );
		$handles = array();
		foreach ( $branches as $index => $branch ) {
			$key        = (string) ( $branch['key'] ?? (string) $index );
			$handle_id  = $run_id . ':' . $step_id . ':' . $key . ':' . $index;
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

function auth_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability( $name, array( 'execute_callback' => $handler ) );
}

auth_register_ability(
	'demo/role-worker',
	static function ( array $input ): array {
		return array( 'fragment' => strtoupper( (string) ( $input['label'] ?? 'X' ) ) );
	}
);
auth_register_ability(
	'demo/aggregate',
	static function ( array $input ): array {
		return array( 'final_bundle' => 'FUSED[' . (string) ( $input['a'] ?? '' ) . '|' . (string) ( $input['b'] ?? '' ) . ']' );
	}
);

// Two required sibling branches (a, b) + an aggregator. With exactly two handles,
// a single forged completion is enough to reach count parity and (pre-fix) resume
// the run before branch b has actually finished.
function auth_roles_spec(): WP_Agent_Workflow_Spec {
	return WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'demo/auth-roles',
			'steps' => array(
				array(
					'id'       => 'scatter',
					'type'     => 'parallel',
					'context'  => array( 'marker' => 'M' ),
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

function auth_execute_branch( array $descriptor ): array {
	$key      = (string) ( $descriptor['key'] ?? '' );
	$steps    = is_array( $descriptor['steps'] ?? null ) ? $descriptor['steps'] : array();
	$handlers = array(
		'ability'  => array( WP_Agent_Workflow_Runner::class, 'default_ability_handler' ),
		'agent'    => array( WP_Agent_Workflow_Runner::class, 'default_agent_handler' ),
		'foreach'  => array( WP_Agent_Workflow_Runner::class, 'default_foreach_handler' ),
		'parallel' => array( WP_Agent_Workflow_Runner::class, 'default_parallel_handler' ),
	);
	$executor    = new \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Step_Executor( $handlers );
	$branch_vars = is_array( $descriptor['branch_vars'] ?? null ) ? $descriptor['branch_vars'] : array();
	$branch_context = ( new \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Context(
		array( 'inputs' => array(), 'steps' => array(), 'vars' => array() )
	) )->with_vars( $branch_vars );

	$run = WP_Agent_Workflow_Runner::run_branch_steps( $steps, $branch_context, $executor, $handlers, false, $key );
	if ( is_wp_error( $run ) ) {
		return array( 'key' => $key, 'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED, 'output' => null, 'steps' => array(), 'error' => array( 'code' => $run->get_error_code(), 'message' => $run->get_error_message() ) );
	}
	return array( 'key' => $key, 'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, 'output' => $run['last'], 'steps' => $run['steps'], 'error' => null );
}

function auth_completed_count( Auth_Recorder $recorder, string $run_id ): int {
	$result = $recorder->find( $run_id );
	if ( null === $result ) {
		return -1;
	}
	$suspension = $result->get_suspension();
	$completed  = is_array( $suspension['completed'] ?? null ) ? $suspension['completed'] : array();
	return count( $completed );
}

// ═════════════════════════════════════════════════════════════════════════════
// FINDING 1: a forged / unknown handle id must NOT inflate completed[] nor resume
// the run early with attacker-chosen output.
// ═════════════════════════════════════════════════════════════════════════════

$GLOBALS['__options'] = array();
$recorder = new Auth_Recorder();
remove_all_filters( 'wp_agent_workflow_run_recorder' );
remove_all_filters( 'wp_agent_workflow_step_executor' );
remove_all_filters( 'wp_agent_workflow_resume_dispatch' );
add_filter( 'wp_agent_workflow_run_recorder', static function () use ( $recorder ) { return $recorder; } );
add_filter( 'wp_agent_workflow_step_executor', static function () { return new Auth_Executor(); } );

$run = ( new WP_Agent_Workflow_Runner( $recorder ) )->run( auth_roles_spec(), array(), array( 'run_id' => 'auth-1' ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run->get_status(), 'auth: run SUSPENDED after dispatch', $failures, $passes );

$descriptors = Auth_Executor::$dispatched; // two siblings: a, b
smoke_assert( 2, count( $descriptors ), 'auth: two sibling branches dispatched', $failures, $passes );

// Branch a finishes legitimately. completed == {a}, still suspended.
$result_a = auth_execute_branch( $descriptors[0] );
agents_reconcile_workflow_branch( 'auth-1', (string) $descriptors[0]['handle_id'], $result_a );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $recorder->find( 'auth-1' )->get_status(), 'auth: still suspended after branch a', $failures, $passes );
smoke_assert( 1, auth_completed_count( $recorder, 'auth-1' ), 'auth: completed has exactly branch a', $failures, $passes );

// ATTACK: forge a completion under a handle id that was never dispatched, claiming
// branch b's key + an attacker output. Pre-fix this would push completed to 2/2,
// trip the all-terminal gate and resume the run to SUCCEEDED with the forged
// aggregate. Post-fix it fails closed and leaves the frame untouched.
$forged = agents_reconcile_workflow_branch(
	'auth-1',
	'FORGED-handle-never-dispatched',
	array( 'key' => 'b', 'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, 'output' => array( 'fragment' => 'HACK' ) )
);
smoke_assert_true( is_wp_error( $forged ), 'auth: forged handle id rejected with WP_Error', $failures, $passes );
smoke_assert( 'agents_reconcile_workflow_branch_unknown_handle', is_wp_error( $forged ) ? $forged->get_error_code() : '', 'auth: rejection uses unknown_handle code', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $recorder->find( 'auth-1' )->get_status(), 'auth: run STILL suspended after forged reconcile (not resumed early)', $failures, $passes );
smoke_assert( 1, auth_completed_count( $recorder, 'auth-1' ), 'auth: forged handle did NOT inflate completed[]', $failures, $passes );

// ATTACK 2: use branch b's REAL handle id but remap its output onto a different
// aggregate key. Post-fix this fails closed on the stored-key binding.
$mismatch = agents_reconcile_workflow_branch(
	'auth-1',
	(string) $descriptors[1]['handle_id'],
	array( 'key' => 'a', 'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, 'output' => array( 'fragment' => 'HACK' ) )
);
smoke_assert_true( is_wp_error( $mismatch ), 'auth: key-remapped reconcile rejected with WP_Error', $failures, $passes );
smoke_assert( 'agents_reconcile_workflow_branch_key_mismatch', is_wp_error( $mismatch ) ? $mismatch->get_error_code() : '', 'auth: rejection uses key_mismatch code', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $recorder->find( 'auth-1' )->get_status(), 'auth: run STILL suspended after key-mismatch reconcile', $failures, $passes );

// LEGITIMATE completion of branch b with its real handle + key resumes the run and
// fuses the GENUINE outputs — proving the fix does not break real callers.
$result_b = auth_execute_branch( $descriptors[1] );
agents_reconcile_workflow_branch( 'auth-1', (string) $descriptors[1]['handle_id'], $result_b );
$final = $recorder->find( 'auth-1' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $final->get_status(), 'auth: legitimate branch b resumes run to SUCCEEDED', $failures, $passes );
$bundle = $final->get_output()['steps']['scatter']['final']['final_bundle'] ?? '';
smoke_assert( 'FUSED[A|B]', $bundle, 'auth: aggregate fused the GENUINE branch outputs (no HACK)', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════════
// FINDING 2: when the per-run reconcile lock cannot be acquired, with_lock() must
// FAIL CLOSED (retryable WP_Error) and must NOT run the critical section.
// ═════════════════════════════════════════════════════════════════════════════

$GLOBALS['__options'] = array();
$lock_run_id = 'lock-contended';

// Pre-occupy the lock row with a live (non-expired) holder so acquire() can never
// win nor reclaim it — modelling a genuinely contended lock.
$lock_option = 'agents_wf_reconcile_lock_' . md5( $lock_run_id );
$GLOBALS['__options'][ $lock_option ] = array(
	'token'   => 'held-by-another-process',
	'expires' => time() + 3600,
);

$critical_ran = false;
$result = WP_Agent_Workflow_Reconcile_Lock::with_lock(
	$lock_run_id,
	static function () use ( &$critical_ran ) {
		$critical_ran = true;
		return 'CRITICAL-RAN';
	}
);

smoke_assert( false, $critical_ran, 'lock: critical section NOT run when lock unavailable', $failures, $passes );
smoke_assert_true( is_wp_error( $result ), 'lock: with_lock returns WP_Error when lock unavailable', $failures, $passes );
smoke_assert( 'agents_reconcile_lock_unavailable', is_wp_error( $result ) ? $result->get_error_code() : '', 'lock: fail-closed error uses lock_unavailable code', $failures, $passes );

// The pre-existing holder's lock must be untouched (not stolen/deleted by the loser).
smoke_assert_true( array_key_exists( $lock_option, $GLOBALS['__options'] ) && 'held-by-another-process' === ( $GLOBALS['__options'][ $lock_option ]['token'] ?? '' ), 'lock: existing holder lock left intact', $failures, $passes );

// Sanity: an UNCONTENDED with_lock still runs the critical section and releases.
$GLOBALS['__options'] = array();
$ran2 = false;
$ok   = WP_Agent_Workflow_Reconcile_Lock::with_lock(
	'lock-free',
	static function () use ( &$ran2 ) {
		$ran2 = true;
		return 'OK';
	}
);
smoke_assert( true, $ran2, 'lock: uncontended critical section runs', $failures, $passes );
smoke_assert( 'OK', $ok, 'lock: uncontended with_lock returns the critical result', $failures, $passes );
smoke_assert( false, array_key_exists( 'agents_wf_reconcile_lock_' . md5( 'lock-free' ), $GLOBALS['__options'] ), 'lock: lock released after uncontended run', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
