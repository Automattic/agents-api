<?php
/**
 * Pure-PHP smoke test asserting packages reject duplicate artifact identities.
 *
 * A package must not carry two artifacts that normalize to the same
 * (type, slug) identity. That pair is the canonical key used downstream by
 * the update planner and adoption orchestrator, whose last-wins index maps
 * disagree with artifact_from_entry()'s first-match lookup. Left unchecked,
 * the diff a reviewer approves can describe a different declaration than the
 * one import() resolves. Fail closed at construction to remove the ambiguity.
 *
 * Run with: php tests/package-duplicate-artifact-identity-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-package-duplicate-artifact-identity-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Distinct artifact identities are accepted:\n";
$distinct_ok = false;
try {
	$package = WP_Agent_Package::from_array(
		array(
			'slug'      => 'demo-package',
			'version'   => '1.0.0',
			'agent'     => array(
				'slug'  => 'demo-agent',
				'label' => 'Demo Agent',
			),
			'artifacts' => array(
				array(
					'type'   => 'example/prompt',
					'slug'   => 'welcome',
					'source' => 'prompts/welcome.md',
				),
				array(
					'type'   => 'example/prompt',
					'slug'   => 'farewell',
					'source' => 'prompts/farewell.md',
				),
			),
		)
	);
	$distinct_ok = 2 === count( $package->get_artifacts() );
} catch ( InvalidArgumentException $e ) {
	$distinct_ok = false;
}
agents_api_smoke_assert_equals( true, $distinct_ok, 'distinct (type, slug) artifacts are preserved', $failures, $passes );

echo "\n[2] Duplicate normalized identity is rejected at construction:\n";
$duplicate_rejected = false;
try {
	WP_Agent_Package::from_array(
		array(
			'slug'      => 'demo-package',
			'version'   => '1.0.0',
			'agent'     => array(
				'slug'  => 'demo-agent',
				'label' => 'Demo Agent',
			),
			'artifacts' => array(
				array(
					'type'    => 'example/prompt',
					'slug'    => 'welcome',
					'source'  => 'prompts/welcome-a.md',
					'label'   => 'Declaration A',
				),
				array(
					'type'    => 'example/prompt',
					'slug'    => 'welcome',
					'source'  => 'prompts/welcome-b.md',
					'label'   => 'Declaration B',
				),
			),
		)
	);
} catch ( InvalidArgumentException $e ) {
	$duplicate_rejected = true;
}
agents_api_smoke_assert_equals( true, $duplicate_rejected, 'two artifacts with the same (type, slug) throw', $failures, $passes );

echo "\n[3] Collision after slug normalization is also rejected:\n";
$normalized_collision_rejected = false;
try {
	WP_Agent_Package::from_array(
		array(
			'slug'      => 'demo-package',
			'version'   => '1.0.0',
			'agent'     => array(
				'slug'  => 'demo-agent',
				'label' => 'Demo Agent',
			),
			'artifacts' => array(
				array(
					'type'   => 'example/prompt',
					'slug'   => 'Welcome Note',
					'source' => 'prompts/welcome-a.md',
				),
				array(
					'type'   => 'example/prompt',
					'slug'   => 'welcome-note',
					'source' => 'prompts/welcome-b.md',
				),
			),
		)
	);
} catch ( InvalidArgumentException $e ) {
	$normalized_collision_rejected = true;
}
agents_api_smoke_assert_equals( true, $normalized_collision_rejected, 'slugs that normalize to the same value collide', $failures, $passes );

agents_api_smoke_finish( 'Agents API package duplicate artifact identity', $failures, $passes );
