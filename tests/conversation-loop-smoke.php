<?php
/**
 * Pure-PHP smoke test for the Agents API conversation loop facade.
 *
 * Run with: php tests/conversation-loop-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-loop-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Loop delegates turn execution and continuation policy to callers:\n";
$turns  = array();
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'hello' ) ),
	static function ( array $messages, array $context ) use ( &$turns ): array {
		$turns[]    = $context['turn'];
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'turn ' . $context['turn'] );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(
				array(
					'type'     => 'turn_completed',
					'metadata' => array( 'turn' => $context['turn'] ),
				),
			),
		);
	},
	array(
		'max_turns'       => 2,
		'should_continue' => static function ( array $turn_result, array $context ): bool {
			unset( $turn_result );
			return 1 === $context['turn'];
		},
	)
);

agents_api_smoke_assert_equals( array( 1, 2 ), $turns, 'loop runs until caller policy stops', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $result['messages'] ), 'loop returns the final normalized transcript', $failures, $passes );
agents_api_smoke_assert_equals( 2, count( $result['events'] ), 'loop preserves caller lifecycle events', $failures, $passes );

echo "\n[2] Loop can apply caller-supplied compaction without owning model dispatch:\n";
$summarized_messages = array();
$compacted_result    = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array(
		array( 'role' => 'user', 'content' => 'one' ),
		array( 'role' => 'assistant', 'content' => 'two' ),
		array( 'role' => 'user', 'content' => 'three' ),
	),
	static function ( array $messages ) use ( &$summarized_messages ): array {
		$summarized_messages = $messages;
		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
		);
	},
	array(
		'compaction_policy' => array(
			'enabled'         => true,
			'max_messages'    => 2,
			'recent_messages' => 1,
		),
		'summarizer'         => static function ( array $messages ): string {
			return 'summary of ' . count( $messages ) . ' messages';
		},
	)
);

agents_api_smoke_assert_equals( 2, count( $summarized_messages ), 'turn runner receives compacted transcript', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Compaction::EVENT_COMPLETED, $compacted_result['events'][1]['type'], 'loop surfaces compaction lifecycle events', $failures, $passes );

echo "\n[3] Loop validates adapter result shape:\n";
$threw = false;
try {
	AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'hello' ) ),
		static function (): string {
			return 'not an array';
		}
	);
} catch ( InvalidArgumentException $e ) {
	$threw = str_starts_with( $e->getMessage(), 'invalid_agent_conversation_loop:' );
}
agents_api_smoke_assert_equals( true, $threw, 'loop rejects non-array adapter results', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop', $failures, $passes );
