<?php
/**
 * Pure-PHP smoke test for generic conversation session abilities.
 *
 * Run with: php tests/agents-conversation-session-abilities-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "agents-conversation-session-abilities-smoke\n";

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function current_user_can( string $cap ): bool {
	return in_array( $cap, $GLOBALS['__smoke_caps'] ?? array(), true );
}

function get_current_user_id(): int {
	return (int) ( $GLOBALS['__smoke_current_user_id'] ?? 0 );
}

function get_current_blog_id(): int {
	return 42;
}

$GLOBALS['__smoke_filters']    = array();
$GLOBALS['__smoke_abilities']  = array();
$GLOBALS['__smoke_categories'] = array();

function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $accepted_args );
	$GLOBALS['__smoke_filters'][ $hook ][ $priority ][] = $cb;
}

function apply_filters( string $hook, $value, ...$args ) {
	$callbacks = $GLOBALS['__smoke_filters'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $cb ) {
			$value = call_user_func_array( $cb, array_merge( array( $value ), $args ) );
		}
	}
	return $value;
}

function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	add_filter( $hook, $cb, $priority, $accepted_args );
}

function do_action( string $hook, ...$args ): void {
	$callbacks = $GLOBALS['__smoke_filters'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $cb ) {
			call_user_func_array( $cb, $args );
		}
	}
}

function wp_has_ability_category( string $category ): bool {
	return isset( $GLOBALS['__smoke_categories'][ $category ] );
}

function wp_register_ability_category( string $category, array $args ): void {
	$GLOBALS['__smoke_categories'][ $category ] = $args;
}

function wp_has_ability( string $ability ): bool {
	return isset( $GLOBALS['__smoke_abilities'][ $ability ] );
}

function wp_register_ability( string $ability, array $args ): void {
	$GLOBALS['__smoke_abilities'][ $ability ] = $args;
}

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
require_once __DIR__ . '/../src/Transcripts/class-wp-agent-conversation-sessions.php';
require_once __DIR__ . '/../src/Transcripts/register-agents-conversation-session-abilities.php';

use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\Core\Database\Chat\WP_Agent_Principal_Conversation_Store;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
use function AgentsAPI\Core\Database\Chat\agents_create_conversation_session;
use function AgentsAPI\Core\Database\Chat\agents_delete_conversation_session;
use function AgentsAPI\Core\Database\Chat\agents_get_conversation_session;
use function AgentsAPI\Core\Database\Chat\agents_list_conversation_sessions;
use function AgentsAPI\Core\Database\Chat\agents_update_conversation_session_title;
use const AgentsAPI\Core\Database\Chat\AGENTS_CREATE_CONVERSATION_SESSION_ABILITY;
use const AgentsAPI\Core\Database\Chat\AGENTS_DELETE_CONVERSATION_SESSION_ABILITY;
use const AgentsAPI\Core\Database\Chat\AGENTS_GET_CONVERSATION_SESSION_ABILITY;
use const AgentsAPI\Core\Database\Chat\AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY;
use const AgentsAPI\Core\Database\Chat\AGENTS_UPDATE_CONVERSATION_SESSION_TITLE_ABILITY;

$store = new class() implements WP_Agent_Conversation_Store {
	/** @var array<string,array<string,mixed>> */
	public array $sessions = array();
	public array $last_list_args = array();

	public function create_session( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		$session_id = 's-' . ( count( $this->sessions ) + 1 );
		$this->sessions[ $session_id ] = array(
			'session_id'     => $session_id,
			'workspace_type' => $workspace->workspace_type,
			'workspace_id'   => $workspace->workspace_id,
			'user_id'        => $user_id,
			'agent_slug'     => $agent_slug,
			'title'          => '',
			'messages'       => array(),
			'metadata'       => $metadata,
			'context'        => $context,
			'created_at'     => '2026-05-12T00:00:00Z',
			'updated_at'     => '2026-05-12T00:00:00Z',
		);
		return $session_id;
	}

	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		$this->last_list_args = $args;
		return array_values(
			array_filter(
				$this->sessions,
				static fn( array $session ): bool => $session['workspace_type'] === $workspace->workspace_type && $session['workspace_id'] === $workspace->workspace_id && $session['user_id'] === $user_id
			)
		);
	}

	public function get_session( string $session_id ): ?array {
		return $this->sessions[ $session_id ] ?? null;
	}

	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool {
		unset( $provider, $model, $provider_response_id );
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}
		$this->sessions[ $session_id ]['messages'] = $messages;
		$this->sessions[ $session_id ]['metadata'] = $metadata;
		return true;
	}

	public function delete_session( string $session_id ): bool {
		unset( $this->sessions[ $session_id ] );
		return true;
	}

	public function get_recent_pending_session( WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		unset( $workspace, $user_id, $seconds, $context, $token_id );
		return null;
	}

	public function update_title( string $session_id, string $title ): bool {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}
		$this->sessions[ $session_id ]['title'] = $title;
		return true;
	}
};

add_filter( 'wp_agent_conversation_store', static fn() => $store );

$principal = WP_Agent_Execution_Principal::user_session( 7, 'demo-agent', WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST );
$input     = array(
	'principal' => $principal,
	'workspace' => array(
		'workspace_type' => 'site',
		'workspace_id'   => '42',
	),
	'agent'     => 'demo-agent',
	'metadata'  => array( 'client' => 'frontend' ),
	'context'   => 'chat',
);

$created = agents_create_conversation_session( $input );
smoke_assert( 's-1', $created['session']['session_id'] ?? null, 'create returns stored session', $failures, $passes );
smoke_assert( 'frontend', $created['session']['metadata']['client'] ?? null, 'create passes metadata to store', $failures, $passes );

$store->update_session( 's-1', array( array( 'role' => 'user', 'content' => 'hello' ) ) );
$got = agents_get_conversation_session( array( 'principal' => $principal, 'session_id' => 's-1' ) );
smoke_assert( 'hello', $got['session']['messages'][0]['content'] ?? null, 'get returns messages', $failures, $passes );

$listed = agents_list_conversation_sessions( $input + array( 'limit' => 10 ) );
smoke_assert( 's-1', $listed['sessions'][0]['session_id'] ?? null, 'list returns owned workspace session', $failures, $passes );
smoke_assert( false, isset( $listed['sessions'][0]['messages'] ), 'list omits transcript messages', $failures, $passes );
smoke_assert( 10, $store->last_list_args['limit'] ?? null, 'list forwards pagination args', $failures, $passes );
smoke_assert( false, $store->last_list_args['include_messages'] ?? true, 'list requests summary rows by default', $failures, $passes );

$renamed = agents_update_conversation_session_title( array( 'principal' => $principal, 'session_id' => 's-1', 'title' => 'New title' ) );
smoke_assert( 'New title', $renamed['session']['title'] ?? null, 'update title delegates to store', $failures, $passes );

$other_principal = WP_Agent_Execution_Principal::user_session( 8, 'demo-agent', WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST );
$forbidden       = agents_get_conversation_session( array( 'principal' => $other_principal, 'session_id' => 's-1' ) );
smoke_assert( true, $forbidden instanceof WP_Error, 'get blocks sessions owned by another user', $failures, $passes );
smoke_assert( 'agents_conversation_session_forbidden', $forbidden instanceof WP_Error ? $forbidden->get_error_code() : '', 'forbidden error code', $failures, $passes );

$audience_without_owner = WP_Agent_Execution_Principal::audience( 'audience:public', 'demo-agent' );
$owner_required         = agents_list_conversation_sessions( array( 'principal' => $audience_without_owner ) );
smoke_assert( 'agents_conversation_session_owner_required', $owner_required instanceof WP_Error ? $owner_required->get_error_code() : '', 'audience access alone cannot list sessions', $failures, $passes );

$audience_with_owner = WP_Agent_Execution_Principal::audience( 'audience:public', 'demo-agent', WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST, array(), null, null, array(), 'browser:one' );
$store_required      = agents_list_conversation_sessions( array( 'principal' => $audience_with_owner ) );
smoke_assert( 'agents_conversation_session_principal_store_required', $store_required instanceof WP_Error ? $store_required->get_error_code() : '', 'legacy store only accepts user owners', $failures, $passes );

$deleted = agents_delete_conversation_session( array( 'principal' => $principal, 'session_id' => 's-1' ) );
smoke_assert( true, $deleted['deleted'] ?? false, 'delete delegates to store', $failures, $passes );
smoke_assert( null, $store->get_session( 's-1' ), 'delete removes session', $failures, $passes );

$principal_store = new class() implements WP_Agent_Principal_Conversation_Store {
	/** @var array<string,array<string,mixed>> */
	public array $sessions = array();

	public function create_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		$session_id = 'p-' . ( count( $this->sessions ) + 1 );
		$this->sessions[ $session_id ] = array(
			'session_id'     => $session_id,
			'workspace_type' => $workspace->workspace_type,
			'workspace_id'   => $workspace->workspace_id,
			'owner_type'     => $owner['type'],
			'owner_key'      => $owner['key'],
			'user_id'        => 0,
			'agent_slug'     => $agent_slug,
			'title'          => '',
			'messages'       => array(),
			'metadata'       => $metadata,
			'context'        => $context,
		);
		return $session_id;
	}

	public function list_sessions_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, array $args = array() ): array {
		unset( $args );
		return array_values(
			array_filter(
				$this->sessions,
				static fn( array $session ): bool => $session['workspace_type'] === $workspace->workspace_type && $session['workspace_id'] === $workspace->workspace_id && $session['owner_type'] === $owner['type'] && $session['owner_key'] === $owner['key']
			)
		);
	}

	public function get_recent_pending_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		unset( $workspace, $owner, $seconds, $context, $token_id );
		return null;
	}

	public function create_session( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		return $this->create_session_for_owner( $workspace, array( 'type' => WP_Agent_Execution_Principal::OWNER_TYPE_USER, 'key' => (string) $user_id ), $agent_slug, $metadata, $context );
	}

	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		return $this->list_sessions_for_owner( $workspace, array( 'type' => WP_Agent_Execution_Principal::OWNER_TYPE_USER, 'key' => (string) $user_id ), $args );
	}

	public function get_session( string $session_id ): ?array { return $this->sessions[ $session_id ] ?? null; }
	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool { unset( $session_id, $messages, $metadata, $provider, $model, $provider_response_id ); return true; }
	public function delete_session( string $session_id ): bool { unset( $this->sessions[ $session_id ] ); return true; }
	public function get_recent_pending_session( WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array { unset( $workspace, $user_id, $seconds, $context, $token_id ); return null; }
	public function update_title( string $session_id, string $title ): bool { $this->sessions[ $session_id ]['title'] = $title; return true; }
};

add_filter( 'wp_agent_conversation_store', static fn() => $principal_store, 20 );

$audience_created = agents_create_conversation_session( array( 'principal' => $audience_with_owner, 'workspace' => array( 'workspace_type' => 'site', 'workspace_id' => '42' ) ) );
smoke_assert( 'p-1', $audience_created['session']['session_id'] ?? null, 'principal store creates audience-owned session', $failures, $passes );
smoke_assert( 'browser:one', $principal_store->sessions['p-1']['owner_key'] ?? null, 'principal store receives opaque owner key', $failures, $passes );

$audience_listed = agents_list_conversation_sessions( array( 'principal' => $audience_with_owner, 'workspace' => array( 'workspace_type' => 'site', 'workspace_id' => '42' ) ) );
smoke_assert( 'p-1', $audience_listed['sessions'][0]['session_id'] ?? null, 'principal store lists matching owner only', $failures, $passes );

$other_audience = WP_Agent_Execution_Principal::audience( 'audience:public', 'demo-agent', WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST, array(), null, null, array(), 'browser:two' );
$blocked_owner  = agents_get_conversation_session( array( 'principal' => $other_audience, 'session_id' => 'p-1' ) );
smoke_assert( 'agents_conversation_session_forbidden', $blocked_owner instanceof WP_Error ? $blocked_owner->get_error_code() : '', 'principal owner key blocks other audience sessions', $failures, $passes );

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

smoke_assert( true, wp_has_ability( AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY ), 'list ability registers', $failures, $passes );
smoke_assert( true, wp_has_ability( AGENTS_GET_CONVERSATION_SESSION_ABILITY ), 'get ability registers', $failures, $passes );
smoke_assert( true, wp_has_ability( AGENTS_CREATE_CONVERSATION_SESSION_ABILITY ), 'create ability registers', $failures, $passes );
smoke_assert( true, wp_has_ability( AGENTS_UPDATE_CONVERSATION_SESSION_TITLE_ABILITY ), 'update-title ability registers', $failures, $passes );
smoke_assert( true, wp_has_ability( AGENTS_DELETE_CONVERSATION_SESSION_ABILITY ), 'delete ability registers', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " agents conversation session ability assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} agents conversation session ability assertions passed.\n";
