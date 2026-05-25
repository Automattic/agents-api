<?php
/**
 * Pure-PHP smoke test for package capability compatibility contracts.
 *
 * Run with: php tests/package-capability-contract-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-package-capability-contract-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$package = WP_Agent_Package::from_array(
	array(
		'slug'         => 'portable-demo',
		'version'      => '1.0.0',
		'capabilities' => array( 'chat', 'memory/files' ),
		'agent'        => array(
			'slug'  => 'portable-demo-agent',
			'label' => 'Portable Demo Agent',
		),
		'artifacts'    => array(
			array(
				'type'     => 'agents/prompt',
				'slug'     => 'system',
				'source'   => 'prompts/system.md',
				'requires' => array( 'prompt/text' ),
			),
			array(
				'type'     => 'datamachine/flow',
				'slug'     => 'daily-fetch',
				'source'   => 'flows/daily-fetch.json',
				'requires' => array( 'schedule/cron', 'queue/actions' ),
			),
			array(
				'type'     => 'vendor/custom',
				'slug'     => 'custom-shape',
				'source'   => 'extensions/custom.json',
				'requires' => array( 'vendor/custom-runtime' ),
			),
		),
	)
);

echo "\n[1] Host capability reports unsupported runtime needs without applying artifacts:\n";
$report = WP_Agent_Package_Capability_Checker::check(
	$package,
	array( 'chat', 'prompt/text', 'queue/actions' ),
	array( 'known_artifact_types' => array( 'agents/prompt', 'datamachine/flow' ) )
);
$report_array = $report->to_array();

agents_api_smoke_assert_equals( false, $report->is_compatible(), 'report is incompatible when required capabilities are missing', $failures, $passes );
agents_api_smoke_assert_equals( array( 'memory/files', 'schedule/cron', 'vendor/custom-runtime' ), $report->get_unsupported_capabilities(), 'package and artifact requirements are merged and normalized', $failures, $passes );
agents_api_smoke_assert_equals( array( 'vendor/custom' ), $report->get_unknown_artifact_types(), 'unknown artifact type is reported', $failures, $passes );
agents_api_smoke_assert_equals( 'unsupported', $report_array['status'], 'array report carries stable unsupported status', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $report->get_unsupported_artifacts() ), 'unsupported artifacts include missing requirements and unknown types', $failures, $passes );

$unsupported_by_key = array();
foreach ( $report->get_unsupported_artifacts() as $artifact_report ) {
	$unsupported_by_key[ $artifact_report['artifact_key'] ] = $artifact_report;
}

agents_api_smoke_assert_equals( array( 'schedule/cron' ), $unsupported_by_key['datamachine/flow:daily-fetch']['unsupported_capabilities'], 'artifact-level report keeps missing capability scoped to the artifact', $failures, $passes );
agents_api_smoke_assert_equals( true, $unsupported_by_key['vendor/custom:custom-shape']['unknown_artifact_type'], 'artifact-level report flags unknown type', $failures, $passes );

echo "\n[2] A fully capable host reports compatibility:\n";
$compatible = WP_Agent_Package_Capability_Checker::check(
	$package,
	array( 'chat', 'memory/files', 'prompt/text', 'queue/actions', 'schedule/cron', 'vendor/custom-runtime' ),
	array( 'known_artifact_types' => array( 'agents/prompt', 'datamachine/flow', 'vendor/custom' ) )
);

agents_api_smoke_assert_equals( true, $compatible->is_compatible(), 'all declared needs are supported', $failures, $passes );
agents_api_smoke_assert_equals( 'compatible', $compatible->to_array()['status'], 'compatible report exports stable status', $failures, $passes );
agents_api_smoke_assert_equals( array(), $compatible->get_unsupported_artifacts(), 'compatible report has no artifact blockers', $failures, $passes );

agents_api_smoke_finish( 'Agents API package capability contract', $failures, $passes );
