<?php
/**
 * Generic agent conversation loop facade.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\AI\Tools\ToolCall;
use AgentsAPI\AI\Tools\ToolExecutionCore;
use AgentsAPI\AI\Tools\ToolExecutionResult;
use AgentsAPI\AI\Tools\ToolExecutorInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sequences multi-turn agent execution around caller-owned adapters.
 *
 * The loop owns neutral transcript normalization, optional compaction,
 * turn sequencing, result validation, stop-condition dispatch, optional
 * tool-call mediation, completion policy, transcript persistence, and
 * lifecycle event emission. Callers supply prompt assembly, provider/model
 * dispatch, and concrete tool execution through adapters.
 */
class AgentConversationLoop {

	/**
	 * Run a conversation loop.
	 *
	 * The turn runner receives `(array $messages, array $context)` and must return
	 * an array. When tool mediation is enabled (`tool_executor` + `tool_declarations`),
	 * the turn runner can return a `tool_calls` key (array of `{name, parameters}`)
	 * and the loop handles execution internally. Otherwise the turn runner must
	 * return an `AgentConversationResult`-compatible array as before.
	 *
 	 * Supported options:
	 *
	 * - `max_turns` (int): Maximum turns to run. Defaults to 1.
	 * - `budgets` (IterationBudget[]): Named iteration budgets for bounded execution.
	 * - `context` (array): Caller-owned context passed to adapters.
	 * - `should_continue` (callable|null): Caller-owned continuation policy.
	 * - `compaction_policy` (array|null): Optional compaction policy.
	 * - `summarizer` (callable|null): Optional compaction summarizer.
	 * - `tool_executor` (ToolExecutorInterface|null): Tool execution adapter.
	 * - `tool_declarations` (array|null): Tool declarations keyed by name.
	 * - `completion_policy` (AgentConversationCompletionPolicyInterface|null): Typed completion policy.
	 * - `transcript_persister` (AgentConversationTranscriptPersisterInterface|null): Transcript persister.
	 * - `on_event` (callable|null): Lifecycle event sink `fn(string $event, array $payload)`.
	 *
	 * @param array    $messages    Initial transcript messages.
	 * @param callable $turn_runner Caller-owned turn adapter.
	 * @param array    $options     Loop options.
	 * @return array Normalized conversation result.
	 */
	public static function run( array $messages, callable $turn_runner, array $options = array() ): array {
		$max_turns             = self::max_turns( $options['max_turns'] ?? 1 );
		$context               = isset( $options['context'] ) && is_array( $options['context'] ) ? $options['context'] : array();
		$should_continue       = $options['should_continue'] ?? null;
		$tool_executor         = self::resolve_tool_executor( $options );
		$tool_declarations     = self::resolve_tool_declarations( $options );
		$completion_policy     = self::resolve_completion_policy( $options );
		$transcript_persister  = self::resolve_transcript_persister( $options );
		$on_event              = self::resolve_event_sink( $options );
		$budget_resolution     = self::resolve_budgets( $options, $max_turns );
		$budgets               = $budget_resolution['budgets'];
		$has_explicit_turns    = $budget_resolution['has_explicit_turns'];
		$mediation_enabled     = null !== $tool_executor && ! empty( $tool_declarations );
		$messages              = AgentMessageEnvelope::normalize_many( $messages );
		$events                = array();
		$tool_results          = array();
		$conversation_complete = false;
		$exceeded_budget       = null;

		for ( $turn = 1; $turn <= $max_turns; ++$turn ) {
			$turn_context         = $context;
			$turn_context['turn'] = $turn;

			self::emit_event( $on_event, 'turn_started', array(
				'turn'          => $turn,
				'max_turns'     => $max_turns,
				'message_count' => count( $messages ),
			) );

			$compaction = self::maybe_compact( $messages, $options );
			$messages   = $compaction['messages'];
			$events     = array_merge( $events, $compaction['events'] );

			try {
				$result = call_user_func( $turn_runner, $messages, $turn_context );
			} catch ( \Throwable $error ) {
				self::emit_event( $on_event, 'failed', array(
					'turn'  => $turn,
					'error' => $error->getMessage(),
				) );

				$failure_result = AgentConversationResult::normalize( array(
					'messages'               => $messages,
					'tool_execution_results' => $tool_results,
					'events'                 => $events,
				) );

				self::persist_transcript( $transcript_persister, $messages, $options, $failure_result );
				throw $error;
			}

			if ( ! is_array( $result ) ) {
				$error = new \InvalidArgumentException( 'invalid_agent_conversation_loop: turn runner must return an array' );

				self::emit_event( $on_event, 'failed', array(
					'turn'  => $turn,
					'error' => $error->getMessage(),
				) );

				self::persist_transcript( $transcript_persister, $messages, $options, array(
					'messages'               => $messages,
					'tool_execution_results' => $tool_results,
					'events'                 => $events,
				) );

				throw $error;
			}

			// When mediation is enabled, the turn runner returns tool_calls
			// and the loop handles execution. Otherwise, the legacy path applies.
			if ( $mediation_enabled && isset( $result['tool_calls'] ) && is_array( $result['tool_calls'] ) ) {
				$mediation_result = self::mediate_tool_calls(
					$result,
					$tool_executor,
					$tool_declarations,
					$completion_policy,
					$turn_context,
					$turn,
					$on_event,
					$budgets
				);

				$messages              = $mediation_result['messages'];
				$tool_results          = array_merge( $tool_results, $mediation_result['tool_execution_results'] );
				$events                = array_merge( $events, $mediation_result['events'] );
				$conversation_complete = $mediation_result['conversation_complete'];
				$exceeded_budget       = $mediation_result['exceeded_budget'];
			} else {
				// Legacy path: turn runner handles everything internally.
				$result       = AgentConversationResult::normalize( $result );
				$messages     = $result['messages'];
				$tool_results = array_merge( $tool_results, $result['tool_execution_results'] );
				$events       = array_merge( $events, self::normalize_events( $result['events'] ?? array() ) );

				// Apply completion policy to tool results from the turn runner
				// when the loop owns policy but the turn runner handled execution.
				if ( null !== $completion_policy && ! empty( $result['tool_execution_results'] ) ) {
					foreach ( $result['tool_execution_results'] as $tool_exec_result ) {
						$tool_name = $tool_exec_result['tool_name'] ?? '';
						$tool_def  = $tool_declarations[ $tool_name ] ?? null;
						$decision  = $completion_policy->recordToolResult(
							$tool_name,
							is_array( $tool_def ) ? $tool_def : null,
							$tool_exec_result,
							$turn_context,
							$turn
						);
						if ( $decision->isComplete() ) {
							$conversation_complete = true;
							break;
						}
					}
				}
			}

			// Stop conditions: budget exceeded, completion policy, or legacy should_continue.
			if ( null !== $exceeded_budget ) {
				break;
			}

			if ( $conversation_complete ) {
				break;
			}

			// Increment the turns budget after a completed turn.
			// Synthesized turns budgets (from max_turns) break the loop silently
			// to preserve backwards compatibility. Explicit turns budgets signal
			// budget_exceeded so callers know the stop reason.
			$turns_exceeded = self::increment_budget( $budgets, 'turns', $has_explicit_turns ? $on_event : null );
			if ( null !== $turns_exceeded ) {
				if ( $has_explicit_turns ) {
					$exceeded_budget = $turns_exceeded;
				}
				break;
			}

			if ( ! is_callable( $should_continue ) || ! call_user_func( $should_continue, $result, $turn_context ) ) {
				break;
			}
		}

		$final_result_data = array(
			'messages'               => $messages,
			'tool_execution_results' => $tool_results,
			'events'                 => $events,
		);

		if ( null !== $exceeded_budget ) {
			$final_result_data['status'] = 'budget_exceeded';
			$final_result_data['budget'] = $exceeded_budget;
		}

		$final_result = AgentConversationResult::normalize( $final_result_data );

		self::persist_transcript( $transcript_persister, $messages, $options, $final_result );

		self::emit_event( $on_event, 'completed', array(
			'turn'          => $turn,
			'message_count' => count( $messages ),
			'tool_results'  => count( $tool_results ),
		) );

		return $final_result;
	}

	/**
	 * Mediate tool calls extracted from the turn runner result.
	 *
	 * Handles the tool-call → validate → execute → message assembly cycle.
	 *
	 * @param array                                             $result          Turn runner result with tool_calls.
	 * @param ToolExecutorInterface                             $executor        Tool executor adapter.
	 * @param array                                             $declarations    Tool declarations keyed by name.
	 * @param AgentConversationCompletionPolicyInterface|null   $policy          Completion policy.
	 * @param array                                             $turn_context    Turn context.
	 * @param int                                               $turn            Current turn number.
	 * @param callable|null                                     $on_event        Event sink.
	 * @param array<string, IterationBudget>                    $budgets         Named iteration budgets.
	 * @return array{messages: array, tool_execution_results: array, events: array, conversation_complete: bool, exceeded_budget: string|null}
	 */
	private static function mediate_tool_calls(
		array $result,
		ToolExecutorInterface $executor,
		array $declarations,
		?AgentConversationCompletionPolicyInterface $policy,
		array $turn_context,
		int $turn,
		?callable $on_event,
		array $budgets = array()
	): array {
		$core                   = new ToolExecutionCore();
		$messages               = isset( $result['messages'] ) && is_array( $result['messages'] )
			? AgentMessageEnvelope::normalize_many( $result['messages'] )
			: array();
		$tool_calls             = $result['tool_calls'];
		$tool_execution_results = array();
		$events                 = array();
		$complete               = false;
		$exceeded_budget        = null;

		// If the turn runner returned text content, add it as an assistant message.
		if ( isset( $result['content'] ) && is_string( $result['content'] ) && '' !== $result['content'] ) {
			$messages[] = AgentMessageEnvelope::text( 'assistant', $result['content'] );
		}

		foreach ( $tool_calls as $raw_call ) {
			$tool_name  = $raw_call['name'] ?? $raw_call['tool_name'] ?? '';
			$parameters = $raw_call['parameters'] ?? array();

			if ( ! is_string( $tool_name ) || '' === $tool_name ) {
				continue;
			}

			self::emit_event( $on_event, 'tool_call', array(
				'turn'       => $turn,
				'tool_name'  => $tool_name,
				'parameters' => $parameters,
			) );

			// Add tool-call message to transcript.
			$messages[] = AgentMessageEnvelope::toolCall(
				'Calling ' . $tool_name,
				$tool_name,
				is_array( $parameters ) ? $parameters : array(),
				$turn
			);

			// Execute through ToolExecutionCore.
			$exec_result = $core->executeTool(
				$tool_name,
				is_array( $parameters ) ? $parameters : array(),
				$declarations,
				$executor,
				$turn_context
			);

			$tool_def = $declarations[ $tool_name ] ?? null;

			self::emit_event( $on_event, 'tool_result', array(
				'turn'      => $turn,
				'tool_name' => $tool_name,
				'success'   => (bool) ( $exec_result['success'] ?? false ),
			) );

			// Build the tool_execution_results entry.
			$tool_execution_results[] = array(
				'tool_name'  => $tool_name,
				'result'     => $exec_result,
				'parameters' => is_array( $parameters ) ? $parameters : array(),
				'turn_count' => $turn,
			);

			// Add tool-result message to transcript.
			$result_content = ( $exec_result['success'] ?? false )
				? self::json_encode_safe( $exec_result['result'] ?? array() )
				: ( $exec_result['error'] ?? 'Tool execution failed.' );

			$messages[] = AgentMessageEnvelope::toolResult(
				is_string( $result_content ) ? $result_content : '',
				$tool_name,
				$exec_result
			);

			// Increment tool-call budgets: total and per-tool-name.
			$exceeded_budget = self::increment_budget( $budgets, 'tool_calls', $on_event );
			if ( null === $exceeded_budget ) {
				$exceeded_budget = self::increment_budget( $budgets, 'tool_calls_' . $tool_name, $on_event );
			}

			if ( null !== $exceeded_budget ) {
				$complete = true;
				break;
			}

			// Consult completion policy.
			if ( null !== $policy ) {
				$decision = $policy->recordToolResult(
					$tool_name,
					is_array( $tool_def ) ? $tool_def : null,
					$exec_result,
					$turn_context,
					$turn
				);

				if ( $decision->isComplete() ) {
					$complete = true;
					$events[] = array(
						'type'     => 'completion_policy_stop',
						'metadata' => array(
							'tool_name' => $tool_name,
							'turn'      => $turn,
							'message'   => $decision->message(),
							'context'   => $decision->context(),
						),
					);
					break;
				}
			}
		}

		// No tool calls at all = natural completion (assistant responded with text only).
		if ( empty( $tool_calls ) ) {
			$complete = true;
		}

		return array(
			'messages'               => $messages,
			'tool_execution_results' => $tool_execution_results,
			'events'                 => $events,
			'conversation_complete'  => $complete,
			'exceeded_budget'        => $exceeded_budget,
		);
	}

	/**
	 * Persist the transcript through the persister if available.
	 *
	 * @param AgentConversationTranscriptPersisterInterface|null $persister Transcript persister.
	 * @param array                                              $messages  Final messages.
	 * @param array                                              $options   Loop options.
	 * @param array                                              $result    Loop result.
	 */
	private static function persist_transcript(
		?AgentConversationTranscriptPersisterInterface $persister,
		array $messages,
		array $options,
		array $result
	): void {
		if ( null === $persister ) {
			return;
		}

		$request = $options['request'] ?? null;

		if ( ! $request instanceof AgentConversationRequest ) {
			// Build a minimal request from the loop options for persistence context.
			$request = new AgentConversationRequest(
				$messages,
				array(),
				null,
				isset( $options['context'] ) && is_array( $options['context'] ) ? $options['context'] : array(),
				array(),
				self::max_turns( $options['max_turns'] ?? 1 )
			);
		}

		try {
			$persister->persist( $messages, $request, $result );
		} catch ( \Throwable $error ) {
			// Persister failures must not change loop results.
			unset( $error );
		}
	}

	/**
	 * Emit a lifecycle event through the event sink.
	 *
	 * Observer failures are swallowed to prevent changing loop results.
	 *
	 * @param callable|null $on_event Event sink.
	 * @param string        $event    Event name.
	 * @param array         $payload  Event payload.
	 */
	private static function emit_event( ?callable $on_event, string $event, array $payload = array() ): void {
		if ( null === $on_event ) {
			return;
		}

		try {
			call_user_func( $on_event, $event, $payload );
		} catch ( \Throwable $error ) {
			// Observer failures must not change loop results.
			unset( $error );
		}
	}

	/**
	 * Resolve the tool executor from options.
	 *
	 * @param array $options Loop options.
	 * @return ToolExecutorInterface|null
	 */
	private static function resolve_tool_executor( array $options ): ?ToolExecutorInterface {
		$executor = $options['tool_executor'] ?? null;
		return $executor instanceof ToolExecutorInterface ? $executor : null;
	}

	/**
	 * Resolve tool declarations from options.
	 *
	 * @param array $options Loop options.
	 * @return array Tool declarations keyed by name.
	 */
	private static function resolve_tool_declarations( array $options ): array {
		$declarations = $options['tool_declarations'] ?? null;
		return is_array( $declarations ) ? $declarations : array();
	}

	/**
	 * Resolve the completion policy from options.
	 *
	 * @param array $options Loop options.
	 * @return AgentConversationCompletionPolicyInterface|null
	 */
	private static function resolve_completion_policy( array $options ): ?AgentConversationCompletionPolicyInterface {
		$policy = $options['completion_policy'] ?? null;
		return $policy instanceof AgentConversationCompletionPolicyInterface ? $policy : null;
	}

	/**
	 * Resolve the transcript persister from options.
	 *
	 * @param array $options Loop options.
	 * @return AgentConversationTranscriptPersisterInterface|null
	 */
	private static function resolve_transcript_persister( array $options ): ?AgentConversationTranscriptPersisterInterface {
		$persister = $options['transcript_persister'] ?? null;
		return $persister instanceof AgentConversationTranscriptPersisterInterface ? $persister : null;
	}

	/**
	 * Resolve the event sink from options.
	 *
	 * @param array $options Loop options.
	 * @return callable|null
	 */
	private static function resolve_event_sink( array $options ): ?callable {
		$sink = $options['on_event'] ?? null;
		return is_callable( $sink ) ? $sink : null;
	}

	/**
	 * Resolve iteration budgets from options.
	 *
	 * Synthesizes a `turns` budget from `max_turns` when no explicit `turns`
	 * budget is provided, preserving backwards compatibility.
	 *
	 * @param array $options   Loop options.
	 * @param int   $max_turns Resolved max turns value.
	 * @return array{budgets: array<string, IterationBudget>, has_explicit_turns: bool}
	 */
	private static function resolve_budgets( array $options, int $max_turns ): array {
		$raw     = $options['budgets'] ?? array();
		$budgets = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $budget ) {
				if ( $budget instanceof IterationBudget ) {
					$budgets[ $budget->name() ] = $budget;
				}
			}
		}

		$has_explicit_turns = isset( $budgets['turns'] );

		// Synthesize a turns budget from max_turns when none was explicitly provided.
		if ( ! $has_explicit_turns ) {
			$budgets['turns'] = new IterationBudget( 'turns', $max_turns );
		}

		return array(
			'budgets'            => $budgets,
			'has_explicit_turns' => $has_explicit_turns,
		);
	}

	/**
	 * Increment a named budget and check for exceedance.
	 *
	 * When the budget is exceeded, emits a `budget_exceeded` event and returns
	 * the budget name. Returns null when the budget is not exceeded or does not exist.
	 *
	 * @param array<string, IterationBudget> $budgets  Named budgets.
	 * @param string                         $name     Budget name to increment.
	 * @param callable|null                  $on_event Event sink.
	 * @return string|null Exceeded budget name, or null.
	 */
	private static function increment_budget( array $budgets, string $name, ?callable $on_event ): ?string {
		if ( ! isset( $budgets[ $name ] ) ) {
			return null;
		}

		$budget = $budgets[ $name ];
		$budget->increment();

		if ( $budget->exceeded() ) {
			self::emit_event( $on_event, 'budget_exceeded', array(
				'budget'  => $name,
				'current' => $budget->current(),
				'ceiling' => $budget->ceiling(),
			) );

			return $name;
		}

		return null;
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
			'events'   => self::normalize_events( $compaction['events'] ),
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

	/**
	 * Encode data to JSON with a pure-PHP fallback for smoke tests.
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false Encoded JSON or false on failure.
	 */
	private static function json_encode_safe( $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
		return json_encode( $data );
	}
}
