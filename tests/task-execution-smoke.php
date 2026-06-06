<?php
/**
 * Pure-PHP smoke test for canonical task execution abilities.
 *
 * Run with: php tests/task-execution-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "task-execution-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_abilities']  = array();
$GLOBALS['__agents_api_smoke_categories'] = array();
$GLOBALS['__agents_api_smoke_options']    = array();

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

function current_user_can( string $capability ): bool {
	unset( $capability );
	return true;
}

function wp_has_ability_category( string $category ): bool {
	return isset( $GLOBALS['__agents_api_smoke_categories'][ $category ] );
}

function wp_register_ability_category( string $category, array $args ): void {
	$GLOBALS['__agents_api_smoke_categories'][ $category ] = $args;
}

function wp_has_ability( string $ability ): bool {
	return isset( $GLOBALS['__agents_api_smoke_abilities'][ $ability ] );
}

function wp_register_ability( string $ability, array $args ): void {
	$GLOBALS['__agents_api_smoke_abilities'][ $ability ] = $args;
}

function get_option( string $option, $default = false ) {
	return $GLOBALS['__agents_api_smoke_options'][ $option ] ?? $default;
}

function update_option( string $option, $value, $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['__agents_api_smoke_options'][ $option ] = $value;
	return true;
}

agents_api_smoke_require_module();

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

agents_api_smoke_assert_equals( true, isset( $GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\AI\Tasks\AGENTS_RUN_TASK_ABILITY ] ), 'run-task ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\AI\Tasks\AGENTS_LIST_EXECUTION_TARGETS_ABILITY ] ), 'list-execution-targets ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\AI\Tasks\AGENTS_GET_TASK_RUN_ABILITY ] ), 'get-task-run ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\AI\Tasks\AGENTS_CANCEL_TASK_RUN_ABILITY ] ), 'cancel-task-run ability registers', $failures, $passes );

$no_executor = AgentsAPI\AI\Tasks\agents_run_task(
	array(
		'task' => array(
			'id'           => 'task-no-executor',
			'instructions' => 'Do something generic.',
		),
	)
);
agents_api_smoke_assert_equals( true, $no_executor instanceof WP_Error, 'no executor returns typed error', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_task_no_handler', $no_executor->get_error_code(), 'no executor error code is typed', $failures, $passes );

$captured_input  = array();
$captured_target = array();
add_filter(
	'wp_agent_execution_targets',
	static function ( array $targets ): array {
		$targets[] = array(
			'id'               => 'fake-executor',
			'label'            => 'Fake Executor',
			'kind'             => 'test',
			'capabilities'     => array( 'test.run', 'artifacts.reference' ),
			'resource_classes' => array( 'generic', 'test' ),
			'metadata'         => array( 'provider' => 'smoke' ),
		);
		return $targets;
	}
);
add_filter(
	'wp_agent_task_handler',
	static function ( $handler, array $input, array $target ) use ( &$captured_input, &$captured_target ) {
		if ( null !== $handler ) {
			return $handler;
		}

		$captured_input  = $input;
		$captured_target = $target;
		return static function ( array $runtime_input, array $runtime_target ): array {
			return array(
				'run_id'            => $runtime_input['run_id'],
				'session_id'        => $runtime_input['session_id'],
				'executor_id'       => $runtime_target['id'],
				'status'            => 'succeeded',
				'execution_metrics' => array(
					'environment'         => 'test',
					'wall_time_ms'        => '42',
					'startup_time_ms'     => 7,
					'tool_call_count'     => 2,
					'per_tool_timings_ms' => array( 'inspect' => '11' ),
					'payload_bytes_in'    => 100,
					'payload_bytes_out'   => 200,
					'artifact_bytes'      => 300,
					'quality_signals'     => array( 'confidence' => 'high' ),
					'raw_refs'            => array(
						array(
							'id'   => 'metrics-raw-1',
							'type' => 'trace',
						),
					),
				),
				'artifact_refs'     => array(
					array(
						'id'   => 'artifact-1',
						'type' => 'log',
					),
				),
				'diagnostics'       => array( 'summary' => 'ok' ),
				'events'            => array(
					array(
						'id'   => 'evt-1',
						'type' => 'completed',
					),
				),
				'provenance'        => array( 'source' => 'fake' ),
				'output'            => array( 'answer' => 'done' ),
			);
		};
	},
	10,
	3
);

$targets = AgentsAPI\AI\Tasks\agents_list_execution_targets(
	array(
		'resource_class'        => 'test',
		'required_capabilities' => array( 'test.run' ),
	)
);
agents_api_smoke_assert_equals( 'fake-executor', $targets['targets'][0]['id'] ?? null, 'list-execution-targets filters registered target', $failures, $passes );

$result = AgentsAPI\AI\Tasks\agents_run_task(
	array(
		'task'      => array(
			'id'           => 'task-1',
			'instructions' => 'Run the fake task.',
		),
		'placement' => array(
			'preferred_target'      => 'fake-executor',
			'resource_class'        => 'test',
			'required_capabilities' => array( 'test.run' ),
		),
	)
);
agents_api_smoke_assert_equals( 'agents-api/task-input/v1', $captured_input['schema'] ?? null, 'run-task normalizes task input schema', $failures, $passes );
agents_api_smoke_assert_equals( 'agents-api/execution-placement/v1', $captured_input['placement']['schema'] ?? null, 'run-task normalizes placement schema', $failures, $passes );
agents_api_smoke_assert_equals( 'fake-executor', $captured_target['id'] ?? null, 'run-task dispatches selected target', $failures, $passes );
agents_api_smoke_assert_equals( 'agents-api/task-result/v1', $result['schema'] ?? null, 'run-task returns result envelope schema', $failures, $passes );
agents_api_smoke_assert_equals( 'succeeded', $result['status'] ?? null, 'run-task normalizes task result status', $failures, $passes );
agents_api_smoke_assert_equals( 'fake-executor', $result['executor_id'] ?? null, 'run-task preserves executor id', $failures, $passes );
agents_api_smoke_assert_equals( 'object', $GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\AI\Tasks\AGENTS_RUN_TASK_ABILITY ]['output_schema']['properties']['execution_metrics']['type'] ?? null, 'run-task result schema allows execution metrics', $failures, $passes );
agents_api_smoke_assert_equals( 'agents-api/execution-metrics/v1', $result['execution_metrics']['schema'] ?? null, 'run-task normalizes execution metrics schema', $failures, $passes );
agents_api_smoke_assert_equals( 'test', $result['execution_metrics']['environment'] ?? null, 'run-task preserves metrics environment', $failures, $passes );
agents_api_smoke_assert_equals( 'fake-executor', $result['execution_metrics']['executor_id'] ?? null, 'run-task fills metrics executor id', $failures, $passes );
agents_api_smoke_assert_equals( 42, $result['execution_metrics']['wall_time_ms'] ?? null, 'run-task normalizes metrics wall time', $failures, $passes );
agents_api_smoke_assert_equals( 11, $result['execution_metrics']['per_tool_timings_ms']['inspect'] ?? null, 'run-task normalizes per-tool timing metrics', $failures, $passes );
agents_api_smoke_assert_equals( 'artifact-1', $result['artifact_refs'][0]['id'] ?? null, 'run-task preserves artifact refs', $failures, $passes );

$stored = AgentsAPI\AI\Tasks\agents_get_task_run(
	array(
		'session_id' => $result['session_id'],
		'run_id'     => $result['run_id'],
	)
);
agents_api_smoke_assert_equals( 'succeeded', $stored['status'] ?? null, 'get-task-run reads stored normalized result', $failures, $passes );
agents_api_smoke_assert_equals( 'agents-api/execution-metrics/v1', $stored['execution_metrics']['schema'] ?? null, 'get-task-run preserves stored metrics schema', $failures, $passes );
agents_api_smoke_assert_equals( 300, $stored['execution_metrics']['artifact_bytes'] ?? null, 'get-task-run preserves stored metrics artifact bytes', $failures, $passes );
agents_api_smoke_assert_equals( 'metrics-raw-1', $stored['execution_metrics']['raw_refs'][0]['id'] ?? null, 'get-task-run preserves stored metrics raw refs', $failures, $passes );

AgentsAPI\AI\Tasks\WP_Agent_Task_Run_Control::start_run( 'task-run-cancel', 'task-session-cancel', 'fake-executor' );
$cancelled = AgentsAPI\AI\Tasks\agents_cancel_task_run(
	array(
		'session_id' => 'task-session-cancel',
		'run_id'     => 'task-run-cancel',
	)
);
agents_api_smoke_assert_equals( 'cancelling', $cancelled['status'] ?? null, 'cancel-task-run marks running runs as cancelling', $failures, $passes );
agents_api_smoke_assert_equals( true, $cancelled['cancelled'] ?? null, 'cancel-task-run reports cancellation accepted', $failures, $passes );

add_filter(
	'wp_agent_task_handler',
	static function (): callable {
		return static fn(): string => 'bad-result';
	},
	1,
	3
);

$invalid = AgentsAPI\AI\Tasks\agents_run_task(
	array(
		'task'      => array( 'id' => 'task-invalid-result' ),
		'placement' => array( 'preferred_target' => 'fake-executor' ),
	)
);
agents_api_smoke_assert_equals( true, $invalid instanceof WP_Error, 'invalid executor result returns typed error', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_task_invalid_result', $invalid->get_error_code(), 'invalid executor result error code is typed', $failures, $passes );

agents_api_smoke_finish( 'task execution', $failures, $passes );
