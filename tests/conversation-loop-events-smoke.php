<?php
/**
 * Pure-PHP smoke test for AgentConversationLoop lifecycle event hooks.
 *
 * Run with: php tests/conversation-loop-events-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-loop-events-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

// Build a tool executor.
$executor = new class() implements AgentsAPI\AI\Tools\ToolExecutorInterface {
	public function executeToolCall( array $tool_call, array $tool_definition, array $context = array() ): array {
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
);

echo "\n[1] Events emit at correct lifecycle points during mediated execution:\n";
$event_log = array();

$result = AgentsAPI\AI\AgentConversationLoop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages, array $context ): array {
		if ( 1 === $context['turn'] ) {
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array( 'name' => 'client/work', 'parameters' => array() ),
				),
			);
		}

		// Turn 2: no tool calls.
		return array(
			'messages'   => $messages,
			'content'    => 'Done.',
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
		'on_event'          => static function ( string $event, array $payload ) use ( &$event_log ): void {
			$event_log[] = array( 'event' => $event, 'payload' => $payload );
		},
	)
);

$event_names = array_column( $event_log, 'event' );
agents_api_smoke_assert_equals( true, in_array( 'turn_started', $event_names, true ), 'turn_started event was emitted', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'tool_call', $event_names, true ), 'tool_call event was emitted', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'tool_result', $event_names, true ), 'tool_result event was emitted', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'completed', $event_names, true ), 'completed event was emitted', $failures, $passes );

// Verify event order: turn_started should come before tool_call.
$turn_started_idx = array_search( 'turn_started', $event_names );
$tool_call_idx    = array_search( 'tool_call', $event_names );
agents_api_smoke_assert_equals( true, $turn_started_idx < $tool_call_idx, 'turn_started fires before tool_call', $failures, $passes );

// Verify turn_started payload.
$turn_started_event = $event_log[ $turn_started_idx ];
agents_api_smoke_assert_equals( 1, $turn_started_event['payload']['turn'], 'turn_started payload contains turn number', $failures, $passes );
agents_api_smoke_assert_equals( 5, $turn_started_event['payload']['max_turns'], 'turn_started payload contains max_turns', $failures, $passes );

// Verify tool_call payload.
$tool_call_event = $event_log[ $tool_call_idx ];
agents_api_smoke_assert_equals( 'client/work', $tool_call_event['payload']['tool_name'], 'tool_call payload contains tool name', $failures, $passes );

echo "\n[2] Failed event emits on turn runner exception:\n";
$event_log = array();

$threw = false;
try {
	AgentsAPI\AI\AgentConversationLoop::run(
		array( array( 'role' => 'user', 'content' => 'fail' ) ),
		static function (): array {
			throw new \RuntimeException( 'provider down' );
		},
		array(
			'max_turns' => 1,
			'on_event'  => static function ( string $event, array $payload ) use ( &$event_log ): void {
				$event_log[] = array( 'event' => $event, 'payload' => $payload );
			},
		)
	);
} catch ( \RuntimeException $e ) {
	$threw = true;
}

agents_api_smoke_assert_equals( true, $threw, 'exception was re-thrown', $failures, $passes );
$event_names = array_column( $event_log, 'event' );
agents_api_smoke_assert_equals( true, in_array( 'failed', $event_names, true ), 'failed event was emitted on exception', $failures, $passes );

$failed_event = $event_log[ array_search( 'failed', $event_names ) ];
agents_api_smoke_assert_equals( 'provider down', $failed_event['payload']['error'], 'failed event carries error message', $failures, $passes );

echo "\n[3] Event sink failure does not affect loop result:\n";
$crashing_sink_result = AgentsAPI\AI\AgentConversationLoop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages ): array {
		$messages[] = AgentsAPI\AI\AgentMessageEnvelope::text( 'assistant', 'hi' );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns' => 1,
		'on_event'  => static function (): void {
			throw new \RuntimeException( 'observer crash' );
		},
	)
);

agents_api_smoke_assert_equals( 2, count( $crashing_sink_result['messages'] ), 'loop result is unaffected by event sink crash', $failures, $passes );

echo "\n[4] No event sink = no events emitted (backwards compatible):\n";
$no_event_result = AgentsAPI\AI\AgentConversationLoop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array( 'max_turns' => 1 )
);

agents_api_smoke_assert_equals( 1, count( $no_event_result['messages'] ), 'loop works without event sink', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop events', $failures, $passes );
