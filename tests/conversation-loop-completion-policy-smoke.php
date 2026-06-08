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

$stop_events = array_values(
	array_filter(
		$result['events'],
		static function ( array $event ): bool {
			return 'completion_policy_stop' === ( $event['type'] ?? '' );
		}
	)
);

agents_api_smoke_assert_equals( 1, count( $stop_events ), 'complete decision records one stop event', $failures, $passes );
agents_api_smoke_assert_equals( 'finish tool called', $stop_events[0]['metadata']['message'] ?? '', 'stop event carries decision message', $failures, $passes );
agents_api_smoke_assert_equals( array( 'tool_name' => 'client/finish', 'turn' => 3 ), $stop_events[0]['metadata']['context'] ?? array(), 'stop event carries decision context', $failures, $passes );

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

echo "\n[2b] Structured provider extraction preserves multi-candidate tool calls:\n";

$function_call_part = static function ( string $id, string $name, array $args ) {
	$function_call = new class( $id, $name, $args ) {
		private string $id;
		private string $name;
		private array $args;

		public function __construct( string $id, string $name, array $args ) {
			$this->id   = $id;
			$this->name = $name;
			$this->args = $args;
		}

		public function getId(): string {
			return $this->id;
		}

		public function getName(): string {
			return $this->name;
		}

		public function getArgs(): array {
			return $this->args;
		}
	};

	return new class( $function_call ) {
		private object $function_call;

		public function __construct( object $function_call ) {
			$this->function_call = $function_call;
		}

		public function getFunctionCall(): object {
			return $this->function_call;
		}

		public function getText(): string {
			return '';
		}
	};
};

$candidate = static function ( object $part ) {
	$message = new class( $part ) {
		private object $part;

		public function __construct( object $part ) {
			$this->part = $part;
		}

		public function getParts(): array {
			return array( $this->part );
		}
	};

	return new class( $message ) {
		private object $message;

		public function __construct( object $message ) {
			$this->message = $message;
		}

		public function getMessage(): object {
			return $this->message;
		}
	};
};

$multi_candidate_result = new class( $candidate, $function_call_part ) {
	/** @var callable */
	private $candidate;
	/** @var callable */
	private $function_call_part;

	public function __construct( callable $candidate, callable $function_call_part ) {
		$this->candidate          = $candidate;
		$this->function_call_part = $function_call_part;
	}

	public function getCandidates(): array {
		$candidate          = $this->candidate;
		$function_call_part = $this->function_call_part;

		return array(
			$candidate( $function_call_part( 'call_one', 'client/work', array( 'path' => 'index.html' ) ) ),
			$candidate( $function_call_part( 'call_two', 'client/finish', array( 'path' => 'styles.css' ) ) ),
		);
	}
};

$extracted_calls = AgentsAPI\AI\WP_Agent_Provider_Turn_Result::extract_tool_calls( $multi_candidate_result );
agents_api_smoke_assert_equals( 2, count( $extracted_calls ), 'structured extraction scans all candidates', $failures, $passes );
agents_api_smoke_assert_equals( 'client/work', $extracted_calls[0]['name'] ?? '', 'first candidate tool call is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'client/finish', $extracted_calls[1]['name'] ?? '', 'second candidate tool call is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'call_two', $extracted_calls[1]['id'] ?? '', 'second candidate tool call id is preserved', $failures, $passes );

echo "\n[3] Non-empty incomplete decisions append continuation messages and events:\n";

$continue_events = array();
$seen_messages   = array();
$nudge_policy    = new class() implements AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy {
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision {
		return AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision::incomplete(
			'Please keep going: the required artifact is still missing.',
			array( 'missing' => 'artifact' )
		);
	}
};

$nudge_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'start' ) ),
	static function ( array $messages, array $context ) use ( &$seen_messages ): array {
		$seen_messages[ $context['turn'] ] = $messages;

		if ( 1 === $context['turn'] ) {
			return array(
				'messages'   => $messages,
				'tool_calls' => array(
					array( 'name' => 'client/work', 'parameters' => array() ),
				),
			);
		}

		return array(
			'messages'   => $messages,
			'content'    => 'continued',
			'tool_calls' => array(),
		);
	},
	array(
		'max_turns'         => 3,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'completion_policy' => $nudge_policy,
		'on_event'          => static function ( string $event, array $payload ) use ( &$continue_events ): void {
			if ( 'completion_policy_continue' === $event ) {
				$continue_events[] = $payload;
			}
		},
	)
);

$nudge_messages = array_values(
	array_filter(
		$nudge_result['messages'],
		static function ( array $message ): bool {
			return 'completion_policy_continue' === ( $message['metadata']['type'] ?? '' );
		}
	)
);
$nudge_events = array_values(
	array_filter(
		$nudge_result['events'],
		static function ( array $event ): bool {
			return 'completion_policy_continue' === ( $event['type'] ?? '' );
		}
	)
);

agents_api_smoke_assert_equals( 1, count( $nudge_messages ), 'non-empty incomplete decision appends one continuation message', $failures, $passes );
agents_api_smoke_assert_equals( 'user', $nudge_messages[0]['role'] ?? '', 'continuation message uses provider-safe user role', $failures, $passes );
agents_api_smoke_assert_equals( 'Please keep going: the required artifact is still missing.', $nudge_messages[0]['content'] ?? '', 'continuation message carries decision text', $failures, $passes );
agents_api_smoke_assert_equals( array( 'missing' => 'artifact' ), $nudge_events[0]['metadata']['context'] ?? array(), 'continuation event carries decision context', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $continue_events ), 'continuation lifecycle event is emitted', $failures, $passes );
$turn_two_messages = $seen_messages[2] ?? array();
$turn_two_tail     = ! empty( $turn_two_messages ) ? $turn_two_messages[ array_key_last( $turn_two_messages ) ] : array();
agents_api_smoke_assert_equals( 'Please keep going: the required artifact is still missing.', $turn_two_tail['content'] ?? '', 'next turn receives appended continuation message', $failures, $passes );

echo "\n[4] Empty incomplete decisions preserve existing no-op behavior:\n";

$empty_policy = new class() implements AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy {
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision {
		return AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision::incomplete();
	}
};

$empty_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'start' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'name' => 'client/work', 'parameters' => array() ),
			),
		);
	},
	array(
		'max_turns'         => 1,
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'completion_policy' => $empty_policy,
	)
);

$empty_nudge_messages = array_values(
	array_filter(
		$empty_result['messages'],
		static function ( array $message ): bool {
			return 'completion_policy_continue' === ( $message['metadata']['type'] ?? '' );
		}
	)
);
$empty_nudge_events = array_values(
	array_filter(
		$empty_result['events'],
		static function ( array $event ): bool {
			return 'completion_policy_continue' === ( $event['type'] ?? '' );
		}
	)
);

agents_api_smoke_assert_equals( 0, count( $empty_nudge_messages ), 'empty incomplete decision does not append a continuation message', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $empty_nudge_events ), 'empty incomplete decision does not append a continuation event', $failures, $passes );

echo "\n[5] Completion policy works in caller-managed path (turn runner handles tool execution):\n";
$policy_log = array();

// Build a policy that completes on any tool.
$always_complete_policy = new class() implements AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy {
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision {
		return AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision::complete( 'always stop' );
	}
};

$caller_managed_turns = 0;
$result3      = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages, array $context ) use ( &$caller_managed_turns ): array {
		++$caller_managed_turns;
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

agents_api_smoke_assert_equals( 1, $caller_managed_turns, 'caller-managed loop stopped after one turn via completion policy', $failures, $passes );

echo "\n[6] Completion policy does not truncate same-turn tool results for Responses API pairing:\n";
$policy_log = array();
$same_turn_executor_calls = 0;
$same_turn_executor = new class( $same_turn_executor_calls ) implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	private int $calls;

	public function __construct( int &$calls ) {
		$this->calls = &$calls;
	}

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		++$this->calls;
		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array( 'path' => $tool_call['parameters']['path'] ?? '' ),
		);
	}
};

$same_turn_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'write files' ) ),
	static function ( array $messages ): array {
		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array( 'id' => 'call_one', 'name' => 'client/work', 'parameters' => array( 'path' => 'one.html' ) ),
				array( 'id' => 'call_two', 'name' => 'client/work', 'parameters' => array( 'path' => 'two.html' ) ),
			),
		);
	},
	array(
		'max_turns'         => 5,
		'tool_executor'     => $same_turn_executor,
		'tool_declarations' => $tools,
		'completion_policy' => $always_complete_policy,
		'should_continue'   => static function (): bool {
			return true;
		},
	)
);

$same_turn_tool_results = array_values(
	array_filter(
		$same_turn_result['messages'],
		static function ( array $message ): bool {
			return ( $message['type'] ?? '' ) === AgentsAPI\AI\WP_Agent_Message::TYPE_TOOL_RESULT;
		}
	)
);
$same_turn_stop_events = array_values(
	array_filter(
		$same_turn_result['events'],
		static function ( array $event ): bool {
			return 'completion_policy_stop' === ( $event['type'] ?? '' );
		}
	)
);

agents_api_smoke_assert_equals( 2, $same_turn_executor_calls, 'completion policy still executes every same-turn tool call', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $same_turn_result['tool_execution_results'] ), 'completion policy preserves every same-turn tool execution result', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $same_turn_tool_results ), 'transcript contains every same-turn tool result message', $failures, $passes );
agents_api_smoke_assert_equals( array( 'call_one', 'call_two' ), array_column( array_column( $same_turn_tool_results, 'metadata' ), 'tool_call_id' ), 'tool result messages keep every provider call id', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $same_turn_stop_events ), 'complete decision records one stop event while draining same-turn calls', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop completion policy', $failures, $passes );
