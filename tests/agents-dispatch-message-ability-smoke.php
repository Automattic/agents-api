<?php
/**
 * Pure-PHP smoke test for the agents/dispatch-message ability dispatcher.
 *
 * Run with: php tests/agents-dispatch-message-ability-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "agents-dispatch-message-ability-smoke\n";

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

require_once __DIR__ . '/../src/Channels/register-agents-dispatch-message-ability.php';

use function AgentsAPI\AI\Channels\agents_dispatch_message_dispatch;
use function AgentsAPI\AI\Channels\agents_dispatch_message_input_schema;
use function AgentsAPI\AI\Channels\agents_dispatch_message_output_schema;
use function AgentsAPI\AI\Channels\agents_dispatch_message_permission;
use function AgentsAPI\AI\Channels\register_dispatch_message_handler;
use const AgentsAPI\AI\Channels\AGENTS_DISPATCH_MESSAGE_ABILITY;

smoke_assert( 'agents/dispatch-message', AGENTS_DISPATCH_MESSAGE_ABILITY, 'slug_is_agents_dispatch_message', $failures, $passes );

$dispatch_failures = array();
add_filter(
	'agents_dispatch_message_failed',
	static function ( $reason, $input ) use ( &$dispatch_failures ) {
		$dispatch_failures[] = array( 'reason' => $reason, 'channel' => $input['channel'] ?? null );
	},
	10,
	2
);

$result = agents_dispatch_message_dispatch(
	array(
		'channel'   => 'whatsapp',
		'recipient' => '+59899123456',
		'message'   => 'hola',
	)
);
smoke_assert( true, $result instanceof WP_Error, 'no_handler_returns_wp_error', $failures, $passes );
smoke_assert( 'agents_dispatch_message_no_handler', $result->get_error_code(), 'no_handler_error_code', $failures, $passes );
smoke_assert( 'no_handler', $dispatch_failures[0]['reason'] ?? 'missing', 'no_handler_fires_observability', $failures, $passes );
smoke_assert( 'whatsapp', $dispatch_failures[0]['channel'] ?? 'missing', 'observability_includes_channel', $failures, $passes );

$captured = array();
$GLOBALS['__smoke_filters'] = array();
register_dispatch_message_handler(
	static function ( array $input ) use ( &$captured ): array {
		$captured = $input;
		return array(
			'sent'       => true,
			'channel'    => $input['channel'],
			'recipient'  => $input['recipient'],
			'message_id' => 'm-1',
			'metadata'   => array( 'provider' => 'stub' ),
		);
	}
);

$ok = agents_dispatch_message_dispatch(
	array(
		'channel'        => 'whatsapp',
		'recipient'      => '+59899123456',
		'message'        => 'hola',
		'conversation_id' => 'group-1',
	)
);
smoke_assert( 'hola', $captured['message'] ?? null, 'handler_receives_message', $failures, $passes );
smoke_assert( true, $ok['sent'] ?? false, 'handler_result_returned', $failures, $passes );
smoke_assert( 'm-1', $ok['message_id'] ?? null, 'message_id_returned', $failures, $passes );

$GLOBALS['__smoke_filters'] = array();
register_dispatch_message_handler( static fn( array $input ) => 'bad' );
$bad = agents_dispatch_message_dispatch( array( 'channel' => 'x', 'recipient' => 'y', 'message' => 'z' ) );
smoke_assert( true, $bad instanceof WP_Error, 'invalid_result_returns_wp_error', $failures, $passes );
smoke_assert( 'agents_dispatch_message_invalid_result', $bad->get_error_code(), 'invalid_result_error_code', $failures, $passes );

$GLOBALS['__smoke_can'] = false;
smoke_assert( false, agents_dispatch_message_permission( array() ), 'default_permission_blocks_non_admin', $failures, $passes );
$GLOBALS['__smoke_can'] = true;
smoke_assert( true, agents_dispatch_message_permission( array() ), 'default_permission_allows_admin', $failures, $passes );

$GLOBALS['__smoke_can'] = false;
add_filter( 'agents_dispatch_message_permission', static fn() => true );
smoke_assert( true, agents_dispatch_message_permission( array() ), 'permission_filter_widens_gate', $failures, $passes );

$in = agents_dispatch_message_input_schema();
smoke_assert( array( 'channel', 'recipient', 'message' ), $in['required'] ?? array(), 'input_schema_required_fields', $failures, $passes );
smoke_assert( true, isset( $in['properties']['attachments'] ), 'input_schema_has_attachments', $failures, $passes );

$out = agents_dispatch_message_output_schema();
smoke_assert( array( 'sent', 'channel', 'recipient' ), $out['required'] ?? array(), 'output_schema_required_fields', $failures, $passes );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
