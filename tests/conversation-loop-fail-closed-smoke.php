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

echo "\n[3] The turn-runner contract violation (non-array return) still escapes to the caller:\n";
$threw = false;
try {
	AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'hello' ) ),
		static function (): string {
			return 'not an array';
		}
	);
} catch ( InvalidArgumentException $e ) {
	$threw = str_starts_with( $e->getMessage(), 'invalid_agent_conversation_loop:' );
}
agents_api_smoke_assert_equals( true, $threw, 'deliberate contract violation is not swallowed by the fail-closed guard', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop fail-closed', $failures, $passes );
