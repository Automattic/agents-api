<?php
/**
 * Pure-PHP smoke test for package adoption canonical status mapping.
 *
 * Guards the canonical distinction between partial adoptions with failures
 * and partial adoptions containing only intentionally skipped artifacts.
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
$skipped_artifact = array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'skipped-one', 'apply_status' => 'skipped', 'apply_reason' => 'not approved' );
$failed_artifact  = array( 'artifact_type' => 'example/prompt', 'artifact_id' => 'failed-one', 'apply_status' => 'failed', 'apply_reason' => 'import rejected' );

echo "\n[1] Partial adoption with a failure maps to incomplete:\n";

$partial_result   = new WP_Agent_Package_Adoption_Result(
	'partial',
	'demo-agent',
	array(),
	null,
	array( $applied_artifact ),
	array(),
	array( $failed_artifact )
);
$partial_envelope = $partial_result->to_canonical_envelope();
$partial_status   = $partial_envelope->get_status();
$partial_outputs  = $partial_envelope->get_outputs();

agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_INCOMPLETE, $partial_status, 'partial adoption maps to the incomplete canonical status', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $partial_outputs['failed'] ?? array() ), 'failed artifacts remain visible in the envelope outputs', $failures, $passes );
agents_api_smoke_assert_equals( 'failed-one', $partial_outputs['failed'][0]['artifact_id'] ?? null, 'failed artifact identity is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'partial', $partial_result->get_status(), 'raw partial status remains unchanged', $failures, $passes );
agents_api_smoke_assert_equals( 'partial', $partial_result->to_array()['status'], 'serialized partial status remains unchanged', $failures, $passes );

echo "\n[2] Partial adoption with only an intentional skip maps to succeeded:\n";

$skipped_result   = new WP_Agent_Package_Adoption_Result(
	'partial',
	'demo-agent',
	array(),
	null,
	array( $applied_artifact ),
	array( $skipped_artifact )
);
$skipped_envelope = $skipped_result->to_canonical_envelope();
$skipped_outputs  = $skipped_envelope->get_outputs();

agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_SUCCEEDED, $skipped_envelope->get_status(), 'benign partial adoption maps to succeeded', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $skipped_outputs['failed'] ?? array() ), 'benign partial adoption has no failed artifacts', $failures, $passes );
agents_api_smoke_assert_equals( 'skipped-one', $skipped_outputs['skipped'][0]['artifact_id'] ?? null, 'skipped artifact remains visible in the envelope outputs', $failures, $passes );
agents_api_smoke_assert_equals( 'partial', $skipped_result->get_status(), 'raw benign partial status remains unchanged', $failures, $passes );
agents_api_smoke_assert_equals( 'partial', $skipped_result->to_array()['status'], 'serialized benign partial status remains unchanged', $failures, $passes );

echo "\n[3] Fully successful adoption maps to succeeded:\n";

$success_result  = new WP_Agent_Package_Adoption_Result(
	'applied',
	'demo-agent',
	array(),
	null,
	array( $applied_artifact )
);
$success_status  = $success_result->to_canonical_envelope()->get_status();
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_SUCCEEDED, $success_status, 'clean adoption maps to succeeded', $failures, $passes );

echo "\n[4] Fully failed adoption maps to failed:\n";

$failed_result = new WP_Agent_Package_Adoption_Result(
	'failed',
	'demo-agent',
	array(),
	null,
	array(),
	array(),
	array( $failed_artifact )
);
$failed_status = $failed_result->to_canonical_envelope()->get_status();
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_FAILED, $failed_status, 'fully failed adoption maps to failed', $failures, $passes );

agents_api_smoke_finish( 'Agents API package adoption result canonical status', $failures, $passes );
