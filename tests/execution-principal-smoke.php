<?php
/**
 * Pure-PHP smoke test for the Agents API execution principal contract.
 *
 * Run with: php tests/execution-principal-smoke.php
 *
 * @package AgentsAPI\Tests
 */

use AgentsAPI\Execution\AgentExecutionPrincipal;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-execution-principal-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Principal value object serializes predictably:\n";
$token_principal = new AgentExecutionPrincipal(
	17,
	42,
	AgentExecutionPrincipal::AUTH_SOURCE_BEARER_TOKEN,
	'token_abc123',
	'rest'
);

agents_api_smoke_assert_equals(
	array(
		'schema'             => AgentExecutionPrincipal::SCHEMA,
		'version'            => AgentExecutionPrincipal::VERSION,
		'acting_user_id'     => 17,
		'effective_agent_id' => 42,
		'auth_source'        => AgentExecutionPrincipal::AUTH_SOURCE_BEARER_TOKEN,
		'token_id'           => 'token_abc123',
		'request_context'    => 'rest',
	),
	$token_principal->to_array(),
	'token-shaped principal exposes stable array shape',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	'17:42:bearer_token:token_abc123:rest',
	$token_principal->key(),
	'token-shaped principal exposes stable key',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	$token_principal->to_array(),
	AgentExecutionPrincipal::from_array( $token_principal->to_array() )->to_array(),
	'principal round-trips from serialized array',
	$failures,
	$passes
);

echo "\n[2] User-session principal resolves from the local WordPress user seam:\n";
$GLOBALS['__agents_api_smoke_user_id'] = 123;
$user_principal                       = wp_get_agent_execution_principal(
	array(
		'effective_agent_id' => 456,
		'request_context'    => 'chat',
	)
);

agents_api_smoke_assert_equals( true, $user_principal instanceof AgentExecutionPrincipal, 'user-session resolver returns a principal', $failures, $passes );
agents_api_smoke_assert_equals(
	array(
		'schema'             => AgentExecutionPrincipal::SCHEMA,
		'version'            => AgentExecutionPrincipal::VERSION,
		'acting_user_id'     => 123,
		'effective_agent_id' => 456,
		'auth_source'        => AgentExecutionPrincipal::AUTH_SOURCE_USER_SESSION,
		'token_id'           => null,
		'request_context'    => 'chat',
	),
	$user_principal ? $user_principal->to_array() : null,
	'user-session principal shape is stable',
	$failures,
	$passes
);

echo "\n[3] Host resolver hook can provide token-derived principal shape:\n";
$GLOBALS['__agents_api_smoke_user_id'] = 0;
add_filter(
	'wp_agents_api_execution_principal',
	function ( $principal, array $context ) {
		unset( $principal );

		return array(
			'acting_user_id'     => (int) $context['acting_user_id'],
			'effective_agent_id' => (int) $context['effective_agent_id'],
			'auth_source'        => AgentExecutionPrincipal::AUTH_SOURCE_BEARER_TOKEN,
			'token_id'           => (string) $context['token_id'],
			'request_context'    => (string) $context['request_context'],
		);
	},
	10,
	2
);

$resolved_token_principal = wp_get_agent_execution_principal(
	array(
		'acting_user_id'     => 0,
		'effective_agent_id' => 789,
		'token_id'           => 'tok_live_456',
		'request_context'    => 'rest',
	)
);

agents_api_smoke_assert_equals( true, $resolved_token_principal instanceof AgentExecutionPrincipal, 'token resolver returns a principal', $failures, $passes );
agents_api_smoke_assert_equals(
	array(
		'schema'             => AgentExecutionPrincipal::SCHEMA,
		'version'            => AgentExecutionPrincipal::VERSION,
		'acting_user_id'     => 0,
		'effective_agent_id' => 789,
		'auth_source'        => AgentExecutionPrincipal::AUTH_SOURCE_BEARER_TOKEN,
		'token_id'           => 'tok_live_456',
		'request_context'    => 'rest',
	),
	$resolved_token_principal ? $resolved_token_principal->to_array() : null,
	'token-derived principal shape is stable',
	$failures,
	$passes
);

agents_api_smoke_finish( 'Agents API execution principal', $failures, $passes );
