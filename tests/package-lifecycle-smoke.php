<?php
/**
 * Pure-PHP smoke test for package lifecycle primitives.
 *
 * Run with: php tests/package-lifecycle-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-package-lifecycle-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Artifact hashes are deterministic for JSON-friendly payloads:\n";
$hash_a = WP_Agent_Package_Artifact_Hasher::hash(
	array(
		'b' => array( 'two', 'one' ),
		'a' => array(
			'z' => true,
			'y' => 2,
		),
	)
);
$hash_b = WP_Agent_Package_Artifact_Hasher::hash(
	array(
		'a' => array(
			'y' => 2,
			'z' => true,
		),
		'b' => array( 'two', 'one' ),
	)
);
$hash_c = WP_Agent_Package_Artifact_Hasher::hash(
	array(
		'a' => array(
			'y' => 2,
			'z' => true,
		),
		'b' => array( 'one', 'two' ),
	)
);
agents_api_smoke_assert_equals( $hash_a, $hash_b, 'associative key order does not change hash', $failures, $passes );
agents_api_smoke_assert_equals( false, hash_equals( $hash_a, $hash_c ), 'list order remains hash-significant', $failures, $passes );

echo "\n[2] Installed artifact snapshots export drift status:\n";
$package  = WP_Agent_Package::from_array(
	array(
		'slug'    => 'demo-package',
		'version' => '1.2.3',
		'agent'   => array(
			'slug'  => 'demo-agent',
			'label' => 'Demo Agent',
		),
	)
);
$artifact = WP_Agent_Package_Artifact::from_array(
	array(
		'type'   => 'example/prompt',
		'slug'   => 'welcome',
		'source' => 'prompts/welcome.md',
	)
);
$snapshot = WP_Agent_Package_Installed_Artifact::from_installed_payload( $package, $artifact, array( 'prompt' => 'Hello' ), '2026-05-25T00:00:00Z' );
$modified = $snapshot->with_current_payload( array( 'prompt' => 'Hello there' ), '2026-05-25T00:01:00Z' );
$missing  = $snapshot->with_current_payload( null, '2026-05-25T00:02:00Z' );

agents_api_smoke_assert_equals( WP_Agent_Package_Artifact_Status::CLEAN, $snapshot->get_status(), 'installed snapshot starts clean', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Package_Artifact_Status::MODIFIED, $modified->get_status(), 'changed current payload is modified', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Package_Artifact_Status::MISSING, $missing->get_status(), 'missing current payload is missing', $failures, $passes );
agents_api_smoke_assert_equals( 'prompts/welcome.md', $snapshot->to_array()['source'], 'installed snapshot preserves package-relative source', $failures, $passes );

$path_id_snapshot = WP_Agent_Package_Installed_Artifact::from_array(
	array(
		'package_slug'    => 'demo-package',
		'package_version' => '1.2.3',
		'artifact_type'   => 'example/prompt',
		'artifact_id'     => 'memory/agent/SOUL.md',
		'source'          => 'memory/agent/SOUL.md',
		'installed_hash'  => $hash_a,
		'current_hash'    => $hash_a,
		'installed_at'    => '2026-05-25T00:00:00Z',
		'updated_at'      => '2026-05-25T00:00:00Z',
	)
);
agents_api_smoke_assert_equals( 'memory/agent/SOUL.md', $path_id_snapshot->to_array()['artifact_id'], 'installed artifact IDs preserve package-relative paths', $failures, $passes );

echo "\n[3] Update planner buckets artifacts without mutating storage:\n";
$installed = array(
	array_merge(
		$snapshot->to_array(),
		array(
			'artifact_type' => 'example/prompt',
			'artifact_id'   => 'clean-update',
			'source'        => 'prompts/clean-update.md',
			'installed_hash' => WP_Agent_Package_Artifact_Hasher::hash( array( 'body' => 'old' ) ),
			'current_hash'   => WP_Agent_Package_Artifact_Hasher::hash( array( 'body' => 'old' ) ),
		)
	),
	array_merge(
		$snapshot->to_array(),
		array(
			'artifact_type' => 'example/prompt',
			'artifact_id'   => 'local-edit',
			'source'        => 'prompts/local-edit.md',
			'installed_hash' => WP_Agent_Package_Artifact_Hasher::hash( array( 'body' => 'base' ) ),
			'current_hash'   => WP_Agent_Package_Artifact_Hasher::hash( array( 'body' => 'local' ) ),
		)
	),
	array_merge(
		$snapshot->to_array(),
		array(
			'artifact_type' => 'example/prompt',
			'artifact_id'   => 'removed-upstream',
			'source'        => 'prompts/removed-upstream.md',
		)
	),
);
$current = array(
	array(
		'artifact_type' => 'example/prompt',
		'artifact_id'   => 'clean-update',
		'source'        => 'prompts/clean-update.md',
		'payload'       => array( 'body' => 'old' ),
	),
	array(
		'artifact_type' => 'example/prompt',
		'artifact_id'   => 'local-edit',
		'source'        => 'prompts/local-edit.md',
		'payload'       => array(
			'body'  => 'local',
			'token' => 'secret-value',
		),
	),
	array(
		'artifact_type' => 'example/prompt',
		'artifact_id'   => 'untracked-local',
		'source'        => 'prompts/untracked-local.md',
		'payload'       => array( 'body' => 'local-only' ),
	),
);
$target = array(
	array(
		'artifact_type' => 'example/prompt',
		'artifact_id'   => 'clean-update',
		'source'        => 'prompts/clean-update.md',
		'payload'       => array( 'body' => 'new' ),
	),
	array(
		'artifact_type' => 'example/prompt',
		'artifact_id'   => 'local-edit',
		'source'        => 'prompts/local-edit.md',
		'payload'       => array( 'body' => 'remote' ),
	),
	array(
		'artifact_type' => 'example/prompt',
		'artifact_id'   => 'untracked-local',
		'source'        => 'prompts/untracked-local.md',
		'payload'       => array( 'body' => 'remote' ),
	),
	array(
		'artifact_type' => 'example/prompt',
		'artifact_id'   => 'brand-new',
		'source'        => 'prompts/brand-new.md',
		'payload'       => array( 'body' => 'new' ),
	),
);

$plan    = WP_Agent_Package_Update_Planner::plan( $installed, $current, $target, array( 'package_slug' => 'demo-package' ) );
$buckets = $plan->get_buckets();

agents_api_smoke_assert_equals( 2, count( $buckets['auto_apply'] ), 'new and clean installed artifacts auto-apply', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $buckets['needs_approval'] ), 'local edits and untracked local artifacts need approval', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $buckets['warnings'] ), 'orphaned installed artifacts warn', $failures, $passes );
agents_api_smoke_assert_equals( true, $plan->needs_approval(), 'plan reports approval needed', $failures, $passes );
agents_api_smoke_assert_equals( '[redacted]', $buckets['needs_approval'][0]['diff']['before']['token'], 'diff redacts secret-like keys', $failures, $passes );

echo "\n[4] Adoption diffs can carry bucketed artifact plans:\n";
$diff = new WP_Agent_Package_Adoption_Diff( 'needs-update', array(), array( 'Review local edits.' ), $plan );
$data = $diff->to_array();
agents_api_smoke_assert_equals( 'needs-update', $data['status'], 'adoption diff status remains available', $failures, $passes );
agents_api_smoke_assert_equals( true, isset( $data['artifact_plan']['buckets']['needs_approval'] ), 'adoption diff exports artifact plan buckets', $failures, $passes );

echo "\n[5] Artifact lifecycle callbacks dispatch through registered types:\n";
add_action(
	'wp_agent_package_artifacts_init',
	static function (): void {
		wp_register_agent_package_artifact_type(
			'example/prompt',
			array(
				'validate_callback' => static function ( WP_Agent_Package_Artifact $artifact, array $context ): array {
					return array( $artifact->get_slug(), $context['phase'] ?? '' );
				},
			)
		);
	}
);
do_action( 'init' );
$callback_result = WP_Agent_Package_Artifact_Callbacks::validate( $artifact, array( 'phase' => 'install' ) );
agents_api_smoke_assert_equals( array( 'welcome', 'install' ), $callback_result, 'validate callback receives artifact and context', $failures, $passes );

agents_api_smoke_finish( 'Agents API package lifecycle', $failures, $passes );
