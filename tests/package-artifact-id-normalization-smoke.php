<?php
/**
 * Pure-PHP smoke test asserting uniform package artifact-id normalization.
 *
 * Artifact-id / source-path normalization used to be duplicated across four
 * sites with three incompatible rule-sets, and the adoption orchestrator's
 * copy validated nothing at all -- a path-traversal seam (CWE-22/-20). This
 * test pins every seam to one shared rule: reject absolute (leading-slash) and
 * parent-directory-traversal identifiers, treat `..` as dangerous only as a
 * whole path segment (so `a..b` is a legitimate name), and never regress
 * ordinary package-local ids.
 *
 * Run with: php tests/package-artifact-id-normalization-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-package-artifact-id-normalization-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

/**
 * Runs a normalizer and reports whether it accepted or rejected the input.
 *
 * @param callable $fn    Normalizer under test.
 * @param string   $input Raw identifier or source path.
 * @return string 'rejected' or 'accepted:<normalized value>'.
 */
function agents_api_probe_normalizer( callable $fn, string $input ): string {
	try {
		return 'accepted:' . $fn( $input );
	} catch ( InvalidArgumentException $e ) {
		return 'rejected';
	}
}

// Identifier seams. The orchestrator copy is private, so reach it by reflection
// to prove the previously-unvalidated artifact_key path is now closed.
$orchestrator_id = static function ( string $id ): string {
	$method = new ReflectionMethod( 'WP_Agent_Package_Adoption_Orchestrator', 'artifact_id' );
	$method->setAccessible( true );
	return (string) $method->invoke( null, array( 'artifact_id' => $id ) );
};

$planner_id = static function ( string $id ): string {
	$method = new ReflectionMethod( 'WP_Agent_Package_Update_Planner', 'normalize_artifact_id' );
	$method->setAccessible( true );
	return (string) $method->invoke( null, $id );
};

$installed_id = static function ( string $id ): string {
	$artifact = new WP_Agent_Package_Installed_Artifact(
		array(
			'package_slug'    => 'demo-package',
			'package_version' => '1.0.0',
			'artifact_type'   => 'example/prompt',
			'artifact_id'     => $id,
			'source'          => 'prompts/ok.md',
			'installed_at'    => '2026-05-25T00:00:00Z',
			'updated_at'      => '2026-05-25T00:00:00Z',
		)
	);
	return $artifact->get_artifact_id();
};

$shared_id = static function ( string $id ): string {
	return WP_Agent_Package_Artifact_Identity::normalize_id( $id );
};

$id_normalizers = array(
	'orchestrator' => $orchestrator_id,
	'planner'      => $planner_id,
	'installed'    => $installed_id,
	'shared'       => $shared_id,
);

// Source seams share the traversal/absolute rule but allow an empty value and
// guard against drive-letter anchors.
$artifact_source = static function ( string $source ): string {
	$artifact = new WP_Agent_Package_Artifact(
		array(
			'type'   => 'example/prompt',
			'slug'   => 'demo',
			'source' => $source,
		)
	);
	return $artifact->get_source();
};

$shared_source = static function ( string $source ): string {
	return WP_Agent_Package_Artifact_Identity::normalize_source( $source );
};

$source_normalizers = array(
	'artifact-source' => $artifact_source,
	'shared-source'   => $shared_source,
);

echo "\n[1] Traversal and absolute identifiers are rejected at every seam:\n";
$traversal_inputs = array( '../x', '/abs', 'a/../b', '..\\x', 'a\\..\\b' );
foreach ( $traversal_inputs as $input ) {
	foreach ( $id_normalizers as $label => $fn ) {
		agents_api_smoke_assert_equals(
			'rejected',
			agents_api_probe_normalizer( $fn, $input ),
			sprintf( '%s rejects traversal/absolute id %s', $label, var_export( $input, true ) ),
			$failures,
			$passes
		);
	}
	foreach ( $source_normalizers as $label => $fn ) {
		agents_api_smoke_assert_equals(
			'rejected',
			agents_api_probe_normalizer( $fn, $input ),
			sprintf( '%s rejects traversal/absolute source %s', $label, var_export( $input, true ) ),
			$failures,
			$passes
		);
	}
}

echo "\n[2] The `a..b` segment edge is accepted consistently (not treated as traversal):\n";
foreach ( $id_normalizers as $label => $fn ) {
	agents_api_smoke_assert_equals(
		'accepted:a..b',
		agents_api_probe_normalizer( $fn, 'a..b' ),
		sprintf( '%s accepts non-traversal a..b unchanged', $label ),
		$failures,
		$passes
	);
}
foreach ( $source_normalizers as $label => $fn ) {
	agents_api_smoke_assert_equals(
		'accepted:a..b',
		agents_api_probe_normalizer( $fn, 'a..b' ),
		sprintf( '%s accepts non-traversal a..b source unchanged', $label ),
		$failures,
		$passes
	);
}

echo "\n[3] Legitimate package-local identifiers still pass unchanged:\n";
$legit_inputs = array( 'foo', 'foo/bar', 'foo-bar_baz', 'memory/agent/SOUL.md' );
foreach ( $legit_inputs as $input ) {
	foreach ( $id_normalizers as $label => $fn ) {
		agents_api_smoke_assert_equals(
			'accepted:' . $input,
			agents_api_probe_normalizer( $fn, $input ),
			sprintf( '%s preserves legitimate id %s', $label, var_export( $input, true ) ),
			$failures,
			$passes
		);
	}
	foreach ( $source_normalizers as $label => $fn ) {
		agents_api_smoke_assert_equals(
			'accepted:' . $input,
			agents_api_probe_normalizer( $fn, $input ),
			sprintf( '%s preserves legitimate source %s', $label, var_export( $input, true ) ),
			$failures,
			$passes
		);
	}
}

echo "\n[4] Backslash separators normalize before the segment check:\n";
agents_api_smoke_assert_equals(
	'accepted:foo/bar',
	agents_api_probe_normalizer( $shared_id, 'foo\\bar' ),
	'shared normalizer converts backslashes to forward slashes',
	$failures,
	$passes
);

echo "\n[5] Empty source is allowed; empty id is rejected:\n";
agents_api_smoke_assert_equals(
	'accepted:',
	agents_api_probe_normalizer( $shared_source, '' ),
	'empty source normalizes to an empty string',
	$failures,
	$passes
);
agents_api_smoke_assert_equals(
	'rejected',
	agents_api_probe_normalizer( $shared_id, '' ),
	'empty id is rejected',
	$failures,
	$passes
);

agents_api_smoke_finish( 'Agents API package artifact-id normalization', $failures, $passes );
