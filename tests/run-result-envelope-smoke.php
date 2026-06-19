<?php
/**
 * Pure-PHP smoke test for the canonical run result envelope.
 *
 * Run with: php tests/run-result-envelope-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "run-result-envelope-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-result-envelope.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-runtime-package-run-result.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-result.php';
require_once __DIR__ . '/../src/Tasks/class-wp-agent-task-run-control.php';
require_once __DIR__ . '/../src/Packages/class-wp-agent-package-artifact.php';
require_once __DIR__ . '/../src/Packages/class-wp-agent-package-artifact-status.php';
require_once __DIR__ . '/../src/Packages/class-wp-agent-package-installed-artifact.php';
require_once __DIR__ . '/../src/Packages/class-wp-agent-package-update-plan.php';
require_once __DIR__ . '/../src/Packages/class-wp-agent-package-adoption-result.php';

use AgentsAPI\AI\Tasks\WP_Agent_Task_Run_Control;
use AgentsAPI\AI\WP_Agent_Run_Result_Envelope;
use AgentsAPI\AI\WP_Agent_Runtime_Package_Run_Result;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;

echo "\n[1] Envelope normalizes status, refs, timestamps, and maps:\n";
$envelope = WP_Agent_Run_Result_Envelope::from_array(
	array(
		'run_id'        => 'run-123',
		'status'        => 'SUCCEEDED',
		'outputs'       => array( 'summary' => 'created' ),
		'artifact_refs' => array(
			array(
				'type'  => ' file ',
				'label' => ' transcript ',
				'url'   => ' https://example.com/transcript.json ',
			),
			'ignored',
		),
		'evidence_refs' => array( array( 'type' => 'log', 'label' => 'runner' ) ),
		'replay'        => array( 'seed' => 42 ),
		'provenance'    => array( 'producer' => 'smoke' ),
		'started_at'    => '2026-06-19T00:00:00Z',
		'ended_at'      => '2026-06-19T00:00:01Z',
	)
);
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_SUCCEEDED, $envelope->get_status(), 'status lowercases into shared vocabulary', $failures, $passes );
agents_api_smoke_assert_equals( 'created', $envelope->get_outputs()['summary'] ?? '', 'outputs map is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $envelope->get_artifact_refs() ), 'artifact refs drop non-array items', $failures, $passes );
agents_api_smoke_assert_equals( 'transcript', $envelope->get_artifact_refs()[0]['label'] ?? '', 'artifact ref string fields trim', $failures, $passes );
agents_api_smoke_assert_equals( 'runner', $envelope->get_evidence_refs()[0]['label'] ?? '', 'evidence refs normalize with same vocabulary', $failures, $passes );
agents_api_smoke_assert_equals( '2026-06-19T00:00:00Z', $envelope->get_timestamps()['started_at'] ?? '', 'top-level started_at folds into timestamps', $failures, $passes );

echo "\n[2] Runtime package results convert without changing legacy arrays:\n";
$runtime = WP_Agent_Runtime_Package_Run_Result::from_array(
	array(
		'status'        => 'succeeded',
		'run_id'        => 'runtime-run',
		'result'        => array( 'page_id' => 123 ),
		'artifact_refs' => array( array( 'type' => 'package', 'label' => 'bundle' ) ),
		'evidence_refs' => array( array( 'type' => 'trace', 'label' => 'trace' ) ),
		'replay'        => array( 'recipe' => 'site' ),
		'metadata'      => array( 'runtime' => 'codebox' ),
	)
);
$runtime_array    = $runtime->to_array();
$runtime_envelope = $runtime->to_run_result_envelope();
agents_api_smoke_assert_equals( 'succeeded', $runtime_array['status'] ?? '', 'runtime legacy status remains present', $failures, $passes );
agents_api_smoke_assert_equals( 123, $runtime_envelope->get_outputs()['page_id'] ?? 0, 'runtime result maps to canonical outputs', $failures, $passes );
agents_api_smoke_assert_equals( 'bundle', $runtime_envelope->get_artifact_refs()[0]['label'] ?? '', 'runtime artifact refs map to canonical refs', $failures, $passes );
agents_api_smoke_assert_equals( 'trace', $runtime_envelope->get_evidence_refs()[0]['label'] ?? '', 'runtime evidence refs map to canonical refs', $failures, $passes );

echo "\n[3] Workflow results convert with provenance and replay metadata:\n";
$workflow = new WP_Agent_Workflow_Run_Result(
	'workflow-run',
	'build-site',
	WP_Agent_Workflow_Run_Result::STATUS_FAILED,
	array( 'prompt' => 'Build' ),
	array( 'partial' => true ),
	array( array( 'id' => 'step-1', 'status' => 'failed' ) ),
	array( 'code' => 'step_failed', 'message' => 'Step failed' ),
	100,
	110,
	array( 'trace_id' => 'trace-1' ),
	array( array( 'type' => 'log', 'label' => 'workflow log' ) ),
	array( 'workflow_version' => 2 )
);
$workflow_envelope = $workflow->to_run_result_envelope();
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_FAILED, $workflow_envelope->get_status(), 'workflow status maps to canonical failed', $failures, $passes );
agents_api_smoke_assert_equals( 'build-site', $workflow_envelope->get_provenance()['workflow_id'] ?? '', 'workflow id maps to provenance', $failures, $passes );
agents_api_smoke_assert_equals( 2, $workflow_envelope->get_replay()['workflow_version'] ?? 0, 'workflow replay metadata is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'workflow log', $workflow_envelope->get_evidence_refs()[0]['label'] ?? '', 'workflow evidence refs are preserved', $failures, $passes );

echo "\n[4] Task run-control arrays convert to the canonical envelope:\n";
$task_envelope = WP_Agent_Task_Run_Control::to_run_result_envelope(
	array(
		'run_id'        => 'task-run',
		'session_id'    => 'session-1',
		'executor_id'   => 'executor-1',
		'status'        => 'cancelling',
		'output'        => array( 'summary' => 'queued cancel' ),
		'artifact_refs' => array( array( 'type' => 'artifact', 'label' => 'task artifact' ) ),
		'provenance'    => array( 'source' => 'task' ),
		'started_at'    => '2026-06-19T00:00:00Z',
		'updated_at'    => '2026-06-19T00:00:02Z',
	)
);
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_CANCELLING, $task_envelope->get_status(), 'task cancelling status is shared', $failures, $passes );
agents_api_smoke_assert_equals( 'queued cancel', $task_envelope->get_outputs()['summary'] ?? '', 'task output maps to outputs', $failures, $passes );
agents_api_smoke_assert_equals( 'executor-1', $task_envelope->get_metadata()['executor_id'] ?? '', 'task executor id maps to metadata', $failures, $passes );

echo "\n[5] Package adoption results expose canonical envelope refs:\n";
$recorded = new WP_Agent_Package_Installed_Artifact(
	array(
		'package_slug'    => 'demo-package',
		'package_version' => '1.0.0',
		'artifact_type'   => 'agents/prompt',
		'artifact_id'     => 'demo-prompt',
		'source'          => 'agents/demo.md',
		'installed_hash'  => 'abc123',
		'current_hash'    => 'abc123',
		'installed_at'    => '2026-06-19T00:00:00Z',
		'updated_at'      => '2026-06-19T00:00:00Z',
	)
);
$package = new WP_Agent_Package_Adoption_Result(
	'applied',
	'demo-agent',
	array( 'Applied demo agent.' ),
	null,
	array( array( 'type' => 'agents/prompt', 'path' => 'agents/demo.md' ) ),
	array(),
	array(),
	array( $recorded ),
	array( 'package' => 'demo' )
);
$package_envelope = $package->to_run_result_envelope();
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_SUCCEEDED, $package_envelope->get_status(), 'package applied maps to succeeded', $failures, $passes );
agents_api_smoke_assert_equals( 'demo-agent', $package_envelope->get_outputs()['agent_slug'] ?? '', 'package agent slug maps to outputs', $failures, $passes );
agents_api_smoke_assert_equals( 'agents/demo.md', $package_envelope->get_artifact_refs()[0]['source'] ?? '', 'package recorded artifacts map to artifact refs', $failures, $passes );

agents_api_smoke_finish( 'Agents API run result envelope', $failures, $passes );
