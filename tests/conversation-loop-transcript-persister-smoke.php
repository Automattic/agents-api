<?php
/**
 * Pure-PHP smoke test for AgentConversationLoop transcript persister wiring.
 *
 * Run with: php tests/conversation-loop-transcript-persister-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-loop-transcript-persister-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

// Build a persister that records calls.
$persister_log = array();
$persister     = new class( $persister_log ) implements AgentsAPI\AI\AgentConversationTranscriptPersisterInterface {
	/** @var array Log reference. */
	private array $log;

	public function __construct( array &$log ) {
		$this->log = &$log;
	}

	public function persist( array $messages, AgentsAPI\AI\AgentConversationRequest $request, array $result ): string {
		$this->log[] = array(
			'message_count' => count( $messages ),
			'request_turns' => $request->maxTurns(),
			'result_keys'   => array_keys( $result ),
		);

		return 'transcript-' . count( $this->log );
	}
};

echo "\n[1] Persister fires on the success path:\n";
$persister_log = array();

$result = AgentsAPI\AI\AgentConversationLoop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages ): array {
		$messages[] = AgentsAPI\AI\AgentMessageEnvelope::text( 'assistant', 'hi there' );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'            => 1,
		'transcript_persister' => $persister,
	)
);

agents_api_smoke_assert_equals( 1, count( $persister_log ), 'persister was called once on success', $failures, $passes );
agents_api_smoke_assert_equals( 2, $persister_log[0]['message_count'], 'persister received final messages', $failures, $passes );
agents_api_smoke_assert_equals( 1, $persister_log[0]['request_turns'], 'persister received request with correct max_turns', $failures, $passes );

echo "\n[2] Persister fires on the failure path (turn runner throws):\n";
$persister_log = array();

$threw = false;
try {
	AgentsAPI\AI\AgentConversationLoop::run(
		array( array( 'role' => 'user', 'content' => 'fail' ) ),
		static function (): array {
			throw new \RuntimeException( 'provider error' );
		},
		array(
			'max_turns'            => 1,
			'transcript_persister' => $persister,
		)
	);
} catch ( \RuntimeException $e ) {
	$threw = 'provider error' === $e->getMessage();
}

agents_api_smoke_assert_equals( true, $threw, 'turn runner exception was re-thrown', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $persister_log ), 'persister was called on failure path', $failures, $passes );

echo "\n[3] No persister = no persistence calls (backwards compatible):\n";
$persister_log = array();

$result3 = AgentsAPI\AI\AgentConversationLoop::run(
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

agents_api_smoke_assert_equals( 0, count( $persister_log ), 'persister was not called when not provided', $failures, $passes );

echo "\n[4] Persister failure does not change loop result:\n";
$crashing_persister = new class() implements AgentsAPI\AI\AgentConversationTranscriptPersisterInterface {
	public function persist( array $messages, AgentsAPI\AI\AgentConversationRequest $request, array $result ): string {
		throw new \RuntimeException( 'database down' );
	}
};

$result4 = AgentsAPI\AI\AgentConversationLoop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages ): array {
		$messages[] = AgentsAPI\AI\AgentMessageEnvelope::text( 'assistant', 'ok' );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'            => 1,
		'transcript_persister' => $crashing_persister,
	)
);

agents_api_smoke_assert_equals( 2, count( $result4['messages'] ), 'loop result is unaffected by persister failure', $failures, $passes );

echo "\n[5] Persister receives the original request when provided:\n";
$persister_log = array();

$request = new AgentsAPI\AI\AgentConversationRequest(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	array(),
	null,
	array( 'mode' => 'test' ),
	array(),
	3
);

$result5 = AgentsAPI\AI\AgentConversationLoop::run(
	$request->messages(),
	static function ( array $messages ): array {
		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'            => 3,
		'context'              => $request->runtimeContext(),
		'request'              => $request,
		'transcript_persister' => $persister,
	)
);

agents_api_smoke_assert_equals( 1, count( $persister_log ), 'persister was called with original request', $failures, $passes );
agents_api_smoke_assert_equals( 3, $persister_log[0]['request_turns'], 'persister received original request max_turns', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop transcript persister', $failures, $passes );
