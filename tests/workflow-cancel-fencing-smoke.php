<?php
/**
 * Pure-PHP smoke test for workflow cancellation fencing.
 *
 * Run with: php tests/workflow-cancel-fencing-smoke.php
 *
 * Covers the two cancellation races from issue #529 deterministically:
 *   - cancel staged before the workflow run is created (runner consumes the
 *     intent at start and executes no steps), and
 *   - cancel racing a live worker's terminalization (exactly one atomic
 *     fence winner across controller, recorder, and run control).
 *
 * No WordPress required.
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$fails    = array();
$passes   = 0;

echo "workflow-cancel-fencing-smoke\n";

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ): bool { return $value instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		unset( $args );
		if ( 'wp_agent_workflow_known_step_types' === $hook && is_array( $value ) ) {
			$value[] = 'counting';
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) { function do_action( string $hook, ...$args ): void { unset( $hook, $args ); } }

require_once __DIR__ . '/../src/Runtime/interface-wp-agent-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-atomic-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-exclusive-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-control-store-exception.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-control.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-result-envelope.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-result.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-recorder.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-context.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-bindings.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-step-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';
require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-scoped-drain.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-awaiter.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-request-controller.php';

$GLOBALS['fencing_unscheduled'] = array();
if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( string $hook, ?array $args = array(), string $group = '' ): void {
		$GLOBALS['fencing_unscheduled'][] = array( $hook, $args, $group );
	}
}

use AgentsAPI\AI\WP_Agent_Atomic_Run_Control_Store;
use AgentsAPI\AI\WP_Agent_Exclusive_Run_Control_Store;
use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Request_Controller;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Awaiter;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

final class Fencing_Memory_Store implements WP_Agent_Atomic_Run_Control_Store, WP_Agent_Exclusive_Run_Control_Store {
	public array $states = array();
	private array $claims = array();
	public function get_state( string $key ): array { return $this->states[ $key ] ?? array( 'runs' => array(), 'queues' => array(), 'events' => array() ); }
	public function save_state( string $key, array $state ): void { $this->states[ $key ] = $state; }
	public function mutate_state( string $key, callable $mutation ): mixed { $out = $mutation( $this->get_state( $key ) ); $this->save_state( $key, $out['state'] ); return $out['result']; }
	public function execute_claimed( string $key, callable $callback ): bool { if ( isset( $this->claims[ $key ] ) ) { return false; } $this->claims[ $key ] = true; try { $callback(); return true; } finally { unset( $this->claims[ $key ] ); } }
}
final class Fencing_Recorder implements WP_Agent_Workflow_Run_Recorder {
	public array $runs = array();
	public function start( WP_Agent_Workflow_Run_Result $r ) { $this->runs[ $r->get_run_id() ] = $r; return $r->get_run_id(); }
	public function update( WP_Agent_Workflow_Run_Result $r ) { $this->runs[ $r->get_run_id() ] = $r; return true; }
	public function find( string $id ): ?WP_Agent_Workflow_Run_Result { return $this->runs[ $id ] ?? null; }
	public function recent( array $args = array() ): array { return array_values( $this->runs ); }
}

/**
 * Real runner with a counting step handler; two deterministic interleaving
 * seams simulate a concurrent cancel() arriving at an exact point: `pre_run`
 * fires before run() creates the run-control row (the pre-start window), and
 * `interleave` fires inside a counted step (the mid-flight window).
 */
final class Fencing_Runner extends WP_Agent_Workflow_Runner {
	public int $executions = 0;
	/** @var callable|null */
	public $pre_run = null;
	/** @var callable|null */
	public $interleave = null;
	public function __construct( private Fencing_Recorder $test_recorder ) {
		parent::__construct( $test_recorder, array(
			'counting' => function ( array $step, array $context ) {
				unset( $context );
				if ( is_callable( $this->interleave ) ) {
					( $this->interleave )( $step );
				}
				++$this->executions;
				return array( 'ok' => true, 'step' => $step['id'] ?? '' );
			},
		) );
	}
	public function run( WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() ): WP_Agent_Workflow_Run_Result {
		if ( is_callable( $this->pre_run ) ) {
			( $this->pre_run )();
		}
		return parent::run( $spec, $inputs, $options );
	}
}

function fencing_assert( bool $ok, string $name ): void {
	global $fails, $passes;
	if ( $ok ) { ++$passes; echo "  PASS $name\n"; } else { $fails[] = $name; echo "  FAIL $name\n"; }
}

/** @param array<int,array<string,mixed>> $steps */
function fencing_spec( array $steps ): WP_Agent_Workflow_Spec {
	$raw = array( 'id' => 'test/fencing', 'version' => '1', 'inputs' => array(), 'steps' => $steps, 'triggers' => array() );
	return new WP_Agent_Workflow_Spec( 'test/fencing', '1', array(), $steps, array(), array(), $raw );
}

/** @param array<int,string> $step_ids */
function fencing_counting_steps( array $step_ids ): array {
	return array_map( static fn ( string $id ): array => array( 'id' => $id, 'type' => 'counting' ), $step_ids );
}

function fencing_controller( string $store_key, Fencing_Runner $runner, Fencing_Recorder $recorder, array &$delivered ): WP_Agent_Workflow_Request_Controller {
	$awaiter = new WP_Agent_Workflow_Run_Awaiter();
	return new WP_Agent_Workflow_Request_Controller(
		$runner,
		$recorder,
		$awaiter,
		$store_key,
		static function ( string $operation_id ) use ( &$delivered ): void { $delivered[] = $operation_id; }
	);
}

$controller_store = new Fencing_Memory_Store();
WP_Agent_Run_Control::set_store( $controller_store );

echo "run-control cancellation primitives\n";
WP_Agent_Run_Control::stage_cancellation( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'staged-run' );
$staged = WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'staged-run' );
fencing_assert( is_array( $staged ) && 'cancelling' === $staged['status'] && true === ( $staged['cancelled'] ?? false ) && '' === ( $staged['started_at'] ?? null ), 'staged intent persists a cancelling row for a run that never started' );
fencing_assert( WP_Agent_Run_Control::cancel_requested( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'staged-run' ), 'staged intent is observable as a requested cancellation' );
WP_Agent_Run_Control::start_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'running-cancel-run' );
WP_Agent_Run_Control::stage_cancellation( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'running-cancel-run' );
fencing_assert( 'cancelling' === ( WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'running-cancel-run' )['status'] ?? '' ), 'staging cancellation on a running row moves it to cancelling' );
WP_Agent_Run_Control::start_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'done-run' );
WP_Agent_Run_Control::finish_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'done-run', WP_Agent_Run_Control::STATUS_SUCCEEDED );
$terminal_staged = WP_Agent_Run_Control::stage_cancellation( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'done-run' );
fencing_assert( 'succeeded' === $terminal_staged['status'] && empty( $terminal_staged['cancelled'] ), 'staging cancellation never mutates an already-terminal row' );
fencing_assert( null === WP_Agent_Run_Control::request_cancel( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'never-created-run' ), 'request_cancel stays strict for missing rows (ability contract preserved)' );
WP_Agent_Run_Control::finish_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'staged-run', WP_Agent_Run_Control::STATUS_CANCELLED );
fencing_assert( WP_Agent_Run_Control::cancel_requested( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'staged-run' ), 'a terminal cancelled row still reports cancellation as requested' );
fencing_assert( ! WP_Agent_Run_Control::cancel_requested( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'done-run' ), 'a non-cancelled terminal row reports no requested cancellation' );

echo "race 1: cancel before run creation\n";
$pre_store = new Fencing_Memory_Store();
WP_Agent_Run_Control::set_store( $pre_store );
WP_Agent_Run_Control::stage_cancellation( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'pre-start-run' );
$pre_recorder = new Fencing_Recorder();
$pre_runner   = new Fencing_Runner( $pre_recorder );
$pre_result   = $pre_runner->run( fencing_spec( fencing_counting_steps( array( 'a', 'b' ) ) ), array(), array( 'run_id' => 'pre-start-run' ) );
fencing_assert( 0 === $pre_runner->executions, 'runner consumes the staged intent without executing any step' );
fencing_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED === $pre_result->get_status() && array() === $pre_result->get_steps(), 'runner returns a terminal cancelled result with no step records' );
fencing_assert( 'cancelled' === ( WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'pre-start-run' )['status'] ?? '' ), 'run-control row terminalizes cancelled when a staged intent is consumed' );
fencing_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED === $pre_recorder->find( 'pre-start-run' )?->get_status(), 'recorder records the consumed cancellation' );

echo "race 1: controller-level cancel before the runner creates the run\n";
$pre_ctrl_store = new Fencing_Memory_Store();
WP_Agent_Run_Control::set_store( $pre_ctrl_store );
$pre_ctrl_recorder = new Fencing_Recorder();
$pre_ctrl_runner   = new Fencing_Runner( $pre_ctrl_recorder );
$pre_delivered     = array();
$pre_controller    = fencing_controller( 'fencing-pre-start', $pre_ctrl_runner, $pre_ctrl_recorder, $pre_delivered );
$cancel_response   = null;
$pre_ctrl_runner->pre_run = static function () use ( $pre_controller, &$cancel_response ): void {
	$cancel_response = $pre_controller->cancel( 'op-pre' );
};
$started = $pre_controller->start( 'op-pre', fencing_spec( fencing_counting_steps( array( 'a', 'b', 'c' ) ) ) );
$pre_ctrl_runner->pre_run = null;
fencing_assert( 0 === $pre_ctrl_runner->executions, 'worker started after the cancel and executed no steps' );
fencing_assert( is_array( $cancel_response ) && true === $cancel_response['terminal'] && 'cancelled' === $cancel_response['status'] && 'cancelled' === ( $cancel_response['cancellation'] ?? '' ), 'cancel() proves durable cancellation and reports terminal cancelled' );
fencing_assert( is_array( $started ) && true === $started['terminal'] && 'cancelled' === $started['status'], 'the racing start() observes the fenced terminal cancellation' );
fencing_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED === $pre_ctrl_recorder->find( $started['run_id'] )?->get_status(), 'recorder holds one consistent cancelled outcome' );
fencing_assert( 'cancelled' === ( WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, $started['run_id'] )['status'] ?? '' ), 'run-control holds one consistent cancelled outcome' );
fencing_assert( true === ( $pre_controller->get( 'op-pre' )['terminal'] ?? false ) && 'cancelled' === ( $pre_controller->get( 'op-pre' )['terminal_status'] ?? '' ), 'operation record holds one consistent cancelled outcome' );
fencing_assert( array( 'op-pre' ) === $pre_delivered, 'terminal delivery happens exactly once' );

echo "race 2: cancel wins the fence against a live worker\n";
$mid_store = new Fencing_Memory_Store();
WP_Agent_Run_Control::set_store( $mid_store );
$mid_recorder = new Fencing_Recorder();
$mid_runner   = new Fencing_Runner( $mid_recorder );
$mid_delivered = array();
$mid_controller = fencing_controller( 'fencing-mid-run', $mid_runner, $mid_recorder, $mid_delivered );
$fired          = false;
$mid_runner->interleave = static function ( array $step ) use ( $mid_controller, &$fired ): void {
	if ( 'b' === ( $step['id'] ?? '' ) && ! $fired ) {
		$fired = true;
		$mid_controller->cancel( 'op-mid' );
	}
};
$mid_response = $mid_controller->start( 'op-mid', fencing_spec( fencing_counting_steps( array( 'a', 'b', 'c', 'd' ) ) ) );
$mid_runner->interleave = null;
fencing_assert( 2 === $mid_runner->executions, 'the live worker stops at its next cancellation check after the fence' );
fencing_assert( is_array( $mid_response ) && true === $mid_response['terminal'] && 'cancelled' === $mid_response['status'], 'mid-flight cancel reports terminal cancelled' );
$mid_run_id = is_array( $mid_response ) ? $mid_response['run_id'] : '';
fencing_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED === $mid_recorder->find( $mid_run_id )?->get_status() && 'cancelled' === ( WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, $mid_run_id )['status'] ?? '' ) && 'cancelled' === ( $mid_controller->get( 'op-mid' )['terminal_status'] ?? '' ), 'controller, recorder, and run-control agree on cancelled' );
fencing_assert( array( 'op-mid' ) === $mid_delivered, 'mid-flight cancellation delivers exactly once' );

echo "race 2: worker wins the fence; cancel reports the superseded outcome\n";
$done_store = new Fencing_Memory_Store();
WP_Agent_Run_Control::set_store( $done_store );
$done_recorder = new Fencing_Recorder();
$done_runner   = new Fencing_Runner( $done_recorder );
$done_delivered = array();
$done_controller = fencing_controller( 'fencing-done', $done_runner, $done_recorder, $done_delivered );
$done_response = $done_controller->start( 'op-done', fencing_spec( fencing_counting_steps( array( 'a' ) ) ) );
$done_run_id   = is_array( $done_response ) ? $done_response['run_id'] : '';
$superseded    = $done_controller->cancel( 'op-done' );
fencing_assert( is_array( $superseded ) && true === $superseded['terminal'] && 'succeeded' === $superseded['status'] && 'superseded' === ( $superseded['cancellation'] ?? '' ), 'cancel() after a worker terminalization reports the worker outcome as superseded' );
fencing_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED === $done_recorder->find( $done_run_id )?->get_status(), 'superseded cancel never overwrites the recorder outcome' );
fencing_assert( 'succeeded' === ( WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, $done_run_id )['status'] ?? '' ) && 'succeeded' === ( $done_controller->get( 'op-done' )['terminal_status'] ?? '' ), 'superseded cancel leaves run-control and operation outcome intact' );
fencing_assert( array( 'op-done' ) === $done_delivered, 'superseded cancel does not deliver a second terminal' );

echo "race 2: fence projects exactly one terminal outcome onto a late worker\n";
$fence_store = new Fencing_Memory_Store();
WP_Agent_Run_Control::set_store( $fence_store );
$fence_recorder = new Fencing_Recorder();
$running        = new WP_Agent_Workflow_Run_Result( 'fence-run', 'test/fencing', WP_Agent_Workflow_Run_Result::STATUS_RUNNING, array(), array(), array(), array(), 1, 0, array() );
$fence_recorder->start( $running );
WP_Agent_Run_Control::start_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'fence-run' );
WP_Agent_Run_Control::stage_cancellation( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'fence-run' );
WP_Agent_Run_Control::finish_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'fence-run', WP_Agent_Run_Control::STATUS_CANCELLED );
$succeeded = new WP_Agent_Workflow_Run_Result( 'fence-run', 'test/fencing', WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, array(), array( 'last' => array( 'ok' => true ) ), array(), array(), 1, 2, array() );
$projected = WP_Agent_Workflow_Runner::authoritative_terminal_result( $succeeded );
$fence_recorder->update( $projected );
fencing_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED === $projected->get_status() && 'cancel_requested' === ( $projected->get_error()['code'] ?? '' ), 'a worker that terminalizes after losing the fence projects cancelled' );
fencing_assert( 'cancelled' === ( WP_Agent_Run_Control::get_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, 'fence-run' )['status'] ?? '' ) && WP_Agent_Workflow_Run_Result::STATUS_CANCELLED === $fence_recorder->find( 'fence-run' )?->get_status(), 'the fence loser can never flip run-control or the recorder to a conflicting terminal' );
$repeat = WP_Agent_Workflow_Runner::authoritative_terminal_result( $succeeded );
fencing_assert( WP_Agent_Workflow_Run_Result::STATUS_CANCELLED === $repeat->get_status(), 'repeated worker terminalization stays on the fenced outcome' );

echo "cancel reports pending while a worker is still finalizing\n";
$pending_store = new Fencing_Memory_Store();
WP_Agent_Run_Control::set_store( $pending_store );
$pending_recorder = new Fencing_Recorder();
$pending_runner   = new Fencing_Runner( $pending_recorder );
$pending_delivered = array();
$pending_controller = fencing_controller( 'fencing-pending', $pending_runner, $pending_recorder, $pending_delivered );
// Simulate a worker that committed its terminal CAS but has not written the
// recorder evidence yet: run-control shows a non-cancelled terminal winner
// while the recorder still holds a running row.
$pending_run_id = 'pending-finalize-run';
$pending_recorder->start( new WP_Agent_Workflow_Run_Result( $pending_run_id, 'test/fencing', WP_Agent_Workflow_Run_Result::STATUS_RUNNING, array(), array(), array(), array(), 1, 0, array() ) );
WP_Agent_Run_Control::start_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, $pending_run_id );
WP_Agent_Run_Control::finish_run( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, $pending_run_id, WP_Agent_Run_Control::STATUS_SUCCEEDED );
$pending_spec = fencing_spec( fencing_counting_steps( array( 'a' ) ) );
$pending_store->states['fencing-pending'] = array(
	'runs'   => array(
		'op-pending' => array(
			'run_id'      => $pending_run_id,
			'spec'        => $pending_spec->to_array(),
			'inputs'      => array(),
			'options'     => array(),
			'terminal'    => false,
			'disposition' => 'pending',
			'lease'       => array(),
			'created_at'  => time(),
		),
	),
	'queues' => array(),
	'events' => array(),
);
$pending_response = $pending_controller->cancel( 'op-pending' );
fencing_assert( is_array( $pending_response ) && false === $pending_response['terminal'] && true === $pending_response['reconnectable'] && 'pending' === ( $pending_response['cancellation'] ?? '' ), 'cancel() without a provable terminal disposition stays non-terminal and reconnectable' );
fencing_assert( null === $pending_recorder->find( $pending_run_id ) || WP_Agent_Workflow_Run_Result::STATUS_RUNNING === $pending_recorder->find( $pending_run_id )?->get_status(), 'pending cancel never fabricates a conflicting recorder outcome' );
fencing_assert( true !== ( $pending_controller->get( 'op-pending' )['terminal'] ?? false ) && array() === $pending_delivered, 'pending cancel records no terminal disposition and delivers nothing' );

echo "cleanup never unschedules actions a live lease-holding worker owns\n";
$lease_store = new Fencing_Memory_Store();
WP_Agent_Run_Control::set_store( $lease_store );
$lease_recorder = new Fencing_Recorder();
$lease_runner   = new Fencing_Runner( $lease_recorder );
$lease_delivered = array();
$lease_controller = fencing_controller( 'fencing-lease', $lease_runner, $lease_recorder, $lease_delivered );
$lease_spec       = fencing_spec( fencing_counting_steps( array( 'a' ) ) );
$lease_store->states['fencing-lease'] = array(
	'runs'   => array(
		'op-lease' => array(
			'run_id'      => 'workflow_request_' . substr( hash( 'sha256', "fencing-lease\0op-lease" ), 0, 32 ),
			'spec'        => $lease_spec->to_array(),
			'inputs'      => array(),
			'options'     => array(),
			'terminal'    => false,
			'disposition' => 'pending',
			'lease'       => array( 'token' => 'live-worker', 'worker_id' => 'live-worker', 'expires_at' => time() + 600 ),
			'created_at'  => time(),
		),
	),
	'queues' => array(),
	'events' => array(),
);
$GLOBALS['fencing_unscheduled'] = array();
$lease_response = $lease_controller->cancel( 'op-lease' );
fencing_assert( is_array( $lease_response ) && true === $lease_response['terminal'] && 'cancelled' === $lease_response['status'] && 'cancelled' === ( $lease_response['cancellation'] ?? '' ), 'cancel with a live lease still proves and reports terminal cancellation' );
fencing_assert( array() === $GLOBALS['fencing_unscheduled'], 'a live lease-holding worker keeps its scheduled actions' );
$lease_state                    = $lease_store->states['fencing-lease'];
$lease_state['runs']['op-lease']['lease'] = array();
$lease_store->states['fencing-lease'] = $lease_state;
$GLOBALS['fencing_unscheduled'] = array();
$lease_controller->cancel( 'op-lease' );
fencing_assert( array() !== $GLOBALS['fencing_unscheduled'], 'cleanup runs once no live worker lease remains' );

echo "Passed: $passes, Failed: " . count( $fails ) . "\n";
exit( empty( $fails ) ? 0 : 1 );
