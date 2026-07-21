<?php
/**
 * Pure-PHP smoke test for canonical run lifecycle controls.
 *
 * Run with: php tests/canonical-run-lifecycle-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "canonical-run-lifecycle-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		unset( $capability );
		return true;
	}
}

agents_api_smoke_require_module();

final class Agents_API_Smoke_Run_Control_Store implements AgentsAPI\AI\WP_Agent_Atomic_Run_Control_Store {
	/** @var array<string,array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}> */
	private array $states = array();

	public function get_state( string $store_key ): array {
		return $this->states[ $store_key ] ?? array(
			'runs'   => array(),
			'queues' => array(),
			'events' => array(),
		);
	}

	public function save_state( string $store_key, array $state ): void {
		$this->states[ $store_key ] = array(
			'runs'   => is_array( $state['runs'] ?? null ) ? $state['runs'] : array(),
			'queues' => is_array( $state['queues'] ?? null ) ? $state['queues'] : array(),
			'events' => is_array( $state['events'] ?? null ) ? $state['events'] : array(),
		);
	}

	public function mutate_state( string $store_key, callable $mutation ): mixed {
		$mutated = $mutation( $this->get_state( $store_key ) );
		$this->save_state( $store_key, $mutated['state'] );
		return $mutated['result'];
	}
}

AgentsAPI\AI\WP_Agent_Run_Control::set_store( new Agents_API_Smoke_Run_Control_Store() );

AgentsAPI\AI\WP_Agent_Run_Control::start_run( 'smoke_store', 'run-1', array( 'metadata' => array( 'kind' => 'unit' ) ) );
$cancelled = AgentsAPI\AI\WP_Agent_Run_Control::request_cancel( 'smoke_store', 'run-1' );
$events    = AgentsAPI\AI\WP_Agent_Run_Control::list_events( 'smoke_store', 'run-1' );
agents_api_smoke_assert_equals( 'cancelling', $cancelled['status'] ?? null, 'generic run-control accepts cancellation', $failures, $passes );
agents_api_smoke_assert_equals( true, count( $events['events'] ?? array() ) >= 2, 'generic run-control records lifecycle events', $failures, $passes );
agents_api_smoke_assert_equals( 'cancel_requested', $events['events'][1]['type'] ?? null, 'generic run-control records cancellation event', $failures, $passes );

AgentsAPI\AI\WP_Agent_Run_Control::start_run(
	AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner::RUN_CONTROL_STORE,
	'workflow-run-1',
	array( 'workflow_id' => 'demo-workflow' )
);
$workflow_status = AgentsAPI\AI\Workflows\agents_get_workflow_run( array( 'run_id' => 'workflow-run-1' ) );
$workflow_cancel = AgentsAPI\AI\Workflows\agents_cancel_workflow_run( array( 'run_id' => 'workflow-run-1' ) );
$workflow_events = AgentsAPI\AI\Workflows\agents_list_workflow_run_events( array( 'run_id' => 'workflow-run-1' ) );
agents_api_smoke_assert_equals( 'demo-workflow', $workflow_status['workflow_id'] ?? null, 'workflow get-run reads shared run-control state', $failures, $passes );
agents_api_smoke_assert_equals( true, $workflow_cancel['cancelled'] ?? null, 'workflow cancel-run marks cancellation requested', $failures, $passes );
agents_api_smoke_assert_equals( 'cancel_requested', $workflow_events['events'][1]['type'] ?? null, 'workflow list-events exposes lifecycle events', $failures, $passes );

add_filter(
	'wp_agent_runtime_package_run_handler',
	static function ( $handler, AgentsAPI\AI\WP_Agent_Runtime_Package_Run_Request $request, array $input ) {
		unset( $handler, $request, $input );
		return static function ( AgentsAPI\AI\WP_Agent_Runtime_Package_Run_Request $request, array $input ): array {
			unset( $request );
			return array(
				'run_id' => $input['run_id'],
				'status' => 'succeeded',
				'result' => array( 'ok' => true ),
			);
		};
	},
	10,
	3
);
$runtime_result = AgentsAPI\AI\agents_runtime_package_run_dispatch(
	array(
		'run_id'   => 'runtime-run-1',
		'package'  => array( 'slug' => 'portable-package' ),
		'workflow' => array( 'id' => 'demo' ),
	)
);
$runtime_status = AgentsAPI\AI\agents_get_runtime_package_run( array( 'run_id' => 'runtime-run-1' ) );
$runtime_events = AgentsAPI\AI\agents_list_runtime_package_run_events( array( 'run_id' => 'runtime-run-1' ) );
agents_api_smoke_assert_equals( true, $runtime_result['result']['ok'] ?? null, 'runtime package run returns handler result', $failures, $passes );
agents_api_smoke_assert_equals( 'succeeded', $runtime_status['status'] ?? null, 'runtime package get-run reads shared run-control state', $failures, $passes );
agents_api_smoke_assert_equals( true, count( $runtime_events['events'] ?? array() ) >= 2, 'runtime package list-events exposes lifecycle events', $failures, $passes );

$turns   = 0;
$loop_id = 'loop-run-1';
$loop    = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( AgentsAPI\AI\WP_Agent_Message::text( 'user', 'hello' ) ),
	static function ( array $messages, array $context ) use ( &$turns, $loop_id ): array {
		unset( $messages, $context );
		++$turns;
		AgentsAPI\AI\WP_Agent_Chat_Run_Control::request_cancel( $loop_id );
		return array(
			'messages'  => array( AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'working' ) ),
			'completed' => false,
		);
	},
	array(
		'run_id'                => $loop_id,
		'transcript_session_id' => 'loop-session-1',
		'max_turns'             => 3,
		'should_continue'       => static fn(): bool => true,
	)
);
agents_api_smoke_assert_equals( 1, $turns, 'conversation loop stops after provider-turn cancellation', $failures, $passes );
agents_api_smoke_assert_equals( 'interrupted', $loop['status'] ?? null, 'conversation loop reports cancellation interruption', $failures, $passes );

agents_api_smoke_finish( 'canonical run lifecycle', $failures, $passes );
