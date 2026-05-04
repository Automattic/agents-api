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
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Tool_Access_Policy_Interface' ), 'WP_Agent_Tool_Access_Policy_Interface is available', $failures, $passes );

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
			new class() implements WP_Agent_Tool_Access_Policy_Interface {
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
			new class() implements WP_Agent_Tool_Access_Policy_Interface {
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

agents_api_smoke_finish( 'Tool policy contracts', $failures, $passes );
