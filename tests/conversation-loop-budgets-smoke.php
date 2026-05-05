<?php
/**
 * Pure-PHP smoke test for WP_Agent_Iteration_Budget enforcement in WP_Agent_Conversation_Loop.
 *
 * Run with: php tests/conversation-loop-budgets-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-loop-budgets-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

// Reusable tool executor for mediated tests.
$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array( 'ok' => true ),
		);
	}
};

$tools = array(
	'client/work' => array(
		'name'        => 'client/work',
		'source'      => 'client',
		'description' => 'Do work.',
		'parameters'  => array(),
		'executor'    => 'client',
		'scope'       => 'run',
	),
	'client/ping' => array(
		'name'        => 'client/ping',
		'source'      => 'client',
		'description' => 'Ping.',
		'parameters'  => array(),
		'executor'    => 'client',
		'scope'       => 'run',
	),
);

echo "\n[1] max_turns only — existing behavior preserved:\n";
$turn_count = 0;
$result     = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ) use ( &$turn_count ): array {
		++$turn_count;
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'turn ' . $context['turn'] );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'       => 3,
		'should_continue' => static function (): bool {
			return true;
		},
	)
);

agents_api_smoke_assert_equals( 3, $turn_count, 'loop runs exactly max_turns turns', $failures, $passes );
agents_api_smoke_assert_equals( false, isset( $result['status'] ), 'no budget_exceeded status for max_turns exit', $failures, $passes );

echo "\n[2] Explicit turns budget stops loop with budget_exceeded status:\n";
$turn_count = 0;
$result     = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ) use ( &$turn_count ): array {
		++$turn_count;
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'turn ' . $context['turn'] );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'       => 10,
		'budgets'         => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'turns', 2 ),
		),
		'should_continue' => static function (): bool {
			return true;
		},
	)
);

agents_api_smoke_assert_equals( 2, $turn_count, 'loop stops after turns budget ceiling', $failures, $passes );
agents_api_smoke_assert_equals( 'budget_exceeded', $result['status'] ?? null, 'result has budget_exceeded status', $failures, $passes );
agents_api_smoke_assert_equals( 'turns', $result['budget'] ?? null, 'result identifies turns budget', $failures, $passes );

echo "\n[3] tool_calls budget stops loop mid-execution:\n";
$tool_call_count = 0;
$event_log       = array();
$result          = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ) use ( &$tool_call_count ): array {
		++$tool_call_count;

		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'name' => 'client/work', 'parameters' => array() ),
				array( 'name' => 'client/work', 'parameters' => array() ),
				array( 'name' => 'client/work', 'parameters' => array() ),
			),
		);
	},
	array(
		'max_turns'         => 10,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'budgets'           => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 2 ),
		),
		'should_continue'   => static function (): bool {
			return true;
		},
		'on_event'          => static function ( string $event, array $payload ) use ( &$event_log ): void {
			$event_log[] = array( 'event' => $event, 'payload' => $payload );
		},
	)
);

agents_api_smoke_assert_equals( 'budget_exceeded', $result['status'] ?? null, 'tool_calls budget stops with budget_exceeded', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_calls', $result['budget'] ?? null, 'result identifies tool_calls budget', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $result['tool_execution_results'] ), 'only 2 tool calls executed before budget exceeded', $failures, $passes );

// Verify budget_exceeded event was emitted.
$budget_events = array_filter( $event_log, static function ( array $e ): bool {
	return 'budget_exceeded' === $e['event'];
} );
agents_api_smoke_assert_equals( 1, count( $budget_events ), 'budget_exceeded event was emitted', $failures, $passes );
$budget_event = array_values( $budget_events )[0];
agents_api_smoke_assert_equals( 'tool_calls', $budget_event['payload']['budget'], 'budget_exceeded event carries budget name', $failures, $passes );
agents_api_smoke_assert_equals( 2, $budget_event['payload']['current'], 'budget_exceeded event carries current count', $failures, $passes );
agents_api_smoke_assert_equals( 2, $budget_event['payload']['ceiling'], 'budget_exceeded event carries ceiling', $failures, $passes );

echo "\n[4] Per-tool-name budget stops specific tool ping-pong:\n";
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'name' => 'client/ping', 'parameters' => array() ),
				array( 'name' => 'client/work', 'parameters' => array() ),
				array( 'name' => 'client/ping', 'parameters' => array() ),
				array( 'name' => 'client/ping', 'parameters' => array() ),
			),
		);
	},
	array(
		'max_turns'         => 10,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'budgets'           => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls_client/ping', 2 ),
		),
		'should_continue'   => static function (): bool {
			return true;
		},
	)
);

agents_api_smoke_assert_equals( 'budget_exceeded', $result['status'] ?? null, 'per-tool budget stops with budget_exceeded', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_calls_client/ping', $result['budget'] ?? null, 'result identifies per-tool budget', $failures, $passes );
// Should have executed: ping(1), work(1), ping(2) = 3 calls before budget exceeded on 2nd ping.
agents_api_smoke_assert_equals( 3, count( $result['tool_execution_results'] ), 'per-tool budget stops after the tool hits ceiling', $failures, $passes );

echo "\n[5] Multiple budgets — any one exceeded stops the loop:\n";
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'name' => 'client/work', 'parameters' => array() ),
				array( 'name' => 'client/work', 'parameters' => array() ),
				array( 'name' => 'client/work', 'parameters' => array() ),
			),
		);
	},
	array(
		'max_turns'         => 10,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'budgets'           => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 10 ),
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls_client/work', 2 ),
		),
		'should_continue'   => static function (): bool {
			return true;
		},
	)
);

agents_api_smoke_assert_equals( 'budget_exceeded', $result['status'] ?? null, 'first exceeded budget stops loop', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_calls_client/work', $result['budget'] ?? null, 'result identifies the per-tool budget that exceeded', $failures, $passes );

echo "\n[6] Budget works alongside completion policy (budget checked first):\n";
$policy = new class() implements AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy {
	public function recordToolResult(
		string $tool_name,
		?array $tool_definition,
		array $result,
		array $context,
		int $turn
	): AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision {
		return AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision::notComplete();
	}
};

$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'name' => 'client/work', 'parameters' => array() ),
			),
		);
	},
	array(
		'max_turns'         => 10,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'completion_policy' => $policy,
		'budgets'           => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 1 ),
		),
		'should_continue'   => static function (): bool {
			return true;
		},
	)
);

agents_api_smoke_assert_equals( 'budget_exceeded', $result['status'] ?? null, 'budget stops even with completion policy present', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_calls', $result['budget'] ?? null, 'budget identified even with completion policy', $failures, $passes );

echo "\n[7] No budgets and no max_turns — default 1-turn behavior unchanged:\n";
$turn_count = 0;
$result     = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages ) use ( &$turn_count ): array {
		++$turn_count;

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	}
);

agents_api_smoke_assert_equals( 1, $turn_count, 'default loop runs exactly 1 turn', $failures, $passes );
agents_api_smoke_assert_equals( false, isset( $result['status'] ), 'no budget_exceeded status for default exit', $failures, $passes );

echo "\n[8] Explicit turns budget overrides max_turns:\n";
$turn_count = 0;
$result     = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ) use ( &$turn_count ): array {
		++$turn_count;
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'turn ' . $context['turn'] );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'       => 10,
		'budgets'         => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'turns', 3 ),
		),
		'should_continue' => static function (): bool {
			return true;
		},
	)
);

agents_api_smoke_assert_equals( 3, $turn_count, 'explicit turns budget overrides higher max_turns', $failures, $passes );
agents_api_smoke_assert_equals( 'budget_exceeded', $result['status'] ?? null, 'explicit turns budget produces budget_exceeded status', $failures, $passes );
agents_api_smoke_assert_equals( 'turns', $result['budget'] ?? null, 'explicit turns budget identified in result', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop budgets', $failures, $passes );
