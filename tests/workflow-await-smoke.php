<?php
/**
 * Pure-PHP smoke test for the `await` primitive (agents-api#577): a generic
 * "park a workflow step on an external durable job" wait, completed exactly
 * once via {@see \AgentsAPI\AI\Workflows\agents_workflow_complete_wait()}.
 *
 * Run with: php tests/workflow-await-smoke.php
 *
 * Drives the REAL runner, reconcile lock, and completion path — no shape
 * mocks. Action Scheduler and the clock are faked deterministically (no
 * sleeps): `as_schedule_single_action()` records its payload and the test
 * "fires" a timeout by invoking the captured payload through the same fake
 * `do_action()` dispatcher the production hook is registered on.
 *
 * Covers every acceptance bullet from the issue:
 *   - a handler suspends with `await`; `complete_wait(succeeded, output)`
 *     resumes and the next step sees the output through
 *     `${steps.<id>.output}` bindings;
 *   - duplicate completion → one resume (a second call is a harmless no-op);
 *   - wrong wait_id → WP_Error; unknown run_id → WP_Error;
 *   - `failed` completion → step failed, normal failure semantics
 *     (including `continue_on_error`);
 *   - a scheduled timeout fires → failed with `workflow_wait_timeout`;
 *   - a completion racing its own timeout → exactly one outcome, proven at
 *     the exact-once claim boundary (before resume ever runs), not just via
 *     the coarser "already terminal" guard;
 *   - cancelling a suspended wait terminalizes cancelled, and a later
 *     completion is a no-op;
 *   - the owning runtime key (#567) is carried on the frame and rides the
 *     timeout payload, and recorder resolution is scoped to it.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-await-smoke\n";

// ── Minimal WordPress shims (mirrors tests/workflow-reconcile-race-smoke.php) ──

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

$GLOBALS['__filters']         = array();
$GLOBALS['__abilities']       = array();
$GLOBALS['__options']         = array();
$GLOBALS['__scheduled_as']    = array();
$GLOBALS['__current_user_can'] = true;

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
	function current_user_can( $cap ): bool { unset( $cap ); return $GLOBALS['__current_user_can']; }
}

// Option-backed store shim — the default reconcile lock's add_option() CAS.
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

// Deterministic fake Action Scheduler: no real scheduling, no sleeps. The test
// "fires" a captured payload by invoking do_action() on the same hook the
// production code registered its callback on.
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group = '' ): int {
		$GLOBALS['__scheduled_as'][] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
		);
		return count( $GLOBALS['__scheduled_as'] );
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
require_once __DIR__ . '/../src/Workflows/register-workflow-step-types.php';
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
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';
require_once __DIR__ . '/../src/Workflows/register-agents-workflow-abilities.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-reconcile-lock.php';
require_once __DIR__ . '/../src/Workflows/register-reconcile-workflow-branch.php';
require_once __DIR__ . '/../src/Workflows/register-workflow-await.php';

use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

use function AgentsAPI\AI\Workflows\agents_workflow_complete_wait;
use function AgentsAPI\AI\Workflows\agents_workflow_complete_wait_locked;

const AWAIT_SMOKE_RUNTIME = 'demo-runtime';

final class Await_Recorder implements WP_Agent_Workflow_Run_Recorder {
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
		return isset( $this->rows[ $run_id ] ) ? WP_Agent_Workflow_Run_Result::from_array( $this->rows[ $run_id ] ) : null;
	}
	public function recent( array $args = array() ): array {
		unset( $args );
		return array_map( array( WP_Agent_Workflow_Run_Result::class, 'from_array' ), array_values( $this->rows ) );
	}
}

function await_register_ability( string $name, \Closure $handler ): void {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability( $name, array( 'execute_callback' => $handler ) );
}

\AgentsAPI\AI\Workflows\register_workflow_step_type(
	'waiting',
	array(
		'handler' => static function ( array $step, array $context ) {
			unset( $context );
			$directive = array(
				'kind'    => 'await',
				'wait_id' => (string) ( $step['wait_id'] ?? 'wait-default' ),
			);
			if ( isset( $step['timeout_at'] ) ) {
				$directive['timeout_at'] = $step['timeout_at'];
			}
			return array( '_suspend' => $directive );
		},
	)
);

$GLOBALS['__echo_calls'] = 0;
await_register_ability(
	'demo/echo-output',
	static function ( array $input ): array {
		++$GLOBALS['__echo_calls'];
		return array( 'value' => $input['value'] ?? null );
	}
);

/** @return array<int,array<string,mixed>> */
function await_steps(): array {
	return array(
		array( 'id' => 'wait_step', 'type' => 'waiting' ),
		array(
			'id'      => 'after',
			'type'    => 'ability',
			'ability' => 'demo/echo-output',
			'args'    => array( 'value' => '${steps.wait_step.output.value}' ),
		),
	);
}

function await_spec(): WP_Agent_Workflow_Spec {
	$spec = WP_Agent_Workflow_Spec::from_array(
		array(
			'id'    => 'test/await',
			'steps' => await_steps(),
		)
	);
	if ( is_wp_error( $spec ) ) {
		throw new \RuntimeException( 'invalid test spec: ' . $spec->get_error_message() );
	}
	return $spec;
}

/**
 * A 'waiting' step handler: always suspends with the `await` directive named
 * by the step's own `wait_id` (+ optional `timeout_at`).
 */
function await_handlers(): array {
	return array(
		'ability' => array( WP_Agent_Workflow_Runner::class, 'default_ability_handler' ),
		'waiting' => static function ( array $step, array $context ) {
			unset( $context );
			$directive = array(
				'kind'    => 'await',
				'wait_id' => (string) ( $step['wait_id'] ?? 'wait-default' ),
			);
			if ( isset( $step['timeout_at'] ) ) {
				$directive['timeout_at'] = $step['timeout_at'];
			}
			return array( '_suspend' => $directive );
		},
	);
}

/** Register the scoped recorder filter used by every scenario below. */
function await_wire_recorder( Await_Recorder $recorder ): void {
	remove_all_filters( 'wp_agent_workflow_run_recorder' );
	remove_all_filters( 'wp_agent_workflow_resume_dispatch' );
	add_filter(
		'wp_agent_workflow_run_recorder',
		static function ( $existing, string $runtime, string $run_id ) use ( $recorder ) {
			unset( $existing, $run_id );
			// Scoped resolution (#567): only resolve for the runtime this test
			// registers runs under, proving the runtime key actually gates
			// recorder resolution rather than a global fallback.
			return AWAIT_SMOKE_RUNTIME === $runtime ? $recorder : null;
		},
		10,
		3
	);
}

function await_run( Await_Recorder $recorder, string $run_id, array $steps = null ): WP_Agent_Workflow_Run_Result {
	$spec = null === $steps ? await_spec() : WP_Agent_Workflow_Spec::from_array( array( 'id' => 'test/await', 'steps' => $steps ) );
	return ( new WP_Agent_Workflow_Runner( $recorder, await_handlers() ) )->run(
		$spec,
		array(),
		array( 'run_id' => $run_id, 'runtime' => AWAIT_SMOKE_RUNTIME )
	);
}

// ═════════════════════════════════════════════════════════════════════════
// 1. Happy path: suspend → complete_wait(succeeded) → resume → downstream
//    ${steps.<id>.output} binding sees the completion's output.
// ═════════════════════════════════════════════════════════════════════════
$GLOBALS['__options']      = array();
$GLOBALS['__scheduled_as'] = array();
$GLOBALS['__echo_calls']   = 0;
$recorder = new Await_Recorder();
await_wire_recorder( $recorder );

$run = await_run( $recorder, 'await-1', array(
	array( 'id' => 'wait_step', 'type' => 'waiting', 'wait_id' => 'wait-1' ),
	array( 'id' => 'after', 'type' => 'ability', 'ability' => 'demo/echo-output', 'args' => array( 'value' => '${steps.wait_step.output.value}' ) ),
) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run->get_status(), 'happy: run SUSPENDED on await', $failures, $passes );

$suspension = $recorder->find( 'await-1' )->get_suspension();
smoke_assert( 'await', $suspension['kind'] ?? null, 'happy: frame kind is await', $failures, $passes );
smoke_assert( 'wait-1', $suspension['wait_id'] ?? null, 'happy: frame carries wait_id', $failures, $passes );
smoke_assert( AWAIT_SMOKE_RUNTIME, $suspension['runtime'] ?? null, 'happy: frame carries the owning runtime key (#567)', $failures, $passes );
smoke_assert_true( is_string( $suspension['generation'] ?? null ) && '' !== $suspension['generation'], 'happy: frame carries a fresh non-empty generation', $failures, $passes );
smoke_assert( array(), $GLOBALS['__scheduled_as'], 'happy: no timeout scheduled without timeout_at', $failures, $passes );

$completed = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-1', 'wait-1', array(
	'status' => 'succeeded',
	'output' => array( 'value' => 'hello' ),
) );
smoke_assert_true( ! is_wp_error( $completed ), 'happy: complete_wait does not error', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $completed->get_status(), 'happy: run resumes to SUCCEEDED', $failures, $passes );
$after_output = $completed->get_output()['steps']['after'] ?? null;
smoke_assert( array( 'value' => 'hello' ), $after_output, 'happy: downstream ${steps.wait_step.output.value} binding sees the completion output', $failures, $passes );
smoke_assert( 1, $GLOBALS['__echo_calls'], 'happy: downstream step executes exactly once', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════
// 2. Duplicate completion on a consumed wait → no-op, one resume total.
// ═════════════════════════════════════════════════════════════════════════
$duplicate = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-1', 'wait-1', array(
	'status' => 'succeeded',
	'output' => array( 'value' => 'SHOULD NOT APPLY' ),
) );
smoke_assert_true( ! is_wp_error( $duplicate ), 'duplicate: no-op does not error', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $duplicate->get_status(), 'duplicate: still SUCCEEDED (unchanged)', $failures, $passes );
smoke_assert( array( 'value' => 'hello' ), $duplicate->get_output()['steps']['after'] ?? null, 'duplicate: original output untouched — the later payload never applied', $failures, $passes );
smoke_assert( 1, $GLOBALS['__echo_calls'], 'duplicate: downstream step did NOT execute a second time', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════
// 3. Wrong wait_id → WP_Error. Unknown run_id → WP_Error.
// ═════════════════════════════════════════════════════════════════════════
$GLOBALS['__options'] = array();
$run2 = await_run( $recorder, 'await-2', array(
	array( 'id' => 'wait_step', 'type' => 'waiting', 'wait_id' => 'wait-2' ),
) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $run2->get_status(), 'wrong-wait: run SUSPENDED', $failures, $passes );

$wrong_wait = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-2', 'not-the-wait-id', array( 'status' => 'succeeded' ) );
smoke_assert_true( is_wp_error( $wrong_wait ), 'wrong-wait: complete_wait with a foreign wait_id errors', $failures, $passes );
smoke_assert( 'agents_workflow_complete_wait_unknown_wait', is_wp_error( $wrong_wait ) ? $wrong_wait->get_error_code() : null, 'wrong-wait: error code is unknown_wait', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $recorder->find( 'await-2' )->get_status(), 'wrong-wait: run stays SUSPENDED after a rejected completion', $failures, $passes );

$unknown_run = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'no-such-run', 'whatever', array( 'status' => 'succeeded' ) );
smoke_assert_true( is_wp_error( $unknown_run ), 'unknown-run: complete_wait for a nonexistent run errors', $failures, $passes );
smoke_assert( 'agents_workflow_complete_wait_not_found', is_wp_error( $unknown_run ) ? $unknown_run->get_error_code() : null, 'unknown-run: error code is not_found', $failures, $passes );

$wrong_runtime = agents_workflow_complete_wait( 'some-other-runtime', 'await-2', 'wait-2', array( 'status' => 'succeeded' ) );
smoke_assert_true( is_wp_error( $wrong_runtime ), 'wrong-runtime: complete_wait scoped to a foreign runtime cannot resolve a recorder', $failures, $passes );
smoke_assert( 'agents_workflow_complete_wait_no_recorder', is_wp_error( $wrong_runtime ) ? $wrong_runtime->get_error_code() : null, 'wrong-runtime: error code is no_recorder', $failures, $passes );

// Finish off await-2 cleanly so later scenarios start from a clean slate.
agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-2', 'wait-2', array( 'status' => 'succeeded', 'output' => array() ) );

// ═════════════════════════════════════════════════════════════════════════
// 4. `failed` completion → step failed, normal failure semantics.
// ═════════════════════════════════════════════════════════════════════════
$GLOBALS['__options'] = array();
await_run( $recorder, 'await-3', array(
	array( 'id' => 'wait_step', 'type' => 'waiting', 'wait_id' => 'wait-3' ),
) );
$failed = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-3', 'wait-3', array(
	'status' => 'failed',
	'error'  => array( 'code' => 'external_job_failed', 'message' => 'The external job failed.' ),
) );
smoke_assert_true( ! is_wp_error( $failed ), 'failed: complete_wait itself does not error on a failed completion', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $failed->get_status(), 'failed: run terminalizes FAILED', $failures, $passes );
smoke_assert( 'external_job_failed', $failed->get_error()['code'] ?? null, 'failed: run error carries the completion error code', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $failed->get_steps()[0]['status'] ?? null, 'failed: the waited step itself is recorded failed', $failures, $passes );

// `await`'s completion feeds the SAME resume() path parallel reconcile
// already uses, so it inherits that path's existing failure semantics
// byte-for-byte — including the pre-existing nuance (shared with `parallel`,
// unchanged by this issue) that a run's `continue_on_error` option is a
// `run()`-time setting, not persisted across a suspend/resume boundary:
// `resume()` always uses ITS OWN default (`continue_on_error=false`)
// regardless of what the original `run()` call was given. Prove `await`
// composes with that SHARED behavior rather than special-casing it:
//   - the step immediately after a resumed suspension still runs
//     unconditionally (it was never gated by the pre-loop failure scan);
//   - a failure that occurs WITHIN that same resumed pass (not the
//     just-spliced wait step itself) DOES stop the loop by default, exactly
//     like a synchronous run's `continue_on_error=false`.
$GLOBALS['__options']    = array();
$GLOBALS['__echo_calls'] = 0;
await_run( $recorder, 'await-3b', array(
	array( 'id' => 'wait_step', 'type' => 'waiting', 'wait_id' => 'wait-3b' ),
	array( 'id' => 'after', 'type' => 'ability', 'ability' => 'demo/echo-output', 'args' => array( 'value' => 'reached' ) ),
	array( 'id' => 'fails', 'type' => 'ability', 'ability' => 'demo/does-not-exist', 'args' => array() ),
	array( 'id' => 'never', 'type' => 'ability', 'ability' => 'demo/echo-output', 'args' => array( 'value' => 'unreached' ) ),
) );
$chained = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-3b', 'wait-3b', array( 'status' => 'succeeded', 'output' => array() ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $chained->get_status(), 'chained: an in-loop failure after a successful wait still fails the run', $failures, $passes );
smoke_assert( 1, $GLOBALS['__echo_calls'], 'chained: exactly the step immediately after the wait ran before the in-loop failure', $failures, $passes );
smoke_assert( array( 'wait_step', 'after', 'fails' ), array_column( $chained->get_steps(), 'id' ), 'chained: the loop stopped at the in-loop failure — the step after it never ran', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════
// 5. Timeout fires → failed with `workflow_wait_timeout`.
// ═════════════════════════════════════════════════════════════════════════
$GLOBALS['__options']      = array();
$GLOBALS['__scheduled_as'] = array();
await_run( $recorder, 'await-4', array(
	array( 'id' => 'wait_step', 'type' => 'waiting', 'wait_id' => 'wait-4', 'timeout_at' => 1000 ),
) );
smoke_assert( 1, count( $GLOBALS['__scheduled_as'] ), 'timeout: exactly one AS action scheduled for a timeout_at wait', $failures, $passes );
$scheduled = $GLOBALS['__scheduled_as'][0];
smoke_assert( \AgentsAPI\AI\Workflows\AGENTS_WORKFLOW_AWAIT_TIMEOUT_HOOK, $scheduled['hook'], 'timeout: scheduled under the await timeout hook', $failures, $passes );
smoke_assert( 1000, $scheduled['timestamp'], 'timeout: scheduled at the directive\'s timeout_at', $failures, $passes );
$timeout_payload = $scheduled['args'][0];
smoke_assert( 'await-4', $timeout_payload['run_id'] ?? null, 'timeout: payload carries run_id', $failures, $passes );
smoke_assert( 'wait-4', $timeout_payload['wait_id'] ?? null, 'timeout: payload carries wait_id', $failures, $passes );
smoke_assert( AWAIT_SMOKE_RUNTIME, $timeout_payload['runtime'] ?? null, 'timeout: payload carries the owning runtime key (#567)', $failures, $passes );
smoke_assert_true( is_string( $timeout_payload['generation'] ?? null ) && '' !== $timeout_payload['generation'], 'timeout: payload carries the suspension generation', $failures, $passes );

// Fire the timeout deterministically — no sleep — by dispatching the exact
// captured payload through the same fake do_action() the real hook uses.
do_action( \AgentsAPI\AI\Workflows\AGENTS_WORKFLOW_AWAIT_TIMEOUT_HOOK, $timeout_payload );
$timed_out = $recorder->find( 'await-4' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $timed_out->get_status(), 'timeout: run terminalizes FAILED', $failures, $passes );
smoke_assert( 'workflow_wait_timeout', $timed_out->get_error()['code'] ?? null, 'timeout: error code is workflow_wait_timeout', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════
// 6a. Completion racing timeout — black-box: a real completion resumes the
//     run first; the (now-late) timeout firing afterwards is a no-op.
// ═════════════════════════════════════════════════════════════════════════
$GLOBALS['__options']      = array();
$GLOBALS['__scheduled_as'] = array();
await_run( $recorder, 'await-5', array(
	array( 'id' => 'wait_step', 'type' => 'waiting', 'wait_id' => 'wait-5', 'timeout_at' => 2000 ),
) );
$race_payload = $GLOBALS['__scheduled_as'][0]['args'][0];

$won = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-5', 'wait-5', array( 'status' => 'succeeded', 'output' => array( 'value' => 'won' ) ) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $won->get_status(), 'race (black-box): the real completion wins and resumes SUCCEEDED', $failures, $passes );

do_action( \AgentsAPI\AI\Workflows\AGENTS_WORKFLOW_AWAIT_TIMEOUT_HOOK, $race_payload );
$after_late_timeout = $recorder->find( 'await-5' );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $after_late_timeout->get_status(), 'race (black-box): a timeout that fires after the real completion is a no-op', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════
// 6b. Completion racing timeout — white-box: prove the claim closes the
//     race BEFORE resume() ever runs, not merely via the coarser
//     "already terminal" guard. Drive the locked transition directly twice
//     with the SAME generation, exactly as two racing callers would.
// ═════════════════════════════════════════════════════════════════════════
$GLOBALS['__options'] = array();
await_run( $recorder, 'await-6', array(
	array( 'id' => 'wait_step', 'type' => 'waiting', 'wait_id' => 'wait-6' ),
) );
$generation_6 = $recorder->find( 'await-6' )->get_suspension()['generation'];

$first_claim = agents_workflow_complete_wait_locked( $recorder, 'await-6', 'wait-6', $generation_6, array( 'status' => 'succeeded', 'output' => array( 'value' => 'first' ) ) );
smoke_assert_true( is_array( $first_claim ) && 'resume' === ( $first_claim['action'] ?? null ), 'race (white-box): the first claimant wins and is handed the resume action', $failures, $passes );
// The run is STILL suspended at this point — resume() has deliberately not
// run yet, modeling the exact window a racing timeout could land in.
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $recorder->find( 'await-6' )->get_status(), 'race (white-box): still SUSPENDED between claim and resume', $failures, $passes );

$second_claim = agents_workflow_complete_wait_locked( $recorder, 'await-6', 'wait-6', $generation_6, array(
	'status' => 'failed',
	'error'  => array( 'code' => 'workflow_wait_timeout', 'message' => 'should never apply' ),
) );
smoke_assert_true( $second_claim instanceof WP_Agent_Workflow_Run_Result, 'race (white-box): the second (racing) claimant is a plain no-op result, not a resume action', $failures, $passes );
$spliced_step = $second_claim->get_steps()[0] ?? array();
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $spliced_step['status'] ?? null, 'race (white-box): the FIRST claimant\'s output survives untouched', $failures, $passes );

// A stale generation (a superseded suspension instance) is likewise a no-op —
// distinct from the "same generation, already consumed" case above.
$GLOBALS['__options'] = array();
await_run( $recorder, 'await-6b', array(
	array( 'id' => 'wait_step', 'type' => 'waiting', 'wait_id' => 'wait-6b' ),
) );
$stale = agents_workflow_complete_wait_locked( $recorder, 'await-6b', 'wait-6b', 'not-the-current-generation', array( 'status' => 'succeeded', 'output' => array() ) );
smoke_assert_true( $stale instanceof WP_Agent_Workflow_Run_Result, 'stale generation: a mismatched generation is a no-op, not an error', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $stale->get_status(), 'stale generation: the run stays untouched, still SUSPENDED', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════
// 7. Cancel while awaiting → cancelled; a later completion is a no-op.
// ═════════════════════════════════════════════════════════════════════════
$GLOBALS['__options'] = array();
await_run( $recorder, 'await-7', array(
	array( 'id' => 'wait_step', 'type' => 'waiting', 'wait_id' => 'wait-7' ),
) );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED, $recorder->find( 'await-7' )->get_status(), 'cancel: run SUSPENDED before cancellation', $failures, $passes );

WP_Agent_Run_Control::request_cancel( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'await-7' );

$cancelled = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-7', 'wait-7', array(
	'status' => 'succeeded',
	'output' => array( 'value' => 'should not win' ),
) );
smoke_assert_true( ! is_wp_error( $cancelled ), 'cancel: complete_wait itself does not error', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED, $cancelled->get_status(), 'cancel: the existing cancellation fence wins — run terminalizes CANCELLED, not SUCCEEDED', $failures, $passes );
smoke_assert( 'cancel_requested', $cancelled->get_error()['code'] ?? null, 'cancel: error code is cancel_requested', $failures, $passes );

$late_after_cancel = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-7', 'wait-7', array(
	'status' => 'succeeded',
	'output' => array( 'value' => 'late' ),
) );
smoke_assert_true( ! is_wp_error( $late_after_cancel ), 'cancel: a late completion after cancellation does not error', $failures, $passes );
smoke_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED, $late_after_cancel->get_status(), 'cancel: a late completion after cancellation is a no-op — stays CANCELLED', $failures, $passes );

// ═════════════════════════════════════════════════════════════════════════
// 8. Completing a wait for a run suspended on something OTHER than `await`
//    (a foreign suspension kind) is rejected, not silently accepted.
// ═════════════════════════════════════════════════════════════════════════
$GLOBALS['__options'] = array();
$parallel_like = new WP_Agent_Workflow_Run_Result(
	'await-8',
	'test/await',
	WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED,
	array(),
	array(),
	array(
		array( 'id' => 'scatter', 'type' => 'parallel', 'status' => 'pending', 'output' => null, 'started_at' => 1, 'ended_at' => 0 ),
	),
	array(),
	1,
	0,
	array(
		'_runtime'    => AWAIT_SMOKE_RUNTIME,
		'_suspension' => array(
			'step_index'       => 0,
			'step_id'          => 'scatter',
			'executor_id'      => 'some-parallel-executor',
			'runtime'          => AWAIT_SMOKE_RUNTIME,
			'reason'           => 'parallel_branches_dispatched',
			'handles'          => array(),
			'aggregate'        => array(),
			'context_snapshot' => array( 'inputs' => array(), 'steps' => array(), 'vars' => array() ),
			'completed'        => array(),
		),
	)
);
$recorder->update( $parallel_like );
$foreign = agents_workflow_complete_wait( AWAIT_SMOKE_RUNTIME, 'await-8', 'anything', array( 'status' => 'succeeded' ) );
smoke_assert_true( is_wp_error( $foreign ), 'foreign-kind: complete_wait against a non-await suspension errors', $failures, $passes );
smoke_assert( 'agents_workflow_complete_wait_not_awaiting', is_wp_error( $foreign ) ? $foreign->get_error_code() : null, 'foreign-kind: error code is not_awaiting', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
