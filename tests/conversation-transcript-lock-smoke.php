<?php
/**
 * Pure-PHP smoke test for conversation transcript lock semantics.
 *
 * Run with: php tests/conversation-transcript-lock-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-transcript-lock-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$clock = 1000;
$lock  = new class( $clock ) implements AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock {
	/** @var int Clock reference. */
	private int $now;

	/** @var array<string, array{token: string, expires_at: int}> */
	private array $locks = array();

	/** @var int Token counter. */
	private int $counter = 0;

	public function __construct( int &$now ) {
		$this->now = &$now;
	}

	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		$active = $this->locks[ $session_id ] ?? null;
		if ( null !== $active && $active['expires_at'] > $this->now ) {
			return null;
		}

		$token                       = 'token-' . ++$this->counter;
		$this->locks[ $session_id ] = array(
			'token'      => $token,
			'expires_at' => $this->now + max( 1, $ttl_seconds ),
		);

		return $token;
	}

	public function release_session_lock( string $session_id, string $lock_token ): bool {
		$active = $this->locks[ $session_id ] ?? null;
		if ( null === $active || $active['token'] !== $lock_token ) {
			return false;
		}

		unset( $this->locks[ $session_id ] );
		return true;
	}
};

echo "\n[1] Acquire then release succeeds:\n";
$token = $lock->acquire_session_lock( 'session-1', 30 );
agents_api_smoke_assert_equals( true, is_string( $token ) && '' !== $token, 'acquire returns a lock token', $failures, $passes );
agents_api_smoke_assert_equals( true, $lock->release_session_lock( 'session-1', (string) $token ), 'release accepts the active token', $failures, $passes );

echo "\n[2] Contention returns null:\n";
$token_a = $lock->acquire_session_lock( 'session-2', 30 );
$token_b = $lock->acquire_session_lock( 'session-2', 30 );
agents_api_smoke_assert_equals( true, is_string( $token_a ) && '' !== $token_a, 'first contender acquires lock', $failures, $passes );
agents_api_smoke_assert_equals( null, $token_b, 'second contender is denied while lock is active', $failures, $passes );

echo "\n[3] TTL expiry permits reacquisition:\n";
$clock += 31;
$token_c = $lock->acquire_session_lock( 'session-2', 30 );
agents_api_smoke_assert_equals( true, is_string( $token_c ) && '' !== $token_c && $token_c !== $token_a, 'expired lock is reclaimable with new token', $failures, $passes );

echo "\n[4] Stale token release is rejected:\n";
agents_api_smoke_assert_equals( false, $lock->release_session_lock( 'session-2', (string) $token_a ), 'stale token does not release reacquired lock', $failures, $passes );
agents_api_smoke_assert_equals( true, $lock->release_session_lock( 'session-2', (string) $token_c ), 'current token still releases after stale rejection', $failures, $passes );

echo "\n[5] Null lock explicitly declines lock ownership:\n";
$null_lock = new AgentsAPI\Core\Database\Chat\WP_Agent_Null_Conversation_Lock();
agents_api_smoke_assert_equals( null, $null_lock->acquire_session_lock( 'session-3', 30 ), 'null lock returns null on acquire', $failures, $passes );
agents_api_smoke_assert_equals( false, $null_lock->release_session_lock( 'session-3', 'token' ), 'null lock returns false on release', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation transcript lock', $failures, $passes );
