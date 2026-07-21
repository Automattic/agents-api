<?php
/**
 * Pure-PHP smoke test for generic run outcome/status finalization.
 *
 * Run with: php tests/run-outcome-status-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "run-outcome-status-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return $GLOBALS['__agents_api_smoke_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['__agents_api_smoke_options'][ $option ] = $value;
		return true;
	}
}

agents_api_smoke_require_module();

$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );
		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array( 'ok' => true ),
		);
	}
};

$approval_envelope = AgentsAPI\AI\WP_Agent_Message::approvalRequired(
	'Do protected work.',
	array(
		'action_id' => 'approve-1',
		'summary'   => 'Do protected work.',
	)
);

$approval_executor = new class( $approval_envelope ) implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	/** @var array<string,mixed> */
	private array $approval_envelope;

	/** @param array<string,mixed> $approval_envelope */
	public function __construct( array $approval_envelope ) {
		$this->approval_envelope = $approval_envelope;
	}

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );
		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => $this->approval_envelope,
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

$run_status = static function ( string $run_id ): ?string {
	$run = AgentsAPI\AI\WP_Agent_Chat_Run_Control::get_run( $run_id );
	return is_array( $run ) ? ( $run['status'] ?? null ) : null;
};

echo "\n[1] completed runs stay completed:\n";
AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages ): array {
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'done' );
		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'run_id'                => 'run-completed',
		'transcript_session_id' => 'session-completed',
	)
);
agents_api_smoke_assert_equals( 'completed', $run_status( 'run-completed' ), 'completed loop finalizes run as completed', $failures, $passes );

echo "\n[2] pending runtime tool remains pending:\n";
$pending = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static fn( array $messages ): array => array(
		'messages'   => $messages,
		'tool_calls' => array( array( 'name' => 'client/work', 'parameters' => array() ) ),
	),
	array(
		'run_id'                => 'run-pending',
		'transcript_session_id' => 'session-pending',
		'tool_executor'         => $executor,
		'tool_declarations'     => $tools,
		'pre_tool_mediator'     => static fn(): array => array(
			'action'               => 'pending',
			'runtime_tool_request' => array( 'request_id' => 'request-1' ),
		),
	)
);
agents_api_smoke_assert_equals( 'runtime_tool_pending', $pending['run_outcome']['status'] ?? null, 'runtime-tool outcome is explicit', $failures, $passes );
agents_api_smoke_assert_equals( 'runtime_tool_pending', $run_status( 'run-pending' ), 'runtime-tool run status is not completed', $failures, $passes );

echo "\n[3] approval-required remains approval-required:\n";
$approval = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static fn( array $messages ): array => array(
		'messages'   => $messages,
		'tool_calls' => array( array( 'name' => 'client/work', 'parameters' => array() ) ),
	),
	array(
		'run_id'                => 'run-approval',
		'transcript_session_id' => 'session-approval',
		'tool_executor'         => $approval_executor,
		'tool_declarations'     => $tools,
	)
);
agents_api_smoke_assert_equals( 'approval_required', $approval['run_outcome']['status'] ?? null, 'approval outcome is explicit', $failures, $passes );
agents_api_smoke_assert_equals( 'approval_required', $run_status( 'run-approval' ), 'approval run status is not completed', $failures, $passes );

echo "\n[4] budget exhaustion finalizes distinctly:\n";
$budget = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static fn( array $messages ): array => array(
		'messages'               => $messages,
		'tool_execution_results' => array(),
		'events'                 => array(),
	),
	array(
		'run_id'                => 'run-budget',
		'transcript_session_id' => 'session-budget',
		'max_turns'             => 10,
		'budgets'               => array( new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'turns', 1 ) ),
		'should_continue'       => static fn(): bool => true,
	)
);
agents_api_smoke_assert_equals( 'budget_exceeded', $budget['run_outcome']['status'] ?? null, 'budget outcome is explicit', $failures, $passes );
agents_api_smoke_assert_equals( 'budget_exceeded', $run_status( 'run-budget' ), 'budget run status is not completed', $failures, $passes );

echo "\n[5] stalled finalizes distinctly:\n";
$spin_detector = new class() implements AgentsAPI\AI\WP_Agent_Spin_Detector {
	public function record_signature( AgentsAPI\AI\WP_Agent_Spin_Signature $signature, array $context = array() ): bool {
		unset( $signature, $context );
		return true;
	}
	public function repeat_count(): int { return 2; }
	public function threshold(): int { return 2; }
};
$stalled = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static fn( array $messages ): array => array(
		'messages'   => $messages,
		'tool_calls' => array( array( 'name' => 'client/work', 'parameters' => array() ) ),
	),
	array(
		'run_id'                => 'run-stalled',
		'transcript_session_id' => 'session-stalled',
		'tool_executor'         => $executor,
		'tool_declarations'     => $tools,
		'spin_detector'         => $spin_detector,
	)
);
agents_api_smoke_assert_equals( 'stalled', $stalled['run_outcome']['status'] ?? null, 'stalled outcome is explicit', $failures, $passes );
agents_api_smoke_assert_equals( 'stalled', $run_status( 'run-stalled' ), 'stalled run status is not completed', $failures, $passes );

echo "\n[6] failed finalizes distinctly:\n";
$failed = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static fn(): array => array(
		'failure' => array(
			'type'    => 'provider_error',
			'message' => 'Provider failed.',
		),
	),
	array(
		'run_id'                => 'run-failed',
		'transcript_session_id' => 'session-failed',
	)
);
agents_api_smoke_assert_equals( 'failed', $failed['run_outcome']['status'] ?? null, 'failed outcome is explicit', $failures, $passes );
agents_api_smoke_assert_equals( 'failed', $run_status( 'run-failed' ), 'failed run status is not completed', $failures, $passes );

echo "\n[7] cancellation interrupt finalizes distinctly:\n";
$interrupted = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static fn( array $messages ): array => array(
		'messages'               => $messages,
		'tool_execution_results' => array(),
		'events'                 => array(),
	),
	array(
		'run_id'                => 'run-interrupted',
		'transcript_session_id' => 'session-interrupted',
		'max_turns'             => 2,
		'should_continue'       => static fn(): bool => true,
		'interrupt_source'      => static fn(): array => AgentsAPI\AI\WP_Agent_Chat_Run_Control::cancellation_interrupt_message( 'run-interrupted', 'session-interrupted' ),
	)
);
agents_api_smoke_assert_equals( 'interrupted', $interrupted['run_outcome']['status'] ?? null, 'interrupted outcome is explicit', $failures, $passes );
agents_api_smoke_assert_equals( 'interrupted', $run_status( 'run-interrupted' ), 'interrupted run status is not completed', $failures, $passes );

agents_api_smoke_finish( 'run outcome status', $failures, $passes );
