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
use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\AI\WP_Agent_Run_Control_Store;
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

$claimed = WP_Agent_Chat_Run_Control::claim_queued_messages( 'network-session', WP_Agent_Workspace_Scope::from_array( $workspace ), $owner );
agents_api_smoke_assert_equals( 'queued continuation', $claimed[0]['message'] ?? null, 'second subsite claims the workspace queue', $failures, $passes );

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

$wrong_principal_input              = $control_input;
$wrong_principal_input['principal'] = $other_principal;
$wrong_principal_input['session_owner'] = array( 'type' => 'user', 'key' => '456' );
$wrong_principal = agents_get_chat_run( $wrong_principal_input );
agents_api_smoke_assert_equals( 'agents_chat_run_not_found', $wrong_principal instanceof WP_Error ? $wrong_principal->get_error_code() : '', 'wrong principal cannot inspect a workspace run', $failures, $passes );

$forged_owner_input                  = $control_input;
$forged_owner_input['session_owner'] = array( 'type' => 'user', 'key' => '456' );
$forged_owner = agents_cancel_chat_run( $forged_owner_input );
agents_api_smoke_assert_equals( 'agents_chat_run_owner_forbidden', $forged_owner instanceof WP_Error ? $forged_owner->get_error_code() : '', 'forged session ownership cannot cancel a run', $failures, $passes );

$invalid_workspace = agents_get_chat_run( array_replace( $control_input, array( 'workspace' => array( 'workspace_type' => 'network' ) ) ) );
agents_api_smoke_assert_equals( 'agents_chat_run_invalid_workspace', $invalid_workspace instanceof WP_Error ? $invalid_workspace->get_error_code() : '', 'malformed canonical workspaces are rejected', $failures, $passes );

restore_current_blog();
$legacy = WP_Agent_Chat_Run_Control::start_run( 'legacy-run', 'legacy-session', array(), null, $owner );
switch_to_blog( 2 );
$legacy_other_site = WP_Agent_Chat_Run_Control::get_run( 'legacy-run', null, $owner );
agents_api_smoke_assert_equals( null, $legacy_other_site, 'omitted workspace state remains site-local', $failures, $passes );
restore_current_blog();
$legacy_origin = WP_Agent_Chat_Run_Control::get_run( 'legacy-run', null, $owner );
agents_api_smoke_assert_equals( 'legacy-run', $legacy_origin['run_id'] ?? null, 'omitted workspace compatibility remains readable on its origin site', $failures, $passes );

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
