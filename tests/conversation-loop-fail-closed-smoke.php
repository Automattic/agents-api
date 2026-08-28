<?php
/**
 * Pure-PHP smoke test: the conversation loop fails closed when the loop body throws.
 *
 * Regression guard for a stuck-RUNNING failure mode. The turn-runner call is
 * wrapped in its own try/catch, but the rest of the outer loop body -- post-turn
 * checks, tool-call mediation, message construction, the runtime-tool store, and
 * the caller-supplied `should_continue` continuation policy -- ran under an outer
 * try whose only companion was a `finally` that released the transcript lock.
 * There was no catch. An unguarded throw from that region escaped run() with the
 * run stuck in STATUS_RUNNING forever: no `failed` event, no persisted transcript.
 *
 * This test drives a throw from the `should_continue` continuation callback (an
 * unguarded caller-owned hook that runs in the outer body after a turn) and
 * asserts the run fails closed instead of hanging.
 *
 * Run with: php tests/conversation-loop-fail-closed-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-loop-fail-closed-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();
require_once __DIR__ . '/class-agents-api-memory-atomic-run-control-store.php';
AgentsAPI\AI\WP_Agent_Run_Control::set_store( new Agents_API_Memory_Atomic_Run_Control_Store() );

// A transcript persister that records whether it was called and with what status.
$persist_log = array();
$persister   = new class( $persist_log ) implements AgentsAPI\AI\WP_Agent_Transcript_Persister {
	/** @var array Log reference. */
	private array $log;

	public function __construct( array &$log ) {
		$this->log = &$log;
	}

	public function persist( array $messages, AgentsAPI\AI\WP_Agent_Conversation_Request $request, array $result ): string {
		unset( $request );
		$this->log[] = array(
			'message_count' => count( $messages ),
			'status'        => $result['status'] ?? '',
			'result'        => $result,
		);

		return 'transcript-' . count( $this->log );
	}
};

// A well-behaved caller-managed turn runner that always yields one assistant turn.
$turn_runner = static function ( array $messages ): array {
	$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'working' );
	return array(
		'messages'               => $messages,
		'tool_execution_results' => array(),
	);
};

echo "\n[1] An unguarded throw from the loop body fails closed instead of escaping:\n";
$persist_log   = array();
$events        = array();
$loop_id       = 'fail-closed-run-1';
$escaped_error = null;

try {
	$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'hello' ) ),
		$turn_runner,
		array(
			'run_id'                => $loop_id,
			'transcript_session_id' => 'fail-closed-session-1',
			'max_turns'             => 3,
			'should_continue'       => static function (): bool {
				// A caller-owned continuation policy that throws inside the outer
				// loop body, past the turn-runner try/catch boundary.
				throw new \RuntimeException( 'continuation policy exploded' );
			},
			'transcript_persister'  => $persister,
			'on_event'              => static function ( string $event, array $payload ) use ( &$events ): void {
				$events[] = array( 'event' => $event, 'payload' => $payload );
			},
		)
	);
} catch ( \Throwable $error ) {
	// Before the fix the throw escapes run() entirely; capture it so the
	// remaining assertions can report the regression instead of fataling.
	$escaped_error = $error;
	$result        = array();
}

$failed_events = array_values( array_filter( $events, static fn( array $e ): bool => 'failed' === $e['event'] ) );

agents_api_smoke_assert_equals( null, $escaped_error, 'unguarded loop-body throw does not escape run()', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', $result['status'] ?? '', 'loop returns a structured failed result instead of hanging in RUNNING', $failures, $passes );
agents_api_smoke_assert_equals( 'continuation policy exploded', $result['failure']['message'] ?? '', 'structured failure preserves the thrown message', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $failed_events ), 'a failed lifecycle event is emitted', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $persist_log ), 'transcript persister is called on the fail-closed path', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', $persist_log[0]['status'] ?? '', 'persister receives the failed result', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( $loop_id )['status'] ?? '', 'should_continue failure durably finalizes run control', $failures, $passes );

echo "\n[2] The normal (non-throwing) continuation path still completes cleanly:\n";
$persist_log = array();
$events      = array();

$ok_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	$turn_runner,
	array(
		'run_id'                => 'fail-closed-run-2',
		'transcript_session_id' => 'fail-closed-session-2',
		'max_turns'             => 3,
		'should_continue'       => static function ( array $turn_result, array $context ): bool {
			unset( $turn_result );
			// Stop after the first turn -- a well-behaved continuation policy.
			return 1 > $context['turn'];
		},
		'transcript_persister'  => $persister,
		'on_event'              => static function ( string $event, array $payload ) use ( &$events ): void {
			$events[] = array( 'event' => $event, 'payload' => $payload );
		},
	)
);

$ok_failed_events    = array_values( array_filter( $events, static fn( array $e ): bool => 'failed' === $e['event'] ) );
$ok_completed_events = array_values( array_filter( $events, static fn( array $e ): bool => 'completed' === $e['event'] ) );

agents_api_smoke_assert_equals( true, $ok_result['completed'] ?? null, 'legitimate flow completes when the continuation policy does not throw', $failures, $passes );
agents_api_smoke_assert_equals( false, 'failed' === ( $ok_result['status'] ?? '' ), 'normal path is not marked failed', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $ok_failed_events ), 'no failed event is emitted on the normal path', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $ok_completed_events ), 'a completed event is emitted on the normal path', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $persist_log ), 'persister is called once on the normal path', $failures, $passes );

echo "\n[3] Policy exceptions fail closed before tool execution:\n";
$policy_executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public int $calls = 0;
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );
		++$this->calls;
		return array( 'success' => true, 'tool_name' => $tool_call['tool_name'], 'result' => array( 'ok' => true ) );
	}
};
$policy_tools = array(
	'write/item' => array(
		'name'        => 'write/item',
		'source'      => 'test',
		'description' => 'Write one item.',
		'parameters'  => array( 'type' => 'object', 'properties' => array() ),
		'executor'    => 'test',
	),
);
$tool_turn = static function ( array $messages ): array {
	return array(
		'messages'   => $messages,
		'tool_calls' => array( array( 'id' => 'policy-call', 'name' => 'write/item', 'parameters' => array() ) ),
	);
};

$persist_log    = array();
$mediator_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'write' ) ),
	$tool_turn,
	array(
		'run_id'                => 'fail-closed-mediator',
		'transcript_session_id' => 'fail-closed-mediator-session',
		'tool_executor'         => $policy_executor,
		'tool_declarations'     => $policy_tools,
		'pre_tool_mediator'     => static function (): array {
			throw new RuntimeException( 'mediator unavailable' );
		},
		'transcript_persister'  => $persister,
	)
);
agents_api_smoke_assert_equals( 'failed', $mediator_result['status'] ?? '', 'throwing mediator fails the run closed', $failures, $passes );
agents_api_smoke_assert_equals( 0, $policy_executor->calls, 'throwing mediator never substitutes proceed', $failures, $passes );

add_filter(
	'agents_api_pre_tool_call_decision',
	static function ( array $decision, array $context ): array {
		if ( 'gate-call' === ( $context['tool_call_id'] ?? '' ) ) {
			throw new RuntimeException( 'gate unavailable' );
		}
		return $decision;
	},
	10,
	2
);
$gate_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'write' ) ),
	static function ( array $messages ): array {
		return array( 'messages' => $messages, 'tool_calls' => array( array( 'id' => 'gate-call', 'name' => 'write/item', 'parameters' => array() ) ) );
	},
	array(
		'run_id'                => 'fail-closed-gate',
		'transcript_session_id' => 'fail-closed-gate-session',
		'tool_executor'         => $policy_executor,
		'tool_declarations'     => $policy_tools,
		'transcript_persister'  => $persister,
	)
);
agents_api_smoke_assert_equals( 'failed', $gate_result['status'] ?? '', 'throwing policy filter fails the run closed', $failures, $passes );
agents_api_smoke_assert_equals( 0, $policy_executor->calls, 'throwing policy filter never executes the tool', $failures, $passes );

echo "\n[3b] Malformed pending and replacement policy payloads fail closed:\n";
$policy_executor->calls = 0;
$persist_log            = array();
$malformed_pending      = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'malformed pending' ) ),
	$tool_turn,
	array(
		'run_id'                => 'fail-closed-malformed-pending',
		'transcript_session_id' => 'fail-closed-malformed-pending-session',
		'tool_executor'         => $policy_executor,
		'tool_declarations'     => $policy_tools,
		'pre_tool_mediator'     => static fn(): array => array(
			'action'               => 'pending',
			'runtime_tool_request' => array( 'tool_name' => array( 'invalid' ) ),
		),
		'transcript_persister'  => $persister,
	)
);
agents_api_smoke_assert_equals( 'failed', $malformed_pending['status'] ?? '', 'malformed pending decision fails the loop', $failures, $passes );
agents_api_smoke_assert_equals( 0, $policy_executor->calls, 'malformed pending decision executes no tool effect', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( 'fail-closed-malformed-pending' )['status'] ?? '', 'malformed pending decision durably fails run control', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', $persist_log[0]['status'] ?? '', 'malformed pending decision persists failed audit state', $failures, $passes );

$persist_log       = array();
$malformed_replace = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'malformed replacement' ) ),
	$tool_turn,
	array(
		'run_id'                => 'fail-closed-malformed-replace',
		'transcript_session_id' => 'fail-closed-malformed-replace-session',
		'tool_executor'         => $policy_executor,
		'tool_declarations'     => $policy_tools,
		'pre_tool_mediator'     => static fn(): array => array( 'action' => 'replace_result', 'result' => 'invalid' ),
		'transcript_persister'  => $persister,
	)
);
agents_api_smoke_assert_equals( 'failed', $malformed_replace['status'] ?? '', 'malformed replace_result decision fails the loop', $failures, $passes );
agents_api_smoke_assert_equals( 0, $policy_executor->calls, 'malformed replace_result decision executes no tool effect', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( 'fail-closed-malformed-replace' )['status'] ?? '', 'malformed replace_result decision durably fails run control', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', $persist_log[0]['status'] ?? '', 'malformed replace_result decision persists failed audit state', $failures, $passes );

echo "\n[4] Runtime-tool store exceptions fail the run and persist the transcript:\n";
$runtime_store = new class() implements AgentsAPI\AI\WP_Agent_Runtime_Tool_Request_Store {
	public function create( array $request ): void { unset( $request ); throw new RuntimeException( 'runtime store unavailable' ); }
	public function get( string $request_id ): ?array { unset( $request_id ); return null; }
	public function complete( string $request_id, array $result ): void { unset( $request_id, $result ); }
	public function timeout( string $request_id ): void { unset( $request_id ); }
	public function recent_pending( array $query = array() ): array { unset( $query ); return array(); }
};
$pending_executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public int $calls = 0;
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );
		++$this->calls;
		return array(
			'success'              => false,
			'tool_name'            => $tool_call['tool_name'],
			'status'               => AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
			'runtime_tool_request' => array( 'tool_name' => $tool_call['tool_name'], 'tool_call_id' => $tool_call['id'], 'parameters' => array() ),
		);
	}
};
$persist_log = array();
$runtime_store_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'external tool' ) ),
	$tool_turn,
	array(
		'run_id'                    => 'fail-closed-runtime-store',
		'transcript_session_id'     => 'fail-closed-runtime-store-session',
		'tool_executor'             => $pending_executor,
		'tool_declarations'         => $policy_tools,
		'runtime_tool_request_store' => $runtime_store,
		'transcript_persister'      => $persister,
	)
);
agents_api_smoke_assert_equals( 'failed', $runtime_store_result['status'] ?? '', 'runtime-tool storage throw returns a failed result', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( 'fail-closed-runtime-store' )['status'] ?? '', 'runtime-tool storage throw durably finalizes run control', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $persist_log ), 'runtime-tool storage throw persists the failed transcript', $failures, $passes );
agents_api_smoke_assert_equals( 1, $pending_executor->calls, 'runtime-tool storage throw does not repeat the effect', $failures, $passes );
agents_api_smoke_assert_equals( true, $runtime_store_result['tool_audit_events'][0]['effect_occurred'] ?? false, 'runtime-tool storage failure audit records the completed effect', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_effect_completed', $runtime_store_result['tool_audit_events'][0]['type'] ?? '', 'runtime-tool storage failure retains the explicit effect receipt', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $runtime_store_result['tool_execution_results'] ?? array() ), 'runtime-tool storage failure does not invent an outward response', $failures, $passes );

echo "\n[5] Completed tool effects remain in the failed audit when later policy throws:\n";
$policy_executor->calls = 0;
$persist_log            = array();
$throwing_policy        = new class() implements AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy {
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision {
		unset( $tool_name, $tool_def, $tool_result, $runtime_context, $turn_count );
		throw new RuntimeException( 'completion policy unavailable' );
	}
};
$post_tool_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'write once' ) ),
	$tool_turn,
	array(
		'run_id'                => 'fail-closed-post-tool',
		'transcript_session_id' => 'fail-closed-post-tool-session',
		'tool_executor'         => $policy_executor,
		'tool_declarations'     => $policy_tools,
		'completion_policy'     => $throwing_policy,
		'transcript_persister'  => $persister,
	)
);
agents_api_smoke_assert_equals( 1, $policy_executor->calls, 'side-effecting tool executes exactly once', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', $post_tool_result['status'] ?? '', 'later completion-policy throw produces a terminal failure', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $post_tool_result['tool_execution_results'] ?? array() ), 'failed result retains the single normalized mediation response', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $post_tool_result['tool_audit_events'] ?? array() ), 'failed result retains completed tool audit event', $failures, $passes );
agents_api_smoke_assert_equals( 'tool_call', $post_tool_result['tool_audit_events'][0]['type'] ?? '', 'completed mediation refines the effect receipt into the canonical audit event', $failures, $passes );
agents_api_smoke_assert_equals( true, str_starts_with( $post_tool_result['tool_audit_events'][0]['result_sha256'] ?? '', 'sha256:' ), 'effect receipt safely hashes the raw diagnostic', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $persist_log[0]['result']['tool_audit_events'] ?? array() ), 'persisted transcript retains completed tool effect receipt', $failures, $passes );

echo "\n[6] Hook and truncator throws retain the immediate effect checkpoint:\n";
$hook_store = new class() implements AgentsAPI\AI\WP_Agent_Runtime_Tool_Request_Store {
	public function create( array $request ): void { unset( $request ); }
	public function get( string $request_id ): ?array { unset( $request_id ); return null; }
	public function complete( string $request_id, array $result ): void { unset( $request_id, $result ); }
	public function timeout( string $request_id ): void { unset( $request_id ); }
	public function recent_pending( array $query = array() ): array { unset( $query ); return array(); }
};
add_action(
	'agents_api_runtime_tool_request_created',
	static function (): void {
		throw new RuntimeException( 'runtime lifecycle hook unavailable' );
	}
);
$pending_executor->calls = 0;
$hook_result             = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'hook failure' ) ),
	$tool_turn,
	array(
		'run_id'                => 'fail-closed-post-tool-hook',
		'transcript_session_id' => 'fail-closed-post-tool-hook-session',
		'tool_executor'         => $pending_executor,
		'tool_declarations'     => $policy_tools,
		'runtime_tool_request_store' => $hook_store,
	)
);
agents_api_smoke_assert_equals( 1, $pending_executor->calls, 'post-effect hook throw executes the tool exactly once', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', $hook_result['status'] ?? '', 'post-effect hook throw fails the run', $failures, $passes );
agents_api_smoke_assert_equals( true, $hook_result['tool_audit_events'][0]['effect_occurred'] ?? false, 'post-effect hook failure audit records the completed effect', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $hook_result['tool_execution_results'] ?? array() ), 'post-effect hook failure retains no unresolved outward response', $failures, $passes );

$throwing_truncator = new class() implements AgentsAPI\AI\WP_Agent_Tool_Result_Truncator {
	public function truncate_result( array $result, string $tool_name, array $context = array() ): array {
		unset( $result, $tool_name, $context );
		throw new RuntimeException( 'truncator unavailable' );
	}
};
$policy_executor->calls = 0;
$truncator_result       = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'truncator failure' ) ),
	$tool_turn,
	array(
		'run_id'                => 'fail-closed-post-tool-truncator',
		'transcript_session_id' => 'fail-closed-post-tool-truncator-session',
		'tool_executor'         => $policy_executor,
		'tool_declarations'     => $policy_tools,
		'tool_result_truncator' => $throwing_truncator,
	)
);
agents_api_smoke_assert_equals( 1, $policy_executor->calls, 'truncator throw executes the tool exactly once', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', $truncator_result['status'] ?? '', 'truncator throw fails the run', $failures, $passes );
agents_api_smoke_assert_equals( true, $truncator_result['tool_audit_events'][0]['effect_occurred'] ?? false, 'truncator failure audit records the completed effect', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $truncator_result['tool_execution_results'] ?? array() ), 'truncator failure retains no unresolved outward response', $failures, $passes );

echo "\n[7] The turn-runner contract violation finalizes run control and still escapes:\n";
$threw = false;
$persist_log = array();
try {
	AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'hello' ) ),
		static function (): string {
			return 'not an array';
		},
		array(
			'run_id'                => 'fail-closed-contract',
			'transcript_session_id' => 'fail-closed-contract-session',
			'transcript_persister'  => $persister,
		)
	);
} catch ( InvalidArgumentException $e ) {
	$threw = str_starts_with( $e->getMessage(), 'invalid_agent_conversation_loop:' );
}
agents_api_smoke_assert_equals( true, $threw, 'deliberate contract violation is not swallowed by the fail-closed guard', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( 'fail-closed-contract' )['status'] ?? '', 'contract violation does not strand running run control', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', $persist_log[0]['status'] ?? '', 'contract violation persists a canonical failed result', $failures, $passes );

echo "\n[8] Run-control finalization storage failures remain retryable and visible:\n";
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {}
}
$failing_store = new class() implements AgentsAPI\AI\WP_Agent_Atomic_Run_Control_Store {
	public array $state = array( 'runs' => array(), 'queues' => array(), 'events' => array() );
	public function get_state( string $store_key ): array { unset( $store_key ); return $this->state; }
	public function save_state( string $store_key, array $state ): void { unset( $store_key ); $this->state = $state; }
	public function mutate_state( string $store_key, callable $mutation ): mixed {
		unset( $store_key );
		$mutated = $mutation( $this->state );
		foreach ( $mutated['state']['runs'] ?? array() as $run ) {
			if ( is_array( $run ) && 'running' !== ( $run['status'] ?? 'running' ) ) {
				throw new AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception( 'terminal state write unavailable' );
			}
		}
		$this->state = $mutated['state'];
		return $mutated['result'];
	}
};
AgentsAPI\AI\WP_Agent_Run_Control::set_store( $failing_store );
$finalization_error = null;
try {
	AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'finish' ) ),
		$turn_runner,
		array( 'run_id' => 'fail-closed-finalization', 'transcript_session_id' => 'fail-closed-finalization-session' )
	);
} catch ( AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception $error ) {
	$finalization_error = $error;
}
agents_api_smoke_assert_equals( true, $finalization_error instanceof AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception, 'terminal storage failure escapes as the canonical retryable exception', $failures, $passes );
agents_api_smoke_assert_equals( 'running', $failing_store->state['runs']['fail-closed-finalization']['status'] ?? '', 'failed terminal write remains visibly retryable instead of pretending completion', $failures, $passes );

echo "\n[9] Missing finalization records are rejected as retryable failures:\n";
$missing_store = new class() implements AgentsAPI\AI\WP_Agent_Atomic_Run_Control_Store {
	public array $state = array( 'runs' => array(), 'queues' => array(), 'events' => array() );
	private int $mutations = 0;
	public function get_state( string $store_key ): array { unset( $store_key ); return $this->state; }
	public function save_state( string $store_key, array $state ): void { unset( $store_key ); $this->state = $state; }
	public function mutate_state( string $store_key, callable $mutation ): mixed {
		unset( $store_key );
		++$this->mutations;
		$mutated = $mutation( 1 < $this->mutations ? array( 'runs' => array(), 'queues' => array(), 'events' => array() ) : $this->state );
		if ( 1 === $this->mutations ) {
			$this->state = $mutated['state'];
		}
		return $mutated['result'];
	}
};
AgentsAPI\AI\WP_Agent_Run_Control::set_store( $missing_store );
$missing_error = null;
try {
	AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'missing terminal record' ) ),
		$turn_runner,
		array( 'run_id' => 'fail-closed-missing-record', 'transcript_session_id' => 'fail-closed-missing-record-session' )
	);
} catch ( AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception $error ) {
	$missing_error = $error;
}
agents_api_smoke_assert_equals( true, $missing_error instanceof AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception, 'missing terminal record is a retryable finalization failure', $failures, $passes );
agents_api_smoke_assert_equals( 'running', $missing_store->state['runs']['fail-closed-missing-record']['status'] ?? '', 'missing finalization does not claim a terminal status', $failures, $passes );

echo "\n[10] Failed transcript persistence is explicit after durable run finalization:\n";
AgentsAPI\AI\WP_Agent_Run_Control::set_store( new Agents_API_Memory_Atomic_Run_Control_Store() );
$throwing_persister = new class() implements AgentsAPI\AI\WP_Agent_Transcript_Persister {
	public function persist( array $messages, AgentsAPI\AI\WP_Agent_Conversation_Request $request, array $result ): string {
		unset( $messages, $request, $result );
		throw new RuntimeException( 'transcript store unavailable' );
	}
};
$persistence_error = null;
$persistence_result = null;
try {
	$persistence_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'persist failure audit' ) ),
		$turn_runner,
		array(
			'run_id'                => 'fail-closed-transcript-store',
			'transcript_session_id' => 'fail-closed-transcript-store-session',
			'max_turns'             => 2,
			'should_continue'       => static function (): bool { throw new RuntimeException( 'continuation failed' ); },
			'transcript_persister'  => $throwing_persister,
		)
	);
} catch ( AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception $error ) {
	$persistence_error = $error;
}
agents_api_smoke_assert_equals( null, $persistence_result, 'transcript storage failure never returns an ordinary failed result', $failures, $passes );
agents_api_smoke_assert_equals( true, $persistence_error instanceof AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception, 'transcript storage failure is exposed as retryable finalization diagnostics', $failures, $passes );
agents_api_smoke_assert_equals( 'transcript store unavailable', $persistence_error?->getPrevious()?->getMessage(), 'retryable diagnostics preserve the underlying persistence error', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( 'fail-closed-transcript-store' )['status'] ?? '', 'transcript storage failure still leaves an observable durable failed run', $failures, $passes );

echo "\n[11] Successful execution cannot publish completion when transcript persistence fails:\n";
AgentsAPI\AI\WP_Agent_Run_Control::set_store( new Agents_API_Memory_Atomic_Run_Control_Store() );
$success_events = array();
$success_turns  = 0;
$success_result = null;
$success_persistence_error = null;
try {
	$success_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'complete once' ) ),
		static function ( array $messages ) use ( &$success_turns ): array {
			++$success_turns;
			$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'completed once' );
			return array( 'messages' => $messages, 'tool_execution_results' => array() );
		},
		array(
			'run_id'                => 'fail-closed-success-transcript-store',
			'transcript_session_id' => 'fail-closed-success-transcript-store-session',
			'transcript_persister'  => $throwing_persister,
			'on_event'              => static function ( string $event ) use ( &$success_events ): void { $success_events[] = $event; },
		)
	);
} catch ( AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception $error ) {
	$success_persistence_error = $error;
}
agents_api_smoke_assert_equals( null, $success_result, 'success transcript failure returns no false completed result', $failures, $passes );
agents_api_smoke_assert_equals( 1, $success_turns, 'success transcript failure does not repeat provider execution', $failures, $passes );
agents_api_smoke_assert_equals( 'transcript store unavailable', $success_persistence_error?->getPrevious()?->getMessage(), 'success transcript failure preserves retryable storage diagnostics', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( 'fail-closed-success-transcript-store' )['status'] ?? '', 'success transcript failure durably records failed run control', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( array_filter( $success_events, static fn( string $event ): bool => 'completed' === $event ) ), 'success transcript failure emits no completed event', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( array_filter( $success_events, static fn( string $event ): bool => 'failed' === $event ) ), 'success transcript failure emits one failed event', $failures, $passes );

echo "\n[12] Transcript lock contention creates no running run-control record:\n";
AgentsAPI\AI\WP_Agent_Run_Control::set_store( new Agents_API_Memory_Atomic_Run_Control_Store() );
$contended_lock = new class() implements AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock {
	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string { unset( $session_id, $ttl_seconds ); return null; }
	public function release_session_lock( string $session_id, string $lock_token ): bool { unset( $session_id, $lock_token ); return false; }
};
$contention_turns = 0;
$contention_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'contended' ) ),
	static function () use ( &$contention_turns ): array { ++$contention_turns; return array(); },
	array(
		'run_id'                => 'fail-closed-lock-contention',
		'transcript_session_id' => 'fail-closed-lock-contention-session',
		'transcript_lock'       => $contended_lock,
	)
);
agents_api_smoke_assert_equals( 'failed', $contention_result['status'] ?? '', 'lock contention returns a terminal failed result', $failures, $passes );
agents_api_smoke_assert_equals( 'transcript_lock_contention', $contention_result['failure']['type'] ?? '', 'lock contention retains busy diagnostics', $failures, $passes );
agents_api_smoke_assert_equals( true, $contention_result['failure']['retryable'] ?? false, 'lock contention remains retryable', $failures, $passes );
agents_api_smoke_assert_equals( 0, $contention_turns, 'lock contention starts no provider execution', $failures, $passes );
agents_api_smoke_assert_equals( null, AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( 'fail-closed-lock-contention' ), 'lock contention creates no running run-control zombie', $failures, $passes );

echo "\n[13] Cancellation committed during transcript persistence wins terminal publication:\n";
AgentsAPI\AI\WP_Agent_Run_Control::set_store( new Agents_API_Memory_Atomic_Run_Control_Store() );
$late_cancel_results = array();
$late_cancel_persister = new class( $late_cancel_results ) implements AgentsAPI\AI\WP_Agent_Transcript_Persister {
	private array $results;
	public function __construct( array &$results ) { $this->results = &$results; }
	public function persist( array $messages, AgentsAPI\AI\WP_Agent_Conversation_Request $request, array $result ): string {
		unset( $messages, $request );
		$this->results[] = $result;
		if ( 1 === count( $this->results ) ) {
			AgentsAPI\AI\WP_Agent_Chat_Run_Control::request_cancel( 'fail-closed-late-cancel' );
		}
		return 'late-cancel-transcript';
	}
};
$late_cancel_events = array();
$late_cancel_turns  = 0;
$late_cancel_result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'cancel during persistence' ) ),
	static function ( array $messages ) use ( &$late_cancel_turns ): array {
		++$late_cancel_turns;
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'candidate completion' );
		return array( 'messages' => $messages, 'tool_execution_results' => array() );
	},
	array(
		'run_id'                => 'fail-closed-late-cancel',
		'transcript_session_id' => 'fail-closed-late-cancel-session',
		'transcript_persister'  => $late_cancel_persister,
		'on_event'              => static function ( string $event, array $payload ) use ( &$late_cancel_events ): void {
			$late_cancel_events[] = array( 'event' => $event, 'status' => $payload['status'] ?? '' );
		},
	)
);
$late_cancel_run = AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( 'fail-closed-late-cancel' );
$late_cancel_terminal_events = array_values( array_filter( $late_cancel_events, static fn( array $event ): bool => in_array( $event['event'], array( 'completed', 'cancelled' ), true ) ) );
agents_api_smoke_assert_equals( 1, $late_cancel_turns, 'late cancellation does not repeat provider execution', $failures, $passes );
agents_api_smoke_assert_equals( 'cancelled', $late_cancel_run['status'] ?? '', 'stored run keeps the atomic cancelled winner', $failures, $passes );
agents_api_smoke_assert_equals( true, $late_cancel_run['cancelled'] ?? false, 'stored cancellation fields remain consistent', $failures, $passes );
agents_api_smoke_assert_equals( 'cancelled', $late_cancel_result['status'] ?? '', 'returned conversation result projects cancelled winner', $failures, $passes );
agents_api_smoke_assert_equals( false, $late_cancel_result['completed'] ?? true, 'returned cancelled result is not successful completion', $failures, $passes );
agents_api_smoke_assert_equals( 'cancelled', $late_cancel_result['run_outcome']['status'] ?? '', 'returned run outcome agrees with cancelled run control', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $late_cancel_results ), 'transcript receives corrected authoritative terminal projection', $failures, $passes );
agents_api_smoke_assert_equals( 'cancelled', $late_cancel_results[1]['status'] ?? '', 'final persisted transcript result is cancelled', $failures, $passes );
agents_api_smoke_assert_equals( array( array( 'event' => 'cancelled', 'status' => 'cancelled' ) ), $late_cancel_terminal_events, 'terminal event reports cancelled with no completion success', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop fail-closed', $failures, $passes );
