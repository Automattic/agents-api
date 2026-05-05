<?php
/**
 * Pure-PHP smoke test for generic action policy values.
 *
 * Run with: php tests/action-policy-values-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-action-policy-values-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$policy_class = AgentsAPI\AI\Tools\WP_Agent_Action_Policy::class;

agents_api_smoke_assert_equals(
	array( 'direct', 'preview', 'forbidden' ),
	$policy_class::all(),
	'action policy exposes stable generic values',
	$failures,
	$passes
);

agents_api_smoke_assert_equals( 'direct', $policy_class::normalize( ' Direct ' ), 'action policy normalizes direct values', $failures, $passes );
agents_api_smoke_assert_equals( 'preview', $policy_class::normalize( 'PREVIEW' ), 'action policy normalizes preview values', $failures, $passes );
agents_api_smoke_assert_equals( 'forbidden', $policy_class::normalize( ' forbidden ' ), 'action policy normalizes forbidden values', $failures, $passes );
agents_api_smoke_assert_equals( null, $policy_class::normalize( 'approve' ), 'action policy rejects unknown values', $failures, $passes );
agents_api_smoke_assert_equals( 'direct', $policy_class::normalize( 'approve', $policy_class::DIRECT ), 'action policy applies valid fallback', $failures, $passes );
agents_api_smoke_assert_equals( null, $policy_class::normalize( 'approve', 'also-invalid' ), 'action policy rejects invalid fallback', $failures, $passes );
agents_api_smoke_assert_equals( true, $policy_class::isValid( 'preview' ), 'action policy validates known values', $failures, $passes );
agents_api_smoke_assert_equals( false, $policy_class::isValid( 'review' ), 'action policy invalidates unknown values', $failures, $passes );
agents_api_smoke_assert_equals( true, $policy_class::allowsDirectExecution( 'direct' ), 'direct policy permits immediate execution', $failures, $passes );
agents_api_smoke_assert_equals( true, $policy_class::stagesApproval( 'preview' ), 'preview policy stages approval', $failures, $passes );
agents_api_smoke_assert_equals( true, $policy_class::refusesExecution( 'forbidden' ), 'forbidden policy refuses execution', $failures, $passes );

agents_api_smoke_finish( 'Agents API action policy values', $failures, $passes );
