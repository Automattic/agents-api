<?php
/**
 * Pure-PHP smoke test for generic run-control normalization.
 *
 * Run with: php tests/run-control-normalization-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "run-control-normalization-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

agents_api_smoke_require_module();

$status = AgentsAPI\AI\WP_Agent_Run_Control::normalize_run_result(
	array(
		'run_id'   => 'run-1',
		'status'   => 'APPROVAL_REQUIRED',
		'metadata' => array( 'provider' => 'test' ),
	),
	'agents_run_invalid_status'
);
agents_api_smoke_assert_equals( 'approval_required', $status['status'] ?? null, 'status normalization lowercases known statuses', $failures, $passes );
agents_api_smoke_assert_equals( 'test', $status['metadata']['provider'] ?? null, 'status normalization preserves metadata', $failures, $passes );

$invalid_status = AgentsAPI\AI\WP_Agent_Run_Control::normalize_run_result( array( 'status' => 'running' ), 'agents_run_invalid_status' );
agents_api_smoke_assert_equals( true, $invalid_status instanceof WP_Error, 'status normalization rejects missing run_id', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_run_invalid_status', $invalid_status->get_error_code(), 'status normalization returns configured error code', $failures, $passes );

$cancel = AgentsAPI\AI\WP_Agent_Run_Control::normalize_cancel_result(
	array(
		'run_id' => 'run-2',
		'status' => 'cancelling',
	),
	'agents_run_invalid_cancel'
);
agents_api_smoke_assert_equals( true, $cancel['cancelled'] ?? null, 'cancel normalization infers accepted cancellation', $failures, $passes );

$completed_cancel = AgentsAPI\AI\WP_Agent_Run_Control::normalize_cancel_result(
	array(
		'run_id'    => 'run-3',
		'status'    => 'completed',
		'cancelled' => false,
	),
	'agents_run_invalid_cancel'
);
agents_api_smoke_assert_equals( false, $completed_cancel['cancelled'] ?? null, 'cancel normalization preserves explicit terminal cancellation state', $failures, $passes );

$events = AgentsAPI\AI\WP_Agent_Run_Control::normalize_events_result(
	array(
		'run_id'   => 'run-4',
		'status'   => 'running',
		'events'   => array(
			array(
				'id'         => 123,
				'type'       => 'tool_call',
				'message'    => true,
				'created_at' => '2026-01-01T00:00:00Z',
				'metadata'   => array(
					'tool_name' => 'client/tool',
					0           => 'ignored',
				),
			),
			'ignored',
		),
		'cursor'   => 456,
		'has_more' => 1,
	),
	'agents_run_invalid_events'
);
agents_api_smoke_assert_equals( '123', $events['events'][0]['id'] ?? null, 'event normalization stringifies scalar event ids', $failures, $passes );
agents_api_smoke_assert_equals( '1', $events['events'][0]['message'] ?? null, 'event normalization stringifies scalar messages', $failures, $passes );
agents_api_smoke_assert_equals( 'client/tool', $events['events'][0]['metadata']['tool_name'] ?? null, 'event normalization preserves string-keyed metadata', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 0, $events['events'][0]['metadata'] ?? array() ), 'event normalization drops non-string metadata keys', $failures, $passes );
agents_api_smoke_assert_equals( '456', $events['cursor'] ?? null, 'event normalization stringifies cursors', $failures, $passes );
agents_api_smoke_assert_equals( true, $events['has_more'] ?? null, 'event normalization coerces has_more', $failures, $passes );

agents_api_smoke_finish( 'run-control normalization', $failures, $passes );
