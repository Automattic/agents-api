<?php
/**
 * Pure-PHP smoke test for atomic generic run-control lifecycle mutations.
 *
 * Run with: php tests/run-control-atomic-mutations-smoke.php
 *
 * @package AgentsAPI\Tests
 */

use AgentsAPI\AI\WP_Agent_Atomic_Workspace_Run_Control_Store;
use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "run-control-atomic-mutations-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

agents_api_smoke_require_module();

final class Run_Control_Atomic_Spy_Store implements WP_Agent_Atomic_Workspace_Run_Control_Store {

	public int $mutate_calls           = 0;
	public int $mutate_workspace_calls = 0;
	public int $save_calls             = 0;
	public int $save_workspace_calls   = 0;
	public ?WP_Agent_Run_Control_Store_Exception $site_failure      = null;
	public ?WP_Agent_Run_Control_Store_Exception $workspace_failure = null;
	/** @var callable|null */
	public $before_mutation = null;

	/** @var array<string,array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}> */
	private array $states = array();

	public function get_state( string $store_key ): array {
		return $this->states[ $this->state_key( $store_key ) ] ?? $this->empty_state();
	}

	public function save_state( string $store_key, array $state ): void {
		++$this->save_calls;
		$this->states[ $this->state_key( $store_key ) ] = $state;
	}

	public function mutate_state( string $store_key, callable $mutation ): mixed {
		++$this->mutate_calls;
		if ( $this->site_failure instanceof WP_Agent_Run_Control_Store_Exception ) {
			throw $this->site_failure;
		}
		return $this->mutate( $store_key, $mutation );
	}

	public function get_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace ): array {
		return $this->states[ $this->state_key( $store_key, $workspace ) ] ?? $this->empty_state();
	}

	public function save_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, array $state ): void {
		++$this->save_workspace_calls;
		$this->states[ $this->state_key( $store_key, $workspace ) ] = $state;
	}

	public function mutate_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, callable $mutation ): mixed {
		++$this->mutate_workspace_calls;
		if ( $this->workspace_failure instanceof WP_Agent_Run_Control_Store_Exception ) {
			throw $this->workspace_failure;
		}
		return $this->mutate( $store_key, $mutation, $workspace );
	}

	/** @param array<string,mixed> $run */
	public function inject_run( string $store_key, string $run_id, array $run, ?WP_Agent_Workspace_Scope $workspace = null ): void {
		$key                       = $this->state_key( $store_key, $workspace );
		$state                     = $this->states[ $key ] ?? $this->empty_state();
		$state['runs'][ $run_id ]  = $run;
		$this->states[ $key ]      = $state;
	}

	private function mutate( string $store_key, callable $mutation, ?WP_Agent_Workspace_Scope $workspace = null ): mixed {
		if ( is_callable( $this->before_mutation ) ) {
			( $this->before_mutation )( $this, $store_key, $workspace );
		}
		$key                  = $this->state_key( $store_key, $workspace );
		$mutated              = $mutation( $this->states[ $key ] ?? $this->empty_state() );
		$this->states[ $key ] = $mutated['state'];
		return $mutated['result'];
	}

	private function state_key( string $store_key, ?WP_Agent_Workspace_Scope $workspace = null ): string {
		return null === $workspace ? 'site:' . $store_key : 'workspace:' . $workspace->key() . ':' . $store_key;
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	private function empty_state(): array {
		return array( 'runs' => array(), 'queues' => array(), 'events' => array() );
	}
}

/** @return array<string,mixed> */
function atomic_get_run( string $store_key, string $run_id, ?WP_Agent_Workspace_Scope $workspace ): array {
	return WP_Agent_Run_Control::get_run( $store_key, $run_id, $workspace ) ?? array();
}

function atomic_start( string $store_key, string $run_id, ?WP_Agent_Workspace_Scope $workspace ): void {
	WP_Agent_Run_Control::start_run( $store_key, $run_id, array(), $workspace );
}

function atomic_save( string $store_key, string $run_id, string $status, ?WP_Agent_Workspace_Scope $workspace ): array {
	return WP_Agent_Run_Control::save_run( $store_key, array( 'run_id' => $run_id, 'status' => $status ), $workspace );
}

$site_spy = new Run_Control_Atomic_Spy_Store();
WP_Agent_Run_Control::set_store( $site_spy );
atomic_start( 'atomic-site', 'run-site', null );
atomic_save( 'atomic-site', 'run-site', WP_Agent_Run_Control::STATUS_RUNNING, null );
WP_Agent_Run_Control::finish_run( 'atomic-site', 'run-site' );
WP_Agent_Run_Control::request_cancel( 'atomic-site', 'run-site' );
agents_api_smoke_assert_equals( 4, $site_spy->mutate_calls, 'all site lifecycle writes use mutate_state', $failures, $passes );
agents_api_smoke_assert_equals( 0, $site_spy->save_calls, 'site lifecycle writes never use unlocked save_state', $failures, $passes );

$workspace_spy = new Run_Control_Atomic_Spy_Store();
$workspace     = WP_Agent_Workspace_Scope::from_parts( 'site', 'atomic-ws' );
WP_Agent_Run_Control::set_store( $workspace_spy );
atomic_start( 'atomic-workspace', 'run-workspace', $workspace );
atomic_save( 'atomic-workspace', 'run-workspace', WP_Agent_Run_Control::STATUS_RUNNING, $workspace );
WP_Agent_Run_Control::finish_run( 'atomic-workspace', 'run-workspace', WP_Agent_Run_Control::STATUS_COMPLETED, $workspace );
WP_Agent_Run_Control::request_cancel( 'atomic-workspace', 'run-workspace', $workspace );
agents_api_smoke_assert_equals( 4, $workspace_spy->mutate_workspace_calls, 'all workspace lifecycle writes use mutate_workspace_state', $failures, $passes );
agents_api_smoke_assert_equals( 0, $workspace_spy->save_workspace_calls, 'workspace lifecycle writes never use unlocked save_workspace_state', $failures, $passes );

foreach ( array( null, $workspace ) as $scope ) {
	$scope_name = null === $scope ? 'site' : 'workspace';
	$race_spy   = new Run_Control_Atomic_Spy_Store();
	WP_Agent_Run_Control::set_store( $race_spy );
	atomic_start( 'unrelated-race', 'runner-run', $scope );
	$race_spy->before_mutation = static function ( Run_Control_Atomic_Spy_Store $store, string $store_key, ?WP_Agent_Workspace_Scope $active_scope ): void {
		$store->before_mutation = null;
		$store->inject_run( $store_key, 'concurrent-run', array( 'run_id' => 'concurrent-run', 'status' => 'running' ), $active_scope );
	};
	WP_Agent_Run_Control::finish_run( 'unrelated-race', 'runner-run', WP_Agent_Run_Control::STATUS_COMPLETED, $scope );
	agents_api_smoke_assert_equals( 'running', atomic_get_run( 'unrelated-race', 'concurrent-run', $scope )['status'] ?? null, "{$scope_name} atomic mutation preserves an unrelated concurrent run", $failures, $passes );

	$cancel_first = new Run_Control_Atomic_Spy_Store();
	WP_Agent_Run_Control::set_store( $cancel_first );
	atomic_start( 'cancel-first', 'same-run', $scope );
	WP_Agent_Run_Control::request_cancel( 'cancel-first', 'same-run', $scope );
	$cancelled = WP_Agent_Run_Control::finish_run( 'cancel-first', 'same-run', WP_Agent_Run_Control::STATUS_SUCCEEDED, $scope );
	agents_api_smoke_assert_equals( WP_Agent_Run_Control::STATUS_CANCELLED, $cancelled['status'] ?? null, "{$scope_name} committed cancellation wins a later successful finish", $failures, $passes );
	agents_api_smoke_assert_equals( true, $cancelled['cancelled'] ?? null, "{$scope_name} cancelled terminal state remains internally consistent", $failures, $passes );
	$saved_after_cancel = atomic_save( 'cancel-first', 'same-run', WP_Agent_Run_Control::STATUS_SUCCEEDED, $scope );
	agents_api_smoke_assert_equals( WP_Agent_Run_Control::STATUS_CANCELLED, $saved_after_cancel['status'] ?? null, "{$scope_name} save_run cannot replace a cancelled terminal state", $failures, $passes );

	$finish_first = new Run_Control_Atomic_Spy_Store();
	WP_Agent_Run_Control::set_store( $finish_first );
	atomic_start( 'finish-first', 'same-run', $scope );
	$finished = WP_Agent_Run_Control::finish_run( 'finish-first', 'same-run', WP_Agent_Run_Control::STATUS_SUCCEEDED, $scope );
	$events_before_noops = count( $finish_first->get_workspace_state( 'finish-first', $workspace )['events']['same-run'] ?? array() );
	if ( null === $scope ) {
		$events_before_noops = count( $finish_first->get_state( 'finish-first' )['events']['same-run'] ?? array() );
	}
	WP_Agent_Run_Control::request_cancel( 'finish-first', 'same-run', $scope );
	atomic_save( 'finish-first', 'same-run', WP_Agent_Run_Control::STATUS_RUNNING, $scope );
	atomic_start( 'finish-first', 'same-run', $scope );
	$terminal = WP_Agent_Run_Control::finish_run( 'finish-first', 'same-run', WP_Agent_Run_Control::STATUS_FAILED, $scope );
	agents_api_smoke_assert_equals( WP_Agent_Run_Control::STATUS_SUCCEEDED, $finished['status'] ?? null, "{$scope_name} finish commits success before a later cancellation", $failures, $passes );
	agents_api_smoke_assert_equals( WP_Agent_Run_Control::STATUS_SUCCEEDED, $terminal['status'] ?? null, "{$scope_name} terminal state is monotonic across every lifecycle writer", $failures, $passes );
	agents_api_smoke_assert_equals( false, $terminal['cancelled'] ?? false, "{$scope_name} cancellation after terminal does not create a contradictory flag", $failures, $passes );
	$events_after_noops = count( $finish_first->get_workspace_state( 'finish-first', $workspace )['events']['same-run'] ?? array() );
	if ( null === $scope ) {
		$events_after_noops = count( $finish_first->get_state( 'finish-first' )['events']['same-run'] ?? array() );
	}
	agents_api_smoke_assert_equals( $events_before_noops, $events_after_noops, "{$scope_name} post-terminal lifecycle requests are event-free no-ops", $failures, $passes );
}

$failure_spy               = new Run_Control_Atomic_Spy_Store();
$failure_spy->site_failure = new WP_Agent_Run_Control_Store_Exception( 'retry site mutation' );
WP_Agent_Run_Control::set_store( $failure_spy );
try {
	atomic_start( 'failed-site', 'run', null );
	$site_failure_propagated = false;
} catch ( WP_Agent_Run_Control_Store_Exception $error ) {
	$site_failure_propagated = 'retry site mutation' === $error->getMessage();
}
agents_api_smoke_assert_equals( true, $site_failure_propagated, 'typed site atomic failure propagates without fallback', $failures, $passes );
agents_api_smoke_assert_equals( 0, $failure_spy->save_calls, 'site atomic failure never falls back to unlocked save_state', $failures, $passes );

$failure_spy->site_failure      = null;
$failure_spy->workspace_failure = new WP_Agent_Run_Control_Store_Exception( 'retry workspace mutation' );
try {
	atomic_start( 'failed-workspace', 'run', $workspace );
	$workspace_failure_propagated = false;
} catch ( WP_Agent_Run_Control_Store_Exception $error ) {
	$workspace_failure_propagated = 'retry workspace mutation' === $error->getMessage();
}
agents_api_smoke_assert_equals( true, $workspace_failure_propagated, 'typed workspace atomic failure propagates without fallback', $failures, $passes );
agents_api_smoke_assert_equals( 0, $failure_spy->save_workspace_calls, 'workspace atomic failure never falls back to unlocked save_workspace_state', $failures, $passes );

WP_Agent_Run_Control::reset_store();
agents_api_smoke_finish( 'run-control atomic mutations', $failures, $passes );
