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
		'logs'          => array( array( 'level' => 'info', 'message' => 'started' ), 'ignored' ),
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
agents_api_smoke_assert_equals( 'started', $envelope->get_logs()[0]['message'] ?? '', 'logs drop non-array items and preserve string-keyed entries', $failures, $passes );
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
		'metadata'      => array( 'runtime' => 'runtime_local' ),
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
	array( 'workflow_version' => 2 ),
	array( array( 'type' => 'json', 'label' => 'workflow artifact' ) ),
	array( array( 'level' => 'error', 'message' => 'workflow failed' ) )
);
$workflow_envelope = $workflow->to_run_result_envelope();
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_FAILED, $workflow_envelope->get_status(), 'workflow status maps to canonical failed', $failures, $passes );
agents_api_smoke_assert_equals( 'build-site', $workflow_envelope->get_provenance()['workflow_id'] ?? '', 'workflow id maps to provenance', $failures, $passes );
agents_api_smoke_assert_equals( 2, $workflow_envelope->get_replay()['workflow_version'] ?? 0, 'workflow replay metadata is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'workflow log', $workflow_envelope->get_evidence_refs()[0]['label'] ?? '', 'workflow evidence refs are preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'workflow artifact', $workflow_envelope->get_artifact_refs()[0]['label'] ?? '', 'workflow artifacts map to canonical artifact refs', $failures, $passes );
agents_api_smoke_assert_equals( 'workflow failed', $workflow_envelope->get_logs()[0]['message'] ?? '', 'workflow logs map to canonical logs', $failures, $passes );

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
agents_api_smoke_assert_equals( true, $task_envelope->get_cancellation()['requested'] ?? false, 'cancelling task run populates cancellation', $failures, $passes );
agents_api_smoke_assert_equals( 'cancelling', $task_envelope->get_cancellation()['status'] ?? '', 'cancellation carries the task status', $failures, $passes );
agents_api_smoke_assert_equals( false, isset( $task_envelope->get_cancellation()['requested_at'] ), 'task updated_at is not treated as the cancellation request time', $failures, $passes );
agents_api_smoke_assert_equals( array(), $task_envelope->get_error(), 'non-failed task run carries empty error', $failures, $passes );

$failed_task_envelope = WP_Agent_Task_Run_Control::to_run_result_envelope(
	array(
		'run_id'      => 'task-run-failed',
		'session_id'  => 'session-1',
		'executor_id' => 'executor-1',
		'status'      => 'failed',
		'diagnostics' => array(
			'error_code'    => 'executor_boom',
			'error_message' => 'Executor exploded.',
		),
	)
);
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_FAILED, $failed_task_envelope->get_status(), 'failed task status is shared', $failures, $passes );
agents_api_smoke_assert_equals( 'executor_boom', $failed_task_envelope->get_error()['code'] ?? '', 'failed task run maps diagnostics code into envelope error', $failures, $passes );
agents_api_smoke_assert_equals( 'Executor exploded.', $failed_task_envelope->get_error()['message'] ?? '', 'failed task run maps diagnostics message into envelope error', $failures, $passes );
agents_api_smoke_assert_equals( 'executor_boom', $failed_task_envelope->get_error()['data']['diagnostics']['error_code'] ?? '', 'failed task run preserves raw diagnostics under error data', $failures, $passes );
agents_api_smoke_assert_equals( false, array() === $failed_task_envelope->get_error(), 'failed task run never carries an empty error', $failures, $passes );

$failed_task_without_diagnostics = WP_Agent_Task_Run_Control::to_run_result_envelope(
	array(
		'run_id'      => 'task-run-failed-without-diagnostics',
		'session_id'  => 'session-1',
		'executor_id' => 'executor-1',
		'status'      => 'failed',
	)
);
agents_api_smoke_assert_equals( 'agents_task_run_failed', $failed_task_without_diagnostics->get_error()['code'] ?? '', 'failed task run uses a deterministic fallback code without diagnostics', $failures, $passes );
agents_api_smoke_assert_equals( 'The task run failed.', $failed_task_without_diagnostics->get_error()['message'] ?? '', 'failed task run uses a deterministic fallback message without diagnostics', $failures, $passes );

$succeeded_task_envelope = WP_Agent_Task_Run_Control::to_run_result_envelope(
	array(
		'run_id'      => 'task-run-succeeded',
		'session_id'  => 'session-1',
		'executor_id' => 'executor-1',
		'status'      => 'succeeded',
		'output'      => array( 'summary' => 'done' ),
	)
);
agents_api_smoke_assert_equals( array(), $succeeded_task_envelope->get_error(), 'successful task run carries empty error', $failures, $passes );
agents_api_smoke_assert_equals( array(), $succeeded_task_envelope->get_cancellation(), 'successful task run carries empty cancellation', $failures, $passes );

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

echo "\n[6] Multi-step fields round-trip through from_array()/to_array():\n";
$multistep = WP_Agent_Run_Result_Envelope::from_array(
	array(
		'run_id'          => 'run-multistep',
		'status'          => 'succeeded',
		'status_detail'   => 'succeeded_no_items',
		'steps'           => array(
			array( 'id' => 'step-1', 'status' => 'succeeded' ),
			'ignored',
		),
		'parent_run_id'   => 'run-parent',
		'child_run_refs'  => array(
			array( 'type' => 'run', 'id' => ' run-child-1 ' ),
		),
	)
);
agents_api_smoke_assert_equals( 'succeeded_no_items', $multistep->get_status_detail(), 'status_detail round-trips verbatim when explicitly supplied', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $multistep->get_steps() ), 'steps drop non-array entries', $failures, $passes );
agents_api_smoke_assert_equals( 'step-1', $multistep->get_steps()[0]['id'] ?? '', 'steps preserve step record fields', $failures, $passes );
agents_api_smoke_assert_equals( 'run-parent', $multistep->get_parent_run_id(), 'parent_run_id round-trips', $failures, $passes );
agents_api_smoke_assert_equals( 'run-child-1', $multistep->get_child_run_refs()[0]['id'] ?? '', 'child_run_refs normalize like artifact_refs', $failures, $passes );

$multistep_array = $multistep->to_array();
agents_api_smoke_assert_equals( 'succeeded_no_items', $multistep_array['status_detail'] ?? '', 'to_array() carries status_detail', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $multistep_array['steps'] ?? array() ), 'to_array() carries steps', $failures, $passes );
agents_api_smoke_assert_equals( 'run-parent', $multistep_array['parent_run_id'] ?? '', 'to_array() carries parent_run_id', $failures, $passes );
agents_api_smoke_assert_equals( 'run-child-1', $multistep_array['child_run_refs'][0]['id'] ?? '', 'to_array() carries child_run_refs', $failures, $passes );

$multistep_rehydrated = WP_Agent_Run_Result_Envelope::from_array( $multistep_array );
agents_api_smoke_assert_equals( $multistep_array, $multistep_rehydrated->to_array(), 'multi-step fields survive a full round-trip', $failures, $passes );

echo "\n[7] normalize_status() never coerces unknown/terminal-looking input to running:\n";
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_INCOMPLETE, WP_Agent_Run_Result_Envelope::normalize_status( 'quux' ), 'unrecognised status maps to incomplete, never running', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_COMPLETED, WP_Agent_Run_Result_Envelope::normalize_status( 'completed_no_items' ), 'completed-prefixed status maps to completed', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_FAILED, WP_Agent_Run_Result_Envelope::normalize_status( 'failed - timeout' ), 'failed-prefixed status maps to failed', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_SKIPPED, WP_Agent_Run_Result_Envelope::normalize_status( 'agent_skipped' ), 'skipped-suffixed status maps to skipped', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_RUNNING, WP_Agent_Run_Result_Envelope::normalize_status( 'suspended' ), 'suspended (parked but alive) stays non-terminal', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_RUNNING, WP_Agent_Run_Result_Envelope::normalize_status( 'waiting' ), 'waiting stays non-terminal', $failures, $passes );
$suspended_workflow = WP_Agent_Run_Result_Envelope::from_array( array( 'run_id' => 'r-susp', 'status' => 'suspended' ) );
agents_api_smoke_assert_equals( 'suspended', $suspended_workflow->get_status_detail(), 'suspended raw status preserved in status_detail', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_CANCELLED, WP_Agent_Run_Result_Envelope::normalize_status( 'cancel_requested' ), 'cancel-prefixed status maps to cancelled', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_RUNNING, WP_Agent_Run_Result_Envelope::normalize_status( null ), 'null status preserves the historical running default', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_RUNNING, WP_Agent_Run_Result_Envelope::normalize_status( '' ), 'empty string status preserves the historical running default', $failures, $passes );
foreach ( WP_Agent_Run_Result_Envelope::statuses() as $exact_status ) {
	agents_api_smoke_assert_equals( $exact_status, WP_Agent_Run_Result_Envelope::normalize_status( $exact_status ), "exact enum value '{$exact_status}' is unchanged", $failures, $passes );
}

echo "\n[8] from_array() preserves the raw status in status_detail only for lossy coercions:\n";
$unknown_envelope = WP_Agent_Run_Result_Envelope::from_array(
	array(
		'run_id' => 'run-unknown-status',
		'status' => 'completed_no_items',
	)
);
agents_api_smoke_assert_equals( WP_Agent_Run_Result_Envelope::STATUS_COMPLETED, $unknown_envelope->get_status(), 'unrecognised terminal-looking status normalizes to completed', $failures, $passes );
agents_api_smoke_assert_equals( 'completed_no_items', $unknown_envelope->get_status_detail(), 'raw status is preserved verbatim in status_detail when not an exact enum match', $failures, $passes );

$exact_envelope = WP_Agent_Run_Result_Envelope::from_array(
	array(
		'run_id' => 'run-exact-status',
		'status' => 'succeeded',
	)
);
agents_api_smoke_assert_equals( '', $exact_envelope->get_status_detail(), 'exact enum status does not synthesize a status_detail', $failures, $passes );

echo "\n[9] Workflow run result projection carries steps into the envelope:\n";
$workflow_with_steps = new WP_Agent_Workflow_Run_Result(
	'workflow-run-steps',
	'build-site',
	WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
	array(),
	array( 'done' => true ),
	array( array( 'id' => 'step-1', 'status' => 'succeeded' ), array( 'id' => 'step-2', 'status' => 'succeeded' ) ),
	array(),
	100,
	110,
	array()
);
$workflow_steps_envelope = $workflow_with_steps->to_run_result_envelope();
agents_api_smoke_assert_equals( 2, count( $workflow_steps_envelope->get_steps() ), 'workflow projection carries every step record into the canonical steps field', $failures, $passes );
agents_api_smoke_assert_equals( 'step-2', $workflow_steps_envelope->get_steps()[1]['id'] ?? '', 'workflow step records preserve their fields in the canonical steps field', $failures, $passes );

agents_api_smoke_finish( 'Agents API run result envelope', $failures, $passes );
