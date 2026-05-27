<?php
/**
 * Pure-PHP smoke test for WP_Agent_Conversation_Loop interrupt sources.
 *
 * Run with: php tests/conversation-loop-interrupt-source-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-conversation-loop-interrupt-source-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

echo "\n[1] Interrupt source can cancel between turns:\n";

$events               = array();
$source_calls         = 0;
$source_message_count = 0;
$source_turn          = 0;
$cancel_result        = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'start' ) ),
	static function ( array $messages, array $context ): array {
		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'turn ' . $context['turn'] );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'        => 3,
		'should_continue'  => static function (): bool {
			return true;
		},
		'interrupt_source' => static function ( AgentsAPI\AI\WP_Agent_Conversation_Request $request, array $context ) use ( &$source_calls, &$source_message_count, &$source_turn ): ?array {
			++$source_calls;
			$source_message_count = count( $request->messages() );
			$source_turn          = (int) $context['turn'];

			return AgentsAPI\AI\WP_Agent_Message::text(
				'user',
				'Cancel this run.',
				array(
					'interrupt_action' => 'cancel',
					'source'           => 'operator',
				)
			);
		},
		'on_event'         => static function ( string $event, array $payload ) use ( &$events ): void {
			$events[] = array(
				'event'   => $event,
				'payload' => $payload,
			);
		},
	)
);

agents_api_smoke_assert_equals( 1, $source_calls, 'interrupt source was checked once before cancel', $failures, $passes );
agents_api_smoke_assert_equals( 2, $source_message_count, 'interrupt source receives current transcript', $failures, $passes );
agents_api_smoke_assert_equals( 1, $source_turn, 'interrupt source receives turn context', $failures, $passes );
agents_api_smoke_assert_equals( 'interrupted', $cancel_result['status'] ?? '', 'cancel interrupt returns interrupted status', $failures, $passes );
agents_api_smoke_assert_equals( false, $cancel_result['completed'], 'cancel interrupt marks result incomplete', $failures, $passes );
agents_api_smoke_assert_equals( 'cancel', $cancel_result['interrupted']['action'] ?? '', 'interrupted diagnostics record cancel action', $failures, $passes );
agents_api_smoke_assert_equals( 3, count( $cancel_result['messages'] ), 'cancel interrupt is appended to transcript', $failures, $passes );
agents_api_smoke_assert_equals( 'Cancel this run.', $cancel_result['messages'][2]['content'] ?? '', 'cancel interrupt content is preserved', $failures, $passes );

$interrupt_events = array_values(
	array_filter(
		$events,
		static function ( array $event ): bool {
			return 'interrupt_received' === $event['event'];
		}
	)
);

agents_api_smoke_assert_equals( 1, count( $interrupt_events ), 'interrupt_received event is emitted once', $failures, $passes );
agents_api_smoke_assert_equals( 'cancel', $interrupt_events[0]['payload']['action'] ?? '', 'interrupt event records action', $failures, $passes );
agents_api_smoke_assert_equals( 'operator', $interrupt_events[0]['payload']['message']['metadata']['source'] ?? '', 'interrupt event includes normalized message', $failures, $passes );

$result_events = array_values(
	array_filter(
		$cancel_result['events'],
		static function ( array $event ): bool {
			return 'interrupt_received' === ( $event['type'] ?? '' );
		}
	)
);

agents_api_smoke_assert_equals( 1, count( $result_events ), 'result includes interrupt event', $failures, $passes );

echo "\n[2] Non-cancel interrupt source injects context and loop continues:\n";

$turn_two_saw_interrupt = false;
$redirect_calls         = 0;
$redirect_result        = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'start' ) ),
	static function ( array $messages, array $context ) use ( &$turn_two_saw_interrupt ): array {
		if ( 2 === $context['turn'] ) {
			$turn_two_saw_interrupt = 'Please use the shorter path.' === ( $messages[2]['content'] ?? '' );
		}

		$messages[] = AgentsAPI\AI\WP_Agent_Message::text( 'assistant', 'turn ' . $context['turn'] );

		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'events'                 => array(),
		);
	},
	array(
		'max_turns'        => 2,
		'should_continue'  => static function ( array $result, array $context ): bool {
			return 1 === $context['turn'];
		},
		'interrupt_source' => static function () use ( &$redirect_calls ): ?array {
			++$redirect_calls;

			if ( 1 < $redirect_calls ) {
				return null;
			}

			return AgentsAPI\AI\WP_Agent_Message::text(
				'user',
				'Please use the shorter path.',
				array( 'interrupt_action' => 'redirect' )
			);
		},
	)
);

agents_api_smoke_assert_equals( true, $turn_two_saw_interrupt, 'next turn receives injected interrupt message', $failures, $passes );
agents_api_smoke_assert_equals( true, $redirect_result['completed'], 'redirect interrupt does not mark run incomplete', $failures, $passes );
agents_api_smoke_assert_equals( 4, count( $redirect_result['messages'] ), 'redirect interrupt preserves full transcript', $failures, $passes );

agents_api_smoke_finish( 'Agents API conversation loop interrupt source', $failures, $passes );
