<?php
/**
 * Pure-PHP smoke test for generic action policy resolver contracts.
 *
 * Run with: php tests/action-policy-resolver-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-action-policy-resolver-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Generic action policy contracts are available:\n";
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Action_Policy_Resolver' ), 'WP_Agent_Action_Policy_Resolver is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Action_Policy_Provider_Interface' ), 'WP_Agent_Action_Policy_Provider_Interface is available', $failures, $passes );

$resolver = new WP_Agent_Action_Policy_Resolver();

echo "\n[2] Resolution order starts with explicit deny and agent config overrides:\n";
agents_api_smoke_assert_equals(
	AgentsAPI\AI\Tools\ActionPolicy::FORBIDDEN,
	$resolver->resolve_for_tool(
		array(
			'tool_name' => 'client/write',
			'deny'      => array( 'client/write' ),
		)
	),
	'explicit deny resolves to forbidden',
	$failures,
	$passes
);

agents_api_smoke_assert_equals(
	AgentsAPI\AI\Tools\ActionPolicy::PREVIEW,
	$resolver->resolve_for_tool(
		array(
			'tool_name'    => 'client/write',
			'agent_config' => array(
				'action_policy' => array(
					'tools' => array(
						'client/write' => 'preview',
					),
				),
			),
		)
	),
	'agent config tool override resolves to preview',
	$failures,
	$passes
);

echo "\n[3] Category policy, host providers, and tool defaults compose generically:\n";
agents_api_smoke_assert_equals(
	AgentsAPI\AI\Tools\ActionPolicy::PREVIEW,
	$resolver->resolve_for_tool(
		array(
			'tool_name'    => 'client/publish',
			'tool_def'     => array( 'categories' => array( 'publishing' ) ),
			'agent_config' => array(
				'action_policy' => array(
					'categories' => array(
						'publishing' => 'preview',
					),
				),
			),
		)
	),
	'agent config category override resolves to preview',
	$failures,
	$passes
);

agents_api_smoke_assert_equals(
	AgentsAPI\AI\Tools\ActionPolicy::FORBIDDEN,
	$resolver->resolve_for_tool(
		array(
			'tool_name'               => 'client/private',
			'action_policy_providers' => array(
				new class() implements WP_Agent_Action_Policy_Provider_Interface {
					public function get_action_policy( array $context ): ?string {
						return 'client/private' === ( $context['tool_name'] ?? '' ) ? 'forbidden' : null;
					}
				},
			),
		)
	),
	'host action provider resolves to forbidden',
	$failures,
	$passes
);

agents_api_smoke_assert_equals(
	AgentsAPI\AI\Tools\ActionPolicy::DIRECT,
	$resolver->resolve_for_tool(
		array(
			'tool_name' => 'client/read',
			'tool_def'  => array( 'action_policy' => 'direct' ),
		)
	),
	'tool-declared default resolves to direct',
	$failures,
	$passes
);

agents_api_smoke_finish( 'Action policy resolver', $failures, $passes );
