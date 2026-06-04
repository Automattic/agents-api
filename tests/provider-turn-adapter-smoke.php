<?php
/**
 * Pure-PHP smoke test for provider-turn adapter contracts.
 *
 * Run with: php tests/provider-turn-adapter-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-provider-turn-adapter-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$tools = array(
	'client/lookup' => array(
		'name'        => 'client/lookup',
		'source'      => 'client',
		'description' => 'Look up one value.',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'query' ),
			'properties' => array(
				'query' => array( 'type' => 'string' ),
			),
		),
		'executor'    => 'client',
		'scope'       => 'run',
	),
);

$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	/** @var array<int, array<string, mixed>> Executed tool calls. */
	public array $executed = array();

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );
		$this->executed[] = $tool_call;

		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array( 'value' => 'lookup:' . (string) ( $tool_call['parameters']['query'] ?? '' ) ),
		);
	}
};

echo "\n[1] Provider-turn request and result contracts normalize neutral fields:\n";
$request = new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	$tools,
	array( 'provider_id' => 'fake-provider', 'model_id' => 'fake-model' ),
	array( 'runtime_id' => 'fake-runtime' ),
	array( 'turn' => 1 ),
	array( 'turns' => array( 'current' => 0, 'limit' => 3 ) ),
	'run-123',
	'session-456',
	array( 'trace_id' => 'trace-789' )
);

agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Message::SCHEMA, $request->messages()[0]['schema'], 'provider request messages normalize to canonical envelopes', $failures, $passes );
agents_api_smoke_assert_equals( 'client/lookup', array_key_first( $request->toolDeclarations() ), 'provider request carries keyed tool declarations', $failures, $passes );
agents_api_smoke_assert_equals( 'fake-provider', $request->model()['provider_id'], 'provider request carries provider metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'fake-runtime', $request->runtime()['runtime_id'], 'provider request carries runtime metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'run-123', $request->runId(), 'provider request carries run id', $failures, $passes );
agents_api_smoke_assert_equals( 'session-456', $request->sessionId(), 'provider request carries session id', $failures, $passes );

$host_tool = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalizeForConversationRequest(
	array(
		'name'        => 'workspace_read',
		'description' => 'Read a workspace file.',
		'parameters'  => array( 'type' => 'object' ),
	)
);
agents_api_smoke_assert_equals( 'workspace_read', $host_tool['name'], 'host tool declarations allow unnamespaced names', $failures, $passes );
agents_api_smoke_assert_equals( 'host', $host_tool['source'], 'unnamespaced host tools receive a host source', $failures, $passes );

$normalized_turn = AgentsAPI\AI\WP_Agent_Provider_Turn_Result::normalize(
	array(
		'content'              => 'Using a tool.',
		'tool_calls'           => array(
			array(
				'id'         => 'call-1',
				'name'       => 'client/lookup',
				'parameters' => array( 'query' => 'alpha' ),
			),
		),
		'usage'                => array( 'prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3 ),
		'request_metadata'     => array( 'provider_request_id' => 'req-1' ),
		'provider_diagnostics' => array( 'latency_ms' => 12 ),
	)
);
agents_api_smoke_assert_equals( 'client/lookup', $normalized_turn['tool_calls'][0]['name'], 'provider result normalizes tool call names', $failures, $passes );
agents_api_smoke_assert_equals( 3, $normalized_turn['usage']['total_tokens'], 'provider result preserves usage', $failures, $passes );

echo "\n[2] Conversation loop can run through a fake provider-turn adapter without Data Machine:\n";
$adapter_calls = array();
$adapter       = new class( $adapter_calls ) implements AgentsAPI\AI\WP_Agent_Provider_Turn_Adapter {
	/** @var array<int, array<string, mixed>> Captured requests. */
	public array $requests = array();

	/**
	 * @param array<int, array<string, mixed>> $unused Unused initial calls.
	 */
	public function __construct( array $unused ) {
		unset( $unused );
	}

	public function run_turn( AgentsAPI\AI\WP_Agent_Provider_Turn_Request $request ): array {
		$this->requests[] = $request->to_array();
		$turn             = (int) ( $request->context()['turn'] ?? 0 );

		if ( 1 === $turn ) {
			return array(
				'content'              => 'I need one lookup.',
				'tool_calls'           => array(
					array(
						'id'         => 'call-provider-1',
						'name'       => 'client/lookup',
						'parameters' => array( 'query' => 'alpha' ),
					),
				),
				'usage'                => array( 'prompt_tokens' => 5, 'completion_tokens' => 7, 'total_tokens' => 12 ),
				'request_metadata'     => array( 'provider_request_id' => 'provider-req-1' ),
				'provider_diagnostics' => array( 'turn' => 1, 'transport' => 'fake' ),
			);
		}

		return array(
			'content'              => 'Final answer from fake adapter.',
			'tool_calls'           => array(),
			'usage'                => array( 'prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 7 ),
			'request_metadata'     => array( 'provider_request_id' => 'provider-req-2' ),
			'provider_diagnostics' => array( 'turn' => 2, 'transport' => 'fake' ),
		);
	}
};

$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'look up alpha' ) ),
	null,
	array(
		'provider_turn_adapter' => $adapter,
		'tool_executor'         => $executor,
		'tool_declarations'     => $tools,
		'max_turns'             => 3,
		'run_id'                => 'run-loop-1',
		'session_id'            => 'session-loop-1',
		'context'               => array(
			'provider_id'  => 'fake-provider',
			'model_id'     => 'fake-model',
			'request_kind' => 'smoke',
		),
	)
);

agents_api_smoke_assert_equals( 2, count( $adapter->requests ), 'loop called provider adapter for tool and final turns', $failures, $passes );
agents_api_smoke_assert_equals( 'fake-provider', $adapter->requests[0]['model']['provider_id'], 'provider adapter receives model metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'smoke', $adapter->requests[0]['runtime']['request_kind'], 'provider adapter receives runtime metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'run-loop-1', $adapter->requests[0]['run_id'], 'provider adapter receives run id', $failures, $passes );
agents_api_smoke_assert_equals( 'session-loop-1', $adapter->requests[0]['session_id'], 'provider adapter receives session id', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $executor->executed ), 'loop mediated adapter tool call through executor', $failures, $passes );
agents_api_smoke_assert_equals( 'call-provider-1', $executor->executed[0]['id'] ?? '', 'loop preserved provider tool call id', $failures, $passes );
agents_api_smoke_assert_equals( 'Final answer from fake adapter.', $result['final_content'], 'loop surfaces adapter final assistant content', $failures, $passes );
agents_api_smoke_assert_equals( 19, $result['usage']['total_tokens'], 'loop accumulates adapter usage', $failures, $passes );
agents_api_smoke_assert_equals( 'provider-req-2', $result['request_metadata']['provider_request_id'], 'loop surfaces latest provider request metadata', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $result['provider_diagnostics'] ), 'loop accumulates provider diagnostics per turn', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Result::OUTCOME_STATUS_COMPLETED, $result['run_outcome']['status'] ?? '', 'loop successful run outcome is completed', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Result::OUTCOME_STOP_NATURAL, $result['run_outcome']['stop_reason'] ?? '', 'loop successful run outcome stop reason is natural', $failures, $passes );

echo "\n[3] Content-only provider-turn adapters work without tool mediation:\n";
$content_only_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'answer only' ) ),
	null,
	array(
		'provider_turn_adapter' => static function ( AgentsAPI\AI\WP_Agent_Provider_Turn_Request $unused ): array {
			unset( $unused );
			return array(
				'content'          => 'Plain assistant answer.',
				'request_metadata' => array( 'provider_request_id' => 'content-only-1' ),
			);
		},
	)
);

agents_api_smoke_assert_equals( 'Plain assistant answer.', $content_only_result['final_content'], 'content-only adapter result becomes final assistant content', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $content_only_result['messages'] ), 'content-only adapter appends one assistant message', $failures, $passes );
agents_api_smoke_assert_equals( 'content-only-1', $content_only_result['request_metadata']['provider_request_id'], 'content-only adapter preserves request metadata', $failures, $passes );

echo "\n[4] Provider-turn adapters can append continuation messages before the next turn:\n";
$continuation_adapter = new class() implements AgentsAPI\AI\WP_Agent_Provider_Turn_Adapter {
	/** @var array<int, array<string, mixed>> Captured requests. */
	public array $requests = array();

	public function run_turn( AgentsAPI\AI\WP_Agent_Provider_Turn_Request $request ): array {
		$this->requests[] = $request->to_array();
		$turn             = (int) ( $request->context()['turn'] ?? 0 );

		if ( 1 === $turn ) {
			return array(
				'content'               => 'I am not done yet.',
				'continuation_messages' => array(
					array(
						'role'    => 'user',
						'content' => 'Please continue until the task is complete.',
					),
				),
			);
		}

		return array(
			'content' => 'Complete after continuation.',
		);
	}
};

$continuation_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'finish with a continuation if needed' ) ),
	null,
	array(
		'provider_turn_adapter' => $continuation_adapter,
		'max_turns'             => 3,
		'should_continue'       => static function ( array $turn_result ): bool {
			return ! empty( $turn_result['continuation_messages'] );
		},
	)
);

agents_api_smoke_assert_equals( 2, count( $continuation_adapter->requests ), 'continuation message causes a second provider turn', $failures, $passes );
agents_api_smoke_assert_equals( 'Please continue until the task is complete.', $continuation_adapter->requests[1]['messages'][2]['content'] ?? '', 'second provider turn receives continuation message', $failures, $passes );
agents_api_smoke_assert_equals( 'Complete after continuation.', $continuation_result['final_content'], 'continuation run surfaces final content', $failures, $passes );
agents_api_smoke_assert_equals( 'continuation_message_added', $continuation_result['events'][0]['type'] ?? '', 'continuation run records an event', $failures, $passes );

echo "\n[5] Provider-turn typed failures become canonical failed loop results:\n";
$failing_adapter = static function ( AgentsAPI\AI\WP_Agent_Provider_Turn_Request $unused ): array {
	unset( $unused );
	return array(
		'failure'              => array(
			'type'    => 'rate_limit',
			'message' => 'Provider rate limit exceeded.',
		),
		'provider_diagnostics' => array( 'retry_after_seconds' => 30 ),
	);
};

$failure_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'fail' ) ),
	null,
	array(
		'provider_turn_adapter' => $failing_adapter,
		'tool_executor'         => $executor,
		'tool_declarations'     => $tools,
	)
);

agents_api_smoke_assert_equals( 'failed', $failure_result['status'] ?? '', 'typed provider failure sets failed status', $failures, $passes );
agents_api_smoke_assert_equals( false, $failure_result['completed'] ?? true, 'typed provider failure is incomplete', $failures, $passes );
agents_api_smoke_assert_equals( 'rate_limit', $failure_result['failure']['type'] ?? '', 'typed provider failure preserves type', $failures, $passes );
agents_api_smoke_assert_equals( 30, $failure_result['provider_diagnostics'][0]['retry_after_seconds'] ?? 0, 'typed provider failure preserves diagnostics', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Result::OUTCOME_STATUS_FAILED, $failure_result['run_outcome']['status'] ?? '', 'provider failure run outcome is failed', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Result::OUTCOME_STOP_PROVIDER_ERROR, $failure_result['run_outcome']['stop_reason'] ?? '', 'provider failure run outcome stop reason is provider error', $failures, $passes );
agents_api_smoke_assert_equals( 'rate_limit', $failure_result['run_outcome']['provider_error']['type'] ?? '', 'provider failure run outcome preserves provider error', $failures, $passes );

agents_api_smoke_finish( 'Agents API provider-turn adapter', $failures, $passes );
