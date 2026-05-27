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
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Action_Policy_Provider' ), 'WP_Agent_Action_Policy_Provider is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'AgentsAPI\\AI\\Approvals\\WP_Agent_Approval_Memory_Store' ), 'WP_Agent_Approval_Memory_Store is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\Approvals\\WP_Agent_Null_Approval_Memory_Store' ), 'WP_Agent_Null_Approval_Memory_Store is available', $failures, $passes );

$resolver = new WP_Agent_Action_Policy_Resolver();

echo "\n[2] Resolution order starts with explicit deny and agent config overrides:\n";
agents_api_smoke_assert_equals(
	AgentsAPI\AI\Tools\WP_Agent_Action_Policy::FORBIDDEN,
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
	AgentsAPI\AI\Tools\WP_Agent_Action_Policy::PREVIEW,
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
	AgentsAPI\AI\Tools\WP_Agent_Action_Policy::PREVIEW,
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
	AgentsAPI\AI\Tools\WP_Agent_Action_Policy::FORBIDDEN,
	$resolver->resolve_for_tool(
		array(
			'tool_name'               => 'client/private',
			'action_policy_providers' => array(
				new class() implements WP_Agent_Action_Policy_Provider {
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
	AgentsAPI\AI\Tools\WP_Agent_Action_Policy::DIRECT,
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

echo "\n[4] Approval memory participates before tool-declared defaults:\n";
$workspace = AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_parts( 'code_workspace', 'Automattic/agents-api@approval-memory' );
$memory    = new class() implements AgentsAPI\AI\Approvals\WP_Agent_Approval_Memory_Store {
	/** @var array<string, string> */
	public array $values = array();

	public function remember( AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_id, string $tool_name, string $policy ): void {
		$this->values[ $workspace->key() . '|' . $user_id . '|' . $agent_id . '|' . $tool_name ] = $policy;
	}

	public function recall( AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_id, string $tool_name ): ?string {
		return $this->values[ $workspace->key() . '|' . $user_id . '|' . $agent_id . '|' . $tool_name ] ?? null;
	}

	public function forget( AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_id, string $tool_name ): void {
		unset( $this->values[ $workspace->key() . '|' . $user_id . '|' . $agent_id . '|' . $tool_name ] );
	}
};
$memory->remember( $workspace, 123, 'release-agent', 'client/publish', AgentsAPI\AI\Tools\WP_Agent_Action_Policy::DIRECT );

agents_api_smoke_assert_equals(
	AgentsAPI\AI\Tools\WP_Agent_Action_Policy::DIRECT,
	$resolver->resolve_for_tool(
		array(
			'tool_name'             => 'client/publish',
			'tool_def'              => array( 'action_policy' => 'preview' ),
			'workspace'             => $workspace,
			'user_id'               => 123,
			'agent_id'              => 'release-agent',
			'approval_memory_store' => $memory,
		)
	),
	'remembered approval policy resolves before tool-declared default',
	$failures,
	$passes
);

$memory->forget( $workspace, 123, 'release-agent', 'client/publish' );
agents_api_smoke_assert_equals(
	AgentsAPI\AI\Tools\WP_Agent_Action_Policy::PREVIEW,
	$resolver->resolve_for_tool(
		array(
			'tool_name'             => 'client/publish',
			'tool_def'              => array( 'action_policy' => 'preview' ),
			'workspace'             => $workspace,
			'user_id'               => 123,
			'agent_id'              => 'release-agent',
			'approval_memory_store' => $memory,
		)
	),
	'forget clears remembered approval policy',
	$failures,
	$passes
);

agents_api_smoke_assert_equals(
	AgentsAPI\AI\Tools\WP_Agent_Action_Policy::PREVIEW,
	$resolver->resolve_for_tool(
		array(
			'tool_name'             => 'client/publish',
			'tool_def'              => array( 'action_policy' => 'preview' ),
			'workspace'             => $workspace,
			'user_id'               => 123,
			'agent_id'              => 'release-agent',
			'approval_memory_store' => new AgentsAPI\AI\Approvals\WP_Agent_Null_Approval_Memory_Store(),
		)
	),
	'null approval memory store is transparent',
	$failures,
	$passes
);

echo "\n[5] Approval decisions can request remember-on-accept:\n";
$remembered_decision = AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision::accepted()->with_remember();
agents_api_smoke_assert_equals( true, $remembered_decision->remember(), 'with_remember marks decision for persistence', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision::ACCEPTED, $remembered_decision->value(), 'with_remember preserves decision value', $failures, $passes );

agents_api_smoke_finish( 'Action policy resolver', $failures, $passes );
