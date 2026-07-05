<?php
/**
 * Pure-PHP smoke test for the safe-by-default write-capable tool policy.
 *
 * The substrate models only the interactive-vs-non-interactive axis. In a
 * non-interactive context (no human in the loop), write-capable tools are
 * opt-in rather than ambient. Interactive contexts are unchanged.
 *
 * The substrate never enumerates consumer automation mode names: a context
 * is non-interactive when it carries no recognized interactive signal
 * (`interactive => true` or a mode in chat/interactive/rest). Consumer mode
 * strings like `pipeline`/`system` are opaque to the substrate and land as
 * non-interactive simply because they are not interactive markers.
 *
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

echo "\n[1] Safe-default contracts are available and the gate is mode-name neutral:\n";
agents_api_smoke_assert_equals( false, defined( 'WP_Agent_Tool_Policy::NON_INTERACTIVE_MODES' ), 'NON_INTERACTIVE_MODES enumeration is gone (substrate is mode-name neutral)', $failures, $passes );
agents_api_smoke_assert_equals( false, defined( 'WP_Agent_Tool_Policy::RUNTIME_PIPELINE' ), 'RUNTIME_PIPELINE consumer-mode constant is gone', $failures, $passes );
agents_api_smoke_assert_equals( false, defined( 'WP_Agent_Tool_Policy::RUNTIME_SYSTEM' ), 'RUNTIME_SYSTEM consumer-mode constant is gone', $failures, $passes );
agents_api_smoke_assert_equals( true, defined( 'WP_Agent_Tool_Policy::RUNTIME_CHAT' ), 'RUNTIME_CHAT interactive vocabulary is retained', $failures, $passes );
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

echo "\n[2] An opaque consumer automation mode (e.g. 'pipeline') is non-interactive, so write-capable tools are gated while read tools survive:\n";
$resolved = $resolver->resolve( $tools, array( 'mode' => 'pipeline' ) );
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals(
	array( 'host/mandatory-fetch', 'host/read-meta' ),
	$resolved_names,
	'an opaque consumer mode string excludes every write-capable tool (category or flag) but keeps read and mandatory tools',
	$failures,
	$passes
);

echo "\n[3] Declared write/mutating flag is gated exactly like category-classified tools:\n";
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), 'write-category tool is excluded in a non-interactive context', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/publish-post', $resolved ), 'publishing-category tool is excluded in a non-interactive context', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/flagged-write', $resolved ), 'declared write-flag tool is excluded in a non-interactive context', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/flagged-mutate', $resolved ), 'declared mutating-flag tool is excluded in a non-interactive context', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/read-meta', $resolved ), 'non-write read tool survives', $failures, $passes );

echo "\n[4] The explicit interactive flag controls the gate, not the mode name:\n";
$resolved = $resolver->resolve( $tools, array( 'mode' => 'pipeline', 'interactive' => true ) );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-post', $resolved ), 'interactive=true overrides an opaque automation mode and keeps write tools', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/flagged-write', $resolved ), 'interactive=true keeps declared write-flag tools', $failures, $passes );

$resolved = $resolver->resolve( $tools, array( 'mode' => 'pipeline', 'interactive' => false ) );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), 'interactive=false keeps the gate engaged for an automation mode', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/read-meta', $resolved ), 'read tools still survive with interactive=false', $failures, $passes );

echo "\n[5] Recognized interactive modes keep every tool regardless of write classification:\n";
$resolved = $resolver->resolve( $tools, array( 'mode' => 'chat' ) );
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals(
	array( 'host/flagged-mutate', 'host/flagged-write', 'host/mandatory-fetch', 'host/publish-post', 'host/read-meta', 'host/write-post' ),
	$resolved_names,
	'chat mode keeps every tool regardless of write classification',
	$failures,
	$passes
);

// 'interactive' and 'rest' are also substrate-recognized interactive markers.
// Use a tool that declares them in its modes list so the mode filter does not
// obscure the write-gate behavior under test.
$interactive_marker_tools = array(
	'host/write-iv' => array(
		'name'       => 'host/write-iv',
		'categories' => array( 'write' ),
		'modes'      => array( 'interactive', 'rest' ),
	),
);
$resolved = $resolver->resolve( $interactive_marker_tools, array( 'mode' => 'interactive' ) );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-iv', $resolved ), "'interactive' mode is a recognized interactive marker (write tool kept)", $failures, $passes );

$resolved = $resolver->resolve( $interactive_marker_tools, array( 'mode' => 'rest' ) );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-iv', $resolved ), "'rest' mode is a recognized interactive marker (write tool kept)", $failures, $passes );

echo "\n[6] Any other opaque consumer mode string lands as non-interactive too:\n";
$resolved = $resolver->resolve( $tools, array( 'mode' => 'system' ) );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), "opaque 'system' mode is treated as non-interactive", $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/read-meta', $resolved ), 'read tool survives in an opaque automation mode', $failures, $passes );

$resolved = $resolver->resolve( $tools, array( 'mode' => 'editor' ) );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/write-post', $resolved ), "an unknown consumer mode like 'editor' is also non-interactive (not in the interactive set)", $failures, $passes );

echo "\n[7] Explicit opt-in paths restore write tools in a non-interactive context:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'       => 'pipeline',
		'allow_only' => array( 'host/write-post' ),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-post', $resolved ), 'allow_only opts a write tool back in', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'        => 'pipeline',
		'runtime_categories' => array( 'publishing' ),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/publish-post', $resolved ), 'runtime_categories opts a write-category tool back in', $failures, $passes );

$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'        => 'pipeline',
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
		'mode'        => 'pipeline',
		'runtime_tools' => array( 'host/flagged-write' ),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/flagged-write', $resolved ), 'runtime_tools opts a write-flag tool back in', $failures, $passes );

echo "\n[8] Mandatory tools are preserved regardless of write classification:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'        => 'pipeline',
		'tool_policy' => array(
			'mandatory_tools' => array( 'host/publish-post' ),
		),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/publish-post', $resolved ), 'mandatory_tools preserves a write tool even in a non-interactive context', $failures, $passes );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/mandatory-fetch', $resolved ), 'declared-mandatory flag tool is preserved', $failures, $passes );

echo "\n[9] allow_write_tools escape hatch disables the safe default:\n";
$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'               => 'pipeline',
		'allow_write_tools'  => true,
	)
);
$resolved_names = array_keys( $resolved );
sort( $resolved_names );
agents_api_smoke_assert_equals(
	array( 'host/flagged-mutate', 'host/flagged-write', 'host/mandatory-fetch', 'host/publish-post', 'host/read-meta', 'host/write-post' ),
	$resolved_names,
	'context allow_write_tools flag restores ambient write tools in a non-interactive context',
	$failures,
	$passes
);

$resolved = $resolver->resolve(
	$tools,
	array(
		'mode'        => 'pipeline',
		'agent_config' => array(
			'tool_policy' => array(
				'allow_write_tools' => true,
			),
		),
	)
);
agents_api_smoke_assert_equals( true, array_key_exists( 'host/write-post', $resolved ), 'policy allow_write_tools flag restores ambient write tools', $failures, $passes );

echo "\n[10] agents_api_write_capable_categories filter extends the classification set:\n";
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
$resolved = $resolver->resolve( $sensitive_tools, array( 'mode' => 'pipeline' ) );
agents_api_smoke_assert_equals( false, array_key_exists( 'host/sensitive-op', $resolved ), 'filtered-in write-capable category is gated in a non-interactive context', $failures, $passes );

$resolved = $resolver->resolve( $sensitive_tools, array( 'mode' => 'chat' ) );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/sensitive-op', $resolved ), 'filtered-in category is still visible in chat mode', $failures, $passes );

echo "\n[11] agents_api_resolved_tools final filter still runs after the write gate:\n";
add_filter(
	'agents_api_resolved_tools',
	static function ( array $resolved_tools ) {
		$resolved_tools['host/filter-injected'] = array( 'name' => 'host/filter-injected' );
		return $resolved_tools;
	}
);
$resolved = $resolver->resolve( array(), array( 'mode' => 'pipeline' ) );
agents_api_smoke_assert_equals( true, array_key_exists( 'host/filter-injected', $resolved ), 'resolved_tools final filter runs after write gate', $failures, $passes );

agents_api_smoke_finish( 'Write-capable default', $failures, $passes );
