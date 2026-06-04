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
		'runtime'     => array(
			'duplicate_policy' => 'repeatable',
			'completion_signal' => 'progress',
		),
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
						'id'         => 'call_123',
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
	agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Result::SCHEMA, $result['schema'], 'mediated result exposes replay schema', $failures, $passes );
	agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Result::VERSION, $result['version'], 'mediated result exposes replay version', $failures, $passes );
	agents_api_smoke_assert_equals( 'client/summarize', $executor->executed[0]['tool_name'], 'tool executor received correct tool name', $failures, $passes );
	agents_api_smoke_assert_equals( 'call_123', $executor->executed[0]['id'] ?? '', 'tool executor received provider tool call id', $failures, $passes );
	agents_api_smoke_assert_equals( 'call_123', $executor->executed[0]['metadata']['tool_call_id'] ?? '', 'tool executor received provider tool call id in metadata', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $result['tool_execution_results'] ), 'result contains one tool execution result', $failures, $passes );
	agents_api_smoke_assert_equals( 'client/summarize', $result['tool_execution_results'][0]['tool_name'], 'tool execution result has correct tool name', $failures, $passes );
	agents_api_smoke_assert_equals( 'call_123', $result['tool_execution_results'][0]['tool_call_id'] ?? '', 'tool execution result preserves provider tool call id', $failures, $passes );
	agents_api_smoke_assert_equals( 'HELLO WORLD', $result['tool_execution_results'][0]['result']['result']['summary'], 'tool execution result carries executor payload', $failures, $passes );
	agents_api_smoke_assert_equals( 'repeatable', $result['tool_execution_results'][0]['runtime']['duplicate_policy'] ?? '', 'tool execution result exposes duplicate policy runtime metadata', $failures, $passes );
	agents_api_smoke_assert_equals( 'progress', $result['tool_execution_results'][0]['result']['runtime']['completion_signal'] ?? '', 'tool result preserves completion signal runtime metadata', $failures, $passes );
	agents_api_smoke_assert_equals( 'I will summarize that for you.', $result['final_content'], 'final content skips synthetic tool-call messages', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $result['tool_audit_events'] ), 'result contains one tool audit event', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_call', $result['tool_audit_events'][0]['type'], 'tool audit event has stable type', $failures, $passes );
agents_api_smoke_assert_equals( 'client/summarize', $result['tool_audit_events'][0]['tool_name'], 'tool audit event has correct tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'call_123', $result['tool_audit_events'][0]['tool_call_id'], 'tool audit event preserves provider tool call id', $failures, $passes );
agents_api_smoke_assert_equals( true, $result['tool_audit_events'][0]['success'], 'tool audit event records success', $failures, $passes );
agents_api_smoke_assert_equals( true, str_starts_with( $result['tool_audit_events'][0]['parameters_sha256'], 'sha256:' ), 'tool audit event hashes parameters', $failures, $passes );
agents_api_smoke_assert_equals( true, ! array_key_exists( 'parameters', $result['tool_audit_events'][0] ), 'tool audit event omits raw parameters', $failures, $passes );
agents_api_smoke_assert_equals( array( 'tool_call', 'tool_result' ), array_column( $result['tool_events'], 'type' ), 'result contains canonical tool call/result events', $failures, $passes );
agents_api_smoke_assert_equals( 'success', $result['tool_events'][1]['status'] ?? '', 'tool result event records status', $failures, $passes );

// Messages should contain: user, assistant text, tool_call, tool_result.
$message_count = count( $result['messages'] );
agents_api_smoke_assert_equals( true, $message_count >= 4, 'transcript contains user + assistant + tool_call + tool_result messages', $failures, $passes );
$tool_result_messages = array_values(
	array_filter(
		$result['messages'],
		static function ( array $message ): bool {
			return ( $message['type'] ?? '' ) === AgentsAPI\AI\WP_Agent_Message::TYPE_TOOL_RESULT;
		}
	)
);
agents_api_smoke_assert_equals( 'call_123', $tool_result_messages[0]['metadata']['tool_call_id'] ?? '', 'tool result metadata preserves provider tool call id', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'tool_call_id', $tool_result_messages[0]['payload'] ?? array() ), 'tool result payload does not duplicate tool_call_id', $failures, $passes );

$tool_call_messages = array_values(
	array_filter(
		$result['messages'],
		static function ( array $message ): bool {
			return ( $message['type'] ?? '' ) === AgentsAPI\AI\WP_Agent_Message::TYPE_TOOL_CALL;
		}
	)
);
agents_api_smoke_assert_equals( 'call_123', $tool_call_messages[0]['metadata']['tool_call_id'] ?? '', 'tool call metadata preserves provider tool call id', $failures, $passes );
agents_api_smoke_assert_equals( array( 'text' => 'hello world' ), $tool_call_messages[0]['payload']['parameters'] ?? array(), 'tool call payload preserves provider parameters for runtime replay', $failures, $passes );

echo "\n[Pre-tool mediator proceed/reject/replace decisions (#260):\n";

$executor->executed       = array();
$proceed_mediator_context = array();
$proceed_result           = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'mediate proceed' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'call_proceed',
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'go ahead' ),
				),
			),
		);
	},
	array(
		'max_turns'         => 1,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'pre_tool_mediator' => static function ( array $context ) use ( &$proceed_mediator_context ): array {
			$proceed_mediator_context = $context;
			return array( 'action' => 'proceed' );
		},
	)
);

agents_api_smoke_assert_equals( 1, count( $executor->executed ), 'proceed mediator allows executor call', $failures, $passes );
agents_api_smoke_assert_equals( 'call_proceed', $proceed_mediator_context['tool_call_id'] ?? '', 'mediator receives tool call id', $failures, $passes );
agents_api_smoke_assert_equals( 'client/summarize', $proceed_mediator_context['prepared_tool_call']['tool_name'] ?? '', 'mediator receives prepared tool call', $failures, $passes );
agents_api_smoke_assert_equals( 'repeatable', $proceed_mediator_context['tool_declaration']['runtime']['duplicate_policy'] ?? '', 'mediator receives tool declaration runtime metadata', $failures, $passes );
agents_api_smoke_assert_equals( true, count( $proceed_mediator_context['messages'] ?? array() ) >= 2, 'mediator receives current transcript including tool-call message', $failures, $passes );
agents_api_smoke_assert_equals( 'GO AHEAD', $proceed_result['tool_execution_results'][0]['result']['result']['summary'] ?? '', 'proceed result keeps executor payload', $failures, $passes );

$executor->executed = array();
$reject_result      = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'reject duplicate' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'call_reject',
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'duplicate' ),
				),
			),
		);
	},
	array(
		'max_turns'         => 1,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'pre_tool_mediator' => static function (): array {
			return array(
				'action'   => 'reject',
				'error'    => 'Duplicate tool call rejected.',
				'metadata' => array( 'error_type' => 'duplicate_tool_call' ),
			);
		},
	)
);

agents_api_smoke_assert_equals( 0, count( $executor->executed ), 'reject mediator prevents executor call', $failures, $passes );
agents_api_smoke_assert_equals( false, $reject_result['tool_execution_results'][0]['result']['success'] ?? true, 'reject decision records failed tool result', $failures, $passes );
agents_api_smoke_assert_equals( 'Duplicate tool call rejected.', $reject_result['tool_execution_results'][0]['result']['error'] ?? '', 'reject decision records mediator error', $failures, $passes );
agents_api_smoke_assert_equals( 'duplicate_tool_call', $reject_result['tool_audit_events'][0]['error_type'] ?? '', 'reject decision records audit error type', $failures, $passes );
agents_api_smoke_assert_equals( array( 'tool_call', 'tool_result' ), array_column( $reject_result['tool_events'], 'type' ), 'reject decision records canonical tool events', $failures, $passes );
agents_api_smoke_assert_equals( 'error', $reject_result['tool_events'][1]['status'] ?? '', 'reject decision records error tool event status', $failures, $passes );

$executor->executed = array();
$replace_result     = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'replace result' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'call_replace',
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'replace me' ),
				),
			),
		);
	},
	array(
		'max_turns'         => 1,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'pre_tool_mediator' => static function (): array {
			return array(
				'action' => 'replace_result',
				'result' => array(
					'success' => true,
					'result'  => array( 'summary' => 'supplied by mediator' ),
				),
			);
		},
	)
);

agents_api_smoke_assert_equals( 0, count( $executor->executed ), 'replace mediator prevents executor call', $failures, $passes );
agents_api_smoke_assert_equals( true, $replace_result['tool_execution_results'][0]['result']['success'] ?? false, 'replace decision records successful tool result', $failures, $passes );
agents_api_smoke_assert_equals( 'supplied by mediator', $replace_result['tool_execution_results'][0]['result']['result']['summary'] ?? '', 'replace decision records supplied payload', $failures, $passes );
agents_api_smoke_assert_equals( true, $replace_result['tool_audit_events'][0]['success'] ?? false, 'replace decision records successful audit event', $failures, $passes );
agents_api_smoke_assert_equals( 'success', $replace_result['tool_events'][1]['status'] ?? '', 'replace decision records success tool event status', $failures, $passes );

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
agents_api_smoke_assert_equals( 'missing_required_parameters', $validation_result['tool_audit_events'][0]['error_type'], 'validation audit event records missing parameter error type', $failures, $passes );

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

echo "\n[6] Tool-call-only tail has empty final content:\n";

$tool_only_tail_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'tail' ),
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

agents_api_smoke_assert_equals( '', $tool_only_tail_result['final_content'], 'tool-call-only final turn does not become final content', $failures, $passes );

echo "\n[7] Caller-managed path (no mediation) preserves break-after-1 default:\n";
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

echo "\n[8] Missing tools and executor exceptions produce safe audit events:\n";
$executor->executed = array();

$missing_tool_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'test' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'name'       => 'client/missing',
					'parameters' => array( 'token' => 'secret-value' ),
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

agents_api_smoke_assert_equals( 0, count( $executor->executed ), 'executor was not called for missing tool', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_not_found', $missing_tool_result['tool_audit_events'][0]['error_type'], 'missing tool audit event records error type', $failures, $passes );
agents_api_smoke_assert_equals( true, ! str_contains( wp_json_encode( $missing_tool_result['tool_audit_events'][0] ), 'secret-value' ), 'missing tool audit event does not expose raw secret parameter', $failures, $passes );

$throwing_executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		throw new RuntimeException( 'executor exploded' );
	}
};

$exception_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'test' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'hello' ),
				),
			),
		);
	},
	array(
		'max_turns'         => 1,
		'tool_executor'     => $throwing_executor,
		'tool_declarations' => $tools,
	)
);

agents_api_smoke_assert_equals( 'executor_exception', $exception_result['tool_audit_events'][0]['error_type'], 'executor exception audit event records error type', $failures, $passes );
agents_api_smoke_assert_equals( false, $exception_result['tool_audit_events'][0]['success'], 'executor exception audit event records failure', $failures, $passes );

echo "\n[Default should_continue stops on natural completion (#226):\n";
$executor->executed = array();
$turn_count         = 0;

$natural_stop_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'one tool round' ) ),
	static function ( array $messages, array $context ) use ( &$turn_count ): array {
		++$turn_count;
		if ( 1 === $context['turn'] ) {
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array( 'name' => 'client/summarize', 'parameters' => array( 'text' => 'once' ) ),
				),
			);
		}
		// Turn 2: model answers with text and no tool_calls. The default
		// should_continue must stop the loop here rather than letting it run
		// until max_turns.
		return array(
			'messages' => $messages,
			'content'  => 'final answer',
		);
	},
	array(
		'max_turns'         => 8,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		// No should_continue override on purpose — exercise the substrate default.
	)
);

agents_api_smoke_assert_equals( 2, $turn_count, 'loop stops after the natural-completion turn instead of running to max_turns', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $executor->executed ), 'executor was called exactly once', $failures, $passes );

echo "\n[Mediation falls back to prior messages when turn runner omits the key (#227):\n";
$executor->executed = array();
$omit_turn          = 0;

$omit_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'kick off' ) ),
	static function ( array $unused, array $context ) use ( &$omit_turn ): array {
		++$omit_turn;
		// Intentionally omit the `messages` key. The substrate must fall back
		// to the prior turn's messages so transcript history is preserved.
		if ( 1 === $context['turn'] ) {
			return array(
				'tool_calls' => array(
					array( 'name' => 'client/summarize', 'parameters' => array( 'text' => 'kick off' ) ),
				),
			);
		}
		// Empty tool_calls trips natural completion inside the mediation path.
		return array(
			'content'    => 'done',
			'tool_calls' => array(),
		);
	},
	array(
		'max_turns'         => 4,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
	)
);

agents_api_smoke_assert_equals( 2, $omit_turn, 'loop completes naturally even when turn runner omits messages', $failures, $passes );
agents_api_smoke_assert_equals( true, count( $omit_result['messages'] ) >= 4, 'transcript retains user + tool_call + tool_result + final content despite omitted messages key', $failures, $passes );
$omit_roles = array_map( static fn( array $m ): string => (string) ( $m['role'] ?? '' ), $omit_result['messages'] );
agents_api_smoke_assert_equals( 'user', $omit_roles[0] ?? '', 'first transcript message is the original user turn (history preserved)', $failures, $passes );

echo "\n[Runtime tools can pause the loop with a canonical pending request (#250):\n";
$pending_executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		return array(
			'success'              => false,
			'tool_name'            => $tool_call['tool_name'],
			'status'               => AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
			'error'                => 'Waiting for client runtime tool result.',
			'runtime_tool_request' => AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::from_tool_call(
				$tool_call['tool_name'],
				$tool_call['id'] ?? 'pending-call',
				$tool_call['parameters'],
				$context,
				array( 'completion_signal' => 'external_result' ),
				array( 'transport' => 'browser' )
			),
		);
	}
};

$pending_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'choose' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'client-call-1',
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'needs browser' ),
				),
			),
		);
	},
	array(
		'max_turns'         => 3,
		'tool_executor'     => $pending_executor,
		'tool_declarations' => $tools,
	)
);

agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING, $pending_result['status'] ?? '', 'pending runtime tool sets canonical loop status', $failures, $passes );
agents_api_smoke_assert_equals( false, $pending_result['completed'] ?? true, 'pending runtime tool result is incomplete', $failures, $passes );
agents_api_smoke_assert_equals( 'client/summarize', $pending_result['runtime_tool_pending']['tool_name'] ?? '', 'pending request carries tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'client-call-1', $pending_result['runtime_tool_pending']['tool_call_id'] ?? '', 'pending request carries tool call id', $failures, $passes );
agents_api_smoke_assert_equals( array( 'tool_call', AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING ), array_column( $pending_result['tool_events'], 'type' ), 'pending request is recorded in canonical tool events', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Result::OUTCOME_STATUS_PENDING_RUNTIME_TOOL, $pending_result['run_outcome']['status'] ?? '', 'pending runtime tool run outcome is pending', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING, $pending_result['run_outcome']['stop_reason'] ?? '', 'pending runtime tool run outcome stop reason is pending', $failures, $passes );

$loop_runtime_tool_store = new class() implements AgentsAPI\AI\WP_Agent_Runtime_Tool_Request_Store {
	public array $requests = array();

	public function create( array $request ): void {
		$this->requests[ $request['request_id'] ] = $request;
	}

	public function get( string $request_id ): ?array {
		return $this->requests[ $request_id ] ?? null;
	}

	public function complete( string $request_id, array $result ): void {
		unset( $result );
		$this->requests[ $request_id ]['status'] = 'completed';
	}

	public function timeout( string $request_id ): void {
		$this->requests[ $request_id ]['status'] = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT;
	}

	public function recent_pending( array $query = array() ): array {
		unset( $query );
		return array_values( $this->requests );
	}
};

$stored_pending_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'choose stored' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'client-call-store-1',
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'needs durable browser handoff' ),
				),
			),
		);
	},
	array(
		'max_turns'                  => 3,
		'tool_executor'              => $pending_executor,
		'tool_declarations'          => $tools,
		'runtime_tool_request_store' => $loop_runtime_tool_store,
		'context'                    => array( 'run_id' => 'run-loop-store' ),
	)
);

$stored_request_id = $stored_pending_result['runtime_tool_pending']['request_id'] ?? '';
agents_api_smoke_assert_equals( true, isset( $loop_runtime_tool_store->requests[ $stored_request_id ] ), 'loop hands pending runtime tool request to configured lifecycle store', $failures, $passes );
agents_api_smoke_assert_equals( 1, did_action( 'agents_api_runtime_tool_request_created' ), 'loop persistence emits generic runtime tool created event', $failures, $passes );
agents_api_smoke_assert_equals( 1, $loop_runtime_tool_store->requests[ $stored_request_id ]['metadata']['turn_count'] ?? 0, 'stored pending request records generic turn metadata for replay', $failures, $passes );

echo "\n[Pre-tool filter hook allows, rejects, or pauses external runtime tools (#259):\n";

$filter_contexts = array();
add_filter(
	'agents_api_pre_tool_call_decision',
	static function ( array $decision, array $context ) use ( &$filter_contexts ): array {
		$tool_call_id = (string) ( $context['tool_call_id'] ?? '' );
		$filter_contexts[ $tool_call_id ] = $context;

		if ( 'call_filter_allow' === $tool_call_id ) {
			return array( 'action' => 'proceed' );
		}

		if ( 'call_filter_reject' === $tool_call_id ) {
			return array(
				'action'   => 'reject',
				'error'    => 'Rejected by host preflight.',
				'metadata' => array( 'error_type' => 'runtime_rule_rejected' ),
			);
		}

		if ( 'call_filter_pending' === $tool_call_id ) {
			return array(
				'action'  => 'pending',
				'request' => array(
					'metadata' => array( 'transport' => 'browser' ),
					'runtime'  => array( 'completion_signal' => 'external_result' ),
				),
			);
		}

		return $decision;
	},
	10,
	2
);

$executor->executed = array();
$filter_allow_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'allow via hook' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'call_filter_allow',
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'filter allow' ),
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

agents_api_smoke_assert_equals( 1, count( $executor->executed ), 'pre-tool filter proceed keeps default executor path', $failures, $passes );
agents_api_smoke_assert_equals( 'FILTER ALLOW', $filter_allow_result['tool_execution_results'][0]['result']['result']['summary'] ?? '', 'pre-tool filter allow result carries executor payload', $failures, $passes );
agents_api_smoke_assert_equals( 'client/summarize', $filter_contexts['call_filter_allow']['prepared_tool_call']['tool_name'] ?? '', 'pre-tool filter receives prepared tool call context', $failures, $passes );

$executor->executed = array();
$filter_reject_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'reject via hook' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'call_filter_reject',
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'blocked' ),
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

agents_api_smoke_assert_equals( 0, count( $executor->executed ), 'pre-tool filter reject prevents executor call', $failures, $passes );
agents_api_smoke_assert_equals( false, $filter_reject_result['tool_execution_results'][0]['result']['success'] ?? true, 'pre-tool filter reject records failed tool result', $failures, $passes );
agents_api_smoke_assert_equals( 'runtime_rule_rejected', $filter_reject_result['tool_audit_events'][0]['error_type'] ?? '', 'pre-tool filter reject preserves error type for audit', $failures, $passes );

$executor->executed = array();
$filter_pending_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'pending via hook' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'call_filter_pending',
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'needs browser hook' ),
				),
			),
		);
	},
	array(
		'max_turns'         => 3,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'context'           => array(
			'run_id'                  => 'run-filter-pending',
			'runtime_tool_timeout_at' => '2026-06-01T00:00:00Z',
		),
	)
);

agents_api_smoke_assert_equals( 0, count( $executor->executed ), 'pre-tool filter pending prevents executor call', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING, $filter_pending_result['status'] ?? '', 'pre-tool filter pending sets canonical loop status', $failures, $passes );
agents_api_smoke_assert_equals( 'call_filter_pending', $filter_pending_result['runtime_tool_pending']['tool_call_id'] ?? '', 'pre-tool filter pending request carries tool call id', $failures, $passes );
agents_api_smoke_assert_equals( 'browser', $filter_pending_result['runtime_tool_pending']['metadata']['transport'] ?? '', 'pre-tool filter pending request preserves host metadata', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Result::OUTCOME_STATUS_PENDING_RUNTIME_TOOL, $filter_pending_result['run_outcome']['status'] ?? '', 'pre-tool filter pending run outcome is pending', $failures, $passes );

echo "\n[Post-tool diagnostics option and filter attach audit metadata (#259):\n";

add_filter(
	'agents_api_mediated_tool_result_diagnostics',
	static function ( array $diagnostics, array $context ): array {
		if ( 'call_diag' !== ( $context['tool_call_id'] ?? '' ) ) {
			return $diagnostics;
		}

		$diagnostics['filter_trace_id'] = 'trace-filter-1';
		$diagnostics['saw_parameter_hash'] = str_starts_with( (string) ( $context['parameters_sha256'] ?? '' ), 'sha256:' );
		return $diagnostics;
	},
	10,
	2
);

$executor->executed = array();
$diagnostics_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'diagnose result' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'id'         => 'call_diag',
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'secret diagnostic text' ),
				),
			),
		);
	},
	array(
		'max_turns'                    => 1,
		'tool_executor'                => $executor,
		'tool_declarations'            => $tools,
		'post_tool_result_diagnostics' => static function ( array $context ): array {
			return array(
				'option_trace_id' => 'trace-option-1',
				'tool_call_id'    => $context['tool_call_id'] ?? '',
			);
		},
	)
);

$diagnostics = $diagnostics_result['tool_execution_results'][0]['diagnostics'] ?? array();
agents_api_smoke_assert_equals( 'trace-option-1', $diagnostics['option_trace_id'] ?? '', 'post-tool diagnostics option attaches metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'trace-filter-1', $diagnostics['filter_trace_id'] ?? '', 'post-tool diagnostics filter can refine metadata', $failures, $passes );
agents_api_smoke_assert_equals( true, $diagnostics['saw_parameter_hash'] ?? false, 'post-tool diagnostics filter receives parameter hash', $failures, $passes );
agents_api_smoke_assert_equals( true, ! str_contains( wp_json_encode( $diagnostics ), 'secret diagnostic text' ), 'stored diagnostics omit raw tool parameters', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_result_diagnostics', $diagnostics_result['events'][0]['type'] ?? '', 'post-tool diagnostics adds canonical event', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop tool execution', $failures, $passes );
