<?php
/**
 * Pure-PHP smoke test for WP_Agent_Tool_Mediation_Runner.
 *
 * Run with: php tests/tool-mediation-runner-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-mediation-runner-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	/** @var array<int, array<string, mixed>> Executed calls. */
	public array $executed = array();

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		$this->executed[] = array(
			'tool_call' => $tool_call,
			'context'   => $context,
		);

		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array(
				'echo' => $tool_call['parameters']['text'] ?? '',
			),
		);
	}
};

$tools = array(
	'client/echo' => array(
		'name'        => 'client/echo',
		'source'      => 'client',
		'description' => 'Echo text.',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'text' ),
			'properties' => array(
				'text'   => array( 'type' => 'string' ),
				'secret' => array(
					'type'        => 'string',
					'x-sensitive' => true,
				),
			),
		),
	),
);

$events = array();
$result = AgentsAPI\AI\WP_Agent_Tool_Mediation_Runner::run(
	array( array( 'role' => 'user', 'content' => 'echo this' ) ),
	array(
		'content'    => 'I will call a tool.',
		'tool_calls' => array(
			array(
				'id'         => 'call_runner_1',
				'name'       => 'client/echo',
				'parameters' => array(
					'text'   => 'hello',
					'secret' => 'keep-private',
				),
			),
		),
	),
	$executor,
	$tools,
	array(
		'turn'         => 2,
		'turn_context' => array( 'run_id' => 'run-123' ),
		'on_event'     => static function ( string $event, array $payload ) use ( &$events ): void {
			$events[] = array(
				'event'   => $event,
				'payload' => $payload,
			);
		},
	)
);

agents_api_smoke_assert_equals( 1, count( $executor->executed ), 'runner executes one normalized tool call', $failures, $passes );
agents_api_smoke_assert_equals( 'client/echo', $executor->executed[0]['tool_call']['tool_name'] ?? '', 'executor receives canonical tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'call_runner_1', $executor->executed[0]['tool_call']['metadata']['tool_call_id'] ?? '', 'executor receives provider tool call id metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'run-123', $executor->executed[0]['context']['run_id'] ?? '', 'executor receives turn context', $failures, $passes );
agents_api_smoke_assert_equals( 'I will call a tool.', $result['messages'][1]['content'] ?? '', 'runner appends assistant content before tool messages', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $result['tool_execution_results'] ), 'runner returns normalized execution result', $failures, $passes );
agents_api_smoke_assert_equals( '[redacted]', $result['tool_execution_results'][0]['parameters']['secret'] ?? '', 'runner redacts sensitive parameters in public results', $failures, $passes );
agents_api_smoke_assert_equals( true, str_starts_with( $result['tool_execution_results'][0]['parameters_sha256'] ?? '', 'sha256:' ), 'runner hashes public parameter envelope', $failures, $passes );
agents_api_smoke_assert_equals( array( 'tool_call', 'tool_result' ), array_column( $result['tool_events'], 'type' ), 'runner returns canonical tool events', $failures, $passes );
agents_api_smoke_assert_equals( 'call_runner_1', $result['tool_audit_events'][0]['tool_call_id'] ?? '', 'runner returns stable audit event', $failures, $passes );
agents_api_smoke_assert_equals( true, ! str_contains( wp_json_encode( $result['tool_audit_events'][0] ), 'keep-private' ), 'runner audit event omits raw sensitive parameters', $failures, $passes );
agents_api_smoke_assert_equals( array( 'tool_call', 'tool_result' ), array_column( $events, 'event' ), 'runner emits observer events', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo '- ' . $failure . "\n";
	}
	exit( 1 );
}

echo "\nPassed {$passes} assertions.\n";
