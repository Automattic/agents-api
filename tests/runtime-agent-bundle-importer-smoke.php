<?php
/**
 * Pure-PHP smoke test for runtime agent bundle imports.
 *
 * Run with: php tests/runtime-agent-bundle-importer-smoke.php
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
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
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

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

$failures = array();
$passes   = 0;

echo "agents-api-runtime-agent-bundle-importer-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

do_action( 'init' );

$bundle = array(
	'bundle_version' => '1.2.3',
	'bundle_slug'    => 'studio-web-static-site-generator',
	'agent'          => array(
		'agent_slug'   => 'studio-web-static-site-generator',
		'agent_name'   => 'Static Site Generator',
		'agent_config' => array(
			'studio_web'         => array( 'prompt' => 'Cook a site.' ),
			'datamachine_bundle' => array(
				'bundle_slug'    => 'studio-web-static-site-generator',
				'bundle_version' => '1.2.3',
			),
		),
	),
);

echo "\n[1] Inline bundle registers a runtime agent:\n";
$result = apply_filters(
	'wp_agent_runtime_import_bundle',
	null,
	array( 'bundle' => $bundle ),
	array( 'on_conflict' => 'upgrade' ),
	0
);

agents_api_smoke_assert_equals( true, is_array( $result ) && true === ( $result['success'] ?? false ), 'inline bundle import succeeds', $failures, $passes );
agents_api_smoke_assert_equals( 'registered', $result['status'] ?? '', 'inline bundle reports registered status', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_agent( 'studio-web-static-site-generator' ), 'runtime agent is registered', $failures, $passes );
agents_api_smoke_assert_equals( 'Cook a site.', wp_get_agent( 'studio-web-static-site-generator' )->get_default_config()['studio_web']['prompt'] ?? null, 'agent default config is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'runtime-agent-bundle', wp_get_agent( 'studio-web-static-site-generator' )->get_meta()['source_type'] ?? null, 'bundle provenance is recorded', $failures, $passes );

echo "\n[2] Conflict policy is enforced:\n";
$skipped = apply_filters( 'wp_agent_runtime_import_bundle', null, array( 'bundle' => $bundle ), array( 'on_conflict' => 'skip' ), 1 );
agents_api_smoke_assert_equals( 'skipped', $skipped['status'] ?? '', 'skip policy leaves existing agent registered', $failures, $passes );

$error = apply_filters( 'wp_agent_runtime_import_bundle', null, array( 'bundle' => $bundle ), array( 'on_conflict' => 'error' ), 2 );
agents_api_smoke_assert_equals( true, is_wp_error( $error ), 'error policy fails when agent exists', $failures, $passes );
agents_api_smoke_assert_equals( 'wp_agent_runtime_bundle_agent_exists', $error instanceof WP_Error ? $error->get_error_code() : '', 'error policy returns stable code', $failures, $passes );

echo "\n[3] Non-agent bundles remain available for other importers:\n";
$unclaimed = apply_filters( 'wp_agent_runtime_import_bundle', null, array( 'bundle' => array( 'not_agent' => true ) ), array(), 3 );
agents_api_smoke_assert_equals( null, $unclaimed, 'non-agent bundle is not claimed', $failures, $passes );

echo "\n[4] Source bundle files and list helper use the generic importer:\n";
$source_bundle = array(
	'bundle_version' => '1.0.0',
	'bundle_slug'    => 'runtime-source-agent',
	'agent'          => array(
		'agent_slug'   => 'runtime-source-agent',
		'agent_name'   => 'Runtime Source Agent',
		'agent_config' => array( 'runtime' => array( 'source' => 'file' ) ),
	),
);
$source_file = tempnam( sys_get_temp_dir(), 'agents-api-runtime-bundle-' );
file_put_contents( $source_file, wp_json_encode( $source_bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
$source_result = apply_filters( 'wp_agent_runtime_import_bundle', null, array( 'source' => $source_file ), array( 'on_conflict' => 'upgrade' ), 4 );
agents_api_smoke_assert_equals( true, is_array( $source_result ) && true === ( $source_result['success'] ?? false ), 'source bundle import succeeds', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_agent( 'runtime-source-agent' ), 'source bundle registered agent', $failures, $passes );

$helper_results = wp_agent_import_runtime_bundles(
	array(
		array( 'bundle' => array(
			'bundle_slug' => 'runtime-helper-agent',
			'agent'       => array(
				'agent_slug'   => 'runtime-helper-agent',
				'agent_name'   => 'Runtime Helper Agent',
				'agent_config' => array( 'runtime' => array( 'source' => 'helper' ) ),
			),
		) ),
		array( 'bundle' => array( 'not_agent' => true ) ),
	),
	array( 'on_conflict' => 'upgrade' )
);
agents_api_smoke_assert_equals( true, $helper_results[0]['success'] ?? false, 'helper import marks accepted bundle successful', $failures, $passes );
agents_api_smoke_assert_equals( 'registered', $helper_results[0]['status'] ?? '', 'helper import preserves registered status', $failures, $passes );
agents_api_smoke_assert_equals( false, $helper_results[1]['success'] ?? true, 'helper import marks unclaimed bundle failed', $failures, $passes );
agents_api_smoke_assert_equals( 'wp_agent_runtime_bundle_unclaimed', $helper_results[1]['error']['code'] ?? '', 'helper import has stable unclaimed error', $failures, $passes );
@unlink( $source_file );

agents_api_smoke_finish( 'Agents API runtime agent bundle importer', $failures, $passes );
