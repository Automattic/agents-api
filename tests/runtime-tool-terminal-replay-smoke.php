<?php
/**
 * Pure-PHP smoke test for runtime-tool exact-once terminal semantics.
 *
 * Regression coverage for replayable terminal ops and the non-atomic
 * completion race on WP_Agent_Runtime_Tool_Lifecycle:
 *   - timeout/cancel of an already-terminal request must not re-transition the
 *     store, re-fire the terminal hook, or re-invoke the host continuation.
 *   - a completion that loses the exact-once race (store reports it did not
 *     transition the pending record) must resolve as a duplicate rather than
 *     firing the submitted hook and continuation a second time.
 *
 * Run with: php tests/runtime-tool-terminal-replay-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-runtime-tool-terminal-replay-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

/**
 * Store that transitions only pending records, mirroring the exact-once
 * contract host implementations must back with a compare-and-set write.
 */
$store = new class() implements AgentsAPI\AI\WP_Agent_Runtime_Tool_Request_Store {
	/** @var array<string, array<string, mixed>> */
	public array $requests = array();
	public int $timeout_calls = 0;

	public function create( array $request ): void {
		$this->requests[ $request['request_id'] ] = $request;
	}

	public function get( string $request_id ): ?array {
		return $this->requests[ $request_id ] ?? null;
	}

	public function complete( string $request_id, array $result ): bool {
		if ( ! isset( $this->requests[ $request_id ] ) || AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING !== ( $this->requests[ $request_id ]['status'] ?? '' ) ) {
			return false;
		}
		$this->requests[ $request_id ]['status'] = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_COMPLETED;
		$this->requests[ $request_id ]['result'] = $result;
		return true;
	}

	public function timeout( string $request_id ): void {
		++$this->timeout_calls;
		$this->requests[ $request_id ]['status'] = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT;
	}

	public function recent_pending( array $query = array() ): array {
		unset( $query );
		return array_values( array_filter(
			$this->requests,
			static fn( array $request ): bool => AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING === ( $request['status'] ?? '' )
		) );
	}
};

$resume_calls = 0;
$continuation = static function ( array $request, array $result, array $context ) use ( &$resume_calls ): array {
	unset( $request, $result, $context );
	++$resume_calls;
	return array( 'resumed' => true );
};

// ---------------------------------------------------------------------------
// Finding 1 & 2: timeout/cancel replay of an already-terminal request.
// ---------------------------------------------------------------------------

$timeout_request = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::from_tool_call( 'client/choose_post', 'call_replay_timeout', array(), array( 'run_id' => 'run-replay-timeout' ) );
AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::create_pending_request( $store, $timeout_request );

$first_timeout = AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::timeout_request( $store, $timeout_request['request_id'], $continuation );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT, $first_timeout['status'] ?? '', 'first timeout transitions the pending request', $failures, $passes );
agents_api_smoke_assert_equals( false, $first_timeout['duplicate'] ?? true, 'first timeout is not a duplicate', $failures, $passes );
agents_api_smoke_assert_equals( 1, $store->timeout_calls, 'first timeout writes to the store once', $failures, $passes );
agents_api_smoke_assert_equals( 1, $resume_calls, 'first timeout resumes the continuation once', $failures, $passes );
agents_api_smoke_assert_equals( 1, did_action( 'agents_api_runtime_tool_request_timed_out' ), 'first timeout fires the terminal hook once', $failures, $passes );

// Replay the SAME timeout: must short-circuit as an idempotent duplicate.
$replay_timeout = AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::timeout_request( $store, $timeout_request['request_id'], $continuation );
agents_api_smoke_assert_equals( true, $replay_timeout['duplicate'] ?? false, 'replayed timeout is reported as duplicate', $failures, $passes );
agents_api_smoke_assert_equals( null, $replay_timeout['continuation_result'], 'replayed timeout does not resume the continuation', $failures, $passes );
agents_api_smoke_assert_equals( 1, $store->timeout_calls, 'replayed timeout does not write to the store again', $failures, $passes );
agents_api_smoke_assert_equals( 1, $resume_calls, 'replayed timeout does not resume the continuation again', $failures, $passes );
agents_api_smoke_assert_equals( 1, did_action( 'agents_api_runtime_tool_request_timed_out' ), 'replayed timeout does not fire the terminal hook again', $failures, $passes );

// Cancel is routed through the same terminal path; replaying it on the same
// terminal record must likewise be an idempotent duplicate.
$replay_cancel = AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::timeout_request( $store, $timeout_request['request_id'], $continuation );
agents_api_smoke_assert_equals( true, $replay_cancel['duplicate'] ?? false, 'replayed cancel is reported as duplicate', $failures, $passes );
agents_api_smoke_assert_equals( 1, $resume_calls, 'replayed cancel does not resume the continuation again', $failures, $passes );
agents_api_smoke_assert_equals( 1, $store->timeout_calls, 'replayed cancel does not write to the store again', $failures, $passes );

// A request that was completed first must also reject a later timeout/cancel
// terminal replay without side effects.
$completed_request = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::from_tool_call( 'client/choose_post', 'call_complete_then_timeout', array(), array( 'run_id' => 'run-complete-then-timeout' ) );
AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::create_pending_request( $store, $completed_request );
AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::submit_result(
	$store,
	array(
		'request_id' => $completed_request['request_id'],
		'success'    => true,
		'result'     => array( 'post_id' => 42 ),
	)
);
$hooks_before = did_action( 'agents_api_runtime_tool_request_timed_out' );
$timeout_after_complete = AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::timeout_request( $store, $completed_request['request_id'], $continuation );
agents_api_smoke_assert_equals( true, $timeout_after_complete['duplicate'] ?? false, 'timeout of a completed request is a duplicate', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_COMPLETED, $timeout_after_complete['status'] ?? '', 'timeout of a completed request preserves completed status', $failures, $passes );
agents_api_smoke_assert_equals( $hooks_before, did_action( 'agents_api_runtime_tool_request_timed_out' ), 'timeout of a completed request does not fire the terminal hook', $failures, $passes );

// ---------------------------------------------------------------------------
// Finding 3: completion that loses the exact-once race resolves as duplicate.
// ---------------------------------------------------------------------------

/**
 * Store whose complete() always reports a loss (another caller already won),
 * exposing the retained winner result on the subsequent get(). Models the race
 * window where two callers both read a pending record before either writes.
 */
$racing_store = new class() implements AgentsAPI\AI\WP_Agent_Runtime_Tool_Request_Store {
	/** @var array<string, mixed> */
	public array $request = array();
	public bool $won_by_other = false;
	public int $complete_calls = 0;

	public function create( array $request ): void {
		$this->request = $request;
	}

	public function get( string $request_id ): ?array {
		unset( $request_id );
		if ( $this->won_by_other ) {
			$terminal           = $this->request;
			$terminal['status'] = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_COMPLETED;
			$terminal['result'] = array(
				'success' => true,
				'result'  => array( 'post_id' => 7 ),
			);
			return $terminal;
		}
		return $this->request;
	}

	public function complete( string $request_id, array $result ): bool {
		unset( $request_id, $result );
		++$this->complete_calls;
		$this->won_by_other = true;
		return false;
	}

	public function timeout( string $request_id ): void {
		unset( $request_id );
	}

	public function recent_pending( array $query = array() ): array {
		unset( $query );
		return array();
	}
};

$race_request = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::from_tool_call( 'client/choose_post', 'call_race', array(), array( 'run_id' => 'run-race' ) );
$racing_store->create( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::normalize( $race_request ) );

$race_resume_calls = 0;
$submitted_before  = did_action( 'agents_api_runtime_tool_result_submitted' );
$race_submission   = AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::submit_result(
	$racing_store,
	array(
		'request_id' => $race_request['request_id'],
		'success'    => true,
		'result'     => array( 'post_id' => 999 ),
	),
	static function ( array $request, array $result, array $context ) use ( &$race_resume_calls ): array {
		unset( $request, $result, $context );
		++$race_resume_calls;
		return array( 'resumed' => true );
	}
);
agents_api_smoke_assert_equals( true, $race_submission['duplicate'] ?? false, 'losing completion resolves as a duplicate', $failures, $passes );
agents_api_smoke_assert_equals( 7, $race_submission['result']['result']['post_id'] ?? null, 'losing completion returns the retained winner result', $failures, $passes );
agents_api_smoke_assert_equals( null, $race_submission['continuation_result'], 'losing completion does not resume the continuation', $failures, $passes );
agents_api_smoke_assert_equals( 0, $race_resume_calls, 'losing completion never invokes the continuation', $failures, $passes );
agents_api_smoke_assert_equals( $submitted_before, did_action( 'agents_api_runtime_tool_result_submitted' ), 'losing completion does not fire the submitted hook', $failures, $passes );

agents_api_smoke_finish( 'Agents API runtime-tool terminal replay', $failures, $passes );
