<?php
/**
 * Pure-PHP smoke test for the workspace preload package artifact contract.
 *
 * Run with: php tests/workspace-preload-artifact-smoke.php
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

echo "agents-api-workspace-preload-artifact-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();
do_action( 'init' );

echo "\n[1] Core registers the generic workspace preload artifact type:\n";
$type = wp_get_agent_package_artifact_type( 'agent-runtime/workspace-preload' );
agents_api_smoke_assert_equals( true, $type instanceof WP_Agent_Package_Artifact_Type, 'artifact type is registered', $failures, $passes );
agents_api_smoke_assert_equals( 'agent-runtime/workspace-preload/v1', $type->get_meta()['schema'] ?? '', 'artifact type advertises the stable schema', $failures, $passes );

$artifact = new WP_Agent_Package_Artifact(
	array(
		'type'   => 'agent-runtime/workspace-preload',
		'slug'   => 'review-repos',
		'source' => 'extensions/agent-runtime/workspace-preload/review-repos.json',
	)
);

$payload = array(
	'repositories' => array(
		array(
			'name' => 'static-site-importer',
			'url'  => 'https://github.com/chubes4/static-site-importer.git',
		),
		array(
			'name' => 'private-runtime',
			'url'  => 'git@github.a8c.com:Automattic/private-runtime.git',
			'ref'  => 'trunk',
		),
	),
);

echo "\n[2] Validation normalizes repository preload payloads without materializing them:\n";
$validated = WP_Agent_Package_Artifact_Callbacks::validate( $artifact, array( 'target' => array( 'payload' => $payload ) ) );
agents_api_smoke_assert_equals( false, is_wp_error( $validated ), 'valid payload passes validation', $failures, $passes );
agents_api_smoke_assert_equals( 'agent-runtime/workspace-preload', $validated['type'] ?? '', 'validated contract keeps the generic artifact type', $failures, $passes );
agents_api_smoke_assert_equals( 'agent-runtime/workspace-preload/v1', $validated['payload']['schema'] ?? '', 'validated payload carries schema version', $failures, $passes );
agents_api_smoke_assert_equals( 'trunk', $validated['payload']['repositories'][1]['ref'] ?? '', 'repository refs are preserved for runtime materializers', $failures, $passes );

echo "\n[3] Import returns a runtime materialization contract, not a product-specific side effect:\n";
$imported = WP_Agent_Package_Artifact_Callbacks::import( $artifact, array( 'target' => array( 'payload' => $payload ) ) );
agents_api_smoke_assert_equals( 'materialization-contract', $imported['status'] ?? '', 'import status identifies a materialization contract', $failures, $passes );
agents_api_smoke_assert_equals( 'review-repos', $imported['artifact']['slug'] ?? '', 'import contract identifies the artifact slug', $failures, $passes );

echo "\n[4] Invalid repository declarations fail before runtime adoption:\n";
$invalid = WP_Agent_Workspace_Preload_Artifact::normalize_payload(
	array(
		'repositories' => array(
			array(
				'name' => 'Bad Name',
				'url'  => 'file:///tmp/repo',
			),
		),
	)
);
agents_api_smoke_assert_equals( true, is_wp_error( $invalid ), 'invalid repository payload returns WP_Error', $failures, $passes );
agents_api_smoke_assert_equals( 'wp_agent_workspace_preload_repository_name_invalid', $invalid->get_error_code(), 'invalid names are rejected before urls are considered', $failures, $passes );

agents_api_smoke_finish( 'Agents API workspace preload artifact contract', $failures, $passes );
