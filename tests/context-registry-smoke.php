<?php
/**
 * Pure-PHP smoke test for context and memory registry contracts.
 *
 * Run with: php tests/context-registry-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-context-registry-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

WP_Agent_Memory_Registry::reset();
WP_Agent_Context_Section_Registry::reset();

echo "\n[1] Memory sources normalize generic context metadata:\n";
$workspace_source = WP_Agent_Memory_Registry::register(
	'workspace/instructions',
	array(
		'layer'            => 'workspace',
		'priority'         => 20,
		'protected'        => true,
		'editable'         => 'manage_options',
		'modes'            => array( 'chat', 'pipeline' ),
		'retrieval_policy' => 'always',
		'composable'       => true,
		'context_slug'     => 'workspace-instructions',
		'convention_path'  => '/AGENTS.md',
		'label'            => 'Workspace Instructions',
	)
);

$manual_source = WP_Agent_Memory_Registry::register(
	'user/project-notes',
	array(
		'layer'                      => 'user',
		'priority'                   => 80,
		'modes'                      => array( 'chat' ),
		'retrieval_policy'           => 'manual',
		'external_projection_target' => 'guideline:project-notes',
	)
);

agents_api_smoke_assert_equals( 'workspace', $workspace_source['layer'] ?? null, 'workspace is the generic layer vocabulary', $failures, $passes );
agents_api_smoke_assert_equals( false, $workspace_source['editable'] ?? null, 'composable sources are not hand editable', $failures, $passes );
agents_api_smoke_assert_equals( 'AGENTS.md', $workspace_source['convention_path'] ?? null, 'convention path is metadata, not identity', $failures, $passes );
agents_api_smoke_assert_equals( 'manual', $manual_source['retrieval_policy'] ?? null, 'manual retrieval policy is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'always', 'on_intent', 'on_tool_need', 'manual', 'never' ), WP_Agent_Context_Injection_Policy::values(), 'policy vocabulary covers accepted values', $failures, $passes );

$all_sources = WP_Agent_Memory_Registry::get_all();
agents_api_smoke_assert_equals( array( 'workspace/instructions', 'user/project-notes' ), array_keys( $all_sources ), 'sources sort by priority', $failures, $passes );
agents_api_smoke_assert_equals( array( 'workspace/instructions' ), array_keys( WP_Agent_Memory_Registry::get_by_layer( 'workspace' ) ), 'sources filter by layer', $failures, $passes );
agents_api_smoke_assert_equals( array( 'workspace/instructions' ), array_keys( WP_Agent_Memory_Registry::get_always_injected( 'pipeline' ) ), 'always-injected sources filter by mode and policy', $failures, $passes );
agents_api_smoke_assert_equals( array( 'workspace/instructions' ), array_keys( WP_Agent_Memory_Registry::get_composable() ), 'composable sources are discoverable', $failures, $passes );

echo "\n[2] Context sections compose in priority order and respect modes:\n";
WP_Agent_Context_Section_Registry::register(
	'workspace-instructions',
	'late-section',
	50,
	static function ( array $context, array $section ): string {
		return '# ' . $section['label'] . "\nMode: " . ( $context['mode'] ?? 'none' );
	},
	array(
		'label' => 'Late Section',
		'modes' => array( 'chat' ),
	)
);

WP_Agent_Context_Section_Registry::register(
	'workspace-instructions',
	'early-section',
	10,
	static function (): string {
		return '# Early Section';
	},
	array(
		'modes' => array( 'all' ),
	)
);

$chat_context = WP_Agent_Context_Section_Registry::compose( 'workspace-instructions', array( 'mode' => 'chat' ) );
$pipeline_context = WP_Agent_Context_Section_Registry::compose( 'workspace-instructions', array( 'mode' => 'pipeline' ) );

agents_api_smoke_assert_equals( true, $chat_context instanceof WP_Agent_Composable_Context, 'compose returns value object', $failures, $passes );
agents_api_smoke_assert_equals( "# Early Section\n\n# Late Section\nMode: chat", $chat_context->content, 'sections compose in priority order', $failures, $passes );
agents_api_smoke_assert_equals( array( 'early-section', 'late-section' ), $chat_context->metadata['included_sections'] ?? array(), 'composition records included sections', $failures, $passes );
agents_api_smoke_assert_equals( '# Early Section', $pipeline_context->content, 'mode-specific sections are filtered before compose', $failures, $passes );
agents_api_smoke_assert_equals( true, $chat_context->has_content(), 'value object reports non-empty content', $failures, $passes );

agents_api_smoke_finish( 'Agents API context registry', $failures, $passes );
