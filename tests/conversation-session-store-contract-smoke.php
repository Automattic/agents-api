<?php
/**
 * Pure-PHP smoke test for the canonical conversation session store contract.
 *
 * Run with: php tests/conversation-session-store-contract-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "conversation-session-store-contract-smoke\n";

function smoke_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

require_once __DIR__ . '/../src/Workspace/class-wp-agent-workspace-scope.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-execution-principal.php';
require_once __DIR__ . '/../src/Transcripts/class-wp-agent-conversation-store.php';
require_once __DIR__ . '/../src/Transcripts/class-wp-agent-principal-conversation-store.php';
require_once __DIR__ . '/../src/Transcripts/class-wp-agent-principal-conversation-session-reader.php';

use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\Core\Database\Chat\WP_Agent_Principal_Conversation_Session_Reader;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

$store = new class() implements WP_Agent_Principal_Conversation_Session_Reader {
	/** @var array<string,array<string,mixed>> */
	private array $sessions = array();

	public function create_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		$session_id = 'fake-' . ( count( $this->sessions ) + 1 );
		$now        = '2026-06-03T00:00:00Z';

		$this->sessions[ $session_id ] = array(
			'session_id'           => $session_id,
			'workspace_type'       => $workspace->workspace_type,
			'workspace_id'         => $workspace->workspace_id,
			'owner_type'           => (string) ( $owner['type'] ?? '' ),
			'owner_key'            => (string) ( $owner['key'] ?? '' ),
			'user_id'              => WP_Agent_Execution_Principal::OWNER_TYPE_USER === ( $owner['type'] ?? '' ) ? (int) $owner['key'] : 0,
			'agent_slug'           => $agent_slug,
			'title'                => '',
			'messages'             => array(),
			'metadata'             => $metadata,
			'provider'             => '',
			'model'                => '',
			'provider_response_id' => null,
			'context'              => $context,
			'created_at'           => $now,
			'updated_at'           => $now,
			'last_read_at'         => null,
			'expires_at'           => null,
		);

		return $session_id;
	}

	public function create_session( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		return $this->create_session_for_owner( $workspace, array( 'type' => WP_Agent_Execution_Principal::OWNER_TYPE_USER, 'key' => (string) $user_id ), $agent_slug, $metadata, $context );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_sessions_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, array $args = array() ): array {
		$include_messages = ! empty( $args['include_messages'] );
		$agent_slug       = (string) ( $args['agent_slug'] ?? '' );
		$context          = (string) ( $args['context'] ?? '' );

		$rows = array_values(
			array_filter(
				$this->sessions,
				static function ( array $session ) use ( $workspace, $owner, $agent_slug, $context ): bool {
					if ( $session['workspace_type'] !== $workspace->workspace_type || $session['workspace_id'] !== $workspace->workspace_id ) {
						return false;
					}
					if ( $session['owner_type'] !== (string) ( $owner['type'] ?? '' ) || $session['owner_key'] !== (string) ( $owner['key'] ?? '' ) ) {
						return false;
					}
					if ( '' !== $agent_slug && $session['agent_slug'] !== $agent_slug ) {
						return false;
					}
					return '' === $context || $session['context'] === $context;
				}
			)
		);

		if ( ! $include_messages ) {
			foreach ( $rows as &$row ) {
				unset( $row['messages'] );
			}
			unset( $row );
		}

		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$limit  = max( 1, (int) ( $args['limit'] ?? count( $rows ) ?: 1 ) );
		return array_slice( $rows, $offset, $limit );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		return $this->list_sessions_for_owner( $workspace, array( 'type' => WP_Agent_Execution_Principal::OWNER_TYPE_USER, 'key' => (string) $user_id ), $args );
	}

	public function get_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $session_id ): ?array {
		$session = $this->get_session( $session_id );
		if ( null === $session ) {
			return null;
		}

		return $session['workspace_type'] === $workspace->workspace_type
			&& $session['workspace_id'] === $workspace->workspace_id
			&& $session['owner_type'] === (string) ( $owner['type'] ?? '' )
			&& $session['owner_key'] === (string) ( $owner['key'] ?? '' )
			? $session
			: null;
	}

	public function get_session( string $session_id ): ?array {
		return $this->sessions[ $session_id ] ?? null;
	}

	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}

		$this->sessions[ $session_id ]['messages']             = array_values( $messages );
		$this->sessions[ $session_id ]['metadata']             = $metadata;
		$this->sessions[ $session_id ]['provider']             = $provider;
		$this->sessions[ $session_id ]['model']                = $model;
		$this->sessions[ $session_id ]['provider_response_id'] = $provider_response_id;
		$this->sessions[ $session_id ]['updated_at']           = '2026-06-03T00:01:00Z';
		return true;
	}

	public function update_title( string $session_id, string $title ): bool {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}
		$this->sessions[ $session_id ]['title']      = $title;
		$this->sessions[ $session_id ]['updated_at'] = '2026-06-03T00:02:00Z';
		return true;
	}

	public function delete_session( string $session_id ): bool {
		unset( $this->sessions[ $session_id ] );
		return true;
	}

	public function get_recent_pending_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		unset( $seconds, $token_id );
		foreach ( array_reverse( $this->sessions ) as $session ) {
			if ( $session['workspace_type'] === $workspace->workspace_type && $session['workspace_id'] === $workspace->workspace_id && $session['owner_type'] === (string) ( $owner['type'] ?? '' ) && $session['owner_key'] === (string) ( $owner['key'] ?? '' ) && $session['context'] === $context && array() === $session['messages'] ) {
				return $session;
			}
		}
		return null;
	}

	public function get_recent_pending_session( WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		return $this->get_recent_pending_session_for_owner( $workspace, array( 'type' => WP_Agent_Execution_Principal::OWNER_TYPE_USER, 'key' => (string) $user_id ), $seconds, $context, $token_id );
	}
};

$workspace = WP_Agent_Workspace_Scope::from_parts( 'site', '42' );
$metadata  = array(
	'run_id'          => 'run_123',
	'example_product' => array(
		'last_read_at' => '2026-06-03T00:00:30Z',
	),
);

$session_id = $store->create_session( $workspace, 7, 'demo-agent', $metadata, 'chat' );
$session    = $store->get_session( $session_id );

smoke_assert( 'fake-1', $session_id, 'create returns stable adapter session id', $failures, $passes );
smoke_assert( 'site', $session['workspace_type'] ?? null, 'workspace_type is stored', $failures, $passes );
smoke_assert( '42', $session['workspace_id'] ?? null, 'workspace_id is stored', $failures, $passes );
smoke_assert( 'user', $session['owner_type'] ?? null, 'user create stores canonical owner_type', $failures, $passes );
smoke_assert( '7', $session['owner_key'] ?? null, 'user create stores canonical owner_key', $failures, $passes );
smoke_assert( 7, $session['user_id'] ?? null, 'user_id mirrors user owner', $failures, $passes );
smoke_assert( 'demo-agent', $session['agent_slug'] ?? null, 'agent slug is stored', $failures, $passes );
smoke_assert( 'run_123', $session['metadata']['run_id'] ?? null, 'metadata can link caller run id', $failures, $passes );
smoke_assert( '2026-06-03T00:00:30Z', $session['metadata']['example_product']['last_read_at'] ?? null, 'product metadata remains namespaced', $failures, $passes );

$store->update_session(
	$session_id,
	array(
		array( 'role' => 'user', 'content' => 'hello' ),
		array( 'role' => 'assistant', 'content' => 'hi' ),
	),
	array( 'run_id' => 'run_124' ),
	'openai',
	'gpt-5.5',
	'resp_abc'
);
$session = $store->get_session( $session_id );

smoke_assert( 2, count( $session['messages'] ?? array() ), 'update_session replaces full transcript', $failures, $passes );
smoke_assert( 'run_124', $session['metadata']['run_id'] ?? null, 'update_session replaces metadata', $failures, $passes );
smoke_assert( 'openai', $session['provider'] ?? null, 'provider linkage is stored', $failures, $passes );
smoke_assert( 'gpt-5.5', $session['model'] ?? null, 'model linkage is stored', $failures, $passes );
smoke_assert( 'resp_abc', $session['provider_response_id'] ?? null, 'provider response linkage is stored', $failures, $passes );

$store->update_title( $session_id, 'Renamed session' );
smoke_assert( 'Renamed session', $store->get_session( $session_id )['title'] ?? null, 'update_title changes stored display title', $failures, $passes );

$store->create_session( $workspace, 8, 'demo-agent', array(), 'chat' );
$store->create_session( WP_Agent_Workspace_Scope::from_parts( 'site', '43' ), 7, 'demo-agent', array(), 'chat' );
$listed = $store->list_sessions( $workspace, 7, array( 'include_messages' => false, 'agent_slug' => 'demo-agent', 'context' => 'chat' ) );

smoke_assert( 1, count( $listed ), 'list_sessions scopes by workspace/user/agent/context', $failures, $passes );
smoke_assert( false, isset( $listed[0]['messages'] ), 'list_sessions can omit transcript payloads', $failures, $passes );

$browser_owner = array( 'type' => 'browser', 'key' => 'browser:opaque' );
$browser_id    = $store->create_session_for_owner( $workspace, $browser_owner, 'demo-agent', array(), 'chat' );

smoke_assert( 'browser:opaque', $store->get_session_for_owner( $workspace, $browser_owner, $browser_id )['owner_key'] ?? null, 'principal reader loads matching opaque owner', $failures, $passes );
smoke_assert( null, $store->get_session_for_owner( $workspace, array( 'type' => 'browser', 'key' => 'browser:other' ), $browser_id ), 'principal reader blocks other opaque owners', $failures, $passes );
smoke_assert( 'fake-2', $store->get_recent_pending_session( $workspace, 8, 600, 'chat' )['session_id'] ?? null, 'pending lookup scopes user sessions', $failures, $passes );
smoke_assert( 'fake-4', $store->get_recent_pending_session_for_owner( $workspace, $browser_owner, 600, 'chat' )['session_id'] ?? null, 'pending lookup scopes principal sessions', $failures, $passes );

smoke_assert( true, $store->delete_session( $session_id ), 'delete_session returns true', $failures, $passes );
smoke_assert( null, $store->get_session( $session_id ), 'delete_session removes session', $failures, $passes );
smoke_assert( true, $store->delete_session( $session_id ), 'delete_session is idempotent', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " conversation session store contract assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} conversation session store contract assertions passed.\n";
