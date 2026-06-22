<?php
/**
 * Pure-PHP smoke test for the runtime package execution contract.
 *
 * Run with: php tests/runtime-package-run-contract-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "runtime-package-run-contract-smoke\n";

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once __DIR__ . '/agents-api-smoke-helpers.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-runtime-package-run-request.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-runtime-package-run-result.php';
require_once __DIR__ . '/../src/Runtime/register-runtime-package-run-ability.php';
require_once __DIR__ . '/../src/Abilities/functions-ability-dispatch.php';

use AgentsAPI\AI\WP_Agent_Runtime_Package_Run_Request;
use AgentsAPI\AI\WP_Agent_Runtime_Package_Run_Result;

echo "\n[1] Request validates package and workflow selectors:\n";
$request = WP_Agent_Runtime_Package_Run_Request::from_array(
	array(
		'package'  => array(
			'source' => 'bundles/site-builder',
			'slug'   => 'site-builder',
		),
		'workflow' => array( 'id' => 'build-site' ),
		'input'    => array( 'prompt' => 'Build a site.' ),
		'options'  => array( 'max_turns' => 8 ),
	)
);
agents_api_smoke_assert_equals( true, $request instanceof WP_Agent_Runtime_Package_Run_Request, 'valid request normalizes to value object', $failures, $passes );
agents_api_smoke_assert_equals( 'site-builder', $request instanceof WP_Agent_Runtime_Package_Run_Request ? $request->get_package()['slug'] ?? '' : '', 'package slug is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'Build a site.', $request instanceof WP_Agent_Runtime_Package_Run_Request ? $request->get_input()['prompt'] ?? '' : '', 'runtime input is preserved', $failures, $passes );

$missing_package = WP_Agent_Runtime_Package_Run_Request::from_array( array( 'workflow' => array( 'id' => 'build-site' ) ) );
agents_api_smoke_assert_equals( true, is_wp_error( $missing_package ), 'package is required', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_runtime_package_run_missing_package', is_wp_error( $missing_package ) ? $missing_package->get_error_code() : '', 'missing package error is stable', $failures, $passes );

$missing_workflow = WP_Agent_Runtime_Package_Run_Request::from_array( array( 'package' => array( 'slug' => 'site-builder' ) ) );
agents_api_smoke_assert_equals( true, is_wp_error( $missing_workflow ), 'workflow is required', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_runtime_package_run_missing_workflow', is_wp_error( $missing_workflow ) ? $missing_workflow->get_error_code() : '', 'missing workflow error is stable', $failures, $passes );

echo "\n[2] Result envelope normalizes status, result, and evidence refs:\n";
$result = WP_Agent_Runtime_Package_Run_Result::from_array(
	array(
		'status'        => 'succeeded',
		'run_id'        => 'run-123',
		'result'        => array( 'summary' => 'created' ),
		'evidence_refs' => array(
			array(
				'type'  => 'artifact',
				'label' => 'transcript',
				'url'   => 'https://example.com/artifacts/run-123/transcript.json',
			),
		),
		'metadata'      => array( 'runtime' => 'consumer-owned' ),
	)
);
agents_api_smoke_assert_equals( 'succeeded', $result->get_status(), 'status is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'run-123', $result->get_run_id(), 'run id is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'created', $result->get_result()['summary'] ?? '', 'result output is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'transcript', $result->get_evidence_refs()[0]['label'] ?? '', 'evidence refs are preserved', $failures, $passes );

$default_status = WP_Agent_Runtime_Package_Run_Result::from_array( array( 'status' => 'unknown' ) );
agents_api_smoke_assert_equals( 'succeeded', $default_status->get_status(), 'unknown status defaults to succeeded for legacy arrays', $failures, $passes );

echo "\n[3] Dispatcher requires a handler and normalizes handler output:\n";
$GLOBALS['__runtime_package_handler_called'] = null;
add_filter(
	'wp_agent_runtime_package_run_handler',
	static function ( $handler, WP_Agent_Runtime_Package_Run_Request $handler_request, array $raw_input ) {
		unset( $handler, $raw_input );
		return static function () use ( $handler_request ): WP_Agent_Runtime_Package_Run_Result {
			$GLOBALS['__runtime_package_handler_called'] = $handler_request->to_array();
			return new WP_Agent_Runtime_Package_Run_Result(
				WP_Agent_Runtime_Package_Run_Result::STATUS_SUCCEEDED,
				'run-dispatch',
				array( 'workflow_id' => $handler_request->get_workflow()['id'] ?? '' ),
				array(),
				array( array( 'type' => 'log', 'label' => 'runtime log' ) )
			);
		};
	},
	10,
	3
);

$dispatch = AgentsAPI\AI\agents_runtime_package_run_dispatch(
	array(
		'package'  => array( 'slug' => 'site-builder' ),
		'workflow' => array( 'id' => 'build-site' ),
	)
);
agents_api_smoke_assert_equals( false, is_wp_error( $dispatch ), 'dispatcher returns handler output', $failures, $passes );
agents_api_smoke_assert_equals( 'succeeded', is_array( $dispatch ) ? $dispatch['status'] ?? '' : '', 'dispatcher normalizes result status', $failures, $passes );
agents_api_smoke_assert_equals( 'build-site', is_array( $dispatch ) ? $dispatch['result']['workflow_id'] ?? '' : '', 'dispatcher passes workflow to handler', $failures, $passes );
agents_api_smoke_assert_equals( 'runtime log', is_array( $dispatch ) ? $dispatch['evidence_refs'][0]['label'] ?? '' : '', 'dispatcher preserves evidence refs', $failures, $passes );

echo "\n[4] Public host helper invokes the canonical runtime package boundary:\n";
$helper_dispatch = wp_agent_run_runtime_package(
	array(
		'package'  => array( 'slug' => 'site-builder' ),
		'workflow' => array( 'id' => 'build-site' ),
	)
);
agents_api_smoke_assert_equals( false, is_wp_error( $helper_dispatch ), 'public helper returns handler output', $failures, $passes );
agents_api_smoke_assert_equals( 'succeeded', is_array( $helper_dispatch ) ? $helper_dispatch['status'] ?? '' : '', 'public helper preserves result status', $failures, $passes );
agents_api_smoke_assert_equals( 'build-site', is_array( $helper_dispatch ) ? $helper_dispatch['result']['workflow_id'] ?? '' : '', 'public helper passes workflow to handler', $failures, $passes );

agents_api_smoke_finish( 'Agents API runtime package run contract', $failures, $passes );
