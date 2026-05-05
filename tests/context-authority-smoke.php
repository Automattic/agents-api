<?php
/**
 * Pure-PHP smoke test for retrieved context authority contracts.
 *
 * Run with: php tests/context-authority-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-context-authority-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Authority tier vocabulary is stable and generic:\n";
agents_api_smoke_assert_equals(
	array(
		'platform_authority',
		'support_authority',
		'workspace_shared',
		'user_workspace_private',
		'user_global',
		'agent_identity',
		'agent_memory',
		'conversation',
	),
	AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::ordered(),
	'authority tiers are exposed highest authority first',
	$failures,
	$passes
);
agents_api_smoke_assert_equals( true, AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::is_governance_authority( 'platform_authority' ), 'platform authority is generic governance authority', $failures, $passes );
agents_api_smoke_assert_equals( true, AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::is_governance_authority( 'support_authority' ), 'support authority is generic governance authority', $failures, $passes );
agents_api_smoke_assert_equals( false, AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::is_governance_authority( 'user_workspace_private' ), 'user memory is not governance authority', $failures, $passes );

echo "\n[2] Retrieved context item exports scope, authority, and provenance:\n";
$item = new AgentsAPI\AI\Context\WP_Agent_Context_Item(
	'Use concise replies.',
	array( 'workspace' => 'example' ),
	AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::USER_WORKSPACE_PRIVATE,
	array( 'source' => 'memory', 'uri' => 'memory:user/1/preferences.md' ),
	AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind::PREFERENCE,
	'response_style',
	array( 'updated_at' => 1713370000 )
);
agents_api_smoke_assert_equals(
	array(
		'content'        => 'Use concise replies.',
		'scope'          => array( 'workspace' => 'example' ),
		'authority_tier' => 'user_workspace_private',
		'provenance'     => array( 'source' => 'memory', 'uri' => 'memory:user/1/preferences.md' ),
		'conflict_kind'  => 'preference',
		'conflict_key'   => 'response_style',
		'metadata'       => array( 'updated_at' => 1713370000 ),
	),
	$item->to_array(),
	'context item shape is JSON-friendly',
	$failures,
	$passes
);

echo "\n[3] Preferences resolve by specificity, not broad authority:\n";
$resolver = new AgentsAPI\AI\Context\WP_Agent_Default_Context_Conflict_Resolver();
$preference_resolutions = $resolver->resolve(
	array(
		new AgentsAPI\AI\Context\WP_Agent_Context_Item(
			'Use formal replies.',
			array( 'mode' => 'default' ),
			AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::PLATFORM_AUTHORITY,
			array( 'source' => 'platform-policy' ),
			AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind::PREFERENCE,
			'response_style'
		),
		new AgentsAPI\AI\Context\WP_Agent_Context_Item(
			'Use concise replies.',
			array( 'workspace' => 'example', 'user_id' => 12 ),
			AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::USER_WORKSPACE_PRIVATE,
			array( 'source' => 'user-memory' ),
			AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind::PREFERENCE,
			'response_style'
		),
	)
);
agents_api_smoke_assert_equals( 'Use concise replies.', $preference_resolutions['response_style']->winner->content, 'more specific user workspace preference wins', $failures, $passes );
agents_api_smoke_assert_equals( 'specificity_then_authority', $preference_resolutions['response_style']->strategy, 'preference strategy is explicit', $failures, $passes );

echo "\n[4] Authoritative facts cannot be overridden by lower scopes:\n";
$fact_resolutions = $resolver->resolve(
	array(
		new AgentsAPI\AI\Context\WP_Agent_Context_Item(
			'External publishing is disabled.',
			array( 'mode' => 'support' ),
			AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::SUPPORT_AUTHORITY,
			array( 'source' => 'support-policy' ),
			AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind::AUTHORITATIVE_FACT,
			'external_publishing_state'
		),
		new AgentsAPI\AI\Context\WP_Agent_Context_Item(
			'External publishing is enabled.',
			array( 'workspace' => 'example', 'user_id' => 12 ),
			AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::USER_WORKSPACE_PRIVATE,
			array( 'source' => 'user-memory' ),
			AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind::AUTHORITATIVE_FACT,
			'external_publishing_state'
		),
	)
);
agents_api_smoke_assert_equals( 'External publishing is disabled.', $fact_resolutions['external_publishing_state']->winner->content, 'higher authority fact wins over lower memory', $failures, $passes );
agents_api_smoke_assert_equals( 'authority_tier', $fact_resolutions['external_publishing_state']->strategy, 'authoritative fact strategy is explicit', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $fact_resolutions['external_publishing_state']->rejected ), 'lower conflicting fact is rejected', $failures, $passes );

agents_api_smoke_finish( 'Agents API context authority', $failures, $passes );
