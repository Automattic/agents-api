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

agents_api_smoke_finish( 'Agents API guideline substrate', $failures, $passes );
