<?php
/**
 * Pure-PHP smoke test for WP_Agent_Conversation_Loop tool-call mediation.
 *
 * Run with: php tests/conversation-loop-tool-execution-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-loop-tool-execution-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

// Build a reusable in-memory tool executor.
$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	/** @var array Log of executed calls. */
	public array $executed = array();

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		$this->executed[] = $tool_call;

		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array(
				'summary' => strtoupper( (string) ( $tool_call['parameters']['text'] ?? 'done' ) ),
			),
		);
	}
};

// Tool declarations keyed by name.
$tools = array(
	'client/summarize' => array(
		'name'        => 'client/summarize',
		'source'      => 'client',
		'description' => 'Summarize text.',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'text' ),
			'properties' => array(
				'text' => array( 'type' => 'string' ),
			),
		),
		'executor'    => 'client',
		'scope'       => 'run',
	),
);

echo "\n[1] Loop mediates tool calls through WP_Agent_Tool_Execution_Core when tool_executor is provided:\n";
$executor->executed = array();

$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'summarize this' ) ),
	static function ( array $messages, array $context ): array {
		// Turn runner returns tool calls for the loop to mediate.
		return array(
			'messages'   => $messages,
			'content'    => 'I will summarize that for you.',
			'tool_calls' => array(
				array(
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'hello world' ),
				),
			),
		);
	},
	array(
		'max_turns'         => 1,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
	)
);

agents_api_smoke_assert_equals( 1, count( $executor->executed ), 'tool executor was called once', $failures, $passes );
agents_api_smoke_assert_equals( 'client/summarize', $executor->executed[0]['tool_name'], 'tool executor received correct tool name', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $result['tool_execution_results'] ), 'result contains one tool execution result', $failures, $passes );
agents_api_smoke_assert_equals( 'client/summarize', $result['tool_execution_results'][0]['tool_name'], 'tool execution result has correct tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'HELLO WORLD', $result['tool_execution_results'][0]['result']['result']['summary'], 'tool execution result carries executor payload', $failures, $passes );

// Messages should contain: user, assistant text, tool_call, tool_result.
$message_count = count( $result['messages'] );
agents_api_smoke_assert_equals( true, $message_count >= 4, 'transcript contains user + assistant + tool_call + tool_result messages', $failures, $passes );

echo "\n[2] Loop runs without mediation when no tool_executor is provided (backwards compatible):\n";
$executor->executed = array();

$caller_managed_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages, array $context ): array {
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'turn ' . $context['turn'] );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'       => 2,
		'should_continue' => static function ( array $turn_result, array $context ): bool {
			return 1 === $context['turn'];
		},
	)
);

agents_api_smoke_assert_equals( 0, count( $executor->executed ), 'tool executor was not called in caller-managed path', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $caller_managed_result['messages'] ), 'caller-managed loop returns correct transcript length', $failures, $passes );

echo "\n[3] Multi-turn mediation continues when tool calls are present:\n";
$executor->executed = array();
$turn_count         = 0;

$multi_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'start' ) ),
	static function ( array $messages, array $context ) use ( &$turn_count ): array {
		++$turn_count;

		if ( $context['turn'] <= 2 ) {
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array(
						'name'       => 'client/summarize',
						'parameters' => array( 'text' => 'turn ' . $context['turn'] ),
					),
				),
			);
		}

		// Final turn: no tool calls = natural completion.
		return array(
			'messages'   => $messages,
			'content'    => 'All done.',
			'tool_calls' => array(),
		);
	},
	array(
		'max_turns'         => 5,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'should_continue'   => static function (): bool {
			return true;
		},
	)
);

agents_api_smoke_assert_equals( 2, count( $executor->executed ), 'tool executor was called twice across turns', $failures, $passes );
agents_api_smoke_assert_equals( 3, $turn_count, 'loop ran three turns (two with tools, one without)', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $multi_result['tool_execution_results'] ), 'result contains two tool execution results', $failures, $passes );

echo "\n[4] Tool validation errors are returned as error results without crashing:\n";
$executor->executed = array();

$validation_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'test' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'name'       => 'client/summarize',
					'parameters' => array(), // Missing required 'text' parameter.
				),
			),
		);
	},
	array(
		'max_turns'         => 1,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
	)
);

agents_api_smoke_assert_equals( 0, count( $executor->executed ), 'executor was not called for invalid tool call', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $validation_result['tool_execution_results'] ), 'validation error is recorded as tool result', $failures, $passes );
agents_api_smoke_assert_equals( false, $validation_result['tool_execution_results'][0]['result']['success'], 'validation error marks result as failed', $failures, $passes );

echo "\n[5] Multi-turn mediation runs without an explicit should_continue option:\n";
$executor->executed   = array();
$default_turn_count   = 0;

$default_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'start' ) ),
	static function ( array $messages, array $context ) use ( &$default_turn_count ): array {
		++$default_turn_count;

		if ( 1 === $context['turn'] ) {
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array(
						'name'       => 'client/summarize',
						'parameters' => array( 'text' => 'turn ' . $context['turn'] ),
					),
				),
			);
		}

		return array(
			'messages'   => $messages,
			'content'    => 'All done.',
			'tool_calls' => array(),
		);
	},
	array(
		'max_turns'         => 5,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		// NOTE: no should_continue option here — the loop should default to a
		// continue-always policy when mediation is enabled, so this test fails
		// before #96's fix and passes after.
	)
);

agents_api_smoke_assert_equals( 1, count( $executor->executed ), 'mediation executor ran once with no explicit should_continue', $failures, $passes );
agents_api_smoke_assert_equals( 2, $default_turn_count, 'mediation loop ran two turns with no explicit should_continue', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $default_result['tool_execution_results'] ), 'mediation default returned the tool result', $failures, $passes );

echo "\n[6] Caller-managed path (no mediation) preserves break-after-1 default:\n";
$caller_managed_default_count = 0;

$caller_managed_default_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ) use ( &$caller_managed_default_count ): array {
		++$caller_managed_default_count;

		$messages[] = array(
			'role'    => 'assistant',
			'content' => 'turn ' . $context['turn'],
		);

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns' => 3,
		// No mediation, no should_continue → loop should still break after 1.
	)
);

agents_api_smoke_assert_equals( 1, $caller_managed_default_count, 'caller-managed path still breaks after one turn without should_continue', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $caller_managed_default_result['messages'] ), 'caller-managed transcript has user + one assistant message', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop tool execution', $failures, $passes );
