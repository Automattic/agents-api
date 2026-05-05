<?php
/**
 * Pure-PHP smoke test for WP_Agent_Conversation_Loop completion policy wiring.
 *
 * Run with: php tests/conversation-loop-completion-policy-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-loop-completion-policy-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

// Build a tool executor.
$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array( 'done' => true ),
		);
	}
};

// Build a completion policy that stops after seeing a specific tool.
$policy_log = array();
$policy     = new class( $policy_log ) implements AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy {
	/** @var array Log reference. */
	private array $log;

	public function __construct( array &$log ) {
		$this->log = &$log;
	}

	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision {
		$this->log[] = array(
			'tool_name'  => $tool_name,
			'turn_count' => $turn_count,
			'success'    => $tool_result['success'] ?? false,
		);

		if ( 'client/finish' === $tool_name ) {
			return AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision::complete(
				'finish tool called',
				array( 'tool_name' => $tool_name, 'turn' => $turn_count )
			);
		}

		return AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision::incomplete();
	}
};

$tools = array(
	'client/work'   => array(
		'name'        => 'client/work',
		'source'      => 'client',
		'description' => 'Do work.',
		'parameters'  => array(),
		'executor'    => 'client',
		'scope'       => 'run',
	),
	'client/finish' => array(
		'name'        => 'client/finish',
		'source'      => 'client',
		'description' => 'Signal completion.',
		'parameters'  => array(),
		'executor'    => 'client',
		'scope'       => 'run',
	),
);

echo "\n[1] Completion policy stops the loop when it returns complete:\n";
$policy_log = array();
$turn_count = 0;

$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'start' ) ),
	static function ( array $messages, array $context ) use ( &$turn_count ): array {
		++$turn_count;

		if ( $context['turn'] <= 2 ) {
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array( 'name' => 'client/work', 'parameters' => array() ),
				),
			);
		}

		// Turn 3: call the finish tool.
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'name' => 'client/finish', 'parameters' => array() ),
			),
		);
	},
	array(
		'max_turns'         => 10,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'completion_policy' => $policy,
		'should_continue'   => static function (): bool {
			return true; // always continue — policy should override
		},
	)
);

agents_api_smoke_assert_equals( 3, $turn_count, 'loop stopped at turn 3 when policy said complete', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $policy_log ), 'policy was consulted for each tool execution', $failures, $passes );
agents_api_smoke_assert_equals( 'client/finish', $policy_log[2]['tool_name'], 'policy received the finish tool name', $failures, $passes );

echo "\n[2] Completion policy coexists with should_continue — policy takes precedence:\n";
$policy_log       = array();
$continue_called  = 0;

$result2 = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'name' => 'client/finish', 'parameters' => array() ),
			),
		);
	},
	array(
		'max_turns'         => 5,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'completion_policy' => $policy,
		'should_continue'   => static function () use ( &$continue_called ): bool {
			++$continue_called;
			return true;
		},
	)
);

agents_api_smoke_assert_equals( 1, count( $policy_log ), 'policy was called once', $failures, $passes );
agents_api_smoke_assert_equals( 0, $continue_called, 'should_continue was not called when policy stopped the loop', $failures, $passes );

echo "\n[3] Completion policy works in legacy path (turn runner handles tool execution):\n";
$policy_log = array();

// Build a policy that completes on any tool.
$always_complete_policy = new class() implements AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy {
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision {
		return AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision::complete( 'always stop' );
	}
};

$legacy_turns = 0;
$result3      = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages, array $context ) use ( &$legacy_turns ): array {
		++$legacy_turns;
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'calling tool' );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(
				array(
					'tool_name'  => 'client/work',
					'result'     => array( 'done' => true ),
					'parameters' => array(),
					'turn_count' => $context['turn'],
				),
			),
			'events' => array(),
		);
	},
	array(
		'max_turns'         => 5,
		'completion_policy' => $always_complete_policy,
		'tool_declarations' => $tools,
		'should_continue'   => static function (): bool {
			return true;
		},
	)
);

agents_api_smoke_assert_equals( 1, $legacy_turns, 'legacy loop stopped after one turn via completion policy', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop completion policy', $failures, $passes );
