<?php
/**
 * Pure-PHP smoke test for mediated tool result truncation.
 *
 * Run with: php tests/tool-result-truncator-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-result-truncator-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );

		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array(
				'body' => str_repeat( 'x', 200 ),
			),
		);
	}
};

$tools = array(
	'client/large-result' => array(
		'name'        => 'client/large-result',
		'source'      => 'client',
		'description' => 'Return a large result.',
		'parameters'  => array( 'type' => 'object' ),
		'executor'    => 'client',
		'scope'       => 'run',
	),
);

$events = array();
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'large result please' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'call_large',
					'name'       => 'client/large-result',
					'parameters' => array(),
				),
			),
		);
	},
	array(
		'max_turns'             => 1,
		'tool_executor'         => $executor,
		'tool_declarations'     => $tools,
		'tool_result_truncator' => new AgentsAPI\AI\WP_Agent_Byte_Limit_Tool_Result_Truncator( 80 ),
		'on_event'              => static function ( string $event, array $payload ) use ( &$events ): void {
			$events[] = array(
				'event'   => $event,
				'payload' => $payload,
			);
		},
	)
);

$truncated_result = $result['tool_execution_results'][0]['result'] ?? array();

agents_api_smoke_assert_equals( true, (bool) ( $truncated_result['metadata']['truncated'] ?? false ), 'tool execution result is marked truncated', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $truncated_result['result']['excerpt'] ), 'tool execution result stores excerpt payload', $failures, $passes );
agents_api_smoke_assert_equals( false, str_contains( wp_json_encode( $truncated_result ), str_repeat( 'x', 200 ) ), 'truncated execution result omits full original body', $failures, $passes );

$tool_result_messages = array_values(
	array_filter(
		$result['messages'],
		static function ( array $message ): bool {
			return ( $message['type'] ?? '' ) === AgentsAPI\AI\WP_Agent_Message::TYPE_TOOL_RESULT;
		}
	)
);

agents_api_smoke_assert_equals( true, str_contains( $tool_result_messages[0]['content'] ?? '', '"truncated":true' ), 'tool result transcript stores truncated payload', $failures, $passes );
agents_api_smoke_assert_equals( false, str_contains( $tool_result_messages[0]['content'] ?? '', str_repeat( 'x', 200 ) ), 'tool result transcript omits full original body', $failures, $passes );

$truncation_events = array_values(
	array_filter(
		$events,
		static function ( array $event ): bool {
			return 'tool_result_truncated' === $event['event'];
		}
	)
);

agents_api_smoke_assert_equals( 1, count( $truncation_events ), 'tool_result_truncated event is emitted once', $failures, $passes );
agents_api_smoke_assert_equals( 'client/large-result', $truncation_events[0]['payload']['tool_name'] ?? '', 'truncation event includes tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'call_large', $truncation_events[0]['payload']['tool_call_id'] ?? '', 'truncation event includes tool call id', $failures, $passes );
agents_api_smoke_assert_equals( str_repeat( 'x', 200 ), $truncation_events[0]['payload']['original_result']['result']['body'] ?? '', 'truncation event exposes original result to observers', $failures, $passes );

$result_events = array_values(
	array_filter(
		$result['events'],
		static function ( array $event ): bool {
			return 'tool_result_truncated' === ( $event['type'] ?? '' );
		}
	)
);

agents_api_smoke_assert_equals( 1, count( $result_events ), 'normalized result includes truncation event', $failures, $passes );
agents_api_smoke_assert_equals( false, isset( $result_events[0]['metadata']['original_result'] ), 'normalized result event omits full original result', $failures, $passes );

$untruncated = ( new AgentsAPI\AI\WP_Agent_Byte_Limit_Tool_Result_Truncator( 10000 ) )->truncate_result(
	array(
		'success' => true,
		'result'  => array( 'ok' => true ),
	),
	'client/large-result'
);

agents_api_smoke_assert_equals( false, $untruncated['truncated'], 'byte-limit truncator leaves small results unchanged', $failures, $passes );

agents_api_smoke_finish( 'Agents API tool result truncator', $failures, $passes );
