<?php
/**
 * Pure-PHP smoke test for execution principal primitives.
 *
 * Run with: php tests/execution-principal-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-execution-principal-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$principal = new AgentsAPI\AI\AgentExecutionPrincipal(
	123,
	'content-helper',
	AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_AGENT_TOKEN,
	456,
	array(
		'request_id' => 'req-abc',
		'transport'  => 'rest',
	)
);

agents_api_smoke_assert_equals( 123, $principal->acting_user_id, 'principal records acting user id', $failures, $passes );
agents_api_smoke_assert_equals( 'content-helper', $principal->effective_agent_slug, 'principal records effective agent slug', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_AGENT_TOKEN, $principal->auth_source, 'principal records auth source', $failures, $passes );
agents_api_smoke_assert_equals( 456, $principal->token_id, 'principal records optional token id', $failures, $passes );
agents_api_smoke_assert_equals( 'req-abc', $principal->request_metadata['request_id'], 'principal records request metadata', $failures, $passes );

$principal_array = $principal->to_array();
agents_api_smoke_assert_equals( 123, $principal_array['acting_user_id'], 'principal exports acting user id', $failures, $passes );
agents_api_smoke_assert_equals( 'content-helper', $principal_array['effective_agent_slug'], 'principal exports effective agent slug', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_AGENT_TOKEN, $principal_array['auth_source'], 'principal exports auth source', $failures, $passes );
agents_api_smoke_assert_equals( 456, $principal_array['token_id'], 'principal exports token id without token contents', $failures, $passes );

$from_array = AgentsAPI\AI\AgentExecutionPrincipal::from_array(
	array(
		'acting_user_id'       => '7',
		'effective_agent_slug' => 'support-agent',
		'auth_source'          => AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER,
		'request_metadata'     => array( 'ip_hash' => 'abc123' ),
	)
);
agents_api_smoke_assert_equals( 7, $from_array->acting_user_id, 'from_array normalizes acting user id', $failures, $passes );
agents_api_smoke_assert_equals( null, $from_array->token_id, 'from_array allows absent token id', $failures, $passes );
agents_api_smoke_assert_equals( array( 'ip_hash' => 'abc123' ), $from_array->request_metadata, 'from_array keeps metadata array', $failures, $passes );

$with_metadata = $from_array->with_request_metadata( array( 'request_id' => 'req-next' ) );
agents_api_smoke_assert_equals( array( 'request_id' => 'req-next' ), $with_metadata->request_metadata, 'metadata replacement returns updated copy', $failures, $passes );
agents_api_smoke_assert_equals( array( 'ip_hash' => 'abc123' ), $from_array->request_metadata, 'metadata replacement leaves original immutable', $failures, $passes );

try {
	new AgentsAPI\AI\AgentExecutionPrincipal( -1, 'agent', AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER );
	agents_api_smoke_assert_equals( true, false, 'negative user id is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'acting_user_id' ), 'negative user id is rejected', $failures, $passes );
}

try {
	new AgentsAPI\AI\AgentExecutionPrincipal( 1, '', AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER );
	agents_api_smoke_assert_equals( true, false, 'empty effective agent is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'effective_agent_slug' ), 'empty effective agent is rejected', $failures, $passes );
}

try {
	new AgentsAPI\AI\AgentExecutionPrincipal( 1, 'agent', AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER, 0 );
	agents_api_smoke_assert_equals( true, false, 'zero token id is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'token_id' ), 'zero token id is rejected', $failures, $passes );
}

$resource = fopen( 'php://memory', 'r' );
try {
	new AgentsAPI\AI\AgentExecutionPrincipal( 1, 'agent', AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER, null, array( 'resource' => $resource ) );
	agents_api_smoke_assert_equals( true, false, 'non-serializable metadata is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'request_metadata' ), 'non-serializable metadata is rejected', $failures, $passes );
} finally {
	if ( is_resource( $resource ) ) {
		fclose( $resource );
	}
}

agents_api_smoke_finish( 'Agents API execution principal', $failures, $passes );
