<?php
/**
 * Pure-PHP smoke test proving generic run-control lifecycle mutations use the
 * store's atomic read-modify-write path instead of a non-atomic
 * state() -> mutate -> save_state() sequence.
 *
 * A non-atomic read-modify-write leaves a lost-update window: a workflow
 * request_cancel that reads state, then a runner finish_run that also reads the
 * same base state, will each overwrite the other on save. Routing every
 * lifecycle mutation through the store's atomic mutate_state closes that window.
 *
 * Run with: php tests/run-control-atomic-mutations-smoke.php
 *
 * @package AgentsAPI\Tests
 */

use AgentsAPI\AI\WP_Agent_Atomic_Workspace_Run_Control_Store;
use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

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

/**
 * Store spy that separates the atomic mutate path from the non-atomic
 * save_state path so a test can assert which one a lifecycle mutation took.
 * Its mutate_state deliberately does NOT call save_state, so the two counters
 * never overlap.
 */
final class Run_Control_Atomic_Spy_Store implements WP_Agent_Atomic_Workspace_Run_Control_Store {

	public int $mutate_calls           = 0;
	public int $mutate_workspace_calls = 0;
	public int $save_calls             = 0;
	public int $save_workspace_calls   = 0;

	/** @var callable|null Runs inside mutate_state before the mutation, to simulate a concurrent committed write. */
	public $before_mutation = null;

	/** @var array<string,array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}> */
	private array $states = array();

	public function get_state( string $store_key ): array {
		return $this->states[ 'site:' . $store_key ] ?? $this->empty_state();
	}

	public function save_state( string $store_key, array $state ): void {
		++$this->save_calls;
		$this->states[ 'site:' . $store_key ] = $state;
	}

	public function mutate_state( string $store_key, callable $mutation ): mixed {
		++$this->mutate_calls;
		if ( is_callable( $this->before_mutation ) ) {
			( $this->before_mutation )( $this, $store_key );
		}
		$mutated                              = $mutation( $this->states[ 'site:' . $store_key ] ?? $this->empty_state() );
		$this->states[ 'site:' . $store_key ] = $mutated['state'];
		return $mutated['result'];
	}

	public function get_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace ): array {
		return $this->states[ 'workspace:' . $workspace->key() . ':' . $store_key ] ?? $this->empty_state();
	}

	public function save_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, array $state ): void {
		++$this->save_workspace_calls;
		$this->states[ 'workspace:' . $workspace->key() . ':' . $store_key ] = $state;
	}

	public function mutate_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, callable $mutation ): mixed {
		++$this->mutate_workspace_calls;
		$key                  = 'workspace:' . $workspace->key() . ':' . $store_key;
		$mutated              = $mutation( $this->states[ $key ] ?? $this->empty_state() );
		$this->states[ $key ] = $mutated['state'];
		return $mutated['result'];
	}

	/** Inject a run directly into stored state, bypassing the counters. */
	public function inject_run( string $store_key, string $run_id, array $run ): void {
		$state                      = $this->states[ 'site:' . $store_key ] ?? $this->empty_state();
		$state['runs'][ $run_id ]   = $run;
		$this->states[ 'site:' . $store_key ] = $state;
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	private function empty_state(): array {
		return array( 'runs' => array(), 'queues' => array(), 'events' => array() );
	}
}

// --- Section A: site-local lifecycle mutations route through mutate_state ---
$spy = new Run_Control_Atomic_Spy_Store();
WP_Agent_Run_Control::set_store( $spy );

WP_Agent_Run_Control::start_run( 'atomic-store', 'run-a' );
WP_Agent_Run_Control::save_run( 'atomic-store', array( 'run_id' => 'run-a', 'status' => 'running' ) );
WP_Agent_Run_Control::finish_run( 'atomic-store', 'run-a', WP_Agent_Run_Control::STATUS_COMPLETED );
WP_Agent_Run_Control::request_cancel( 'atomic-store', 'run-a' );

agents_api_smoke_assert_equals( 4, $spy->mutate_calls, 'start/save/finish/cancel all route through the atomic mutate_state path', $failures, $passes );
agents_api_smoke_assert_equals( 0, $spy->save_calls, 'lifecycle mutations never use the non-atomic save_state path', $failures, $passes );

// --- Section B: workspace lifecycle mutations route through mutate_workspace_state ---
$workspace_spy = new Run_Control_Atomic_Spy_Store();
WP_Agent_Run_Control::set_store( $workspace_spy );
$workspace = WP_Agent_Workspace_Scope::from_parts( 'site', 'atomic-ws' );

WP_Agent_Run_Control::start_run( 'atomic-store', 'run-w', array(), $workspace );
WP_Agent_Run_Control::finish_run( 'atomic-store', 'run-w', WP_Agent_Run_Control::STATUS_COMPLETED, $workspace );
WP_Agent_Run_Control::request_cancel( 'atomic-store', 'run-w', $workspace );

agents_api_smoke_assert_equals( 3, $workspace_spy->mutate_workspace_calls, 'workspace lifecycle mutations route through the atomic mutate_workspace_state path', $failures, $passes );
agents_api_smoke_assert_equals( 0, $workspace_spy->save_workspace_calls, 'workspace lifecycle mutations never use the non-atomic save_workspace_state path', $failures, $passes );

// --- Section C: an interleaved concurrent write is not lost ---
// Simulate a second writer that commits a different run between our read and
// write. A non-atomic read-modify-write would clobber it; the atomic path reads
// current state inside the mutation and preserves it.
$race_spy = new Run_Control_Atomic_Spy_Store();
WP_Agent_Run_Control::set_store( $race_spy );

WP_Agent_Run_Control::start_run( 'race-store', 'runner-run' );
$race_spy->before_mutation = static function ( Run_Control_Atomic_Spy_Store $store, string $store_key ): void {
	$store->before_mutation = null; // Fire exactly once.
	$store->inject_run( $store_key, 'concurrent-run', array( 'run_id' => 'concurrent-run', 'status' => 'running' ) );
};

$finished = WP_Agent_Run_Control::finish_run( 'race-store', 'runner-run', WP_Agent_Run_Control::STATUS_COMPLETED );

agents_api_smoke_assert_equals( 'completed', $finished['status'] ?? null, 'finish_run still commits its own terminal status', $failures, $passes );
$concurrent = WP_Agent_Run_Control::get_run( 'race-store', 'concurrent-run' );
agents_api_smoke_assert_equals( 'running', $concurrent['status'] ?? null, 'a concurrently committed run survives an interleaved lifecycle mutation', $failures, $passes );
$runner = WP_Agent_Run_Control::get_run( 'race-store', 'runner-run' );
agents_api_smoke_assert_equals( 'completed', $runner['status'] ?? null, 'the mutated run keeps its committed status alongside the concurrent run', $failures, $passes );

agents_api_smoke_finish( 'run-control atomic mutations', $failures, $passes );
