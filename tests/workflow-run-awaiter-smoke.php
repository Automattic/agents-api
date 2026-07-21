<?php
/**
 * Pure-PHP behavioral smoke test for the workflow run await service.
 *
 * Run with: php tests/workflow-run-awaiter-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require_once __DIR__ . '/../src/Runtime/class-wp-agent-run-result-envelope.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-result.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-recorder.php';
require_once __DIR__ . '/../src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-scoped-drain.php';
require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-awaiter.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Awaiter;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;

final class Await_Smoke_Recorder implements WP_Agent_Workflow_Run_Recorder {
	/** @var array<string,WP_Agent_Workflow_Run_Result> */
	public array $runs = array();
	public int $finds  = 0;

	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$this->runs[ $result->get_run_id() ] = $result;
		return $result->get_run_id();
	}
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$this->runs[ $result->get_run_id() ] = $result;
		return true;
	}
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result {
		++$this->finds;
		return $this->runs[ $run_id ] ?? null;
	}
	public function recent( array $args = array() ): array {
		unset( $args );
		return array_values( $this->runs );
	}
}

final class Await_Smoke_Awaiter extends WP_Agent_Workflow_Run_Awaiter {
	public int $drains = 0;
	public string $next_status = WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED;
	public string $stop_reason = 'terminal_status';
	public array $last_options = array();

	public function __construct() {}

	protected function drain_suspended_run( string $run_id, WP_Agent_Workflow_Run_Recorder $recorder, array $options ): array {
		++$this->drains;
		$this->last_options = $options;
		$current = $recorder->find( $run_id );
		if ( null !== $current && WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED !== $this->next_status ) {
			$recorder->update( $current->with( array( 'status' => $this->next_status ) ) );
		}
		$result = $recorder->find( $run_id );
		return array(
			'result' => $result,
			'stats'  => array(
				'batches' => isset( $options['limit'] ) ? 1 : 2, 'actions_processed' => isset( $options['limit'] ) ? 1 : 2,
				'completions' => 2, 'failures' => 0, 'remaining_pending' => WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED === $this->next_status ? 1 : 0,
				'total_pending' => 1, 'warnings' => 0, 'stop_reason' => $this->stop_reason,
				'terminal_state' => WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED === $this->next_status ? '' : $this->next_status,
				'hooks' => 'branch,resume', 'group' => 'workflow', 'available' => true,
			),
		);
	}
}

function await_result( string $id, string $status, array $error = array() ): WP_Agent_Workflow_Run_Result {
	return new WP_Agent_Workflow_Run_Result( $id, 'workflow', $status, array(), array( 'answer' => 42 ), array(), $error, 1, 2, array() );
}

$failures = array();
$passes   = 0;
function await_assert( $expected, $actual, string $name ): void {
	global $failures, $passes;
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}
	$failures[] = $name;
	echo "  FAIL {$name}\n    expected: " . var_export( $expected, true ) . "\n    actual:   " . var_export( $actual, true ) . "\n";
}

echo "workflow-run-awaiter-smoke\n";
$recorder = new Await_Smoke_Recorder();
$awaiter  = new Await_Smoke_Awaiter();

$missing = $awaiter->await( 'missing', $recorder );
await_assert( true, $missing instanceof WP_Error, 'missing run returns WP_Error' );
await_assert( 'agents_workflow_run_not_found', $missing->get_error_code(), 'missing run uses generic workflow error code' );
await_assert( 0, $awaiter->drains, 'missing run performs no drain' );

$recorder->start( await_result( 'succeeded', WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED ) );
$succeeded = $awaiter->await( 'succeeded', $recorder );
await_assert( WP_Agent_Workflow_Run_Awaiter::SCHEMA, $succeeded['schema'], 'response has versioned await schema' );
await_assert( true, $succeeded['terminal'], 'already succeeded is terminal' );
await_assert( false, $succeeded['reconnectable'], 'already succeeded is not reconnectable' );
await_assert( 0, $succeeded['drain']['actions_processed'], 'already succeeded performs zero drain work' );
await_assert( 'agents-api/run-result/v1', $succeeded['result']['schema'], 'terminal result uses canonical generic envelope' );

$recorder->start( await_result( 'suspended-success', WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED ) );
$before_finds = $recorder->finds;
$completed    = $awaiter->await( 'suspended-success', $recorder );
await_assert( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, $completed['status'], 'suspended run can complete through drain seam' );
await_assert( true, $completed['terminal'], 'completed suspended run is terminal' );
await_assert( true, $recorder->finds - $before_finds >= 3, 'suspended await re-reads recorder live and afterward' );

$recorder->start( await_result( 'limited', WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED ) );
$awaiter->next_status = WP_Agent_Workflow_Run_Result::STATUS_SUSPENDED;
$awaiter->stop_reason = 'limit';
$limited = $awaiter->await( 'limited', $recorder, array( 'limit' => 1 ) );
await_assert( false, $limited['terminal'], 'limit-exhausted suspended run is nonterminal' );
await_assert( true, $limited['reconnectable'], 'limit-exhausted suspended run is reconnectable' );
await_assert( 'limit', $limited['drain']['stop_reason'], 'limit stop is preserved' );
await_assert( null, $limited['result'], 'nonterminal state has no terminal result' );
await_assert( 'agents-api', $awaiter->last_options['group'] ?? '', 'legacy run without a persisted mapping drains through the backward-compatible shared group' );
await_assert( false, $awaiter->last_options['allow_group_fallback'] ?? null, 'run await never widens a failed group claim to other runs sharing the hooks' );

$awaiter->await( 'limited', $recorder, array( 'limit' => 1, 'group' => 'caller-supplied' ) );
await_assert( 'caller-supplied', $awaiter->last_options['group'] ?? '', 'an explicit caller group remains authoritative' );

$awaiter->stop_reason = 'time_limit';
$budgeted = $awaiter->await( 'limited', $recorder, array( 'time_limit_ms' => 1 ) );
await_assert( true, $budgeted['reconnectable'], 'budget-exhausted suspended run is reconnectable' );
await_assert( 'time_limit', $budgeted['drain']['stop_reason'], 'budget stop is preserved' );

$recorder->start( await_result( 'failed', WP_Agent_Workflow_Run_Result::STATUS_FAILED, array( 'code' => 'step_failed', 'message' => 'Nope' ) ) );
$failed = $awaiter->await( 'failed', $recorder );
await_assert( WP_Agent_Workflow_Run_Result::STATUS_FAILED, $failed['status'], 'failed terminal remains failed' );
await_assert( 'step_failed', $failed['result']['error']['code'], 'failed terminal preserves canonical error' );

$awaiter->stop_reason = 'refused_reentrant';
$refused = $awaiter->await( 'limited', $recorder );
await_assert( 'refused_reentrant', $refused['drain']['stop_reason'], 'reentrant refusal is preserved without fallback' );
await_assert( true, $refused['reconnectable'], 'refused suspended run remains reconnectable' );

$source = (string) file_get_contents( __DIR__ . '/../src/Workflows/class-wp-agent-workflow-run-awaiter.php' );
await_assert( false, str_contains( $source, 'wp_agent_workflow_run_recorder' ), 'awaiter has no ambient recorder resolver' );
await_assert( false, str_contains( $source, 'implements WP_Agent_Workflow_Run_Recorder' ), 'awaiter adds no storage implementation' );

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
