<?php
/**
 * Generic agent conversation loop facade.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Call;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Result;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock;

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
class WP_Agent_Conversation_Loop {

	/**
	 * Run a conversation loop.
	 *
	 * The turn runner receives `(array $messages, array $context)` and must return
	 * an array. When tool mediation is enabled (`tool_executor` + `tool_declarations`),
	 * the turn runner can return a `tool_calls` key (array of `{name, parameters}`)
	 * and the loop handles execution internally. Otherwise the turn runner must
	 * return an `WP_Agent_Conversation_Result`-compatible array as before.
	 *
	 * Supported options:
	 *
	 * - `max_turns` (int): Maximum turns to run. Defaults to 1.
	 * - `budgets` (WP_Agent_Iteration_Budget[]): Named iteration budgets for bounded execution.
	 * - `context` (array): Caller-owned context passed to adapters.
	 * - `should_continue` (callable|null): Caller-owned continuation policy.
	 *   Defaults to `null` in the caller-managed path (which causes the loop to break
	 *   after one turn unless the caller supplies a callback). When tool
	 *   mediation is enabled (`tool_executor` + `tool_declarations` provided),
	 *   defaults to a `__return_true` callable so the loop continues until
	 *   `tool_calls` is empty (natural completion), `completion_policy` fires,
	 *   `max_turns` is reached, or a budget is exceeded — i.e. the caller no
	 *   longer needs to supply this option just to get multi-turn mediation.
	 * - `compaction_policy` (array|null): Optional compaction policy.
	 * - `summarizer` (callable|null): Optional compaction summarizer.
	 * - `tool_executor` (WP_Agent_Tool_Executor|null): Tool execution adapter.
	 * - `tool_declarations` (array|null): Tool declarations keyed by name.
	 * - `completion_policy` (WP_Agent_Conversation_Completion_Policy|null): Typed completion policy.
	 * - `transcript_lock` or `transcript_lock_store` (WP_Agent_Conversation_Lock|null): Optional transcript lock.
	 * - `transcript_session_id` (string): Session ID to lock when a lock store is provided.
	 * - `transcript_lock_ttl` (int): Lock TTL in seconds. Defaults to 300.
	 * - `transcript_persister` (WP_Agent_Transcript_Persister|null): Transcript persister.
	 * - `on_event` (callable|null): Caller-owned lifecycle event sink `fn(string $event, array $payload)`.
	 *
	 * @param array    $messages    Initial transcript messages.
	 * @param callable $turn_runner Caller-owned turn adapter.
	 * @param array    $options     Loop options.
	 * @return array Normalized conversation result.
	 */
	public static function run( array $messages, callable $turn_runner, array $options = array() ): array {
		$max_turns             = self::max_turns( $options['max_turns'] ?? 1 );
		$context               = isset( $options['context'] ) && is_array( $options['context'] ) ? $options['context'] : array();
		$tool_executor         = self::resolve_tool_executor( $options );
		$tool_declarations     = self::resolve_tool_declarations( $options );
		$should_continue       = self::resolve_should_continue( $options, $tool_executor, $tool_declarations );
		$completion_policy     = self::resolve_completion_policy( $options );
		$transcript_persister  = self::resolve_transcript_persister( $options );
		$transcript_lock       = self::resolve_transcript_lock( $options );
		$on_event              = self::resolve_event_sink( $options );
		$request               = self::resolve_request( $messages, $options );
		$lock_session_id       = self::resolve_lock_session_id( $options, $request );
		$lock_ttl              = self::resolve_lock_ttl( $options );
		$lock_token            = null;
		$budget_resolution     = self::resolve_budgets( $options, $max_turns );
		$budgets               = $budget_resolution['budgets'];
		$has_explicit_turns    = $budget_resolution['has_explicit_turns'];
		$mediation_enabled     = null !== $tool_executor && ! empty( $tool_declarations );
		$messages              = WP_Agent_Message::normalize_many( $messages );
		$events                = array();
		$tool_results          = array();
		$conversation_complete = false;
		$exceeded_budget       = null;

		// Universal observability accumulators. Turn runners may report
		// `usage` (token counts) and `request_metadata` (last provider request
		// descriptor) in their per-turn return value; the loop accumulates
		// these and exposes them in the final result so consumers don't have
		// to track them out-of-band via mutable state.
		$turns_run        = 0;
		$total_usage      = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		);
		$request_metadata = array();

		if ( null !== $transcript_lock && '' !== $lock_session_id ) {
			$lock_token = $transcript_lock->acquire_session_lock( $lock_session_id, $lock_ttl );
			if ( null === $lock_token || '' === $lock_token ) {
				self::emit_event( $on_event, 'transcript_lock_contention', array(
					'session_id' => $lock_session_id,
				) );

				return WP_Agent_Conversation_Result::normalize( array(
					'messages'               => $messages,
					'tool_execution_results' => array(),
					'events'                 => array(),
					'status'                 => 'transcript_lock_contention',
				) );
			}
		}

		try {
			for ( $turn = 1; $turn <= $max_turns; ++$turn ) {
				$turns_run            = $turn;
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

					$failure_result = WP_Agent_Conversation_Result::normalize( array(
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

				// Accumulate optional observability fields from the turn runner.
				// `usage` is a per-turn token-count array that gets summed into
				// `$total_usage`. `request_metadata` is the most recent provider
				// request descriptor and overwrites on each turn — consumers
				// typically only care about the last one.
				if ( isset( $result['usage'] ) && is_array( $result['usage'] ) ) {
					$total_usage = self::accumulate_usage( $total_usage, $result['usage'] );
				}
				if ( isset( $result['request_metadata'] ) && is_array( $result['request_metadata'] ) ) {
					$request_metadata = $result['request_metadata'];
				}

				// When mediation is enabled, the turn runner returns tool_calls
				// and the loop handles execution. Otherwise, the caller-managed path applies.
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
					// Caller-managed path: turn runner handles everything internally.
					$result       = WP_Agent_Conversation_Result::normalize( $result );
					$messages     = $result['messages'];
					$tool_results = array_merge( $tool_results, $result['tool_execution_results'] );
					$events       = array_merge( $events, self::normalize_events( $result['events'] ?? array() ) );
					if ( isset( $result['request_metadata'] ) && is_array( $result['request_metadata'] ) ) {
						$last_request_metadata = $result['request_metadata'];
					}

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

				// Stop conditions: budget exceeded, completion policy, or caller should_continue.
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
				'turn_count'             => $turns_run,
				'final_content'          => self::extract_final_content( $messages ),
				'usage'                  => $total_usage,
				'request_metadata'       => $request_metadata,
				'completed'              => true,
			);

			if ( null !== $exceeded_budget ) {
				$final_result_data['status']    = 'budget_exceeded';
				$final_result_data['budget']    = $exceeded_budget;
				$final_result_data['completed'] = false;
			}

			$final_result = WP_Agent_Conversation_Result::normalize( $final_result_data );

			self::persist_transcript( $transcript_persister, $messages, $options, $final_result );

			self::emit_event( $on_event, 'completed', array(
				'turn'          => $turns_run,
				'message_count' => count( $messages ),
				'tool_results'  => count( $tool_results ),
			) );

			return $final_result;
		} finally {
			if ( null !== $transcript_lock && null !== $lock_token && '' !== $lock_session_id ) {
				try {
					$transcript_lock->release_session_lock( $lock_session_id, $lock_token );
				} catch ( \Throwable $error ) {
					// Lock release failures must not change loop results.
					unset( $error );
				}
			}
		}
	}

	/**
	 * Mediate tool calls extracted from the turn runner result.
	 *
	 * Handles the tool-call → validate → execute → message assembly cycle.
	 *
	 * @param array                                             $result          Turn runner result with tool_calls.
	 * @param WP_Agent_Tool_Executor                             $executor        Tool executor adapter.
	 * @param array                                             $declarations    Tool declarations keyed by name.
	 * @param WP_Agent_Conversation_Completion_Policy|null   $policy          Completion policy.
	 * @param array                                             $turn_context    Turn context.
	 * @param int                                               $turn            Current turn number.
	 * @param callable|null                                     $on_event        Event sink.
	 * @param array<string, WP_Agent_Iteration_Budget>                    $budgets         Named iteration budgets.
	 * @return array{messages: array, tool_execution_results: array, events: array, conversation_complete: bool, exceeded_budget: string|null}
	 */
	private static function mediate_tool_calls(
		array $result,
		WP_Agent_Tool_Executor $executor,
		array $declarations,
		?WP_Agent_Conversation_Completion_Policy $policy,
		array $turn_context,
		int $turn,
		?callable $on_event,
		array $budgets = array()
	): array {
		$core                   = new WP_Agent_Tool_Execution_Core();
		$messages               = isset( $result['messages'] ) && is_array( $result['messages'] )
			? WP_Agent_Message::normalize_many( $result['messages'] )
			: array();
		$tool_calls             = $result['tool_calls'];
		$tool_execution_results = array();
		$events                 = array();
		$complete               = false;
		$exceeded_budget        = null;

		// If the turn runner returned text content, add it as an assistant message.
		if ( isset( $result['content'] ) && is_string( $result['content'] ) && '' !== $result['content'] ) {
			$messages[] = WP_Agent_Message::text( 'assistant', $result['content'] );
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
			$messages[] = WP_Agent_Message::toolCall(
				'Calling ' . $tool_name,
				$tool_name,
				is_array( $parameters ) ? $parameters : array(),
				$turn
			);

			// Execute through WP_Agent_Tool_Execution_Core.
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

			$messages[] = WP_Agent_Message::toolResult(
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
	 * @param WP_Agent_Transcript_Persister|null $persister Transcript persister.
	 * @param array                                              $messages  Final messages.
	 * @param array                                              $options   Loop options.
	 * @param array                                              $result    Loop result.
	 */
	private static function persist_transcript(
		?WP_Agent_Transcript_Persister $persister,
		array $messages,
		array $options,
		array $result
	): void {
		if ( null === $persister ) {
			return;
		}

		$request = self::resolve_request( $messages, $options );

		try {
			$persister->persist( $messages, $request, $result );
		} catch ( \Throwable $error ) {
			// Persister failures must not change loop results.
			unset( $error );
		}
	}

	/**
	 * Resolve the request object from options, or build a minimal one.
	 *
	 * @param array $messages Current messages.
	 * @param array $options  Loop options.
	 * @return WP_Agent_Conversation_Request
	 */
	private static function resolve_request( array $messages, array $options ): WP_Agent_Conversation_Request {
		$request = $options['request'] ?? null;
		if ( $request instanceof WP_Agent_Conversation_Request ) {
			return $request;
		}

		return new WP_Agent_Conversation_Request(
			$messages,
			array(),
			null,
			isset( $options['context'] ) && is_array( $options['context'] ) ? $options['context'] : array(),
			array(),
			self::max_turns( $options['max_turns'] ?? 1 )
		);
	}

	/**
	 * Emit a lifecycle event through the caller sink and WordPress observers.
	 *
	 * The caller-owned `on_event` sink and the `agents_api_loop_event` action are
	 * observational surfaces. Event payloads are read-only snapshots for observers;
	 * observer failures are swallowed to prevent changing loop results.
	 *
	 * @param callable|null $on_event Event sink.
	 * @param string        $event    Event name.
	 * @param array         $payload  Event payload.
	 */
	private static function emit_event( ?callable $on_event, string $event, array $payload = array() ): void {
		if ( null !== $on_event ) {
			try {
				call_user_func( $on_event, $event, $payload );
			} catch ( \Throwable $error ) {
				// Observer failures must not change loop results.
				unset( $error );
			}
		}

		if ( function_exists( 'do_action' ) ) {
			try {
				/**
				 * Fires when WP_Agent_Conversation_Loop emits a lifecycle event.
				 *
				 * Observers receive read-only event snapshots. Exceptions thrown by
				 * observers are swallowed and cannot change loop results.
				 *
				 * @param string $event   Event name.
				 * @param array  $payload Event payload snapshot.
				 */
				do_action( 'agents_api_loop_event', $event, $payload );
			} catch ( \Throwable $error ) {
				// Observer failures must not change loop results.
				unset( $error );
			}
		}
	}

	/**
	 * Resolve the tool executor from options.
	 *
	 * @param array $options Loop options.
	 * @return WP_Agent_Tool_Executor|null
	 */
	private static function resolve_tool_executor( array $options ): ?WP_Agent_Tool_Executor {
		$executor = $options['tool_executor'] ?? null;
		return $executor instanceof WP_Agent_Tool_Executor ? $executor : null;
	}

	/**
	 * Resolve the should_continue callable for this run.
	 *
	 * When tool mediation is enabled, defaults to a continue-always callable so
	 * `max_turns`, `completion_policy`, budgets, and natural completion (empty
	 * `tool_calls`) become the only stop conditions. In the caller-managed path (no
	 * mediation), preserves the historical break-after-1 behavior unless the
	 * caller supplies their own continuation policy.
	 *
	 * @param array                       $options           Loop options.
	 * @param WP_Agent_Tool_Executor|null $tool_executor     Resolved tool executor.
	 * @param array                       $tool_declarations Resolved tool declarations.
	 * @return callable|null
	 */
	private static function resolve_should_continue(
		array $options,
		?WP_Agent_Tool_Executor $tool_executor,
		array $tool_declarations
	) {
		if ( array_key_exists( 'should_continue', $options ) ) {
			$caller_supplied = $options['should_continue'];
			if ( is_callable( $caller_supplied ) ) {
				return $caller_supplied;
			}
			// Caller passed a non-callable value (e.g. null) — preserve the
			// break-after-1 behavior they explicitly opted into.
			return null;
		}

		// No caller-supplied policy. When mediation is enabled, default to
		// "keep going" so the loop's other stop conditions can do their job.
		$mediation_enabled = null !== $tool_executor && ! empty( $tool_declarations );
		if ( $mediation_enabled ) {
			return static function (): bool {
				return true;
			};
		}

		return null;
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
	 * @return WP_Agent_Conversation_Completion_Policy|null
	 */
	private static function resolve_completion_policy( array $options ): ?WP_Agent_Conversation_Completion_Policy {
		$policy = $options['completion_policy'] ?? null;
		return $policy instanceof WP_Agent_Conversation_Completion_Policy ? $policy : null;
	}

	/**
	 * Resolve the transcript persister from options.
	 *
	 * @param array $options Loop options.
	 * @return WP_Agent_Transcript_Persister|null
	 */
	private static function resolve_transcript_persister( array $options ): ?WP_Agent_Transcript_Persister {
		$persister = $options['transcript_persister'] ?? null;
		return $persister instanceof WP_Agent_Transcript_Persister ? $persister : null;
	}

	/**
	 * Resolve the transcript lock primitive from options.
	 *
	 * @param array $options Loop options.
	 * @return WP_Agent_Conversation_Lock|null
	 */
	private static function resolve_transcript_lock( array $options ): ?WP_Agent_Conversation_Lock {
		foreach ( array( 'transcript_lock', 'transcript_lock_store', 'transcript_store' ) as $key ) {
			$lock = $options[ $key ] ?? null;
			if ( $lock instanceof WP_Agent_Conversation_Lock ) {
				return $lock;
			}
		}

		return null;
	}

	/**
	 * Resolve the transcript session ID to lock.
	 *
	 * @param array                    $options Loop options.
	 * @param WP_Agent_Conversation_Request $request Request object.
	 * @return string
	 */
	private static function resolve_lock_session_id( array $options, WP_Agent_Conversation_Request $request ): string {
		foreach ( array( 'transcript_session_id', 'session_id', 'transcript_id' ) as $key ) {
			$value = $options[ $key ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		$metadata = $request->metadata();
		foreach ( array( 'transcript_session_id', 'session_id', 'transcript_id' ) as $key ) {
			$value = $metadata[ $key ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * Resolve transcript lock TTL.
	 *
	 * @param array $options Loop options.
	 * @return int TTL in seconds.
	 */
	private static function resolve_lock_ttl( array $options ): int {
		return max( 1, (int) ( $options['transcript_lock_ttl'] ?? 300 ) );
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
	 * @return array{budgets: array<string, WP_Agent_Iteration_Budget>, has_explicit_turns: bool}
	 */
	private static function resolve_budgets( array $options, int $max_turns ): array {
		$raw     = $options['budgets'] ?? array();
		$budgets = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $budget ) {
				if ( $budget instanceof WP_Agent_Iteration_Budget ) {
					$budgets[ $budget->name() ] = $budget;
				}
			}
		}

		$has_explicit_turns = isset( $budgets['turns'] );

		// Synthesize a turns budget from max_turns when none was explicitly provided.
		if ( ! $has_explicit_turns ) {
			$budgets['turns'] = new WP_Agent_Iteration_Budget( 'turns', $max_turns );
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
	 * @param array<string, WP_Agent_Iteration_Budget> $budgets  Named budgets.
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

		$compaction = WP_Agent_Conversation_Compaction::compact( $messages, $policy, $summarizer );
		return array(
			'messages' => $compaction['messages'],
			'events'   => self::normalize_events( $compaction['events'] ),
		);
	}

	/**
	 * Accumulate per-turn usage into the running total.
	 *
	 * Sums the canonical `prompt_tokens`/`completion_tokens`/`total_tokens`
	 * fields and preserves any provider-specific keys from the latest turn
	 * (e.g. `cache_creation_input_tokens`, `reasoning_tokens`) so consumers
	 * can read provider extensions without the loop having to know about
	 * each one. Numeric fields are summed; non-numeric fields are taken
	 * from the latest turn.
	 *
	 * @param array<string, mixed> $running Current accumulated usage.
	 * @param array<string, mixed> $turn    Per-turn usage.
	 * @return array<string, mixed> Accumulated usage.
	 */
	private static function accumulate_usage( array $running, array $turn ): array {
		foreach ( $turn as $key => $value ) {
			if ( is_int( $value ) || is_float( $value ) ) {
				$running[ $key ] = (float) ( $running[ $key ] ?? 0 ) + (float) $value;
				if ( is_int( $value ) && (float) (int) $running[ $key ] === (float) $running[ $key ] ) {
					$running[ $key ] = (int) $running[ $key ];
				}
				continue;
			}
			$running[ $key ] = $value;
		}
		return $running;
	}

	/**
	 * Extract the text of the last assistant message from a transcript.
	 *
	 * Returns an empty string when no assistant message exists or the
	 * latest assistant message has no text content (e.g. tool-call-only
	 * turns at the tail).
	 *
	 * @param array $messages Normalized transcript messages.
	 * @return string Final assistant text content.
	 */
	private static function extract_final_content( array $messages ): string {
		for ( $i = count( $messages ) - 1; $i >= 0; --$i ) {
			$message = $messages[ $i ];
			if ( ! is_array( $message ) ) {
				continue;
			}
			if ( ( $message['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}
			$content = $message['content'] ?? '';
			if ( is_string( $content ) && '' !== $content ) {
				return $content;
			}
		}
		return '';
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
