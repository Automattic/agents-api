<?php
/**
 * Pure-PHP smoke test for runtime tool policy projection.
 *
 * Run with: php tests/runtime-tool-policy-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-runtime-tool-policy-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

use AgentsAPI\AI\Tools\WP_Agent_Runtime_Tool_Policy;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;

$tools = array(
	'filesystem-write'                 => array(
		'name'            => 'filesystem-write',
		'source'          => 'runtime',
		'description'     => 'Write one generated website artifact file.',
		'runtime_tool_id' => 'filesystem_write',
		'runtime'         => array(
			WP_Agent_Tool_Declaration::RUNTIME_ENVIRONMENT      => WP_Agent_Tool_Declaration::ENVIRONMENT_RUNTIME_LOCAL,
			WP_Agent_Tool_Declaration::RUNTIME_CAPABILITY_SCOPE => WP_Agent_Tool_Declaration::CAPABILITY_SCOPE_RUNTIME_LOCAL,
		),
	),
	'control-plane/workspace-git-push' => array(
		'name'        => 'control-plane/workspace-git-push',
		'source'      => 'control-plane',
		'description' => 'Push a workspace branch from the parent control plane.',
		'runtime'     => array(
			WP_Agent_Tool_Declaration::RUNTIME_ENVIRONMENT      => WP_Agent_Tool_Declaration::ENVIRONMENT_CONTROL_PLANE,
			WP_Agent_Tool_Declaration::RUNTIME_CAPABILITY_SCOPE => WP_Agent_Tool_Declaration::CAPABILITY_SCOPE_CONTROL_PLANE,
		),
	),
	'control-plane/workspace-read'     => array(
		'name'        => 'control-plane/workspace-read',
		'source'      => 'control-plane',
		'description' => 'Read a workspace file through the parent control plane.',
	),
);

$policy = WP_Agent_Runtime_Tool_Policy::fromTools(
	$tools,
	array(
		'runtime_type' => 'wordpress-playground',
		'session_id'   => 'abc123',
		'ignored'      => array( 'nested' ),
	)
);

$provider_safe_runtime_tool = WP_Agent_Tool_Declaration::normalize(
	array(
		'name'        => 'filesystem_write',
		'source'      => WP_Agent_Tool_Declaration::SOURCE_CLIENT,
		'description' => 'Write one generated website artifact file.',
		'parameters'  => array( 'type' => 'object' ),
		'executor'    => WP_Agent_Tool_Declaration::EXECUTOR_CLIENT,
		'scope'       => WP_Agent_Tool_Declaration::SCOPE_RUN,
	)
);

agents_api_smoke_assert_equals( WP_Agent_Runtime_Tool_Policy::SCHEMA, $policy['schema'] ?? '', 'policy exposes canonical schema', $failures, $passes );
agents_api_smoke_assert_equals( 'filesystem_write', $provider_safe_runtime_tool['name'] ?? '', 'provider-safe runtime tool name is accepted', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Declaration::SOURCE_CLIENT, $provider_safe_runtime_tool['source'] ?? '', 'provider-safe runtime tool keeps client source', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Runtime_Tool_Policy::VERSION, $policy['version'] ?? 0, 'policy exposes canonical version', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $policy['tools'] ?? array() ), 'policy includes all declared tools', $failures, $passes );
agents_api_smoke_assert_equals( array( 'runtime_type' => 'wordpress-playground', 'session_id' => 'abc123' ), $policy['context'] ?? array(), 'policy context keeps only scalar metadata', $failures, $passes );

$by_id = array();
foreach ( $policy['tools'] as $tool ) {
	$by_id[ $tool['id'] ] = $tool;
}

agents_api_smoke_assert_equals( true, $by_id['filesystem-write']['allowed'] ?? false, 'runtime-local tool is allowed', $failures, $passes );
agents_api_smoke_assert_equals( 'filesystem_write', $by_id['filesystem-write']['runtime_tool_id'] ?? '', 'runtime tool id can be declared explicitly', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Tool_Declaration::ENVIRONMENT_RUNTIME_LOCAL, $by_id['filesystem-write']['runtime'][ WP_Agent_Tool_Declaration::RUNTIME_ENVIRONMENT ] ?? '', 'runtime-local tool records execution environment', $failures, $passes );
agents_api_smoke_assert_equals( 'sandbox', $by_id['filesystem-write']['execution_location'] ?? '', 'runtime-local tool projects legacy sandbox location for consumers', $failures, $passes );

agents_api_smoke_assert_equals( false, $by_id['control-plane/workspace-git-push']['allowed'] ?? true, 'control-plane tool is denied to runtime-local agent', $failures, $passes );
agents_api_smoke_assert_equals( 'parent', $by_id['control-plane/workspace-git-push']['transport_visibility'] ?? '', 'control-plane tool projects parent visibility', $failures, $passes );
agents_api_smoke_assert_equals( 'control_plane_workspace_read', $by_id['control-plane/workspace-read']['runtime_tool_id'] ?? '', 'runtime tool id defaults from tool name', $failures, $passes );
agents_api_smoke_assert_equals( false, $by_id['control-plane/workspace-read']['allowed'] ?? true, 'tools without runtime metadata default closed', $failures, $passes );

add_filter(
	'agents_api_runtime_tool_policy',
	static function ( array $runtime_policy ): array {
		$runtime_policy['metadata'] = array( 'filtered' => true );
		return $runtime_policy;
	}
);
$filtered_policy = WP_Agent_Runtime_Tool_Policy::fromTools( $tools );
agents_api_smoke_assert_equals( true, $filtered_policy['metadata']['filtered'] ?? false, 'policy projection exposes a host mediation filter', $failures, $passes );

agents_api_smoke_finish( 'runtime tool policy', $failures, $passes );
