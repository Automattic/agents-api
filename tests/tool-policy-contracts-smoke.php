<?php
/**
 * Pure-PHP smoke test for generic tool visibility policy contracts.
 *
 * Run with: php tests/tool-policy-contracts-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-policy-contracts-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Generic tool visibility contracts are available:\n";
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Tool_Policy' ), 'WP_Agent_Tool_Policy is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Tool_Policy_Filter' ), 'WP_Agent_Tool_Policy_Filter is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Tool_Access_Policy' ), 'WP_Agent_Tool_Access_Policy is available', $failures, $passes );

$tools = array(
	'client/read'      => array(
		'name'       => 'client/read',
		'categories' => array( 'read' ),
		'modes'      => array( 'chat', 'system' ),
	),
	'client/write'     => array(
		'name'       => 'client/write',
		'categories' => array( 'write' ),
		'modes'      => array( 'chat' ),
	),
	'client/mandatory' => array(
		'name'       => 'client/mandatory',
		'categories' => array( 'plumbing' ),
		'modes'      => array( 'chat' ),
	),
	'client/system'    => array(
		'name'       => 'client/system',
		'categories' => array( 'system' ),
		'modes'      => array( 'system' ),
	),
	'client/runtime'   => array(
		'name'         => 'client/runtime',
		'categories'   => array( 'read' ),
		'modes'        => array( 'chat' ),
		'executor'     => 'client',
		'scope'        => 'run',
		'parameters'   => array(
			'type'       => 'object',
			'required'   => array( 'api_key' ),
			'properties' => array(
				'api_key' => array( 'type' => 'string' ),
			),
		),
		'runtime_tool' => true,
	),
	'client/ambient'   => array(
		'name'         => 'client/ambient',
		'categories'   => array( 'read' ),
		'modes'        => array( 'chat' ),
		'executor'     => 'client',
		'scope'        => 'run',
		'runtime_tool' => true,
	),
	'web_fetch'        => array(
		'name'       => 'web_fetch',
		'categories' => array( 'read' ),
		'modes'      => array( 'chat' ),
		'executor'   => 'host',
		'scope'      => 'run',
	),
);

$resolver = new WP_Agent_Tool_Policy();

echo "\n[2] Agent/runtime allow policy narrows optional tools and preserves mandatory provider tools:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'                  => 'chat',
		'agent_config'          => array(
			'tool_policy' => array(
				'mode'  => 'allow',
				'tools' => array( 'client/read' ),
			),
		),
		'tool_policy_providers' => array(
			new class() implements WP_Agent_Tool_Access_Policy {
				public function get_tool_policy( array $context ): ?array {
					unset( $context );
					return array(
						'mandatory_tools' => array( 'client/mandatory' ),
					);
				}
			},
		),
	)
);
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals( array( 'client/mandatory', 'client/read' ), $resolved_names, 'mandatory provider tools survive allow policy', $failures, $passes );

echo "\n[3] Runtime category and mode filters are generic:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'       => 'system',
		'categories' => array( 'system' ),
	)
);
agents_api_smoke_assert_equals( array( 'client/system' ), array_keys( $resolved ), 'system mode category filter returns only matching system tool', $failures, $passes );

echo "\n[4] Explicit deny always wins over mandatory providers:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'                  => 'chat',
		'deny'                  => array( 'client/mandatory' ),
		'tool_policy'           => array(
			'mode'  => 'allow',
			'tools' => array( 'client/read' ),
		),
		'tool_policy_providers' => array(
			new class() implements WP_Agent_Tool_Access_Policy {
				public function get_tool_policy( array $context ): ?array {
					unset( $context );
					return array(
						'mandatory_tools' => array( 'client/mandatory' ),
					);
				}
			},
		),
	)
);
agents_api_smoke_assert_equals( array( 'client/read' ), array_keys( $resolved ), 'explicit deny removes mandatory provider tool', $failures, $passes );

echo "\n[5] Runtime/client tools require explicit opt-in while non-runtime tools remain visible:\n";
// This section exercises generic runtime-tool opt-in. It models an
// interactive human session via a user principal so the write-capable
// curation gate (now keyed off principal autonomy) stays out of the way:
// the host `client/write` tool remains ambient and the assertions can
// focus on runtime-tool opt-in behavior.
$user_principal = AgentsAPI\AI\WP_Agent_Execution_Principal::user_session( 1, 'contracts-agent' );

$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'      => 'chat',
		'principal' => $user_principal,
	)
);
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals( array( 'client/mandatory', 'client/read', 'client/write', 'web_fetch' ), $resolved_names, 'runtime tools are excluded by default without hiding ordinary host tools', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'         => 'chat',
		'principal'    => $user_principal,
		'agent_config' => array(
			'tool_policy' => array(
				'mode'  => 'allow',
				'tools' => array( 'client/read', 'client/runtime' ),
			),
		),
	)
);
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals( array( 'client/read', 'client/runtime' ), $resolved_names, 'allow policy can explicitly opt in a runtime tool by name', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'        => 'chat',
		'principal'   => $user_principal,
		'tool_policy' => array(
			'mode'          => 'deny',
			'tools'         => array( 'client/write' ),
			'runtime_tools' => array( 'client/runtime' ),
		),
	)
);
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals( array( 'client/mandatory', 'client/read', 'client/runtime', 'web_fetch' ), $resolved_names, 'deny policy still requires runtime_tools opt-in for runtime tools without hiding host tools', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'       => 'chat',
		'principal'  => $user_principal,
		'allow_only' => array( 'client/read', 'client/runtime' ),
	)
);
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals( array( 'client/read', 'client/runtime' ), $resolved_names, 'allow_only explicitly opts in a named runtime tool', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'        => 'chat',
		'principal'   => $user_principal,
		'tool_policy' => array(
			'mode'               => 'deny',
			'runtime_categories' => array( 'read' ),
		),
		'deny'        => array( 'client/runtime' ),
	)
);
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals( array( 'client/ambient', 'client/mandatory', 'client/read', 'client/write', 'web_fetch' ), $resolved_names, 'runtime category opt-in composes with final explicit deny without hiding host tools', $failures, $passes );

echo "\n[6] Sensitive required parameters stay auditable and intentional:\n";
$parameters = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::buildParameters(
	array(),
	array( 'api_key' => 'ambient-secret' ),
	$tools['client/runtime']
);
agents_api_smoke_assert_equals( array(), $parameters, 'ambient sensitive context does not satisfy runtime tool required parameters', $failures, $passes );

$bound_runtime_tool                            = $tools['client/runtime'];
$bound_runtime_tool['client_context_bindings'] = array( 'api_key' );
$parameters                                    = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::buildParameters(
	array(),
	array( 'api_key' => 'declared-secret' ),
	$bound_runtime_tool
);
agents_api_smoke_assert_equals( array( 'api_key' => 'declared-secret' ), $parameters, 'declared context binding makes sensitive required parameter sourcing explicit', $failures, $passes );

agents_api_smoke_finish( 'Tool policy contracts', $failures, $passes );
