<?php
/**
 * Generic agent conversation loop facade.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sequences multi-turn agent execution around caller-owned adapters.
 *
 * The loop owns only neutral transcript normalization, optional compaction,
 * turn sequencing, result validation, and stop-condition dispatch. Callers
 * supply prompt assembly, provider/model dispatch, concrete tool execution,
 * persistence, and product policy through adapters.
 */
class AgentConversationLoop {

	/**
	 * Run a conversation loop.
	 *
	 * The turn runner receives `(array $messages, array $context)` and must return
	 * an `AgentConversationResult`-compatible array. The optional continuation
	 * policy receives `(array $result, array $context)` and returns true when the
	 * loop should run another turn.
	 *
	 * Supported options:
	 *
	 * - `max_turns` (int): Maximum turns to run. Defaults to 1.
	 * - `context` (array): Caller-owned context passed to adapters.
	 * - `should_continue` (callable|null): Caller-owned continuation policy.
	 * - `compaction_policy` (array|null): Optional compaction policy.
	 * - `summarizer` (callable|null): Optional compaction summarizer.
	 *
	 * @param array    $messages    Initial transcript messages.
	 * @param callable $turn_runner Caller-owned turn adapter.
	 * @param array    $options     Loop options.
	 * @return array Normalized conversation result.
	 */
	public static function run( array $messages, callable $turn_runner, array $options = array() ): array {
		$max_turns       = self::max_turns( $options['max_turns'] ?? 1 );
		$context         = isset( $options['context'] ) && is_array( $options['context'] ) ? $options['context'] : array();
		$should_continue = $options['should_continue'] ?? null;
		$messages        = AgentMessageEnvelope::normalize_many( $messages );
		$events          = array();
		$tool_results    = array();

		for ( $turn = 1; $turn <= $max_turns; ++$turn ) {
			$turn_context         = $context;
			$turn_context['turn'] = $turn;

			$compaction = self::maybe_compact( $messages, $options );
			$messages   = $compaction['messages'];
			$events     = array_merge( $events, $compaction['events'] );

			$result = call_user_func( $turn_runner, $messages, $turn_context );
			if ( ! is_array( $result ) ) {
				throw new \InvalidArgumentException( 'invalid_agent_conversation_loop: turn runner must return an array' );
			}

			$result       = AgentConversationResult::normalize( $result );
			$messages     = $result['messages'];
			$tool_results = array_merge( $tool_results, $result['tool_execution_results'] );
			$events       = array_merge( $events, self::normalize_events( $result['events'] ?? array() ) );

			if ( ! is_callable( $should_continue ) || ! call_user_func( $should_continue, $result, $turn_context ) ) {
				break;
			}
		}

		return AgentConversationResult::normalize(
			array(
				'messages'               => $messages,
				'tool_execution_results' => $tool_results,
				'events'                 => $events,
			)
		);
	}

	/**
	 * Apply optional transcript compaction through caller-owned summarization.
	 *
	 * @param array $messages Current messages.
	 * @param array $options  Loop options.
	 * @return array{messages: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>}
	 */
	private static function maybe_compact( array $messages, array $options ): array {
		$policy     = $options['compaction_policy'] ?? null;
		$summarizer = $options['summarizer'] ?? null;

		if ( ! is_array( $policy ) || ! is_callable( $summarizer ) ) {
			return array(
				'messages' => $messages,
				'events'   => array(),
			);
		}

		$compaction = AgentConversationCompaction::compact( $messages, $policy, $summarizer );
		return array(
			'messages' => $compaction['messages'],
			'events'   => self::normalize_events( $compaction['events'] ?? array() ),
		);
	}

	/**
	 * Normalize the max turn option.
	 *
	 * @param mixed $value Raw option.
	 * @return int
	 */
	private static function max_turns( $value ): int {
		return max( 1, (int) $value );
	}

	/**
	 * Normalize caller-owned lifecycle events.
	 *
	 * @param mixed $events Raw events.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_events( $events ): array {
		if ( ! is_array( $events ) ) {
			throw new \InvalidArgumentException( 'invalid_agent_conversation_loop: events must be an array' );
		}

		$normalized = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				throw new \InvalidArgumentException( 'invalid_agent_conversation_loop: event must be an array' );
			}
			$normalized[] = $event;
		}

		return $normalized;
	}
}
