<?php
/**
 * Pure-PHP smoke test for the agents/chat ability dispatcher.
 *
 * Run with: php tests/agents-chat-ability-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "agents-chat-ability-smoke\n";

// ─── Minimal WP stubs ──────────────────────────────────────────────

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function current_user_can( string $cap ): bool {
	unset( $cap );
	return $GLOBALS['__smoke_can'] ?? false;
}

$GLOBALS['__smoke_filters'] = array();

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

require_once __DIR__ . '/../src/Channels/register-agents-chat-ability.php';

use function AgentsAPI\AI\Channels\agents_chat_dispatch;
use function AgentsAPI\AI\Channels\agents_chat_permission;
use function AgentsAPI\AI\Channels\agents_chat_input_schema;
use function AgentsAPI\AI\Channels\agents_chat_output_schema;
use function AgentsAPI\AI\Channels\register_chat_handler;
use const AgentsAPI\AI\Channels\AGENTS_CHAT_ABILITY;

// ─── Tests ──────────────────────────────────────────────────────────

// 1. Slug constant.
smoke_assert( 'agents/chat', AGENTS_CHAT_ABILITY, 'slug_is_agents_chat', $failures, $passes );

// 2. No handler registered → WP_Error agents_chat_no_handler + observability fires.
$dispatch_failures = array();
add_filter( 'agents_chat_dispatch_failed', static function ( $reason, $input ) use ( &$dispatch_failures ) {
	$dispatch_failures[] = array( 'reason' => $reason, 'agent' => $input['agent'] ?? null );
}, 10, 2 );

$result = agents_chat_dispatch( array( 'agent' => 'foo', 'message' => 'hi' ) );
smoke_assert( true, $result instanceof WP_Error, 'no_handler_returns_wp_error', $failures, $passes );
smoke_assert( 'agents_chat_no_handler', $result->get_error_code(), 'no_handler_error_code', $failures, $passes );
smoke_assert( 'no_handler', $dispatch_failures[0]['reason'] ?? 'missing', 'no_handler_fires_dispatch_failed', $failures, $passes );
smoke_assert( 'foo', $dispatch_failures[0]['agent'] ?? 'missing', 'no_handler_observability_includes_input', $failures, $passes );

// 3. Registered handler is called with the canonical input and its result is returned.
$captured = array();
register_chat_handler( static function ( array $input ) use ( &$captured ) {
	$captured = $input;
	return array( 'session_id' => 's-1', 'reply' => 'hello back', 'completed' => true );
} );

$result = agents_chat_dispatch( array(
	'agent'          => 'demo',
	'message'        => 'ping',
	'session_id'     => null,
	'attachments'    => array(),
	'client_context' => array( 'source' => 'channel', 'client_name' => 'cli-relay' ),
) );

smoke_assert( 'demo', $captured['agent'] ?? null, 'handler_received_agent', $failures, $passes );
smoke_assert( 'ping', $captured['message'] ?? null, 'handler_received_message', $failures, $passes );
smoke_assert( 'channel', $captured['client_context']['source'] ?? null, 'handler_received_client_context', $failures, $passes );
smoke_assert( 'hello back', $result['reply'] ?? null, 'handler_result_returned', $failures, $passes );

// 4. Handler returning non-array → WP_Error agents_chat_invalid_result + observability fires.
$GLOBALS['__smoke_filters'] = array();
$dispatch_failures2 = array();
add_filter( 'agents_chat_dispatch_failed', static function ( $reason ) use ( &$dispatch_failures2 ) {
	$dispatch_failures2[] = $reason;
}, 10, 2 );
register_chat_handler( static fn( array $i ) => 'not an array' );
$bad = agents_chat_dispatch( array( 'agent' => 'x', 'message' => 'y' ) );
smoke_assert( true, $bad instanceof WP_Error, 'invalid_result_returns_wp_error', $failures, $passes );
smoke_assert( 'agents_chat_invalid_result', $bad->get_error_code(), 'invalid_result_error_code', $failures, $passes );
smoke_assert( 'invalid_result', $dispatch_failures2[0] ?? 'missing', 'invalid_result_fires_dispatch_failed', $failures, $passes );

// 5. Handler returning WP_Error → propagated + observability fires with the error code.
$GLOBALS['__smoke_filters'] = array();
$dispatch_failures3 = array();
add_filter( 'agents_chat_dispatch_failed', static function ( $reason ) use ( &$dispatch_failures3 ) {
	$dispatch_failures3[] = $reason;
}, 10, 2 );
register_chat_handler( static fn( array $i ) => new WP_Error( 'agent_blew_up', 'kaboom' ) );
$err = agents_chat_dispatch( array( 'agent' => 'x', 'message' => 'y' ) );
smoke_assert( true, $err instanceof WP_Error, 'handler_wp_error_propagated', $failures, $passes );
smoke_assert( 'agent_blew_up', $err->get_error_code(), 'handler_wp_error_code_preserved', $failures, $passes );
smoke_assert( 'agent_blew_up', $dispatch_failures3[0] ?? 'missing', 'handler_wp_error_fires_dispatch_failed_with_code', $failures, $passes );

// 6. First-handler-wins: second register call doesn't override.
$GLOBALS['__smoke_filters'] = array();
register_chat_handler( static fn( array $i ) => array( 'session_id' => 'A', 'reply' => 'first wins' ) );
register_chat_handler( static fn( array $i ) => array( 'session_id' => 'B', 'reply' => 'never reached' ) );
$out = agents_chat_dispatch( array( 'agent' => 'x', 'message' => 'y' ) );
smoke_assert( 'first wins', $out['reply'] ?? null, 'first_handler_wins', $failures, $passes );

// 7. Permission gate: defaults to manage_options, filterable.
$GLOBALS['__smoke_can'] = false;
smoke_assert( false, agents_chat_permission( array() ), 'default_permission_blocks_non_admin', $failures, $passes );
$GLOBALS['__smoke_can'] = true;
smoke_assert( true, agents_chat_permission( array() ), 'default_permission_allows_admin', $failures, $passes );

$GLOBALS['__smoke_can'] = false;
add_filter( 'agents_chat_permission', static fn() => true );
smoke_assert( true, agents_chat_permission( array() ), 'permission_filter_widens_gate', $failures, $passes );

// 8. Schemas exist with the expected required fields.
$in = agents_chat_input_schema();
smoke_assert( array( 'agent', 'message' ), $in['required'] ?? array(), 'input_schema_required_fields', $failures, $passes );
smoke_assert( true, isset( $in['properties']['client_context'] ), 'input_schema_has_client_context', $failures, $passes );
smoke_assert( true, isset( $in['properties']['attachments'] ), 'input_schema_has_attachments', $failures, $passes );
smoke_assert( true, isset( $in['properties']['client_context']['properties']['sender_id'] ), 'client_context_schema_has_sender_id', $failures, $passes );

$out_schema = agents_chat_output_schema();
smoke_assert( array( 'session_id', 'reply' ), $out_schema['required'] ?? array(), 'output_schema_required_fields', $failures, $passes );

// ─── Done ───────────────────────────────────────────────────────────

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " agents-chat-ability assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} agents-chat-ability assertions passed.\n";
