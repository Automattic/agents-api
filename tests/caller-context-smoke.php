<?php
/**
 * Pure-PHP smoke test for caller context primitives.
 *
 * Run with: php tests/caller-context-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-caller-context-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Caller_Context' ), 'caller context value object is available', $failures, $passes );

$top = WP_Agent_Caller_Context::from_headers( array() );
agents_api_smoke_assert_equals( '', $top->caller_agent_id, 'no headers produces no caller agent', $failures, $passes );
agents_api_smoke_assert_equals( 0, $top->caller_user_id, 'no headers produces no caller user', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Caller_Context::SELF_HOST, $top->caller_host, 'no headers produces self caller host', $failures, $passes );
agents_api_smoke_assert_equals( 0, $top->chain_depth, 'no headers produces top-of-chain depth', $failures, $passes );
agents_api_smoke_assert_equals( false, $top->is_cross_site(), 'no headers is not cross-site', $failures, $passes );
agents_api_smoke_assert_equals( true, '' !== $top->chain_root_request_id, 'no headers generates a chain root request id', $failures, $passes );

$valid_headers = array(
	WP_Agent_Caller_Context::HEADER_CALLER_AGENT => 'source-agent',
	WP_Agent_Caller_Context::HEADER_CALLER_USER  => '42',
	WP_Agent_Caller_Context::HEADER_CALLER_HOST  => 'https://source.example',
	WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH  => '2',
	WP_Agent_Caller_Context::HEADER_CHAIN_ROOT   => 'root-request-123',
);

$chain = WP_Agent_Caller_Context::from_headers( $valid_headers );
agents_api_smoke_assert_equals( 'source-agent', $chain->caller_agent_id, 'valid headers record caller agent', $failures, $passes );
agents_api_smoke_assert_equals( 42, $chain->caller_user_id, 'valid headers record caller user', $failures, $passes );
agents_api_smoke_assert_equals( 'https://source.example', $chain->caller_host, 'valid headers record caller host', $failures, $passes );
agents_api_smoke_assert_equals( 2, $chain->chain_depth, 'valid headers record chain depth', $failures, $passes );
agents_api_smoke_assert_equals( 'root-request-123', $chain->chain_root_request_id, 'valid headers record chain root', $failures, $passes );
agents_api_smoke_assert_equals( true, $chain->is_cross_site(), 'valid remote host is cross-site', $failures, $passes );
agents_api_smoke_assert_equals( $chain->to_array(), WP_Agent_Caller_Context::from_array( $chain->to_array() )->to_array(), 'caller context round-trips through array shape', $failures, $passes );

try {
	WP_Agent_Caller_Context::from_headers(
		array(
			WP_Agent_Caller_Context::HEADER_CALLER_AGENT => 'source-agent',
			WP_Agent_Caller_Context::HEADER_CALLER_HOST  => 'https://source.example',
			WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH  => 'not-a-number',
			WP_Agent_Caller_Context::HEADER_CHAIN_ROOT   => 'root-request-123',
		)
	);
	agents_api_smoke_assert_equals( true, false, 'malformed chain depth is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'chain_depth' ), 'malformed chain depth is rejected', $failures, $passes );
}

try {
	WP_Agent_Caller_Context::from_headers( $valid_headers, 1 );
	agents_api_smoke_assert_equals( true, false, 'chain depth limit is enforced', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'chain_depth' ), 'chain depth limit is enforced', $failures, $passes );
}

try {
	WP_Agent_Caller_Context::from_headers(
		array(
			WP_Agent_Caller_Context::HEADER_CALLER_AGENT => 'source-agent',
			WP_Agent_Caller_Context::HEADER_CALLER_HOST  => 'self',
			WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH  => '1',
			WP_Agent_Caller_Context::HEADER_CHAIN_ROOT   => 'root-request-123',
		)
	);
	agents_api_smoke_assert_equals( true, false, 'chained context with self host is rejected via from_headers', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'caller_host' ), 'chained context with self host is rejected via from_headers', $failures, $passes );
}

try {
	new WP_Agent_Caller_Context( 'source-agent', 0, WP_Agent_Caller_Context::SELF_HOST, 1, 'root-request-123' );
	agents_api_smoke_assert_equals( true, false, 'chained context with self host is rejected via constructor', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'caller_host' ), 'chained context with self host is rejected via constructor', $failures, $passes );
}

agents_api_smoke_finish( 'Agents API caller context', $failures, $passes );
