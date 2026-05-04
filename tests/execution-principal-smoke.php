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

$principal = AgentsAPI\AI\AgentExecutionPrincipal::agent_token(
	123,
	'content-helper',
	456,
	AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST,
	array(
		'request_id' => 'req-abc',
		'transport'  => AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST,
	),
	'site:42',
	'kimaki',
	new WP_Agent_Capability_Ceiling( 123, array( 'edit_posts' ) )
);

agents_api_smoke_assert_equals( 123, $principal->acting_user_id, 'principal records acting user id', $failures, $passes );
agents_api_smoke_assert_equals( 'content-helper', $principal->effective_agent_id, 'principal records effective agent id', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_AGENT_TOKEN, $principal->auth_source, 'principal records auth source', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST, $principal->request_context, 'principal records request context', $failures, $passes );
agents_api_smoke_assert_equals( 456, $principal->token_id, 'principal records optional token id', $failures, $passes );
agents_api_smoke_assert_equals( 'req-abc', $principal->request_metadata['request_id'], 'principal records request metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'site:42', $principal->workspace_id, 'principal records workspace id', $failures, $passes );
agents_api_smoke_assert_equals( 'kimaki', $principal->client_id, 'principal records client id', $failures, $passes );
agents_api_smoke_assert_equals( array( 'edit_posts' ), $principal->capability_ceiling->allowed_capabilities, 'principal records capability ceiling', $failures, $passes );

$principal_array = $principal->to_array();
agents_api_smoke_assert_equals( 123, $principal_array['acting_user_id'], 'principal exports acting user id', $failures, $passes );
agents_api_smoke_assert_equals( 'content-helper', $principal_array['effective_agent_id'], 'principal exports effective agent id', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_AGENT_TOKEN, $principal_array['auth_source'], 'principal exports auth source', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST, $principal_array['request_context'], 'principal exports request context', $failures, $passes );
agents_api_smoke_assert_equals( 456, $principal_array['token_id'], 'principal exports token id without token contents', $failures, $passes );
agents_api_smoke_assert_equals( 'site:42', $principal_array['workspace_id'], 'principal exports workspace id', $failures, $passes );
agents_api_smoke_assert_equals( 'kimaki', $principal_array['client_id'], 'principal exports client id', $failures, $passes );
agents_api_smoke_assert_equals( array( 'edit_posts' ), $principal_array['capability_ceiling']['allowed_capabilities'], 'principal exports capability ceiling', $failures, $passes );

$from_array = AgentsAPI\AI\AgentExecutionPrincipal::from_array(
	array(
		'acting_user_id'       => '7',
		'effective_agent_id'   => 'support-agent',
		'auth_source'          => AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER,
		'request_context'      => AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_CHAT,
		'request_metadata'     => array( 'ip_hash' => 'abc123' ),
		'workspace_id'         => 'site:99',
		'client_id'            => 'browser',
		'capability_ceiling'   => array(
			'user_id'              => 7,
			'allowed_capabilities' => array( 'read' ),
		),
	)
);
agents_api_smoke_assert_equals( 7, $from_array->acting_user_id, 'from_array normalizes acting user id', $failures, $passes );
agents_api_smoke_assert_equals( 'support-agent', $from_array->effective_agent_id, 'from_array normalizes effective agent id', $failures, $passes );
agents_api_smoke_assert_equals( null, $from_array->token_id, 'from_array allows absent token id', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_CHAT, $from_array->request_context, 'from_array normalizes request context', $failures, $passes );
agents_api_smoke_assert_equals( array( 'ip_hash' => 'abc123' ), $from_array->request_metadata, 'from_array keeps metadata array', $failures, $passes );
agents_api_smoke_assert_equals( 'site:99', $from_array->workspace_id, 'from_array normalizes workspace id', $failures, $passes );
agents_api_smoke_assert_equals( 'browser', $from_array->client_id, 'from_array normalizes client id', $failures, $passes );
agents_api_smoke_assert_equals( array( 'read' ), $from_array->capability_ceiling->allowed_capabilities, 'from_array normalizes capability ceiling', $failures, $passes );

$user_session = AgentsAPI\AI\AgentExecutionPrincipal::user_session(
	99,
	'editor-agent',
	AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST,
	array( 'route' => '/agents/v1/run' )
);
agents_api_smoke_assert_equals( 99, $user_session->acting_user_id, 'user_session records acting user id', $failures, $passes );
agents_api_smoke_assert_equals( 'editor-agent', $user_session->effective_agent_id, 'user_session records effective agent id', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER, $user_session->auth_source, 'user_session records user auth source', $failures, $passes );
agents_api_smoke_assert_equals( null, $user_session->token_id, 'user_session omits token id', $failures, $passes );

add_filter(
	'agents_api_execution_principal',
	static function ( $principal, array $context ) {
		if ( AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST !== ( $context['request_context'] ?? '' ) ) {
			return $principal;
		}

		return array(
			'acting_user_id'       => 42,
			'effective_agent_id'   => 'token-agent',
			'auth_source'          => AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_AGENT_TOKEN,
			'request_context'      => $context['request_context'],
			'token_id'             => 321,
			'request_metadata'     => array( 'credential' => 'bearer' ),
		);
	},
	10,
	2
);

$resolved = AgentsAPI\AI\AgentExecutionPrincipal::resolve(
	array( 'request_context' => AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST )
);
agents_api_smoke_assert_equals( 42, $resolved->acting_user_id, 'resolve accepts filter-provided array principal', $failures, $passes );
agents_api_smoke_assert_equals( 'token-agent', $resolved->effective_agent_id, 'resolve records token effective agent id', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_AGENT_TOKEN, $resolved->auth_source, 'resolve records token auth source', $failures, $passes );
agents_api_smoke_assert_equals( 321, $resolved->token_id, 'resolve records token id', $failures, $passes );

$with_metadata = $from_array->with_request_metadata( array( 'request_id' => 'req-next' ) );
agents_api_smoke_assert_equals( array( 'request_id' => 'req-next' ), $with_metadata->request_metadata, 'metadata replacement returns updated copy', $failures, $passes );
agents_api_smoke_assert_equals( array( 'ip_hash' => 'abc123' ), $from_array->request_metadata, 'metadata replacement leaves original immutable', $failures, $passes );

try {
	new AgentsAPI\AI\AgentExecutionPrincipal( -1, 'agent', AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER, AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST );
	agents_api_smoke_assert_equals( true, false, 'negative user id is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'acting_user_id' ), 'negative user id is rejected', $failures, $passes );
}

try {
	new AgentsAPI\AI\AgentExecutionPrincipal( 1, '', AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER, AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST );
	agents_api_smoke_assert_equals( true, false, 'empty effective agent id is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'effective_agent_id' ), 'empty effective agent id is rejected', $failures, $passes );
}

try {
	new AgentsAPI\AI\AgentExecutionPrincipal( 1, 'agent', AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER, AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST, 0 );
	agents_api_smoke_assert_equals( true, false, 'zero token id is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'token_id' ), 'zero token id is rejected', $failures, $passes );
}

$resource = fopen( 'php://memory', 'r' );
try {
	new AgentsAPI\AI\AgentExecutionPrincipal( 1, 'agent', AgentsAPI\AI\AgentExecutionPrincipal::AUTH_SOURCE_USER, AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST, null, array( 'resource' => $resource ) );
	agents_api_smoke_assert_equals( true, false, 'non-serializable metadata is rejected', $failures, $passes );
} catch ( InvalidArgumentException $e ) {
	agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'request_metadata' ), 'non-serializable metadata is rejected', $failures, $passes );
} finally {
	if ( is_resource( $resource ) ) {
		fclose( $resource );
	}
}

agents_api_smoke_finish( 'Agents API execution principal', $failures, $passes );
