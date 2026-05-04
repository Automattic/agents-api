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
$principal = AgentsAPI\AI\AgentExecutionPrincipal::user_session( 123, 'example-agent', AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST );
$request   = new AgentsAPI\AI\AgentConversationRequest(
	array(
		array( 'role' => 'user', 'content' => 'Hello' ),
	),
	array(
		array(
			'name'        => 'client/lookup',
			'description' => 'Look up something.',
			'executor'    => AgentsAPI\AI\Tools\RuntimeToolDeclaration::EXECUTOR_CLIENT,
			'scope'       => AgentsAPI\AI\Tools\RuntimeToolDeclaration::SCOPE_RUN,
		),
	),
	$principal,
	array( 'request_kind' => 'interactive' ),
	array( 'trace_id' => 'abc123' ),
	0,
	true
);

agents_api_smoke_assert_equals( AgentsAPI\AI\AgentMessageEnvelope::SCHEMA, $request->messages()[0]['schema'], 'request messages normalize to envelopes', $failures, $passes );
agents_api_smoke_assert_equals( $principal, $request->principal(), 'request exposes execution principal', $failures, $passes );
agents_api_smoke_assert_equals( 'interactive', $request->runtimeContext()['request_kind'], 'request preserves caller runtime context', $failures, $passes );
agents_api_smoke_assert_equals( 1, $request->maxTurns(), 'request enforces a positive turn budget', $failures, $passes );
agents_api_smoke_assert_equals( true, $request->singleTurn(), 'request preserves single-turn flag', $failures, $passes );
agents_api_smoke_assert_equals( 'abc123', $request->metadata()['trace_id'], 'request preserves caller metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'client/lookup', $request->tools()[0]['name'], 'request preserves normalized tool list', $failures, $passes );
agents_api_smoke_assert_equals(
	array( 'messages', 'tools', 'principal', 'runtime_context', 'metadata', 'max_turns', 'single_turn', 'workspace' ),
	array_keys( $request->to_array() ),
	'request array exposes neutral runner and workspace keys',
	$failures,
	$passes
);

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
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): AgentsAPI\AI\AgentConversationCompletionDecision {
		unset( $tool_def );

		return AgentsAPI\AI\AgentConversationCompletionDecision::complete(
			'tool completed',
			array(
				'tool_name'    => $tool_name,
				'turn_count'   => $turn_count,
				'success'      => $tool_result['success'] ?? false,
				'request_kind' => $runtime_context['request_kind'] ?? '',
			)
		);
	}
};

$decision = $policy->recordToolResult( 'client/lookup', null, array( 'success' => true ), $request->runtimeContext(), 2 );
agents_api_smoke_assert_equals( true, $decision->isComplete(), 'completion decision marks complete', $failures, $passes );
agents_api_smoke_assert_equals( 'tool completed', $decision->message(), 'completion decision exposes message', $failures, $passes );
agents_api_smoke_assert_equals( 2, $decision->context()['turn_count'], 'completion decision exposes context', $failures, $passes );
agents_api_smoke_assert_equals( 'interactive', $decision->context()['request_kind'], 'completion policy receives runtime context', $failures, $passes );
agents_api_smoke_assert_equals( true, $decision->to_array()['complete'], 'completion decision has array projection', $failures, $passes );
agents_api_smoke_assert_equals( false, AgentsAPI\AI\AgentConversationCompletionDecision::incomplete()->isComplete(), 'incomplete decision marks incomplete', $failures, $passes );

echo "\n[4] Transcript persisters expose a no-op implementation:\n";
$persister = new AgentsAPI\AI\NullAgentConversationTranscriptPersister();
agents_api_smoke_assert_equals( '', $persister->persist( $request->messages(), $request, $runner_result ), 'null persister declines persistence with empty ID', $failures, $passes );

echo "\n[5] Invalid request inputs fail early:\n";
$threw = false;
try {
	new AgentsAPI\AI\AgentConversationRequest(
		array( array( 'role' => 'user', 'content' => 'Hello' ) ),
		array( 'not-a-tool-array' )
	);
} catch ( InvalidArgumentException $error ) {
	$threw = str_starts_with( $error->getMessage(), 'invalid_agent_conversation_request:' );
}
agents_api_smoke_assert_equals( true, $threw, 'request rejects non-array tool declarations', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation runner contracts', $failures, $passes );
