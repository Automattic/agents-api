<?php
/**
 * Pure-PHP smoke test for conversation session authorization boundaries.
 *
 * Covers two families of confirmed authorization defects in
 * register-agents-conversation-session-abilities.php:
 *
 *   1. self-asserted-owner (CWE-863 / CWE-639): a low-privilege authenticated
 *      caller must not be able to address a foreign, non-user owner
 *      (token/audience/system/custom) by declaring it in the request body.
 *   2. missing-workspace-boundary (CWE-639): the non-reader fallback must not
 *      return a same-owner row that lives in a different workspace.
 *
 * Each assertion FAILS without the fix and PASSES with it.
 *
 * Run with: php tests/conversation-session-authz-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "conversation-session-authz-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return in_array( $cap, $GLOBALS['__smoke_caps'] ?? array(), true );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['__smoke_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return 42;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '' );
	}
}

$GLOBALS['__smoke_caps']            = array( 'read' );
$GLOBALS['__smoke_current_user_id'] = 0;

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

function smoke_error_code( $value ): string {
	return $value instanceof WP_Error ? $value->get_error_code() : '(not-a-wp-error)';
}

agents_api_smoke_require_module();

use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\Core\Database\Chat\WP_Agent_Principal_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
use function AgentsAPI\Core\Database\Chat\agents_create_conversation_session;
use function AgentsAPI\Core\Database\Chat\agents_delete_conversation_session;
use function AgentsAPI\Core\Database\Chat\agents_get_conversation_session;
use function AgentsAPI\Core\Database\Chat\agents_list_conversation_sessions;
use function AgentsAPI\Core\Database\Chat\agents_update_conversation_session_title;

/*
 * A principal-aware store that, like the shipped CPT store, implements
 * WP_Agent_Principal_Conversation_Store but NOT the Session_Reader interface,
 * so get/update/delete take the non-reader fallback. get_session() resolves a
 * row by id alone, ignoring workspace — the property the fallback must guard.
 */
$store = new class() implements WP_Agent_Principal_Conversation_Store {
	/** @var array<string,array<string,mixed>> */
	public array $sessions = array();

	public function seed( string $id, string $owner_type, string $owner_key, string $workspace_id, int $user_id = 0 ): void {
		$this->sessions[ $id ] = array(
			'session_id'     => $id,
			'workspace_type' => 'site',
			'workspace_id'   => $workspace_id,
			'owner_type'     => $owner_type,
			'owner_key'      => $owner_key,
			'user_id'        => $user_id,
			'title'          => 'seed',
			'messages'       => array(),
			'metadata'       => array(),
			'context'        => 'chat',
		);
	}

	public function create_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		$id                  = 'c-' . ( count( $this->sessions ) + 1 );
		$this->sessions[ $id ] = array(
			'session_id'     => $id,
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
		return $id;
	}

	public function list_sessions_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, array $args = array() ): array {
		unset( $args );
		return array_values(
			array_filter(
				$this->sessions,
				static fn( array $s ): bool => $s['workspace_type'] === $workspace->workspace_type && $s['workspace_id'] === $workspace->workspace_id && $s['owner_type'] === $owner['type'] && $s['owner_key'] === $owner['key']
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

	// get_session resolves by id only — no workspace scoping (the vulnerable seam).
	public function get_session( string $session_id ): ?array {
		return $this->sessions[ $session_id ] ?? null;
	}

	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool {
		unset( $session_id, $messages, $metadata, $provider, $model, $provider_response_id );
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

// Foreign, non-user owner the attacker will try to forge (workspace 42).
$store->seed( 'victim-token', WP_Agent_Execution_Principal::OWNER_TYPE_TOKEN, '999', '42' );
// Same-owner (attacker == user 7) rows in two different workspaces.
$store->seed( 'user7-wsA', WP_Agent_Execution_Principal::OWNER_TYPE_USER, '7', '42', 7 );
$store->seed( 'user7-wsB', WP_Agent_Execution_Principal::OWNER_TYPE_USER, '7', '99', 7 );

add_filter( 'wp_agent_conversation_store', static fn() => $store );

$ws42     = array( 'workspace_type' => 'site', 'workspace_id' => '42' );
$attacker = WP_Agent_Execution_Principal::user_session( 7, 'demo-agent', WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST );

// ---------------------------------------------------------------------------
// Family 1: self-asserted non-user owner forgery. A read-level user principal
// must not address the token:999 owner by declaring it in session_owner.
// ---------------------------------------------------------------------------
$forged_owner = array( 'type' => 'token', 'key' => '999' );

$forged_list = agents_list_conversation_sessions( array( 'principal' => $attacker, 'workspace' => $ws42, 'session_owner' => $forged_owner ) );
smoke_assert( 'agents_conversation_session_owner_forbidden', smoke_error_code( $forged_list ), 'list: forged non-user owner is rejected', $failures, $passes );

$forged_get = agents_get_conversation_session( array( 'principal' => $attacker, 'workspace' => $ws42, 'session_id' => 'victim-token', 'session_owner' => $forged_owner ) );
smoke_assert( 'agents_conversation_session_owner_forbidden', smoke_error_code( $forged_get ), 'get: forged non-user owner is rejected', $failures, $passes );

$forged_create = agents_create_conversation_session( array( 'principal' => $attacker, 'workspace' => $ws42, 'session_owner' => $forged_owner ) );
smoke_assert( 'agents_conversation_session_owner_forbidden', smoke_error_code( $forged_create ), 'create: forged non-user owner is rejected', $failures, $passes );

$forged_title = agents_update_conversation_session_title( array( 'principal' => $attacker, 'workspace' => $ws42, 'session_id' => 'victim-token', 'title' => 'pwned', 'session_owner' => $forged_owner ) );
smoke_assert( 'agents_conversation_session_owner_forbidden', smoke_error_code( $forged_title ), 'update-title: forged non-user owner is rejected', $failures, $passes );

$forged_delete = agents_delete_conversation_session( array( 'principal' => $attacker, 'workspace' => $ws42, 'session_id' => 'victim-token', 'session_owner' => $forged_owner ) );
smoke_assert( 'agents_conversation_session_owner_forbidden', smoke_error_code( $forged_delete ), 'delete: forged non-user owner is rejected', $failures, $passes );
smoke_assert( true, isset( $store->sessions['victim-token'] ), 'delete: forged owner leaves victim session intact', $failures, $passes );

// ---------------------------------------------------------------------------
// Family 2: missing workspace boundary in the non-reader fallback. The
// attacker owns user7-wsB, but it lives in workspace 99, not the requested 42.
// ---------------------------------------------------------------------------
$cross_get = agents_get_conversation_session( array( 'principal' => $attacker, 'workspace' => $ws42, 'session_id' => 'user7-wsB' ) );
smoke_assert( 'agents_conversation_session_forbidden', smoke_error_code( $cross_get ), 'get: same-owner cross-workspace row is blocked', $failures, $passes );
smoke_assert( 403, $cross_get instanceof WP_Error ? ( $cross_get->get_error_data()['status'] ?? null ) : null, 'get: cross-workspace block carries 403', $failures, $passes );

$cross_title = agents_update_conversation_session_title( array( 'principal' => $attacker, 'workspace' => $ws42, 'session_id' => 'user7-wsB', 'title' => 'renamed' ) );
smoke_assert( 'agents_conversation_session_forbidden', smoke_error_code( $cross_title ), 'update-title: same-owner cross-workspace row is blocked', $failures, $passes );

$cross_delete = agents_delete_conversation_session( array( 'principal' => $attacker, 'workspace' => $ws42, 'session_id' => 'user7-wsB' ) );
smoke_assert( 'agents_conversation_session_forbidden', smoke_error_code( $cross_delete ), 'delete: same-owner cross-workspace row is blocked', $failures, $passes );
smoke_assert( true, isset( $store->sessions['user7-wsB'] ), 'delete: cross-workspace row survives', $failures, $passes );

// Positive control: the attacker can still reach its own row in workspace 42.
$own_get = agents_get_conversation_session( array( 'principal' => $attacker, 'workspace' => $ws42, 'session_id' => 'user7-wsA' ) );
smoke_assert( 'user7-wsA', is_array( $own_get ) ? ( $own_get['session']['session_id'] ?? null ) : null, 'get: legitimate same-workspace owner still resolves', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " conversation session authorization assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} conversation session authorization assertions passed.\n";
