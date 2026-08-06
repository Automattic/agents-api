<?php
/**
 * Pure-PHP smoke test for package adoption canonical status mapping.
 *
 * Guards against partial adoptions (some artifacts applied, others failed)
 * being surfaced as a top-level `succeeded` canonical status, which would
 * hide the failures from any consumer keying on the envelope status.
 *
 * Run with: php tests/package-adoption-result-canonical-status-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-package-adoption-result-canonical-status-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

use AgentsAPI\AI\WP_Agent_Run_Result_Envelope;

$applied_artifact = array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'applied-one', 'apply_status' => 'applied' );
$failed_artifact  = array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'failed-one', 'apply_status' => 'failed', 'apply_reason' => 'import rejected' );

echo "\n[1] Partial adoption surfaces a non-success canonical status with failures intact:\n";

$partial_result   = new WP_Agent_Package_Adoption_Result(
	'partial',
	'demo-agent',
	array(),
	null,
	array( $applied_artifact ),
	array(),
	array( $failed_artifact )
);
$partial_envelope = $partial_result->to_run_result_envelope();
$partial_status   = $partial_envelope->get_status();
$partial_outputs  = $partial_envelope->get_outputs();

agents_api_smoke_assert_equals( false, WP_Agent_Run_Result_Envelope::STATUS_SUCCEEDED === $partial_status, 'partial adoption does not report succeeded', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_INCOMPLETE, $partial_status, 'partial adoption maps to the incomplete canonical status', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $partial_outputs['failed'] ?? array() ), 'failed artifacts remain visible in the envelope outputs', $failures, $passes );
agents_api_smoke_assert_equals( 'failed-one', $partial_outputs['failed'][0]['artifact_id'] ?? null, 'failed artifact identity is preserved', $failures, $passes );

echo "\n[2] Fully successful adoption still maps to succeeded (no regression):\n";

$success_result  = new WP_Agent_Package_Adoption_Result(
	'applied',
	'demo-agent',
	array(),
	null,
	array( $applied_artifact )
);
$success_status  = $success_result->to_run_result_envelope()->get_status();
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_SUCCEEDED, $success_status, 'clean adoption maps to succeeded', $failures, $passes );

echo "\n[3] Fully failed adoption still maps to failed (no regression):\n";

$failed_result = new WP_Agent_Package_Adoption_Result(
	'failed',
	'demo-agent',
	array(),
	null,
	array(),
	array(),
	array( $failed_artifact )
);
$failed_status = $failed_result->to_run_result_envelope()->get_status();
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_FAILED, $failed_status, 'fully failed adoption maps to failed', $failures, $passes );

agents_api_smoke_finish( 'Agents API package adoption result canonical status', $failures, $passes );
