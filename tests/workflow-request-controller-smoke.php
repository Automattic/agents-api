<?php
/** Run with: php tests/workflow-request-controller-smoke.php */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ): bool { return $value instanceof WP_Error; } }

require_once __DIR__ . '/../src/Runtime/interface-wp-agent-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/interface-wp-agent-atomic-run-control-store.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-control.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-spec.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-result-envelope.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-result.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-recorder.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-context.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-runner.php';
require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-scoped-drain.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-awaiter.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-request-controller.php';

$GLOBALS['controller_unscheduled'] = array();
if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( string $hook, ?array $args = array(), string $group = '' ): void {
		$GLOBALS['controller_unscheduled'][] = array( $hook, $args, $group );
	}
}

use AgentsAPI\AI\WP_Agent_Atomic_Run_Control_Store;
use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Request_Controller;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Awaiter;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

final class Controller_Memory_Store implements WP_Agent_Atomic_Run_Control_Store {
	public array $states = array();
	public function get_state( string $key ): array { return $this->states[ $key ] ?? array( 'runs' => array(), 'queues' => array(), 'events' => array() ); }
	public function save_state( string $key, array $state ): void { $this->states[ $key ] = $state; }
	public function mutate_state( string $key, callable $mutation ): mixed { $out = $mutation( $this->get_state( $key ) ); $this->save_state( $key, $out['state'] ); return $out['result']; }
}
final class Controller_Recorder implements WP_Agent_Workflow_Run_Recorder {
	public array $runs = array();
	public function start( WP_Agent_Workflow_Run_Result $r ) { $this->runs[ $r->get_run_id() ] = $r; return $r->get_run_id(); }
	public function update( WP_Agent_Workflow_Run_Result $r ) { $this->runs[ $r->get_run_id() ] = $r; return true; }
	public function find( string $id ): ?WP_Agent_Workflow_Run_Result { return $this->runs[ $id ] ?? null; }
	public function recent( array $args = array() ): array { return array_values( $this->runs ); }
}
final class Controller_Runner extends WP_Agent_Workflow_Runner {
	public int $starts = 0;
	public function __construct( private Controller_Recorder $test_recorder ) {}
	public function run( WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() ): WP_Agent_Workflow_Run_Result {
		++$this->starts; $status = $inputs['status'] ?? WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED;
		$r = new WP_Agent_Workflow_Run_Result( $options['run_id'], $spec->get_id(), $status, $inputs, array(), array(), array(), 1, 'suspended' === $status ? 0 : 2, array() );
		$this->test_recorder->start( $r ); return $r;
	}
}
final class Controller_Awaiter extends WP_Agent_Workflow_Run_Awaiter {
	public int $calls = 0;
	public array $last_options = array();
	public function __construct( private Controller_Recorder $recorder ) {}
	public function await( string $id, WP_Agent_Workflow_Run_Recorder $recorder, array $options = array() ) {
		++$this->calls; $this->last_options = $options; $r = $this->recorder->find( $id );
		if ( ! empty( $options['complete'] ) && null !== $r ) { $this->recorder->update( $r->with( array( 'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, 'ended_at' => 2 ) ) ); }
		return array();
	}
}
$fails = array(); $passes = 0;
function controller_assert( bool $ok, string $name ): void { global $fails, $passes; if ( $ok ) { ++$passes; echo "  PASS $name\n"; } else { $fails[] = $name; echo "  FAIL $name\n"; } }

WP_Agent_Run_Control::set_store( new Controller_Memory_Store() );
$recorder = new Controller_Recorder(); $runner = new Controller_Runner( $recorder ); $awaiter = new Controller_Awaiter( $recorder ); $delivered = array(); $cleaned = array();
$controller = new WP_Agent_Workflow_Request_Controller( $runner, $recorder, $awaiter, 'controller-test', static function ( $id ) use ( &$delivered ) { $delivered[] = $id; }, static function ( $id ) use ( &$cleaned ) { $cleaned[] = $id; } );
$spec = new WP_Agent_Workflow_Spec( 'test/controller', '1', array(), array( array( 'id' => 'step', 'type' => 'ability', 'ability' => 'test/step' ) ), array(), array(), array( 'id' => 'test/controller', 'version' => '1', 'inputs' => array(), 'steps' => array( array( 'id' => 'step', 'type' => 'ability', 'ability' => 'test/step' ) ), 'triggers' => array() ) );

echo "workflow-request-controller-smoke\n";
$one = $controller->start( 'one', $spec ); $duplicate = $controller->start( 'one', $spec );
controller_assert( 1 === $runner->starts, 'duplicate starts reserve one durable run' );
controller_assert( $one['run_id'] === $duplicate['run_id'], 'duplicate starts return the original run identity' );
$two = $controller->start( 'two', $spec );
controller_assert( 2 === $runner->starts && $one['run_id'] !== $two['run_id'], 'operation scopes are isolated' );
controller_assert( 'running' === $controller->get_status( 'two' )['status'], 'public get-status returns reconnectable operation state' );
$controller->reconnect( 'two', array( 'await' => array( 'time_limit_ms' => 999999, 'limit' => 999999 ) ) );
controller_assert( 5000 === $awaiter->last_options['time_limit_ms'] && 25 === $awaiter->last_options['limit'], 'advance clamps wall-clock and action budgets' );
controller_assert( true === $one['reconnectable'], 'interrupted suspended request is reconnectable' );
$complete = $controller->reconnect( 'one', array( 'await' => array( 'complete' => true ) ) );
controller_assert( true === $complete['terminal'] && 'succeeded' === $complete['status'], 'reconnect advances the original suspended run' );
$controller->reconnect( 'one', array( 'await' => array( 'complete' => true ) ) );
controller_assert( array( 'one' ) === $delivered, 'duplicate terminal delivery is suppressed' );
controller_assert( array( 'one' ) === $cleaned, 'terminal cleanup runs once' );
$failed = $controller->start( 'failed', $spec, array( 'status' => 'failed' ) );
$cancelled = $controller->start( 'cancelled', $spec, array( 'status' => 'cancelled' ) );
controller_assert( $failed['terminal'] && $cancelled['terminal'], 'failed and cancelled runs are terminal' );
controller_assert( in_array( 'failed', $cleaned, true ) && in_array( 'cancelled', $cleaned, true ), 'failed and cancelled runs release terminal cleanup' );
controller_assert( array() === ( $controller->get( 'one' )['lease'] ?? null ), 'terminal lease is cleared' );
$one_group_cleanup = array_filter( $GLOBALS['controller_unscheduled'], static function ( array $call ) use ( $one ): bool { return 'agents-api-run-' . md5( $one['run_id'] ) === $call[2]; } );
controller_assert( 2 === count( $one_group_cleanup ), 'terminal cleanup removes only the run-scoped branch and resume actions' );
controller_assert( array() === array_filter( $one_group_cleanup, static fn ( array $call ): bool => null !== $call[1] ), 'terminal cleanup matches every argument shape in the run-scoped group' );
$state = WP_Agent_Run_Control::state( 'controller-test' );
$state['runs']['two']['lease'] = array( 'token' => 'other-worker', 'worker_id' => 'worker-a', 'expires_at' => time() + 60 );
WP_Agent_Run_Control::store()->save_state( 'controller-test', $state );
$busy = $controller->advance( 'two', array( 'worker_id' => 'worker-b' ) );
controller_assert( true === $busy['busy'], 'concurrent worker lane claim is rejected while lease is active' );
$state = WP_Agent_Run_Control::state( 'controller-test' );
$state['runs']['two']['lease']['expires_at'] = 0;
WP_Agent_Run_Control::store()->save_state( 'controller-test', $state );
$reclaimed = $controller->advance( 'two', array( 'worker_id' => 'worker-b' ) );
controller_assert( false === $reclaimed['busy'], 'expired worker lane is reclaimed deterministically' );
$cancelled_operation = $controller->cancel( 'two' );
controller_assert( true === $cancelled_operation['terminal'] && 'cancelled' === $cancelled_operation['status'], 'public cancel atomically records a terminal disposition' );
controller_assert( isset( WP_Agent_Run_Control::state( 'controller-test' )['idempotency']['two'] ), 'idempotency key and authoritative run identity are persisted together' );
echo "Passed: $passes, Failed: " . count( $fails ) . "\n";
exit( empty( $fails ) ? 0 : 1 );
