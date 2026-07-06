<?php
/**
 * Pure-PHP smoke test for the safe-by-default write-capable tool curation.
 *
 * The write-capable gate is TOOL-SURFACE CURATION (defense-in-depth), not
 * the enforcement boundary. Capability-ceiling enforcement at ability/tool
 * execution is tracked separately in #412. This listing gate only decides
 * whether write-capable tools are *offered* to the model.
 *
 * The gate keys off the execution PRINCIPAL'S AUTONOMY — which the substrate
 * already models precisely — instead of a mode/interactive string. A
 * principal is autonomous when its `auth_source` is an automation source
 * (system / runtime / agent_token) OR it has no human backing it
 * (`acting_user_id` === 0). For autonomous principals, write-capable tools
 * are opt-in rather than ambient. Human-backed principals (interactive user
 * sessions) are unchanged.
 *
 * The gate names NO consumer modes: a `mode` string does not move the gate.
 * Run with: php tests/write-capable-default-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-write-capable-default-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$principal_class = 'AgentsAPI\AI\WP_Agent_Execution_Principal';

echo "\n[1] Curation contracts are available and the gate names no consumer modes:\n";
agents_api_smoke_assert_equals( false, defined( 'WP_Agent_Tool_Policy::NON_INTERACTIVE_MODES' ), 'NON_INTERACTIVE_MODES enumeration is gone (substrate is mode-name neutral)', $failures, $passes );
agents_api_smoke_assert_equals( false, defined( 'WP_Agent_Tool_Policy::INTERACTIVE_MODES' ), 'INTERACTIVE_MODES mode-marker list is gone from the write gate', $failures, $passes );
agents_api_smoke_assert_equals( false, defined( 'WP_Agent_Tool_Policy::RUNTIME_PIPELINE' ), 'RUNTIME_PIPELINE consumer-mode constant is gone', $failures, $passes );
agents_api_smoke_assert_equals( false, defined( 'WP_Agent_Tool_Policy::RUNTIME_SYSTEM' ), 'RUNTIME_SYSTEM consumer-mode constant is gone', $failures, $passes );
agents_api_smoke_assert_equals( true, defined( 'WP_Agent_Tool_Policy::RUNTIME_CHAT' ), 'RUNTIME_CHAT is retained as the generic tool mode-filter default', $failures, $passes );
agents_api_smoke_assert_equals( true, method_exists( 'WP_Agent_Tool_Policy', 'resolve' ), 'WP_Agent_Tool_Policy resolver is available', $failures, $passes );
agents_api_smoke_assert_equals( true, method_exists( 'WP_Agent_Tool_Policy_Filter', 'write_capable_categories' ), 'write_capable_categories method is available', $failures, $passes );
agents_api_smoke_assert_equals( true, method_exists( 'WP_Agent_Tool_Policy_Filter', 'is_write_capable_tool' ), 'is_write_capable_tool method is available', $failures, $passes );
agents_api_smoke_assert_equals( true, method_exists( 'WP_Agent_Tool_Policy_Filter', 'filter_write_capable_by_policy_opt_in' ), 'filter_write_capable_by_policy_opt_in method is available', $failures, $passes );

$resolver = new WP_Agent_Tool_Policy();

$tools = array(
	'host/read-meta'       => array(
		'name'       => 'host/read-meta',
		'categories' => array( 'read' ),
		'modes'      => array( 'chat', 'pipeline', 'system' ),
	),
	'host/write-post'      => array(
		'name'       => 'host/write-post',
		'categories' => array( 'write' ),
		'modes'      => array( 'chat', 'pipeline', 'system' ),
	),
	'host/publish-post'    => array(
		'name'       => 'host/publish-post',
		'categories' => array( 'publishing' ),
		'modes'      => array( 'chat', 'pipeline', 'system' ),
	),
	'host/flagged-write'   => array(
		'name'       => 'host/flagged-write',
		'categories' => array( 'misc' ),
		'modes'      => array( 'chat', 'pipeline', 'system' ),
		'write'      => true,
	),
	'host/flagged-mutate'  => array(
		'name'       => 'host/flagged-mutate',
		'categories' => array( 'misc' ),
		'modes'      => array( 'chat', 'pipeline', 'system' ),
		'mutating'   => true,
	),
	'host/mandatory-fetch' => array(
		'name'       => 'host/mandatory-fetch',
		'categories' => array( 'plumbing' ),
		'modes'      => array( 'chat', 'pipeline', 'system' ),
		'mandatory'  => true,
	),
);

// A runtime principal (auth_source=runtime, acting_user_id=0) is autonomous.
$autonomous_runtime = $principal_class::runtime( 'runtime-1', 'smoke-agent' );

// An agent-token principal (auth_source=agent_token) is autonomous even when
// it carries an acting user, because automation drives the loop.
$autonomous_token = $principal_class::agent_token( 5, 'smoke-agent', 9 );

// An audience principal (acting_user_id=0) is autonomous by the no-human signal.
$autonomous_audience = $principal_class::audience( 'aud-1', 'smoke-agent' );

// A user-session principal (auth_source=user, acting_user_id>0) is interactive.
$interactive_user = $principal_class::user_session( 7, 'smoke-agent' );

// An application-password principal is a human-bound session, not autonomous.
$interactive_app_password = $principal_class::from_array(
	array(
		'acting_user_id'     => 7,
		'effective_agent_id' => 'smoke-agent',
		'auth_source'        => $principal_class::AUTH_SOURCE_APPLICATION_PASSWORD,
		'request_context'    => $principal_class::REQUEST_CONTEXT_REST,
	)
);

echo "\n[2] An autonomous runtime principal excludes every write-capable tool while read + mandatory survive:\n";
$resolved       = $resolver->resolve( $tools, array( 'principal' => $autonomous_runtime ) );
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals(
	array( 'host/mandatory-fetch', 'host/read-meta' ),
	$resolved_names,
	'an autonomous runtime principal curates out every write-capable tool (category or flag) but keeps read and mandatory tools',
	$failures,
	$passes
);

echo "\n[3] Declared write/mutating flag is gated exactly like category-classified tools:\n";
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), 'write-category tool is excluded for an autonomous principal', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/publish-post', $resolved ), 'publishing-category tool is excluded for an autonomous principal', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/flagged-write', $resolved ), 'declared write-flag tool is excluded for an autonomous principal', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/flagged-mutate', $resolved ), 'declared mutating-flag tool is excluded for an autonomous principal', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/read-meta', $resolved ), 'non-write read tool survives', $failures, $passes );

echo "\n[4] Both autonomy signals engage the gate independently:\n";
$resolved = $resolver->resolve( $tools, array( 'principal' => $autonomous_token ) );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), 'agent_token auth_source engages the gate even with an acting user', $failures, $passes );

$resolved = $resolver->resolve( $tools, array( 'principal' => $autonomous_audience ) );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), 'acting_user_id=0 engages the gate even for a non-automation auth source', $failures, $passes );

echo "\n[5] A human-backed principal keeps write tools regardless of the mode string:\n";
$resolved = $resolver->resolve( $tools, array( 'principal' => $interactive_user ) );
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals(
	array( 'host/flagged-mutate', 'host/flagged-write', 'host/mandatory-fetch', 'host/publish-post', 'host/read-meta', 'host/write-post' ),
	$resolved_names,
	'a user-session principal keeps every tool regardless of write classification',
	$failures,
	$passes
);

$resolved = $resolver->resolve(
	$tools,
	array(
		'principal' => $interactive_app_password,
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-post', $resolved ), 'an application-password (human-bound) principal keeps write tools', $failures, $passes );

echo "\n[6] The gate keys off the principal, NOT the mode string (decisive contract):\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'principal' => $autonomous_runtime,
		'mode'      => 'chat',
	)
);
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), 'an autonomous principal is gated even in chat mode (mode does not rescue autonomy)', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'principal' => $interactive_user,
		'mode'      => 'pipeline',
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-post', $resolved ), 'a user principal keeps write tools even in an opaque automation mode (mode does not create autonomy)', $failures, $passes );

echo "\n[7] The principal may be supplied as an array shape or as flat context fields:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'principal' => array(
			'acting_user_id'     => 0,
			'effective_agent_id' => 'smoke-agent',
			'auth_source'        => 'system',
			'request_context'    => 'runtime',
		),
	)
);
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), 'a system principal in array shape engages the gate', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'auth_source'    => 'runtime',
		'acting_user_id' => 0,
	)
);
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), 'flat auth_source/acting_user_id context fields engage the gate', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'auth_source'    => 'user',
		'acting_user_id' => 7,
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-post', $resolved ), 'flat user fields keep write tools', $failures, $passes );

echo "\n[8] Safe fallback: a context with no principal information is treated as autonomous:\n";
$resolved = $resolver->resolve( $tools, array( 'mode' => 'pipeline' ) );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), 'a context with no principal falls back to autonomous (gate engages, safe)', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/read-meta', $resolved ), 'read tool survives in the no-principal fallback', $failures, $passes );

echo "\n[9] Explicit opt-in paths restore write tools for an autonomous principal:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'principal'  => $autonomous_runtime,
		'allow_only' => array( 'host/write-post' ),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-post', $resolved ), 'allow_only opts a write tool back in', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'principal'          => $autonomous_runtime,
		'runtime_categories' => array( 'publishing' ),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/publish-post', $resolved ), 'runtime_categories opts a write-category tool back in', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'principal'   => $autonomous_runtime,
		'tool_policy' => array(
			'mode'  => 'allow',
			'tools' => array( 'host/write-post' ),
		),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-post', $resolved ), 'ALLOW-mode policy opts a write tool back in', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'principal'      => $autonomous_runtime,
		'runtime_tools'  => array( 'host/flagged-write' ),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/flagged-write', $resolved ), 'runtime_tools opts a write-flag tool back in', $failures, $passes );

echo "\n[10] Mandatory tools are preserved regardless of write classification:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'principal'   => $autonomous_runtime,
		'tool_policy' => array(
			'mandatory_tools' => array( 'host/publish-post' ),
		),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/publish-post', $resolved ), 'mandatory_tools preserves a write tool even for an autonomous principal', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/mandatory-fetch', $resolved ), 'declared-mandatory flag tool is preserved', $failures, $passes );

echo "\n[11] allow_write_tools escape hatch disables the curation gate:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'principal'          => $autonomous_runtime,
		'allow_write_tools'  => true,
	)
);
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals(
	array( 'host/flagged-mutate', 'host/flagged-write', 'host/mandatory-fetch', 'host/publish-post', 'host/read-meta', 'host/write-post' ),
	$resolved_names,
	'context allow_write_tools flag restores ambient write tools for an autonomous principal',
	$failures,
	$passes
);

$resolved = $resolver->resolve(
	$tools,
	array(
		'principal'    => $autonomous_runtime,
		'agent_config' => array(
			'tool_policy' => array(
				'allow_write_tools' => true,
			),
		),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-post', $resolved ), 'policy allow_write_tools flag restores ambient write tools', $failures, $passes );

echo "\n[12] agents_api_write_capable_categories filter extends the classification set:\n";
add_filter(
	'agents_api_write_capable_categories',
	static function ( array $categories ): array {
		$categories[] = 'sensitive';
		return $categories;
	}
);
$sensitive_tools = array(
	'host/sensitive-op' => array(
		'name'       => 'host/sensitive-op',
		'categories' => array( 'sensitive' ),
		'modes'      => array( 'chat', 'pipeline' ),
	),
);
$resolved = $resolver->resolve( $sensitive_tools, array( 'principal' => $autonomous_runtime ) );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/sensitive-op', $resolved ), 'filtered-in write-capable category is gated for an autonomous principal', $failures, $passes );

$resolved = $resolver->resolve( $sensitive_tools, array( 'principal' => $interactive_user ) );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/sensitive-op', $resolved ), 'filtered-in category is still visible for a user principal', $failures, $passes );

echo "\n[13] agents_api_resolved_tools final filter still runs after the write gate:\n";
add_filter(
	'agents_api_resolved_tools',
	static function ( array $resolved_tools ) {
		$resolved_tools['host/filter-injected'] = array( 'name' => 'host/filter-injected' );
		return $resolved_tools;
	}
);
$resolved = $resolver->resolve( array(), array( 'principal' => $autonomous_runtime ) );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/filter-injected', $resolved ), 'resolved_tools final filter runs after write gate', $failures, $passes );

agents_api_smoke_finish( 'Write-capable default', $failures, $passes );
