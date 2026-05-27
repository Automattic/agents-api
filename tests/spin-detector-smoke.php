<?php
/**
 * Pure-PHP smoke test for loop spin detector contracts.
 *
 * Run with: php tests/spin-detector-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-spin-detector-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Spin detector contracts are available:\n";
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\WP_Agent_Spin_Signature' ), 'spin signature value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'AgentsAPI\\AI\\WP_Agent_Spin_Detector' ), 'spin detector interface is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\WP_Agent_Consecutive_Spin_Detector' ), 'consecutive spin detector is available', $failures, $passes );

echo "\n[2] Signatures normalize argument order deterministically:\n";
$signature_a = new AgentsAPI\AI\WP_Agent_Spin_Signature(
	'client/search',
	array(
		'b' => 2,
		'a' => array(
			'd' => 4,
			'c' => 3,
		),
	)
);
$signature_b = new AgentsAPI\AI\WP_Agent_Spin_Signature(
	'client/search',
	array(
		'a' => array(
			'c' => 3,
			'd' => 4,
		),
		'b' => 2,
	)
);
agents_api_smoke_assert_equals( $signature_a->hash(), $signature_b->hash(), 'same tool call shape hashes identically', $failures, $passes );
agents_api_smoke_assert_equals( $signature_a->parameters_hash(), $signature_b->parameters_hash(), 'same parameters hash identically', $failures, $passes );

echo "\n[3] Consecutive detector reports repeated signatures at threshold:\n";
$detector = new AgentsAPI\AI\WP_Agent_Consecutive_Spin_Detector( 2 );
agents_api_smoke_assert_equals( false, $detector->record_signature( $signature_a ), 'first signature does not stall', $failures, $passes );
agents_api_smoke_assert_equals( true, $detector->record_signature( $signature_b ), 'second matching signature stalls', $failures, $passes );
agents_api_smoke_assert_equals( 2, $detector->repeat_count(), 'repeat count reaches threshold', $failures, $passes );

echo "\n[4] Conversation loop stops as stalled when detector fires:\n";
$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array( 'ok' => true ),
		);
	}
};
$events   = array();
$turns    = 0;
$result   = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
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
		'max_turns'         => 5,
		'tool_executor'     => $executor,
		'tool_declarations' => array(
			'client/search' => array(
				'name'       => 'client/search',
				'parameters' => array(),
			),
		),
		'spin_detector'     => new AgentsAPI\AI\WP_Agent_Consecutive_Spin_Detector( 2 ),
		'should_continue'   => static function (): bool {
			return true;
		},
		'on_event'          => static function ( string $event, array $payload ) use ( &$events ): void {
			$events[] = array( 'event' => $event, 'payload' => $payload );
		},
	)
);

agents_api_smoke_assert_equals( 2, $turns, 'loop stops on second repeated tool-call turn', $failures, $passes );
agents_api_smoke_assert_equals( 'stalled', $result['status'] ?? null, 'result status is stalled', $failures, $passes );
agents_api_smoke_assert_equals( false, $result['completed'] ?? true, 'stalled result is incomplete', $failures, $passes );
agents_api_smoke_assert_equals( 2, $result['stalled']['repeat_count'] ?? null, 'stalled diagnostics include repeat count', $failures, $passes );
agents_api_smoke_assert_equals( 'client/search', $result['stalled']['tool_name'] ?? null, 'stalled diagnostics include tool name', $failures, $passes );

$stall_events = array_filter(
	$events,
	static function ( array $entry ): bool {
		return 'loop_stalled' === $entry['event'];
	}
);
agents_api_smoke_assert_equals( 1, count( $stall_events ), 'loop_stalled event is emitted', $failures, $passes );

agents_api_smoke_finish( 'Spin detector', $failures, $passes );
