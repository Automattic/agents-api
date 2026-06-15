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

// Resolve the registered post-type / taxonomy configuration from either the
// real WordPress registry (host backend) or the pure-PHP capture globals.
$guideline_real_pt  = function_exists( 'get_post_type_object' ) ? get_post_type_object( 'wp_guideline' ) : null;
$guideline_real_tax = function_exists( 'get_taxonomy' ) ? get_taxonomy( 'wp_guideline_type' ) : null;

if ( is_object( $guideline_real_pt ) ) {
	$post_type_args = array(
		'public'          => $guideline_real_pt->public,
		'show_in_rest'    => $guideline_real_pt->show_in_rest,
		'rest_base'       => $guideline_real_pt->rest_base,
		'capability_type' => $guideline_real_pt->capability_type,
		'capabilities'    => array(
			'read'              => $guideline_real_pt->cap->read ?? null,
			'edit_posts'        => $guideline_real_pt->cap->edit_posts ?? null,
			'read_private_posts' => $guideline_real_pt->cap->read_private_posts ?? null,
		),
	);
} else {
	$post_type_args = $GLOBALS['__agents_api_smoke_post_types']['wp_guideline'] ?? array();
}

if ( is_object( $guideline_real_tax ) ) {
	$taxonomy_entry = array(
		'object_type' => is_array( $guideline_real_tax->object_type ) ? ( $guideline_real_tax->object_type[0] ?? null ) : $guideline_real_tax->object_type,
		'args'        => array(
			'hierarchical' => $guideline_real_tax->hierarchical,
			'show_in_rest' => $guideline_real_tax->show_in_rest,
		),
	);
} else {
	$taxonomy_entry = $GLOBALS['__agents_api_smoke_taxonomies']['wp_guideline_type'] ?? array();
}
$taxonomy_args = $taxonomy_entry['args'] ?? array();

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

// Under real WordPress the meta-cap mapper reads real posts and post meta, so
// create genuine wp_guideline posts; under pure-PHP fall back to the in-memory
// post/meta fixtures. Either way the assertions exercise the real map_meta_cap
// filter.
$guideline_real_storage = function_exists( 'wp_insert_post' ) && function_exists( 'update_post_meta' );

if ( $guideline_real_storage ) {
	$private_guideline_id = (int) wp_insert_post(
		array(
			'post_type'   => 'wp_guideline',
			'post_title'  => 'Private memory guideline',
			'post_status' => 'publish',
		)
	);
	update_post_meta( $private_guideline_id, '_wp_guideline_scope', 'private_user_workspace_memory' );
	update_post_meta( $private_guideline_id, '_wp_guideline_user_id', '7' );
	update_post_meta( $private_guideline_id, '_wp_guideline_workspace_id', 'workspace-a' );

	$shared_guideline_id = (int) wp_insert_post(
		array(
			'post_type'   => 'wp_guideline',
			'post_title'  => 'Shared guidance guideline',
			'post_status' => 'publish',
		)
	);
	update_post_meta( $shared_guideline_id, '_wp_guideline_scope', 'workspace_shared_guidance' );
	update_post_meta( $shared_guideline_id, '_wp_guideline_workspace_id', 'workspace-a' );
} else {
	$private_guideline_id = 200;
	$shared_guideline_id  = 201;

	$GLOBALS['__agents_api_smoke_posts'][ $private_guideline_id ] = (object) array(
		'ID'        => $private_guideline_id,
		'post_type' => 'wp_guideline',
	);
	$GLOBALS['__agents_api_smoke_post_meta'][ $private_guideline_id ] = array(
		'_wp_guideline_scope'        => 'private_user_workspace_memory',
		'_wp_guideline_user_id'      => '7',
		'_wp_guideline_workspace_id' => 'workspace-a',
	);

	$GLOBALS['__agents_api_smoke_posts'][ $shared_guideline_id ] = (object) array(
		'ID'        => $shared_guideline_id,
		'post_type' => 'wp_guideline',
	);
	$GLOBALS['__agents_api_smoke_post_meta'][ $shared_guideline_id ] = array(
		'_wp_guideline_scope'        => 'workspace_shared_guidance',
		'_wp_guideline_workspace_id' => 'workspace-a',
	);
}

agents_api_smoke_assert_equals(
	array( 'read' ),
	apply_filters( 'map_meta_cap', array( 'read_private_posts' ), 'read_post', 7, array( $private_guideline_id ) ),
	'private memory owner can read via explicit owner metadata',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'do_not_allow' ),
	apply_filters( 'map_meta_cap', array( 'read_private_posts' ), 'read_post', 8, array( $private_guideline_id ) ),
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
	apply_filters( 'map_meta_cap', array(), 'read_post', 8, array( $shared_guideline_id ) ),
	'workspace-shared guideline post reads use explicit guidance capability',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'promote_agent_memory' ),
	apply_filters( 'map_meta_cap', array(), 'promote_agent_memory', 7, array( $private_guideline_id ) ),
	'private memory owner still needs explicit promotion capability',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	array( 'do_not_allow' ),
	apply_filters( 'map_meta_cap', array(), 'promote_agent_memory', 8, array( $private_guideline_id ) ),
	'private memory non-owner cannot promote memory',
	$failures,
	$passes
);

if ( $guideline_real_storage ) {
	wp_delete_post( $private_guideline_id, true );
	wp_delete_post( $shared_guideline_id, true );
}

agents_api_smoke_finish( 'Agents API guideline substrate', $failures, $passes );
