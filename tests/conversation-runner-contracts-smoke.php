<?php
/**
 * Pure-PHP smoke test for the Agents API conversation runner contracts.
 *
 * Run with: php tests/conversation-runner-contracts-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-runner-contracts-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Conversation requests normalize runner inputs:\n";
$request = AgentsAPI\AI\AgentConversationRequest::fromRunArgs(
	array(
		array( 'role' => 'user', 'content' => 'Hello' ),
	),
	array(
		array( 'name' => 'lookup', 'description' => 'Look up something.' ),
	),
	'example-provider',
	'example-model',
	'chat',
	array( 'trace_id' => 'abc123' ),
	0,
	true
);

agents_api_smoke_assert_equals( AgentsAPI\AI\AgentMessageEnvelope::SCHEMA, $request->messages()[0]['schema'], 'request messages normalize to envelopes', $failures, $passes );
agents_api_smoke_assert_equals( 'example-provider', $request->provider(), 'request exposes provider', $failures, $passes );
agents_api_smoke_assert_equals( 'example-model', $request->model(), 'request exposes model', $failures, $passes );
agents_api_smoke_assert_equals( 'chat', $request->mode(), 'request exposes mode', $failures, $passes );
agents_api_smoke_assert_equals( 1, $request->maxTurns(), 'request enforces a positive turn budget', $failures, $passes );
agents_api_smoke_assert_equals( true, $request->singleTurn(), 'request preserves single-turn flag', $failures, $passes );
agents_api_smoke_assert_equals( 'abc123', $request->payload()['trace_id'], 'request preserves runtime payload', $failures, $passes );
agents_api_smoke_assert_equals( 'lookup', $request->tools()[0]['name'], 'request preserves normalized tool list', $failures, $passes );

echo "\n[2] Runner interfaces accept request value objects and result arrays:\n";
$runner = new class() implements AgentsAPI\AI\AgentConversationRunnerInterface {
	public function run( AgentsAPI\AI\AgentConversationRequest $request ): array {
		return AgentsAPI\AI\AgentConversationResult::normalize(
			array(
				'messages' => $request->messages(),
			)
		);
	}
};

$runner_result = $runner->run( $request );
agents_api_smoke_assert_equals( 1, count( $runner_result['messages'] ), 'runner returns normalized messages', $failures, $passes );
agents_api_smoke_assert_equals( array(), $runner_result['tool_execution_results'], 'runner result normalization provides empty tool results', $failures, $passes );

echo "\n[3] Completion decisions and policies are immutable value contracts:\n";
$policy = new class() implements AgentsAPI\AI\AgentConversationCompletionPolicyInterface {
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, string $mode, int $turn_count ): AgentsAPI\AI\AgentConversationCompletionDecision {
		unset( $tool_def, $mode );

		return AgentsAPI\AI\AgentConversationCompletionDecision::complete(
			'tool completed',
			array(
				'tool_name'  => $tool_name,
				'turn_count' => $turn_count,
				'success'    => $tool_result['success'] ?? false,
			)
		);
	}
};

$decision = $policy->recordToolResult( 'lookup', null, array( 'success' => true ), 'chat', 2 );
agents_api_smoke_assert_equals( true, $decision->isComplete(), 'completion decision marks complete', $failures, $passes );
agents_api_smoke_assert_equals( 'tool completed', $decision->message(), 'completion decision exposes message', $failures, $passes );
agents_api_smoke_assert_equals( 2, $decision->context()['turn_count'], 'completion decision exposes context', $failures, $passes );
agents_api_smoke_assert_equals( true, $decision->to_array()['complete'], 'completion decision has array projection', $failures, $passes );
agents_api_smoke_assert_equals( false, AgentsAPI\AI\AgentConversationCompletionDecision::incomplete()->isComplete(), 'incomplete decision marks incomplete', $failures, $passes );

echo "\n[4] Transcript persisters expose a no-op implementation:\n";
$persister = new AgentsAPI\AI\NullAgentConversationTranscriptPersister();
agents_api_smoke_assert_equals( true, $persister instanceof AgentsAPI\AI\AgentConversationTranscriptPersisterInterface, 'null persister implements contract', $failures, $passes );
agents_api_smoke_assert_equals( '', $persister->persist( $request->messages(), $request->provider(), $request->model(), $request->payload(), $runner_result ), 'null persister declines persistence with empty ID', $failures, $passes );

echo "\n[5] Invalid request inputs fail early:\n";
$threw = false;
try {
	new AgentsAPI\AI\AgentConversationRequest(
		array( array( 'role' => 'user', 'content' => 'Hello' ) ),
		array( 'not-a-tool-array' ),
		array( 'provider' => 'example-provider', 'model' => 'example-model' ),
		'chat'
	);
} catch ( InvalidArgumentException $error ) {
	$threw = str_starts_with( $error->getMessage(), 'invalid_agent_conversation_request:' );
}
agents_api_smoke_assert_equals( true, $threw, 'request rejects non-array tool declarations', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation runner contracts', $failures, $passes );
