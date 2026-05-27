<?php
/**
 * Pure-PHP smoke test for identical failure tracker contracts.
 *
 * Run with: php tests/identical-failure-tracker-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-identical-failure-tracker-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Identical failure contracts are available:\n";
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\WP_Agent_Identical_Failure_Signature' ), 'failure signature value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'AgentsAPI\\AI\\WP_Agent_Identical_Failure_Tracker' ), 'failure tracker interface is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\WP_Agent_Consecutive_Identical_Failure_Tracker' ), 'consecutive failure tracker is available', $failures, $passes );

echo "\n[2] Failure signatures normalize argument order and error code:\n";
$signature_a = new AgentsAPI\AI\WP_Agent_Identical_Failure_Signature(
	'client/search',
	array(
		'b' => 2,
		'a' => 1,
	),
	array(
		'success'    => false,
		'error_code' => 'Rate Limited',
	)
);
$signature_b = new AgentsAPI\AI\WP_Agent_Identical_Failure_Signature(
	'client/search',
	array(
		'a' => 1,
		'b' => 2,
	),
	array(
		'success'  => false,
		'metadata' => array( 'error_type' => 'rate_limited' ),
	)
);
agents_api_smoke_assert_equals( $signature_a->hash(), $signature_b->hash(), 'same failed call hashes identically', $failures, $passes );
agents_api_smoke_assert_equals( 'rate_limited', $signature_a->error_code(), 'error code is normalized', $failures, $passes );

echo "\n[3] Consecutive tracker nudges at threshold:\n";
$tracker = new AgentsAPI\AI\WP_Agent_Consecutive_Identical_Failure_Tracker( 2 );
agents_api_smoke_assert_equals( null, $tracker->record_failure( $signature_a ), 'first failure does not nudge', $failures, $passes );
$nudge = $tracker->record_failure( $signature_b );
agents_api_smoke_assert_equals( true, is_string( $nudge ) && str_contains( $nudge, 'client/search' ), 'second matching failure returns nudge', $failures, $passes );
agents_api_smoke_assert_equals( 2, $tracker->repeat_count(), 'repeat count reaches threshold', $failures, $passes );

echo "\n[4] Conversation loop injects nudge after repeated failures:\n";
$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		return array(
			'success'    => false,
			'tool_name'  => $tool_call['tool_name'],
			'error'      => 'The service rejected this request.',
			'error_code' => 'service_rejected',
		);
	}
};
$events = array();
$turns  = 0;
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'go' ) ),
	static function ( array $messages ) use ( &$turns ): array {
		++$turns;

		return array(
			'messages'   => $messages,
			'tool_calls' => array(
				array(
					'name'       => 'client/search',
					'parameters' => array( 'query' => 'same' ),
				),
			),
		);
	},
	array(
		'max_turns'                 => 2,
		'tool_executor'             => $executor,
		'tool_declarations'         => array(
			'client/search' => array(
				'name'       => 'client/search',
				'parameters' => array(),
			),
		),
		'identical_failure_tracker' => new AgentsAPI\AI\WP_Agent_Consecutive_Identical_Failure_Tracker( 2 ),
		'should_continue'           => static function (): bool {
			return true;
		},
		'on_event'                  => static function ( string $event, array $payload ) use ( &$events ): void {
			$events[] = array( 'event' => $event, 'payload' => $payload );
		},
	)
);

agents_api_smoke_assert_equals( 2, $turns, 'loop runs until second identical failure', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $result['tool_execution_results'] ), 'both failed tool calls are recorded', $failures, $passes );
$last_message = $result['messages'][ count( $result['messages'] ) - 1 ];
agents_api_smoke_assert_equals( 'assistant', $last_message['role'] ?? null, 'nudge is appended as assistant guidance', $failures, $passes );
agents_api_smoke_assert_equals( true, str_contains( (string) ( $last_message['content'] ?? '' ), 'failed 2 times' ), 'nudge explains repeated failure', $failures, $passes );

$nudge_events = array_filter(
	$events,
	static function ( array $entry ): bool {
		return 'identical_failure_nudged' === $entry['event'];
	}
);
agents_api_smoke_assert_equals( 1, count( $nudge_events ), 'identical_failure_nudged event is emitted', $failures, $passes );

agents_api_smoke_finish( 'Identical failure tracker', $failures, $passes );
