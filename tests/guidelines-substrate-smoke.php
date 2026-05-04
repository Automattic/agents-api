<?php
/**
 * Pure-PHP smoke test for the shared guideline substrate polyfill.
 *
 * Run with: php tests/guidelines-substrate-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-guidelines-substrate-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Guideline substrate registers compatible storage primitives:\n";
do_action( 'init' );

$post_type_args = $GLOBALS['__agents_api_smoke_post_types']['wp_guideline'] ?? array();
$taxonomy_entry = $GLOBALS['__agents_api_smoke_taxonomies']['wp_guideline_type'] ?? array();
$taxonomy_args  = $taxonomy_entry['args'] ?? array();

agents_api_smoke_assert_equals( true, post_type_exists( 'wp_guideline' ), 'wp_guideline post type exists', $failures, $passes );
agents_api_smoke_assert_equals( false, $post_type_args['public'] ?? null, 'wp_guideline is non-public', $failures, $passes );
agents_api_smoke_assert_equals( true, $post_type_args['show_in_rest'] ?? null, 'wp_guideline is REST-visible', $failures, $passes );
agents_api_smoke_assert_equals( 'guidelines', $post_type_args['rest_base'] ?? null, 'wp_guideline uses shared REST base', $failures, $passes );
agents_api_smoke_assert_equals( 'guideline', $post_type_args['capability_type'] ?? null, 'wp_guideline uses guideline capability type', $failures, $passes );
agents_api_smoke_assert_equals( 'read_workspace_guidelines', $post_type_args['capabilities']['read'] ?? null, 'guidelines use explicit read capability', $failures, $passes );
agents_api_smoke_assert_equals( 'edit_workspace_guidelines', $post_type_args['capabilities']['edit_posts'] ?? null, 'guidelines use explicit edit capability', $failures, $passes );
agents_api_smoke_assert_equals( 'read_workspace_guidelines', $post_type_args['capabilities']['read_private_posts'] ?? null, 'private core post reads do not grant guideline reads', $failures, $passes );
agents_api_smoke_assert_equals( true, taxonomy_exists( 'wp_guideline_type' ), 'wp_guideline_type taxonomy exists', $failures, $passes );
agents_api_smoke_assert_equals( 'wp_guideline', $taxonomy_entry['object_type'] ?? null, 'taxonomy is attached to wp_guideline', $failures, $passes );
agents_api_smoke_assert_equals( true, $taxonomy_args['hierarchical'] ?? null, 'guideline type taxonomy is hierarchical', $failures, $passes );
agents_api_smoke_assert_equals( true, $taxonomy_args['show_in_rest'] ?? null, 'guideline type taxonomy is REST-visible', $failures, $passes );

echo "\n[2] Guideline type helper and default term behavior are available:\n";
$types = wp_guideline_types();
agents_api_smoke_assert_equals( 'Artifact', $types['artifact']['title'] ?? null, 'artifact guideline type is declared', $failures, $passes );
agents_api_smoke_assert_equals( 'Content', $types['content']['title'] ?? null, 'content guideline type is declared', $failures, $passes );

$term_data = apply_filters(
	'wp_insert_term_data',
	array(
		'name' => 'artifact',
		'slug' => 'artifact',
	),
	'wp_guideline_type'
);
agents_api_smoke_assert_equals( 'Artifact', $term_data['name'] ?? null, 'raw artifact slug maps to the guideline type label', $failures, $passes );

do_action( 'save_post_wp_guideline', 123 );
agents_api_smoke_assert_equals( true, (bool) term_exists( 'artifact', 'wp_guideline_type' ), 'saving an untyped guideline creates artifact term', $failures, $passes );

echo "\n[3] Guideline meta capabilities enforce memory and guidance boundaries:\n";

$GLOBALS['__agents_api_smoke_posts'][200] = (object) array(
	'ID'        => 200,
	'post_type' => 'wp_guideline',
);
$GLOBALS['__agents_api_smoke_post_meta'][200] = array(
	'_wp_guideline_scope'        => 'private_user_workspace_memory',
	'_wp_guideline_user_id'      => '7',
	'_wp_guideline_workspace_id' => 'workspace-a',
);

$GLOBALS['__agents_api_smoke_posts'][201] = (object) array(
	'ID'        => 201,
	'post_type' => 'wp_guideline',
);
$GLOBALS['__agents_api_smoke_post_meta'][201] = array(
	'_wp_guideline_scope'        => 'workspace_shared_guidance',
	'_wp_guideline_workspace_id' => 'workspace-a',
);

agents_api_smoke_assert_equals(
	array( 'read' ),
	apply_filters( 'map_meta_cap', array( 'read_private_posts' ), 'read_post', 7, array( 200 ) ),
	'private memory owner can read via explicit owner metadata',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'do_not_allow' ),
	apply_filters( 'map_meta_cap', array( 'read_private_posts' ), 'read_post', 8, array( 200 ) ),
	'private memory non-owner is denied despite core private-post capability input',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'edit_posts' ),
	apply_filters( 'map_meta_cap', array(), 'read_workspace_guidelines', 8, array() ),
	'workspace-shared guidance reads require editorial threshold',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'publish_posts' ),
	apply_filters( 'map_meta_cap', array(), 'edit_workspace_guidelines', 8, array() ),
	'workspace-shared guidance edits require publishing threshold',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'read_workspace_guidelines' ),
	apply_filters( 'map_meta_cap', array(), 'read_post', 8, array( 201 ) ),
	'workspace-shared guideline post reads use explicit guidance capability',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'promote_agent_memory' ),
	apply_filters( 'map_meta_cap', array(), 'promote_agent_memory', 7, array( 200 ) ),
	'private memory owner still needs explicit promotion capability',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'do_not_allow' ),
	apply_filters( 'map_meta_cap', array(), 'promote_agent_memory', 8, array( 200 ) ),
	'private memory non-owner cannot promote memory',
	$failures,
	$passes
);

agents_api_smoke_finish( 'Agents API guideline substrate', $failures, $passes );
