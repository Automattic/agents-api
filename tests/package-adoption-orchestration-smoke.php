<?php
/**
 * Pure-PHP smoke test for package adoption orchestration contracts.
 *
 * Run with: php tests/package-adoption-orchestration-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

$failures = array();
$passes   = 0;

echo "agents-api-package-adoption-orchestration-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

final class Agents_API_Test_Package_State_Store implements WP_Agent_Package_Artifact_State_Store {
	/** @var array<int,array<string,mixed>|WP_Agent_Package_Installed_Artifact> */
	private array $installed;
	/** @var array<int,array<string,mixed>> */
	private array $current;
	/** @var array<int,array<string,mixed>> */
	private array $target;
	/** @var array<int,WP_Agent_Package_Installed_Artifact> */
	public array $recorded = array();

	public function __construct( array $installed, array $current, array $target ) {
		$this->installed = $installed;
		$this->current   = $current;
		$this->target    = $target;
	}

	public function get_installed_artifacts( WP_Agent_Package $package, array $context = array() ): array {
		unset( $package, $context );
		return $this->installed;
	}

	public function get_current_artifacts( WP_Agent_Package $package, array $context = array() ): array {
		unset( $package, $context );
		return $this->current;
	}

	public function get_target_artifacts( WP_Agent_Package $package, array $context = array() ): array {
		unset( $package, $context );
		return $this->target;
	}

	public function record_installed_artifacts( WP_Agent_Package $package, array $artifacts, array $context = array() ): bool {
		unset( $package, $context );
		$this->recorded = $artifacts;
		return true;
	}
}

add_action(
	'wp_agent_package_artifacts_init',
	static function (): void {
		wp_register_agent_package_artifact_type(
			'example/prompt',
			array(
				'import_callback' => static function ( WP_Agent_Package_Artifact $artifact, array $context ): array {
					$GLOBALS['__agents_api_adoption_imports'][] = array(
						'artifact' => $artifact->get_slug(),
						'body'     => $context['target']['payload']['body'] ?? null,
					);

					return array( 'imported' => $artifact->get_slug() );
				},
			)
		);
		wp_register_agent_package_artifact_type(
			'example/error-import',
			array(
				'import_callback' => static function ( WP_Agent_Package_Artifact $artifact, array $context ): WP_Error {
					unset( $artifact, $context );
					$GLOBALS['__agents_api_error_import_called'] = true;
					return new WP_Error( 'import_failed', 'Import rejected the payload.' );
				},
			)
		);
		wp_register_agent_package_artifact_type(
			'example/no-import',
			array(
				'label' => 'No import callback',
			)
		);
		wp_register_agent_package_artifact_type(
			'example/void-import',
			array(
				'import_callback' => static function ( WP_Agent_Package_Artifact $artifact, array $context ): void {
					unset( $context );
					$GLOBALS['__agents_api_void_imports'][] = $artifact->get_slug();
				},
			)
		);
	}
);
do_action( 'init' );

$package = WP_Agent_Package::from_array(
	array(
		'slug'      => 'demo-package',
		'version'   => '1.1.0',
		'agent'     => array(
			'slug'  => 'demo-agent',
			'label' => 'Demo Agent',
		),
		'artifacts' => array(
			array( 'type' => 'example/prompt', 'slug' => 'clean-update', 'source' => 'prompts/clean.md' ),
			array( 'type' => 'example/prompt', 'slug' => 'local-edit', 'source' => 'prompts/local.md' ),
			array( 'type' => 'example/prompt', 'slug' => 'brand-new', 'source' => 'prompts/new.md' ),
			array( 'type' => 'example/prompt', 'slug' => 'untracked-local', 'source' => 'prompts/untracked.md' ),
		),
	)
);

$hash_base   = WP_Agent_Package_Artifact_Hasher::hash( array( 'body' => 'base' ) );
$hash_old    = WP_Agent_Package_Artifact_Hasher::hash( array( 'body' => 'old' ) );
$hash_local  = WP_Agent_Package_Artifact_Hasher::hash( array( 'body' => 'local' ) );
$hash_target = WP_Agent_Package_Artifact_Hasher::hash( array( 'body' => 'already-target' ) );

$installed = array(
	array(
		'package_slug'    => 'demo-package',
		'package_version' => '1.0.0',
		'artifact_type'   => 'example/prompt',
		'artifact_id'     => 'clean-update',
		'source'          => 'prompts/clean.md',
		'installed_hash'  => $hash_old,
		'current_hash'    => $hash_old,
		'installed_at'    => '2026-05-25T00:00:00Z',
		'updated_at'      => '2026-05-25T00:00:00Z',
	),
	array(
		'package_slug'    => 'demo-package',
		'package_version' => '1.0.0',
		'artifact_type'   => 'example/prompt',
		'artifact_id'     => 'local-edit',
		'source'          => 'prompts/local.md',
		'installed_hash'  => $hash_base,
		'current_hash'    => $hash_local,
		'installed_at'    => '2026-05-25T00:00:00Z',
		'updated_at'      => '2026-05-25T00:00:00Z',
	),
);

$current = array(
	array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'clean-update', 'source' => 'prompts/clean.md', 'payload' => array( 'body' => 'old' ) ),
	array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'local-edit', 'source' => 'prompts/local.md', 'payload' => array( 'body' => 'local' ) ),
	array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'untracked-local', 'source' => 'prompts/untracked.md', 'payload' => array( 'body' => 'local-only' ) ),
	array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'already-target', 'source' => 'prompts/already.md', 'payload' => array( 'body' => 'already-target' ), 'hash' => $hash_target ),
);

$target = array(
	array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'clean-update', 'source' => 'prompts/clean.md', 'payload' => array( 'body' => 'new' ) ),
	array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'local-edit', 'source' => 'prompts/local.md', 'payload' => array( 'body' => 'remote' ) ),
	array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'brand-new', 'source' => 'prompts/new.md', 'payload' => array( 'body' => 'new' ) ),
	array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'untracked-local', 'source' => 'prompts/untracked.md', 'payload' => array( 'body' => 'remote' ) ),
	array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'already-target', 'source' => 'prompts/already.md', 'payload' => array( 'body' => 'already-target' ), 'hash' => $hash_target ),
);

$store        = new Agents_API_Test_Package_State_Store( $installed, $current, $target );
$orchestrator = new WP_Agent_Package_Adoption_Orchestrator( $store );

echo "\n[1] Orchestrator plans through the shared package planner:\n";
$plan = $orchestrator->plan( $package, array( 'timestamp' => '2026-05-25T01:00:00Z' ) );
agents_api_smoke_assert_equals( 2, count( $plan->get_bucket( 'auto_apply' ) ), 'auto-apply bucket includes clean and new artifacts', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $plan->get_bucket( 'needs_approval' ) ), 'needs-approval bucket includes local edits', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $plan->get_bucket( 'no_op' ) ), 'no-op bucket includes already matching artifact', $failures, $passes );

echo "\n[2] Dry run returns a plan without applying artifacts:\n";
$dry_run = $orchestrator->adopt(
	new WP_Agent_Package_Adoption_Request(
		$package,
		array(
			'operation' => 'dry-run',
			'dry_run'   => true,
			'context'   => array( 'timestamp' => '2026-05-25T01:00:00Z' ),
		)
	)
);
agents_api_smoke_assert_equals( 'planned', $dry_run->get_status(), 'dry run reports planned status', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $GLOBALS['__agents_api_adoption_imports'] ?? array() ), 'dry run does not import artifacts', $failures, $passes );

echo "\n[3] Adoption applies auto-apply plus approved artifacts only:\n";
$result = $orchestrator->adopt(
	new WP_Agent_Package_Adoption_Request(
		$package,
		array(
			'operation'              => 'upgrade',
			'approved_artifact_keys' => array( 'example/prompt:local-edit' ),
			'context'                => array( 'timestamp' => '2026-05-25T01:00:00Z' ),
		)
	)
);

agents_api_smoke_assert_equals( 'partial', $result->get_status(), 'unapproved local artifact keeps result partial', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $result->get_applied_artifacts() ), 'three artifacts applied', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $result->get_skipped_artifacts() ), 'unapproved and no-op artifacts are skipped', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $result->get_failed_artifacts() ), 'no artifacts failed', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $store->recorded ), 'installed snapshots recorded for applied artifacts', $failures, $passes );

$recorded_payloads = array();
foreach ( $store->recorded as $recorded_artifact ) {
	$recorded_payloads[ $recorded_artifact->get_artifact_id() ] = $recorded_artifact->get_installed_payload();
}
$imported_slugs = array_column( $GLOBALS['__agents_api_adoption_imports'], 'artifact' );
agents_api_smoke_assert_equals( true, isset( $recorded_payloads['local-edit'] ), 'recorded snapshot preserves approved artifact id', $failures, $passes );
agents_api_smoke_assert_equals( 'remote', $recorded_payloads['local-edit']['body'], 'recorded snapshot captures target payload', $failures, $passes );
agents_api_smoke_assert_equals( true, in_array( 'local-edit', $imported_slugs, true ), 'import callback receives approved artifact', $failures, $passes );

echo "\n[4] Missing and failed imports are rejected without breaking void callbacks:\n";

$failing_package = WP_Agent_Package::from_array(
	array(
		'slug'      => 'failing-package',
		'version'   => '1.0.0',
		'agent'     => array(
			'slug'  => 'failing-agent',
			'label' => 'Failing Agent',
		),
		'artifacts' => array(
			array( 'type' => 'example/error-import', 'slug' => 'error-artifact', 'source' => 'artifacts/error.md' ),
			array( 'type' => 'example/no-import', 'slug' => 'null-artifact', 'source' => 'artifacts/null.md' ),
			array( 'type' => 'example/void-import', 'slug' => 'void-artifact', 'source' => 'artifacts/void.md' ),
		),
	)
);

$failing_target = array(
	array( 'artifact_type' => 'example/error-import', 'artifact_id' => 'error-artifact', 'source' => 'artifacts/error.md', 'payload' => array( 'body' => 'new' ) ),
	array( 'artifact_type' => 'example/no-import', 'artifact_id' => 'null-artifact', 'source' => 'artifacts/null.md', 'payload' => array( 'body' => 'new' ) ),
	array( 'artifact_type' => 'example/void-import', 'artifact_id' => 'void-artifact', 'source' => 'artifacts/void.md', 'payload' => array( 'body' => 'new' ) ),
);

$failing_store        = new Agents_API_Test_Package_State_Store( array(), array(), $failing_target );
$failing_orchestrator = new WP_Agent_Package_Adoption_Orchestrator( $failing_store );

$failing_result = $failing_orchestrator->adopt(
	new WP_Agent_Package_Adoption_Request(
		$failing_package,
		array(
			'operation' => 'upgrade',
			'context'   => array( 'timestamp' => '2026-05-25T01:00:00Z' ),
		)
	)
);

$failed_ids = array_column( $failing_result->get_failed_artifacts(), 'artifact_id' );
agents_api_smoke_assert_equals( 'partial', $failing_result->get_status(), 'mixed import outcomes produce a partial status', $failures, $passes );
agents_api_smoke_assert_equals( true, $GLOBALS['__agents_api_error_import_called'] ?? false, 'WP_Error import callback is invoked', $failures, $passes );
agents_api_smoke_assert_equals( array( 'error-artifact', 'null-artifact' ), $failed_ids, 'WP_Error and missing callbacks are marked failed', $failures, $passes );
agents_api_smoke_assert_equals( array( 'void-artifact' ), $GLOBALS['__agents_api_void_imports'] ?? array(), 'void import callback is invoked successfully', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $failing_result->get_applied_artifacts() ), 'void callback artifact is recorded as applied', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $failing_store->recorded ), 'only the successful void callback snapshot is recorded', $failures, $passes );
agents_api_smoke_assert_equals( 'void-artifact', $failing_store->recorded[0]->get_artifact_id(), 'failed import snapshots are excluded', $failures, $passes );

agents_api_smoke_finish( 'Agents API package adoption orchestration', $failures, $passes );
