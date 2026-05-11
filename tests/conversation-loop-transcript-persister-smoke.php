<?php
/**
 * Pure-PHP smoke test for WP_Agent_Conversation_Loop transcript persister wiring.
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
$persister     = new class( $persister_log ) implements AgentsAPI\AI\WP_Agent_Transcript_Persister {
	/** @var array Log reference. */
	private array $log;

	public function __construct( array &$log ) {
		$this->log = &$log;
	}

	public function persist( array $messages, AgentsAPI\AI\WP_Agent_Conversation_Request $request, array $result ): string {
		$this->log[] = array(
			'message_count'    => count( $messages ),
			'request_turns'    => $request->maxTurns(),
			'workspace'        => $request->workspace() ? $request->workspace()->to_array() : null,
			'result_keys'      => array_keys( $result ),
			'completed'        => $result['completed'] ?? null,
			'request_metadata' => $result['request_metadata'] ?? null,
		);

		return 'transcript-' . count( $this->log );
	}
};

echo "\n[1] Persister fires on the success path:\n";
$persister_log = array();

$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages ): array {
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'hi there' );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
			'request_metadata'       => array(
				'memory_files' => array(
					array( 'filename' => 'SITE.md' ),
				),
			),
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
agents_api_smoke_assert_equals( true, $result['completed'] ?? null, 'successful loop result is marked completed', $failures, $passes );
agents_api_smoke_assert_equals( true, $persister_log[0]['completed'], 'persister receives completed successful result', $failures, $passes );
agents_api_smoke_assert_equals( 'SITE.md', $result['request_metadata']['memory_files'][0]['filename'] ?? '', 'loop result preserves caller request metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'SITE.md', $persister_log[0]['request_metadata']['memory_files'][0]['filename'] ?? '', 'persister receives caller request metadata', $failures, $passes );

echo "\n[2] Persister fires on the failure path (turn runner throws):\n";
$persister_log = array();

$threw = false;
try {
	AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
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

$result3 = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
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
$crashing_persister = new class() implements AgentsAPI\AI\WP_Agent_Transcript_Persister {
	public function persist( array $messages, AgentsAPI\AI\WP_Agent_Conversation_Request $request, array $result ): string {
		throw new \RuntimeException( 'database down' );
	}
};

$result4 = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages ): array {
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'ok' );

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

$request = new AgentsAPI\AI\WP_Agent_Conversation_Request(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	array(),
	null,
	array( 'mode' => 'test' ),
	array(),
	3,
	false,
	AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_parts( 'runtime', 'intelligence-chubes4' )
);

$result5 = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
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
agents_api_smoke_assert_equals(
	array(
		'workspace_type' => 'runtime',
		'workspace_id'   => 'intelligence-chubes4',
	),
	$persister_log[0]['workspace'],
	'persister received original request workspace scope',
	$failures,
	$passes
);

echo "\n[6] Transcript lock wraps turn execution and persistence when available:\n";
$lock_log      = array();
$persister_log = array();
$locking_store = new class( $lock_log ) implements AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock {
	/** @var array Log reference. */
	private array $log;

	public function __construct( array &$log ) {
		$this->log = &$log;
	}

	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		$this->log[] = array( 'acquire', $session_id, $ttl_seconds );
		return 'lock-token';
	}

	public function release_session_lock( string $session_id, string $lock_token ): bool {
		$this->log[] = array( 'release', $session_id, $lock_token );
		return true;
	}
};
$locking_persister = new class( $persister_log, $lock_log ) implements AgentsAPI\AI\WP_Agent_Transcript_Persister {
	/** @var array Persister log reference. */
	private array $persister_log;

	/** @var array Lock sequencing log reference. */
	private array $lock_log;

	public function __construct( array &$persister_log, array &$lock_log ) {
		$this->persister_log = &$persister_log;
		$this->lock_log      = &$lock_log;
	}

	public function persist( array $messages, AgentsAPI\AI\WP_Agent_Conversation_Request $request, array $result ): string {
		unset( $request, $result );
		$this->persister_log[] = count( $messages );
		$this->lock_log[]      = array( 'persist' );

		return 'locked-transcript';
	}
};

$result6 = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'locked' ) ),
	static function ( array $messages ) use ( &$lock_log ): array {
		$lock_log[] = array( 'runner' );
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'locked ok' );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'             => 1,
		'transcript_session_id' => 'session-lock-1',
		'transcript_lock'       => $locking_store,
		'transcript_lock_ttl'   => 45,
		'transcript_persister'  => $locking_persister,
	)
);

agents_api_smoke_assert_equals( 2, count( $result6['messages'] ), 'locked loop still returns runner result', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $persister_log ), 'persister still runs while lock is held', $failures, $passes );
agents_api_smoke_assert_equals(
	array(
		array( 'acquire', 'session-lock-1', 45 ),
		array( 'runner' ),
		array( 'persist' ),
		array( 'release', 'session-lock-1', 'lock-token' ),
	),
	$lock_log,
	'lock is acquired before runner and released after persistence',
	$failures,
	$passes
);

echo "\n[7] Lock contention returns without running the turn or persister:\n";
$persister_log   = array();
$contention_runs = 0;
$contention_lock = new class() implements AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock {
	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		unset( $session_id, $ttl_seconds );
		return null;
	}

	public function release_session_lock( string $session_id, string $lock_token ): bool {
		unset( $session_id, $lock_token );
		return false;
	}
};

$result7 = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'contended' ) ),
	static function () use ( &$contention_runs ): array {
		++$contention_runs;
		return array( 'messages' => array() );
	},
	array(
		'max_turns'             => 1,
		'transcript_session_id' => 'session-lock-2',
		'transcript_lock'       => $contention_lock,
		'transcript_persister'  => $persister,
	)
);

agents_api_smoke_assert_equals( 'transcript_lock_contention', $result7['status'] ?? '', 'contention result is explicit', $failures, $passes );
agents_api_smoke_assert_equals( 0, $contention_runs, 'turn runner is skipped on lock contention', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $persister_log ), 'persister is skipped on lock contention', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop transcript persister', $failures, $passes );
