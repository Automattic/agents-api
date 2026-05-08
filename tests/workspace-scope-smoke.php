<?php
/**
 * Pure-PHP smoke test for first-class workspace scope contracts.
 *
 * Run with: php tests/workspace-scope-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-workspace-scope-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Workspace scope normalizes to a generic type + ID shape:\n";

$workspace = AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_parts(
	' Code_Workspace ',
	' Automattic/intelligence@contexta8c-read-coverage '
);

agents_api_smoke_assert_equals( 'code_workspace', $workspace->workspace_type, 'workspace type is normalized', $failures, $passes );
agents_api_smoke_assert_equals( 'Automattic/intelligence@contexta8c-read-coverage', $workspace->workspace_id, 'workspace ID is trimmed and otherwise preserved', $failures, $passes );
agents_api_smoke_assert_equals(
	array(
		'workspace_type' => 'code_workspace',
		'workspace_id'   => 'Automattic/intelligence@contexta8c-read-coverage',
	),
	$workspace->to_array(),
	'workspace scope exports JSON-friendly fields',
	$failures,
	$passes
);
agents_api_smoke_assert_equals( 'code_workspace:Automattic/intelligence@contexta8c-read-coverage', $workspace->key(), 'workspace key includes type and ID', $failures, $passes );

echo "\n[2] Memory scope carries workspace identity in its stable key:\n";

$memory_scope = new AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope(
	'user',
	$workspace->workspace_type,
	$workspace->workspace_id,
	123,
	456,
	'daily/2026/05/04.md'
);

agents_api_smoke_assert_equals( $workspace->to_array(), $memory_scope->workspace()->to_array(), 'memory scope exposes workspace value object', $failures, $passes );
agents_api_smoke_assert_equals(
	'user:code_workspace:Automattic/intelligence@contexta8c-read-coverage:123:456:daily/2026/05/04.md',
	$memory_scope->key(),
	'memory key includes layer, workspace, user, agent, and filename',
	$failures,
	$passes
);

echo "\n[3] Conversation requests carry workspace identity for transcript persisters:\n";

$request = new AgentsAPI\AI\WP_Agent_Conversation_Request(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	array(),
	null,
	array( 'mode' => 'chat' ),
	array(),
	2,
	false,
	$workspace
);

agents_api_smoke_assert_equals( $workspace->to_array(), $request->workspace()->to_array(), 'request exposes workspace scope', $failures, $passes );
agents_api_smoke_assert_equals( $workspace->to_array(), $request->to_array()['workspace'], 'request array includes workspace scope', $failures, $passes );

echo "\n[4] Transcript store contract requires workspace scope on session creation and pending dedup:\n";

$reflection       = new ReflectionClass( AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store::class );
$create_params   = $reflection->getMethod( 'create_session' )->getParameters();
$update_params   = $reflection->getMethod( 'update_session' )->getParameters();
$pending_params  = $reflection->getMethod( 'get_recent_pending_session' )->getParameters();
$workspace_class = AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::class;

agents_api_smoke_assert_equals( 'workspace', $create_params[0]->getName(), 'create_session first parameter is workspace', $failures, $passes );
agents_api_smoke_assert_equals( $workspace_class, $create_params[0]->getType()->getName(), 'create_session workspace parameter is typed', $failures, $passes );
agents_api_smoke_assert_equals( 'agent_slug', $create_params[2]->getName(), 'create_session accepts registered agent slug', $failures, $passes );
agents_api_smoke_assert_equals( 'string', $create_params[2]->getType()->getName(), 'create_session agent slug is string typed', $failures, $passes );
agents_api_smoke_assert_equals( '', $create_params[2]->getDefaultValue(), 'create_session agent slug defaults to agent-less session', $failures, $passes );
agents_api_smoke_assert_equals( 'provider_response_id', $update_params[5]->getName(), 'update_session accepts provider response ID', $failures, $passes );
agents_api_smoke_assert_equals( true, $update_params[5]->allowsNull(), 'provider response ID can be null when no provider state exists', $failures, $passes );
agents_api_smoke_assert_equals( 'workspace', $pending_params[0]->getName(), 'get_recent_pending_session first parameter is workspace', $failures, $passes );
agents_api_smoke_assert_equals( $workspace_class, $pending_params[0]->getType()->getName(), 'get_recent_pending_session workspace parameter is typed', $failures, $passes );

echo "\n[5] Invalid workspace scopes fail fast:\n";

$invalid_type = false;
try {
	AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_parts( 'site id', '217002206' );
} catch ( InvalidArgumentException $e ) {
	$invalid_type = true;
}

$invalid_id = false;
try {
	AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_parts( 'site', '   ' );
} catch ( InvalidArgumentException $e ) {
	$invalid_id = true;
}

agents_api_smoke_assert_equals( true, $invalid_type, 'workspace type must be a slug', $failures, $passes );
agents_api_smoke_assert_equals( true, $invalid_id, 'workspace ID must be non-empty', $failures, $passes );

agents_api_smoke_finish( 'Agents API workspace scope', $failures, $passes );
