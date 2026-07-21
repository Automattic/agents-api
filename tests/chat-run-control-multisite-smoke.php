<?php
/**
 * Stateful multisite coverage for workspace-scoped chat run control.
 *
 * Run with: php tests/chat-run-control-multisite-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "chat-run-control-multisite-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_abilities']    = array();
$GLOBALS['__agents_api_smoke_categories']   = array();
$GLOBALS['__agents_api_multisite_blog']     = 1;
$GLOBALS['__agents_api_multisite_stack']    = array();
$GLOBALS['__agents_api_multisite_options']  = array();
$GLOBALS['__agents_api_multisite_network']  = array();
$GLOBALS['__agents_api_multisite_user']     = 123;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

function get_current_blog_id(): int {
	return (int) $GLOBALS['__agents_api_multisite_blog'];
}

function switch_to_blog( int $blog_id ): bool {
	$GLOBALS['__agents_api_multisite_stack'][] = get_current_blog_id();
	$GLOBALS['__agents_api_multisite_blog']    = $blog_id;
	return true;
}

function restore_current_blog(): bool {
	if ( array() === $GLOBALS['__agents_api_multisite_stack'] ) {
		return false;
	}
	$GLOBALS['__agents_api_multisite_blog'] = array_pop( $GLOBALS['__agents_api_multisite_stack'] );
	return true;
}

function get_option( string $option, $default = false ) {
	return $GLOBALS['__agents_api_multisite_options'][ get_current_blog_id() ][ $option ] ?? $default;
}

function update_option( string $option, $value, $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['__agents_api_multisite_options'][ get_current_blog_id() ][ $option ] = $value;
	return true;
}

function get_site_option( string $option, $default = false ) {
	return $GLOBALS['__agents_api_multisite_network'][ $option ] ?? $default;
}

function update_site_option( string $option, $value ): bool {
	$GLOBALS['__agents_api_multisite_network'][ $option ] = $value;
	return true;
}

function get_current_user_id(): int {
	return (int) $GLOBALS['__agents_api_multisite_user'];
}

function current_user_can( string $capability ): bool {
	return 'read' === $capability;
}

function sanitize_key( string $value ): string {
	return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
}

function wp_has_ability_category( string $category ): bool {
	return isset( $GLOBALS['__agents_api_smoke_categories'][ $category ] );
}

function wp_register_ability_category( string $category, array $args ): void {
	$GLOBALS['__agents_api_smoke_categories'][ $category ] = $args;
}

function wp_has_ability( string $ability ): bool {
	return isset( $GLOBALS['__agents_api_smoke_abilities'][ $ability ] );
}

function wp_register_ability( string $ability, array $args ): void {
	$GLOBALS['__agents_api_smoke_abilities'][ $ability ] = $args;
}

agents_api_smoke_require_module();

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;
use AgentsAPI\AI\WP_Agent_Conversation_Loop;
use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\AI\WP_Agent_Run_Control_Store;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
use function AgentsAPI\AI\Channels\agents_cancel_chat_run;
use function AgentsAPI\AI\Channels\agents_chat_dispatch;
use function AgentsAPI\AI\Channels\agents_get_chat_run;
use function AgentsAPI\AI\Channels\agents_list_chat_run_events;
use function AgentsAPI\AI\Channels\agents_queue_chat_message;

$workspace = array(
	'workspace_type' => 'network',
	'workspace_id'   => 'network-9',
);
$other_workspace = array(
	'workspace_type' => 'network',
	'workspace_id'   => 'network-10',
);
$principal = WP_Agent_Execution_Principal::user_session( 123, 'demo-agent' )->to_array();
$other_principal = WP_Agent_Execution_Principal::user_session( 456, 'demo-agent' )->to_array();
$owner = array( 'type' => 'user', 'key' => '123' );
$other_owner = array( 'type' => 'user', 'key' => '456' );
$GLOBALS['__agents_api_canonical_sessions'] = array(
	'network-session' => array(
		'session_id'    => 'network-session',
		'workspace_type' => 'network',
		'workspace_id'   => 'network-9',
		'owner_type'     => 'user',
		'owner_key'      => '123',
	),
	'legacy-session' => array(
		'session_id'    => 'legacy-session',
		'workspace_type' => 'site',
		'workspace_id'   => '1',
		'owner_type'     => 'user',
		'owner_key'      => '123',
	),
	'concurrent-session' => array(
		'session_id'    => 'concurrent-session',
		'workspace_type' => 'network',
		'workspace_id'   => 'network-9',
		'owner_type'     => 'user',
		'owner_key'      => '123',
	),
);
$GLOBALS['__agents_api_session_read_interleave'] = null;
$conversation_store = new class() implements WP_Agent_Conversation_Store {
	public function create_session( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		unset( $workspace, $user_id, $agent_slug, $metadata, $context );
		return '';
	}
	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		unset( $workspace, $user_id, $args );
		return array();
	}
	public function get_session( string $session_id ): ?array {
		$interleave = $GLOBALS['__agents_api_session_read_interleave'];
		$GLOBALS['__agents_api_session_read_interleave'] = null;
		if ( is_callable( $interleave ) ) {
			$interleave();
		}
		return $GLOBALS['__agents_api_canonical_sessions'][ $session_id ] ?? null;
	}
	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool {
		unset( $session_id, $messages, $metadata, $provider, $model, $provider_response_id );
		return true;
	}
	public function delete_session( string $session_id ): bool {
		unset( $session_id );
		return true;
	}
	public function get_recent_pending_session( WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		unset( $workspace, $user_id, $seconds, $context, $token_id );
		return null;
	}
	public function update_title( string $session_id, string $title ): bool {
		unset( $session_id, $title );
		return true;
	}
};
add_filter( 'wp_agent_conversation_store', static fn() => $conversation_store );

add_filter(
	'wp_agent_chat_handler',
	static fn() => static fn( array $input ): array => array(
		'session_id' => (string) ( $input['session_id'] ?? 'network-session' ),
		'run_id'     => (string) $input['run_id'],
		'reply'      => 'pending',
		'completed'  => false,
		'status'     => 'runtime_tool_pending',
	),
	1,
	2
);

$created = agents_chat_dispatch(
	array(
		'agent'         => 'demo-agent',
		'message'       => 'start',
		'session_id'    => 'network-session',
		'workspace'     => $workspace,
		'principal'     => $principal,
		'session_owner' => $owner,
	)
);
$run_id = is_array( $created ) ? (string) ( $created['run_id'] ?? '' ) : '';
agents_api_smoke_assert_equals( false, $created instanceof WP_Error, 'run is created on the first subsite under an explicit workspace', $failures, $passes );
$workspace_scope = WP_Agent_Workspace_Scope::from_array( $workspace );

$foreign_run_id = 'run_foreign_owner';
$GLOBALS['__agents_api_multisite_user'] = 456;
$foreign_run = agents_chat_dispatch(
	array(
		'agent'         => 'demo-agent',
		'message'       => 'claim victim session',
		'session_id'    => 'network-session',
		'run_id'        => $foreign_run_id,
		'workspace'     => $workspace,
		'principal'     => $other_principal,
		'session_owner' => $other_owner,
	)
);
agents_api_smoke_assert_equals( 'agents_chat_run_owner_forbidden', $foreign_run instanceof WP_Error ? $foreign_run->get_error_code() : '', 'foreign principal cannot create a separately owned run for the victim session', $failures, $passes );
$foreign_run_state = WP_Agent_Run_Control::state( 'agents_api_chat_run_control', $workspace_scope );
agents_api_smoke_assert_equals( false, isset( $foreign_run_state['runs'][ $foreign_run_id ] ), 'denied foreign run is not persisted', $failures, $passes );

$foreign_turns = 0;
$foreign_loop  = WP_Agent_Conversation_Loop::run(
	array( WP_Agent_Message::text( 'user', 'foreign turn' ) ),
	static function () use ( &$foreign_turns ): array {
		++$foreign_turns;
		return array( 'messages' => array() );
	},
	array(
		'run_id'                => 'run_foreign_loop',
		'transcript_session_id' => 'network-session',
		'workspace'             => $workspace_scope,
		'principal'             => $other_principal,
		'context'               => array( 'conversation_store' => $conversation_store ),
	)
);
agents_api_smoke_assert_equals( 'failed', $foreign_loop['status'] ?? null, 'conversation loop surfaces canonical session owner conflicts as failures', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_chat_run_owner_forbidden', $foreign_loop['failure']['code'] ?? null, 'conversation loop preserves the canonical owner-conflict error code', $failures, $passes );
agents_api_smoke_assert_equals( 0, $foreign_turns, 'conversation loop stops before foreign model or tool execution', $failures, $passes );
$GLOBALS['__agents_api_multisite_user'] = 123;

$queued = agents_queue_chat_message(
	array(
		'agent'         => 'demo-agent',
		'message'       => 'queued continuation',
		'session_id'    => 'network-session',
		'run_id'        => $run_id,
		'workspace'     => $workspace,
		'principal'     => $principal,
		'session_owner' => $owner,
	)
);
agents_api_smoke_assert_equals( 'queued', $queued['status'] ?? null, 'queue state is created on the first subsite', $failures, $passes );

// Exact #451 exploit: a foreign principal knows both IDs and supplies its own
// otherwise-valid owner claim. Permission and execution must both deny it.
$foreign_input = array(
	'agent'         => 'demo-agent',
	'message'       => 'foreign poison',
	'session_id'    => 'network-session',
	'run_id'        => $run_id,
	'workspace'     => $workspace,
	'principal'     => $other_principal,
	'session_owner' => $other_owner,
);
$GLOBALS['__agents_api_multisite_user'] = 456;
$foreign_allowed = AgentsAPI\AI\Channels\agents_chat_run_enqueue_permission( $foreign_input );
$foreign_queued  = agents_queue_chat_message( $foreign_input );
agents_api_smoke_assert_equals( false, $foreign_allowed, 'foreign principal cannot pass enqueue permission with a known session id', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $foreign_queued instanceof WP_Error ? $foreign_queued->get_error_code() : '', 'foreign principal cannot enqueue with its own owner claim', $failures, $passes );
$GLOBALS['__agents_api_multisite_user'] = 123;

// Simulate pre-fix corrupt state with the foreign run stored first. The
// canonical session binding must remain authoritative regardless of run order.
$reversed_state = WP_Agent_Run_Control::state( 'agents_api_chat_run_control', $workspace_scope );
$reversed_state['runs'] = array_merge(
	array(
		$foreign_run_id => array(
			'run_id'     => $foreign_run_id,
			'session_id' => 'network-session',
			'status'     => 'running',
			'_owner'     => hash( 'sha256', 'user:456' ),
		),
	),
	$reversed_state['runs']
);
WP_Agent_Run_Control::save_state( 'agents_api_chat_run_control', $reversed_state, $workspace_scope );
agents_api_smoke_assert_equals( $foreign_run_id, array_key_first( WP_Agent_Run_Control::state( 'agents_api_chat_run_control', $workspace_scope )['runs'] ), 'corrupt foreign run is first in storage order for regression coverage', $failures, $passes );

$foreign_owned_run_input = array_replace( $foreign_input, array( 'run_id' => $foreign_run_id ) );
$GLOBALS['__agents_api_multisite_user'] = 456;
$foreign_owned_run_queue = agents_queue_chat_message( $foreign_owned_run_input );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $foreign_owned_run_queue instanceof WP_Error ? $foreign_owned_run_queue->get_error_code() : '', 'foreign principal cannot enqueue through a separately owned run for the victim session', $failures, $passes );
$GLOBALS['__agents_api_multisite_user'] = 123;

switch_to_blog( 2 );
$control_input = array(
	'session_id'    => 'network-session',
	'run_id'        => $run_id,
	'workspace'     => $workspace,
	'principal'     => $principal,
	'session_owner' => $owner,
);
$status = agents_get_chat_run( $control_input );
$events = agents_list_chat_run_events( $control_input );
agents_api_smoke_assert_equals( 'runtime_tool_pending', $status['status'] ?? null, 'second subsite reads workspace run status', $failures, $passes );
agents_api_smoke_assert_equals( true, count( $events['events'] ?? array() ) >= 2, 'second subsite reads workspace run events', $failures, $passes );

$GLOBALS['__agents_api_multisite_user'] = 456;
$foreign_cross_site = agents_queue_chat_message( $foreign_input );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $foreign_cross_site instanceof WP_Error ? $foreign_cross_site->get_error_code() : '', 'foreign enqueue remains denied from another subsite', $failures, $passes );
$foreign_claim = WP_Agent_Chat_Run_Control::claim_queued_messages( 'network-session', WP_Agent_Workspace_Scope::from_array( $workspace ), $other_owner );
agents_api_smoke_assert_equals( array(), $foreign_claim, 'foreign claim cannot consume the owner queue', $failures, $passes );
$preserved_queue = WP_Agent_Run_Control::state( 'agents_api_chat_run_control', $workspace_scope )['queues']['network-session'] ?? array();
agents_api_smoke_assert_equals( 'queued continuation', $preserved_queue[0]['message'] ?? null, 'foreign claim preserves the legitimate queue when foreign run is first', $failures, $passes );
$GLOBALS['__agents_api_multisite_user'] = 123;

$claimed = WP_Agent_Chat_Run_Control::claim_queued_messages( 'network-session', WP_Agent_Workspace_Scope::from_array( $workspace ), $owner );
agents_api_smoke_assert_equals( 'queued continuation', $claimed[0]['message'] ?? null, 'second subsite claims the workspace queue', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $claimed ), 'rejected exploit does not poison or block owner queue progress', $failures, $passes );

$continued = agents_chat_dispatch(
	array(
		'agent'         => 'demo-agent',
		'message'       => 'continue',
		'session_id'    => 'network-session',
		'workspace'     => $workspace,
		'principal'     => $principal,
		'session_owner' => $owner,
	)
);
agents_api_smoke_assert_equals( 'network-session', $continued['session_id'] ?? null, 'second subsite continues the same workspace session', $failures, $passes );

$cancelled = agents_cancel_chat_run( $control_input );
agents_api_smoke_assert_equals( true, $cancelled['cancelled'] ?? null, 'second subsite cancels the workspace run', $failures, $passes );
agents_api_smoke_assert_equals( 2, get_current_blog_id(), 'workspace operations preserve the caller blog context', $failures, $passes );

$wrong_workspace = agents_get_chat_run( array_replace( $control_input, array( 'workspace' => $other_workspace ) ) );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $wrong_workspace instanceof WP_Error ? $wrong_workspace->get_error_code() : '', 'same run id is inaccessible in another workspace', $failures, $passes );
$wrong_workspace_queue = agents_queue_chat_message( array_replace( $control_input, array( 'agent' => 'demo-agent', 'message' => 'wrong workspace', 'workspace' => $other_workspace ) ) );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $wrong_workspace_queue instanceof WP_Error ? $wrong_workspace_queue->get_error_code() : '', 'enqueue cannot cross workspace boundaries', $failures, $passes );

$wrong_principal_input              = $control_input;
$wrong_principal_input['principal'] = $other_principal;
$wrong_principal_input['session_owner'] = array( 'type' => 'user', 'key' => '456' );
$wrong_principal = agents_get_chat_run( $wrong_principal_input );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $wrong_principal instanceof WP_Error ? $wrong_principal->get_error_code() : '', 'wrong principal cannot inspect a workspace run', $failures, $passes );
$wrong_principal_queue = agents_queue_chat_message( array_replace( $wrong_principal_input, array( 'agent' => 'demo-agent', 'message' => 'wrong principal' ) ) );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $wrong_principal_queue instanceof WP_Error ? $wrong_principal_queue->get_error_code() : '', 'wrong principal cannot enqueue to a workspace run', $failures, $passes );

$forged_owner_input                  = $control_input;
$forged_owner_input['session_owner'] = array( 'type' => 'user', 'key' => '456' );
$forged_owner = agents_cancel_chat_run( $forged_owner_input );
agents_api_smoke_assert_equals( 'agents_chat_run_owner_forbidden', $forged_owner instanceof WP_Error ? $forged_owner->get_error_code() : '', 'forged session ownership cannot cancel a run', $failures, $passes );

$invalid_workspace = agents_get_chat_run( array_replace( $control_input, array( 'workspace' => array( 'workspace_type' => 'network' ) ) ) );
agents_api_smoke_assert_equals( 'agents_chat_run_invalid_workspace', $invalid_workspace instanceof WP_Error ? $invalid_workspace->get_error_code() : '', 'malformed canonical workspaces are rejected', $failures, $passes );

$mixed_first = agents_queue_chat_message( array_replace( $control_input, array( 'agent' => 'demo-agent', 'message' => 'owner first' ) ) );
$mixed_second = agents_queue_chat_message( array_replace( $control_input, array( 'agent' => 'demo-agent', 'message' => 'owner second' ) ) );
agents_api_smoke_assert_equals( 'queued', $mixed_first['status'] ?? null, 'owner can enqueue before mixed-state recovery', $failures, $passes );
agents_api_smoke_assert_equals( 'queued', $mixed_second['status'] ?? null, 'owner can append before mixed-state recovery', $failures, $passes );

$mixed_state     = WP_Agent_Run_Control::state( 'agents_api_chat_run_control', $workspace_scope );
$mixed_state['queues']['network-session'][] = array(
	'queued_message_id' => 'queued_foreign',
	'session_id'        => 'network-session',
	'run_id'            => $run_id,
	'message'           => 'foreign stored poison',
	'_owner'            => hash( 'sha256', 'user:456' ),
);
$mixed_state['queues']['network-session'][] = array(
	'queued_message_id' => 'queued_invalid',
	'session_id'        => 'network-session',
	'run_id'            => $run_id,
	'message'           => 'invalid stored poison',
);
WP_Agent_Run_Control::save_state( 'agents_api_chat_run_control', $mixed_state, $workspace_scope );

$mixed_foreign_claim = WP_Agent_Chat_Run_Control::claim_queued_messages( 'network-session', $workspace_scope, $other_owner );
agents_api_smoke_assert_equals( array(), $mixed_foreign_claim, 'wrong owner cannot use mixed entries to consume the queue', $failures, $passes );
$mixed_preserved = WP_Agent_Run_Control::state( 'agents_api_chat_run_control', $workspace_scope )['queues']['network-session'] ?? array();
agents_api_smoke_assert_equals( array( 'owner first', 'owner second' ), array_slice( array_column( $mixed_preserved, 'message' ), 0, 2 ), 'wrong-owner claim leaves legitimate entries intact beside corrupt rows', $failures, $passes );
$mixed_claim = WP_Agent_Chat_Run_Control::claim_queued_messages( 'network-session', $workspace_scope, $owner );
agents_api_smoke_assert_equals( array( 'owner first', 'owner second' ), array_column( $mixed_claim, 'message' ), 'owner claim progresses while discarding foreign and invalid entries', $failures, $passes );
$recovered_state = WP_Agent_Run_Control::state( 'agents_api_chat_run_control', $workspace_scope );
agents_api_smoke_assert_equals( false, isset( $recovered_state['queues']['network-session'] ), 'mixed-entry recovery cleans poison entries from storage', $failures, $passes );
agents_api_smoke_assert_equals( 2, get_current_blog_id(), 'queue authorization and recovery preserve caller blog context', $failures, $passes );

restore_current_blog();
$legacy = WP_Agent_Chat_Run_Control::start_run( 'legacy-run', 'legacy-session', array(), null, $owner );
$legacy_queued = agents_queue_chat_message(
	array(
		'agent'         => 'demo-agent',
		'message'       => 'legacy continuation',
		'session_id'    => 'legacy-session',
		'run_id'        => 'legacy-run',
		'principal'     => $principal,
		'session_owner' => $owner,
	)
);
agents_api_smoke_assert_equals( 'queued', $legacy_queued['status'] ?? null, 'omitted-workspace enqueue remains compatible', $failures, $passes );
switch_to_blog( 2 );
$legacy_other_site = WP_Agent_Chat_Run_Control::get_run( 'legacy-run', null, $owner );
agents_api_smoke_assert_equals( null, $legacy_other_site, 'omitted workspace state remains site-local', $failures, $passes );
restore_current_blog();
$legacy_origin = WP_Agent_Chat_Run_Control::get_run( 'legacy-run', null, $owner );
agents_api_smoke_assert_equals( 'legacy-run', $legacy_origin['run_id'] ?? null, 'omitted workspace compatibility remains readable on its origin site', $failures, $passes );
$legacy_claim = WP_Agent_Chat_Run_Control::claim_queued_messages( 'legacy-session', null, $owner );
agents_api_smoke_assert_equals( 'legacy continuation', $legacy_claim[0]['message'] ?? null, 'omitted-workspace owner queue still progresses on its origin site', $failures, $passes );

$concurrent_foreign = null;
$GLOBALS['__agents_api_session_read_interleave'] = static function () use ( &$concurrent_foreign, $workspace_scope, $other_owner, $conversation_store ): void {
	$concurrent_foreign = WP_Agent_Chat_Run_Control::start_run( 'concurrent-foreign', 'concurrent-session', array(), $workspace_scope, $other_owner, $conversation_store );
};
$concurrent_owner = WP_Agent_Chat_Run_Control::start_run( 'concurrent-owner', 'concurrent-session', array(), $workspace_scope, $owner, $conversation_store );
agents_api_smoke_assert_equals( 'agents_chat_run_owner_forbidden', $concurrent_foreign instanceof WP_Error ? $concurrent_foreign->get_error_code() : '', 'interleaved foreign start loses against canonical session ownership', $failures, $passes );
agents_api_smoke_assert_equals( false, $concurrent_owner instanceof WP_Error, 'interleaved canonical owner start succeeds without a parallel binding registry', $failures, $passes );
$concurrent_state = WP_Agent_Run_Control::state( 'agents_api_chat_run_control', $workspace_scope );
agents_api_smoke_assert_equals( false, isset( $concurrent_state['runs']['concurrent-foreign'] ), 'interleaved foreign run is never persisted', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $concurrent_state['runs']['concurrent-owner'] ), 'interleaved canonical run is persisted once ownership is proven', $failures, $passes );

$unproven = WP_Agent_Chat_Run_Control::start_run( 'unproven-run', 'missing-canonical-session', array(), $workspace_scope, $owner, $conversation_store );
agents_api_smoke_assert_equals( 'agents_chat_run_owner_forbidden', $unproven instanceof WP_Error ? $unproven->get_error_code() : '', 'workspace start fails closed when the canonical store cannot prove session ownership', $failures, $passes );
$unproven_state = WP_Agent_Run_Control::state( 'agents_api_chat_run_control', $workspace_scope );
agents_api_smoke_assert_equals( false, isset( $unproven_state['runs']['unproven-run'] ), 'unproven workspace run is never persisted', $failures, $passes );

$unsupported_store = new class() implements WP_Agent_Run_Control_Store {
	public function get_state( string $store_key ): array {
		unset( $store_key );
		return array( 'runs' => array(), 'queues' => array(), 'events' => array() );
	}
	public function save_state( string $store_key, array $state ): void {
		unset( $store_key, $state );
	}
};
WP_Agent_Run_Control::set_store( $unsupported_store );
$unsupported = agents_queue_chat_message(
	array(
		'agent'         => 'demo-agent',
		'message'       => 'unsupported',
		'session_id'    => 'network-session',
		'workspace'     => $workspace,
		'principal'     => $principal,
		'session_owner' => $owner,
	)
);
agents_api_smoke_assert_equals( 'agents_chat_run_workspace_unsupported', $unsupported instanceof WP_Error ? $unsupported->get_error_code() : '', 'unsupported stores fail explicit workspace queueing truthfully', $failures, $passes );
WP_Agent_Run_Control::reset_store();

agents_api_smoke_finish( 'chat run-control multisite', $failures, $passes );
