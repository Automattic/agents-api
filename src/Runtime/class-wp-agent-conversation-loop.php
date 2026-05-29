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
	 *   defaults to a callable that returns `true` while the latest turn emitted
	 *   `tool_calls` — so the loop stops on natural completion (empty `tool_calls`)
	 *   and otherwise keeps going until `completion_policy` fires, `max_turns` is
	 *   reached, or a budget is exceeded. Callers can pass `'__return_true'` to
	 *   opt into the historical continue-always behavior.
	 * - `compaction_policy` (array|null): Optional compaction policy.
	 * - `summarizer` (callable|null): Optional compaction summarizer.
	 * - `tool_executor` (WP_Agent_Tool_Executor|null): Tool execution adapter.
	 * - `tool_declarations` (array|null): Tool declarations keyed by name.
	 * - `completion_policy` (WP_Agent_Conversation_Completion_Policy|null): Typed completion policy.
	 * - `spin_detector` (WP_Agent_Spin_Detector|null): Optional repeated tool-call detector.
	 * - `identical_failure_tracker` (WP_Agent_Identical_Failure_Tracker|null): Optional repeated failure nudger.
	 * - `tool_result_truncator` (WP_Agent_Tool_Result_Truncator|null): Optional mediated tool result truncator.
	 * - `interrupt_source` (callable|null): Optional source checked between turns. Returns a message array or null.
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
		$runtime_overrides     = self::resolve_runtime_overrides( $options );
		$options               = self::apply_runtime_overrides_to_options( $options, $runtime_overrides );
		$max_turns             = self::max_turns( $options['max_turns'] ?? 1 );
		$context               = isset( $options['context'] ) && is_array( $options['context'] ) ? $options['context'] : array();
		$tool_executor         = self::resolve_tool_executor( $options );
		$tool_declarations     = self::resolve_tool_declarations( $options );
		$should_continue       = self::resolve_should_continue( $options, $tool_executor, $tool_declarations );
		$completion_policy     = self::resolve_completion_policy( $options );
		$transcript_persister  = self::resolve_transcript_persister( $options );
		$transcript_lock       = self::resolve_transcript_lock( $options );
		$on_event              = self::resolve_event_sink( $options );
		$spin_detector         = self::resolve_spin_detector( $options );
		$failure_tracker       = self::resolve_identical_failure_tracker( $options );
		$result_truncator      = self::resolve_tool_result_truncator( $options );
		$interrupt_source      = self::resolve_interrupt_source( $options );
		$request               = self::resolve_request( $messages, $options );
		$lock_session_id       = self::resolve_lock_session_id( $options, $request );
		$run_id                = self::resolve_run_id( $options, $request );
		if ( '' !== $run_id && '' !== $lock_session_id ) {
			$on_event = self::decorate_chat_run_event_sink( $on_event, $lock_session_id, $run_id );
		}
		$lock_ttl              = self::resolve_lock_ttl( $options );
		$lock_token            = null;
		$budget_resolution     = self::resolve_budgets( $options, $max_turns );
		$budgets               = $budget_resolution['budgets'];
		$has_explicit_turns    = $budget_resolution['has_explicit_turns'];
		$wall_clock_started_at = microtime( true );
		$wall_clock_initial    = isset( $budgets['wall_clock_seconds'] ) ? $budgets['wall_clock_seconds']->current() : 0;
		$mediation_enabled     = null !== $tool_executor && ! empty( $tool_declarations );
		$messages              = WP_Agent_Message::normalize_many( $messages );
		if ( '' !== $run_id && '' !== $lock_session_id ) {
			WP_Agent_Chat_Run_Control::start_run( $run_id, $lock_session_id, array( 'source' => 'conversation_loop' ) );
		}
		$events                = array();
		$tool_results          = array();
		$tool_audit_events     = array();
		$conversation_complete = false;
		$exceeded_budget       = null;
		$stalled               = null;
		$approval_required     = null;
		$interrupted           = null;

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
				$wall_clock_exceeded = self::check_wall_clock_budget( $budgets, $wall_clock_started_at, $wall_clock_initial, $on_event );
				if ( null !== $wall_clock_exceeded ) {
					$exceeded_budget = $wall_clock_exceeded;
					break;
				}

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
						$budgets,
						$failure_tracker,
						$result_truncator,
						$messages
					);

					$messages              = $mediation_result['messages'];
					$tool_results          = array_merge( $tool_results, $mediation_result['tool_execution_results'] );
					$tool_audit_events     = array_merge( $tool_audit_events, $mediation_result['tool_audit_events'] );
					$events                = array_merge( $events, $mediation_result['events'] );
					$conversation_complete = $mediation_result['conversation_complete'];
					$exceeded_budget       = $mediation_result['exceeded_budget'];
					$approval_required     = $mediation_result['approval_required'] ?? null;
					$stalled               = self::check_spin_detector( $spin_detector, $mediation_result['spin_signatures'], $turn_context, $on_event );
				} else {
					// Caller-managed path: turn runner handles everything internally.
					$result       = WP_Agent_Conversation_Result::normalize( $result );
					$messages     = $result['messages'];
					$tool_results = array_merge( $tool_results, $result['tool_execution_results'] );
					if ( isset( $result['tool_audit_events'] ) && is_array( $result['tool_audit_events'] ) ) {
						$tool_audit_events = array_merge( $tool_audit_events, $result['tool_audit_events'] );
					}
					$events = array_merge( $events, self::normalize_events( $result['events'] ?? array() ) );
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

				if ( null !== $stalled ) {
					break;
				}

				if ( $conversation_complete ) {
					break;
				}

				$interrupt = self::check_interrupt_source( $interrupt_source, $messages, $options, $turn_context, $on_event );
				if ( null === $interrupt && '' !== $run_id ) {
					$interrupt_message = WP_Agent_Chat_Run_Control::cancellation_interrupt_for_run( $run_id, $lock_session_id );
					if ( null !== $interrupt_message ) {
						$interrupt = self::normalize_interrupt_message( $interrupt_message, $turn_context, $on_event );
					}
				}
				if ( null !== $interrupt ) {
					$messages[] = $interrupt['message'];
					$events[]   = array(
						'type'     => 'interrupt_received',
						'metadata' => $interrupt['metadata'],
					);

					if ( 'cancel' === $interrupt['action'] ) {
						$interrupted = $interrupt['metadata'];
						break;
					}
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
				'tool_audit_events'      => $tool_audit_events,
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

			if ( null !== $stalled ) {
				$final_result_data['status']    = 'stalled';
				$final_result_data['stalled']   = $stalled;
				$final_result_data['completed'] = false;
			}

			if ( null !== $approval_required ) {
				$final_result_data['status']            = 'approval_required';
				$final_result_data['approval_required'] = $approval_required;
				$final_result_data['completed']         = false;
			}

			if ( null !== $interrupted ) {
				$final_result_data['status']      = 'interrupted';
				$final_result_data['interrupted'] = $interrupted;
				$final_result_data['completed']   = false;
			}

			$final_result = WP_Agent_Conversation_Result::normalize( $final_result_data );

			if ( '' !== $run_id && '' !== $lock_session_id ) {
				WP_Agent_Chat_Run_Control::finish_run(
					$run_id,
					null !== $interrupted ? WP_Agent_Chat_Run_Control::STATUS_CANCELLED : WP_Agent_Chat_Run_Control::STATUS_COMPLETED
				);
			}

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
	 * @param WP_Agent_Identical_Failure_Tracker|null                     $failure_tracker Optional identical-failure tracker.
	 * @param WP_Agent_Tool_Result_Truncator|null                         $truncator       Optional tool result truncator.
	 * @return array{messages: array, tool_execution_results: array, tool_audit_events: array, events: array, conversation_complete: bool, exceeded_budget: string|null, approval_required: array<string, mixed>|null, spin_signatures: WP_Agent_Spin_Signature[]}
	 */
	private static function mediate_tool_calls(
		array $result,
		WP_Agent_Tool_Executor $executor,
		array $declarations,
		?WP_Agent_Conversation_Completion_Policy $policy,
		array $turn_context,
		int $turn,
		?callable $on_event,
		array $budgets = array(),
		?WP_Agent_Identical_Failure_Tracker $failure_tracker = null,
		?WP_Agent_Tool_Result_Truncator $truncator = null,
		array $prior_messages = array()
	): array {
		$core = new WP_Agent_Tool_Execution_Core();

		// Fall back to the prior turn's messages when the turn runner omits
		// `messages` from its return — without this, mediation starts from an
		// empty list and silently drops history between rounds.
		$messages               = isset( $result['messages'] ) && is_array( $result['messages'] )
			? WP_Agent_Message::normalize_many( $result['messages'] )
			: $prior_messages;
		$tool_calls             = $result['tool_calls'];
		$tool_execution_results = array();
		$tool_audit_events      = array();
		$events                 = array();
		$spin_signatures        = array();
		$complete               = false;
		$exceeded_budget        = null;
		$approval_required      = null;

		// If the turn runner returned text content, add it as an assistant message.
		if ( isset( $result['content'] ) && is_string( $result['content'] ) && '' !== $result['content'] ) {
			$messages[] = WP_Agent_Message::text( 'assistant', $result['content'] );
		}

		foreach ( $tool_calls as $index => $raw_call ) {
			$tool_name    = $raw_call['name'] ?? $raw_call['tool_name'] ?? '';
			$parameters   = $raw_call['parameters'] ?? array();
			$tool_call_id = self::resolve_tool_call_id( is_array( $raw_call ) ? $raw_call : array(), $turn, (int) $index + 1 );

			if ( ! is_string( $tool_name ) || '' === $tool_name ) {
				continue;
			}

			$spin_signatures[] = new WP_Agent_Spin_Signature( $tool_name, is_array( $parameters ) ? $parameters : array() );

			self::emit_event( $on_event, 'tool_call', array(
				'turn'         => $turn,
				'tool_name'    => $tool_name,
				'tool_call_id' => $tool_call_id,
				'parameters'   => $parameters,
			) );

			// Add tool-call message to transcript. The structured info
			// (tool_name + parameters) lives in metadata; `content` is
			// intentionally empty so adapters that flatten the transcript
			// to provider text don't end up echoing the human-readable
			// "Calling X" debug string back to the model. When the model
			// sees "Calling foo" as a previous assistant text turn, it
			// pattern-matches and starts emitting that literal string as
			// text instead of issuing real function-calls — a sneaky
			// failure mode on long multi-tool conversations (Gemini Flash
			// in particular). Adapters that want a user-facing label can
			// derive it from `metadata.tool_name`.
			$messages[] = WP_Agent_Message::toolCall(
				'',
				$tool_name,
				is_array( $parameters ) ? $parameters : array(),
				$turn,
				array( 'tool_call_id' => $tool_call_id )
			);

			// Execute through WP_Agent_Tool_Execution_Core.
			$tool_context                 = $turn_context;
			$tool_context['tool_call_id'] = $tool_call_id;
			$exec_result                  = $core->executeTool(
				$tool_name,
				is_array( $parameters ) ? $parameters : array(),
				$declarations,
				$executor,
				$tool_context
			);

			// Detect an approval_required envelope returned by an ability via the
			// wp_pre_execute_ability bridge handler. The envelope replaces the
			// tool_result message and halts mediation so the caller can surface
			// the pending action and resume after the host records a decision.
			$ability_envelope = is_array( $exec_result['result'] ?? null ) ? $exec_result['result'] : null;
			if ( null !== $ability_envelope && WP_Agent_Message::TYPE_APPROVAL_REQUIRED === ( $ability_envelope['type'] ?? null ) ) {
				$approval_required = $ability_envelope;
				$messages[]        = $ability_envelope;
				self::emit_event( $on_event, 'approval_required', array(
					'turn'         => $turn,
					'tool_name'    => $tool_name,
					'tool_call_id' => $tool_call_id,
					'action_id'    => $ability_envelope['payload']['action_id'] ?? null,
				) );
				$complete = true;
				break;
			}

			$tool_def             = $declarations[ $tool_name ] ?? null;
			$original_exec_result = $exec_result;
			$truncation           = self::maybe_truncate_tool_result( $truncator, $exec_result, $tool_name, $tool_context );
			$exec_result          = $truncation['result'];

			if ( $truncation['truncated'] ) {
				$payload = array_merge(
					$truncation['metadata'],
					array(
						'turn'            => $turn,
						'tool_name'       => $tool_name,
						'tool_call_id'    => $tool_call_id,
						'original_result' => $original_exec_result,
					)
				);

				self::emit_event( $on_event, 'tool_result_truncated', $payload );
				$stored_payload = $payload;
				unset( $stored_payload['original_result'] );

				$events[] = array(
					'type'     => 'tool_result_truncated',
					'metadata' => $stored_payload,
				);
			}

			self::emit_event( $on_event, 'tool_result', array(
				'turn'         => $turn,
				'tool_name'    => $tool_name,
				'tool_call_id' => $tool_call_id,
				'success'      => (bool) ( $exec_result['success'] ?? false ),
			) );

			// Build the tool_execution_results entry.
			$execution_result = array(
				'tool_name'    => $tool_name,
				'tool_call_id' => $tool_call_id,
				'result'       => $exec_result,
				'parameters'   => is_array( $parameters ) ? $parameters : array(),
				'turn_count'   => $turn,
			);

			$runtime = isset( $exec_result['runtime'] ) && is_array( $exec_result['runtime'] ) ? $exec_result['runtime'] : array();
			if ( ! empty( $runtime ) ) {
				$execution_result['runtime'] = $runtime;
			}

			$tool_execution_results[] = $execution_result;

			$tool_audit_events[] = self::tool_audit_event(
				$tool_name,
				$tool_call_id,
				is_array( $parameters ) ? $parameters : array(),
				$exec_result,
				is_array( $tool_def ) ? $tool_def : null,
				$turn_context,
				$turn
			);

			// Add tool-result message to transcript.
			$result_content = ( $exec_result['success'] ?? false )
				? self::json_encode_safe( $exec_result['result'] ?? array() )
				: ( $exec_result['error'] ?? 'Tool execution failed.' );

			$messages[] = WP_Agent_Message::toolResult(
				is_string( $result_content ) ? $result_content : '',
				$tool_name,
				$exec_result,
				array( 'tool_call_id' => $tool_call_id )
			);

			$nudge = self::check_identical_failure_tracker(
				$failure_tracker,
				$tool_name,
				is_array( $parameters ) ? $parameters : array(),
				$exec_result,
				$turn_context,
				$on_event
			);
			if ( null !== $nudge ) {
				$messages[] = WP_Agent_Message::text(
					'assistant',
					$nudge['message'],
					array(
						'type'                        => 'identical_failure_nudge',
						'identical_failure_signature' => $nudge,
					)
				);
			}

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
			'tool_audit_events'      => $tool_audit_events,
			'events'                 => $events,
			'conversation_complete'  => $complete,
			'exceeded_budget'        => $exceeded_budget,
			'approval_required'      => $approval_required,
			'spin_signatures'        => $spin_signatures,
		);
	}

	/**
	 * Apply the optional tool result truncator and normalize its return shape.
	 *
	 * @param WP_Agent_Tool_Result_Truncator|null $truncator Optional truncator.
	 * @param array<string, mixed>                $result    Tool execution result.
	 * @param string                              $tool_name Tool name.
	 * @param array<string, mixed>                $context   Tool context.
	 * @return array{result: array<string, mixed>, truncated: bool, metadata: array<string, mixed>}
	 */
	private static function maybe_truncate_tool_result( ?WP_Agent_Tool_Result_Truncator $truncator, array $result, string $tool_name, array $context ): array {
		if ( null === $truncator ) {
			return array(
				'result'    => $result,
				'truncated' => false,
				'metadata'  => array(),
			);
		}

		$truncated = $truncator->truncate_result( $result, $tool_name, $context );

		return array(
			'result'    => $truncated['result'],
			'truncated' => $truncated['truncated'],
			'metadata'  => $truncated['metadata'],
		);
	}

	/**
	 * Check the optional interrupt source for a between-turn message.
	 *
	 * @param callable|null        $interrupt_source Optional interrupt source.
	 * @param array<int, array>    $messages         Current transcript messages.
	 * @param array<string, mixed> $options          Loop options.
	 * @param array<string, mixed> $context          Current turn context.
	 * @param callable|null        $on_event         Event sink.
	 * @return array{message: array<string, mixed>, metadata: array<string, mixed>, action: string}|null Interrupt payload.
	 */
	private static function check_interrupt_source( ?callable $interrupt_source, array $messages, array $options, array $context, ?callable $on_event ): ?array {
		if ( null === $interrupt_source ) {
			return null;
		}

		$request = self::interrupt_request( $messages, $options );
		$message = call_user_func( $interrupt_source, $request, $context );

		if ( null === $message ) {
			return null;
		}

		if ( ! is_array( $message ) ) {
			self::emit_event( $on_event, 'interrupt_ignored', array(
				'reason' => 'invalid_message',
				'turn'   => (int) ( $context['turn'] ?? 0 ),
			) );

			return null;
		}

		return self::normalize_interrupt_message( $message, $context, $on_event );
	}

	/**
	 * Normalize and emit a received interrupt message.
	 *
	 * @param array<string,mixed>  $message  Interrupt message.
	 * @param array<string,mixed>  $context  Current turn context.
	 * @param callable|null        $on_event Event sink.
	 * @return array{message: array<string, mixed>, metadata: array<string, mixed>, action: string}
	 */
	private static function normalize_interrupt_message( array $message, array $context, ?callable $on_event ): array {
		$normalized         = WP_Agent_Message::normalize( $message );
		$message_metadata   = isset( $normalized['metadata'] ) && is_array( $normalized['metadata'] ) ? $normalized['metadata'] : array();
		$action             = self::normalize_interrupt_action( $message_metadata['interrupt_action'] ?? ( $message_metadata['action'] ?? 'message' ) );
		$interrupt_metadata = $message_metadata;
		unset( $interrupt_metadata['action'], $interrupt_metadata['interrupt_action'] );

		$metadata = array_merge( $interrupt_metadata, array(
			'turn'    => (int) ( $context['turn'] ?? 0 ),
			'action'  => $action,
			'message' => $normalized,
		) );

		self::emit_event( $on_event, 'interrupt_received', $metadata );

		return array(
			'message'  => $normalized,
			'metadata' => $metadata,
			'action'   => $action,
		);
	}

	/**
	 * Build an interrupt-source request with the current transcript.
	 *
	 * @param array<int, array>    $messages Current transcript messages.
	 * @param array<string, mixed> $options  Loop options.
	 * @return WP_Agent_Conversation_Request
	 */
	private static function interrupt_request( array $messages, array $options ): WP_Agent_Conversation_Request {
		$request = $options['request'] ?? null;
		if ( $request instanceof WP_Agent_Conversation_Request ) {
			return new WP_Agent_Conversation_Request(
				$messages,
				$request->tools(),
				$request->principal(),
				$request->runtimeContext(),
				$request->metadata(),
				$request->maxTurns(),
				$request->singleTurn(),
				$request->workspace(),
				$request->runtimeOverrides()
			);
		}

		return self::resolve_request( $messages, $options );
	}

	/**
	 * Normalize interrupt action vocabulary.
	 *
	 * @param mixed $action Raw action.
	 * @return string Normalized action.
	 */
	private static function normalize_interrupt_action( $action ): string {
		if ( ! is_string( $action ) ) {
			return 'message';
		}

		$action = strtolower( trim( $action ) );
		return in_array( $action, array( 'cancel', 'redirect', 'message' ), true ) ? $action : 'message';
	}

	/**
	 * Check a spin detector against tool-call signatures from one mediated turn.
	 *
	 * @param WP_Agent_Spin_Detector|null $detector   Optional spin detector.
	 * @param WP_Agent_Spin_Signature[]   $signatures Tool-call signatures.
	 * @param array<string, mixed>        $context    Current turn context.
	 * @param callable|null               $on_event   Event sink.
	 * @return array<string, mixed>|null Stalled diagnostics when the detector fires.
	 */
	private static function check_spin_detector( ?WP_Agent_Spin_Detector $detector, array $signatures, array $context, ?callable $on_event ): ?array {
		if ( null === $detector ) {
			return null;
		}

		foreach ( $signatures as $signature ) {
			if ( $detector->record_signature( $signature, $context ) ) {
				$payload = array_merge(
					$signature->to_array(),
					array(
						'turn'         => (int) ( $context['turn'] ?? 0 ),
						'repeat_count' => $detector->repeat_count(),
						'threshold'    => $detector->threshold(),
					)
				);

				self::emit_event( $on_event, 'loop_stalled', $payload );

				return $payload;
			}
		}

		return null;
	}

	/**
	 * Check a repeated failure tracker and return nudge metadata when it fires.
	 *
	 * @param WP_Agent_Identical_Failure_Tracker|null $tracker    Optional failure tracker.
	 * @param string                                  $tool_name  Tool name.
	 * @param array<string, mixed>                    $parameters Tool parameters.
	 * @param array<string, mixed>                    $result     Tool execution result.
	 * @param array<string, mixed>                    $context    Current turn context.
	 * @param callable|null                           $on_event   Event sink.
	 * @return array<string, mixed>|null Nudge metadata when the tracker fires.
	 */
	private static function check_identical_failure_tracker(
		?WP_Agent_Identical_Failure_Tracker $tracker,
		string $tool_name,
		array $parameters,
		array $result,
		array $context,
		?callable $on_event
	): ?array {
		if ( null === $tracker || ! empty( $result['success'] ) ) {
			return null;
		}

		$signature = new WP_Agent_Identical_Failure_Signature( $tool_name, $parameters, $result );
		$message   = $tracker->record_failure( $signature, $context );
		if ( null === $message || '' === trim( $message ) ) {
			return null;
		}

		$payload = array_merge(
			$signature->to_array(),
			array(
				'turn'         => (int) ( $context['turn'] ?? 0 ),
				'repeat_count' => $tracker->repeat_count(),
				'threshold'    => $tracker->threshold(),
				'message'      => $message,
			)
		);

		self::emit_event( $on_event, 'identical_failure_nudged', $payload );

		return $payload;
	}

	/**
	 * Resolve a stable tool-call identifier for transcript pairing.
	 *
	 * @param array $raw_call Raw tool call emitted by a turn runner.
	 * @param int   $turn Current turn number.
	 * @param int   $sequence Tool-call sequence in this turn.
	 * @return string
	 */
	private static function resolve_tool_call_id( array $raw_call, int $turn, int $sequence ): string {
		$id = $raw_call['id'] ?? $raw_call['tool_call_id'] ?? '';
		if ( is_string( $id ) && '' !== trim( $id ) ) {
			return trim( $id );
		}

		return sprintf( 'tool-call-%d-%d', max( 1, $turn ), max( 1, $sequence ) );
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

		$runtime_overrides = self::resolve_runtime_overrides( $options );

		return new WP_Agent_Conversation_Request(
			$messages,
			array(),
			null,
			isset( $options['context'] ) && is_array( $options['context'] ) ? $options['context'] : array(),
			array(),
			self::max_turns( $options['max_turns'] ?? 1 ),
			false,
			null,
			$runtime_overrides
		);
	}

	/**
	 * Resolve runtime overrides from explicit options or an agent definition.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return \WP_Agent_Runtime_Overrides Runtime overrides.
	 */
	private static function resolve_runtime_overrides( array $options ): \WP_Agent_Runtime_Overrides {
		$overrides = $options['runtime_overrides'] ?? null;
		if ( $overrides instanceof \WP_Agent_Runtime_Overrides ) {
			return $overrides;
		}

		if ( is_array( $overrides ) ) {
			return new \WP_Agent_Runtime_Overrides( $overrides );
		}

		$agent = $options['agent'] ?? ( is_array( $options['context'] ?? null ) ? ( $options['context']['agent'] ?? null ) : null );
		return $agent instanceof \WP_Agent ? $agent->runtime_overrides() : new \WP_Agent_Runtime_Overrides();
	}

	/**
	 * Apply non-null runtime overrides to loop options.
	 *
	 * @param array<string, mixed>          $options   Loop options.
	 * @param \WP_Agent_Runtime_Overrides $overrides Runtime overrides.
	 * @return array<string, mixed> Loop options.
	 */
	private static function apply_runtime_overrides_to_options( array $options, \WP_Agent_Runtime_Overrides $overrides ): array {
		if ( null !== $overrides->max_iterations() ) {
			$options['max_turns'] = min( self::max_turns( $options['max_turns'] ?? $overrides->max_iterations() ), $overrides->max_iterations() );
		}

		$context = isset( $options['context'] ) && is_array( $options['context'] ) ? $options['context'] : array();
		foreach ( $overrides->to_array() as $key => $value ) {
			if ( null !== $value && array() !== $value ) {
				$context[ $key ] = $value;
			}
		}
		$options['context'] = $context;

		return $options;
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

	private static function decorate_chat_run_event_sink( ?callable $on_event, string $session_id, string $run_id ): callable {
		return static function ( string $event, array $payload = array() ) use ( $on_event, $session_id, $run_id ): void {
			try {
				WP_Agent_Chat_Run_Control::record_event( $session_id, $run_id, $event, $payload );
			} catch ( \Throwable $error ) {
				// Event persistence must not change loop results.
				unset( $error );
			}

			if ( null !== $on_event ) {
				call_user_func( $on_event, $event, $payload );
			}
		};
	}

	/**
	 * Build a stable, safe audit entry for a mediated tool call.
	 *
	 * The legacy `tool_execution_results` field intentionally keeps raw
	 * parameters for existing callers. Audit events avoid raw parameter storage by
	 * default so transcripts can be used for replay attestation without leaking
	 * secrets into generic observers.
	 *
	 * @param string     $tool_name       Tool identifier.
	 * @param string     $tool_call_id    Provider or loop-assigned tool-call id.
	 * @param array      $parameters      Runtime tool-call parameters.
	 * @param array      $result          Normalized tool execution result.
	 * @param array|null $tool_definition Tool declaration, when available.
	 * @param array      $context         Turn context.
	 * @param int        $turn            Turn number.
	 * @return array<string, mixed> Audit event.
	 */
	private static function tool_audit_event( string $tool_name, string $tool_call_id, array $parameters, array $result, ?array $tool_definition, array $context, int $turn ): array {
		$safe_parameters = self::redact_tool_audit_parameters( $parameters, $tool_name, $tool_definition, $context );
		$metadata        = isset( $result['metadata'] ) && is_array( $result['metadata'] ) ? $result['metadata'] : array();
		$error_type      = isset( $metadata['error_type'] ) && is_string( $metadata['error_type'] ) ? $metadata['error_type'] : '';

		$audit_event = array(
			'schema_version'      => 1,
			'type'                => 'tool_call',
			'turn_count'          => $turn,
			'tool_name'           => $tool_name,
			'tool_call_id'        => $tool_call_id,
			'tool_source'         => is_array( $tool_definition ) && is_string( $tool_definition['source'] ?? null ) ? $tool_definition['source'] : '',
			'parameters_sha256'   => self::stable_sha256( $safe_parameters ),
			'parameters_redacted' => true,
			'success'             => (bool) ( $result['success'] ?? false ),
			'result_status'       => ! empty( $result['success'] ) ? 'success' : 'error',
			'result_sha256'       => self::stable_sha256( self::audit_result_summary( $result ) ),
		);

		if ( '' !== $error_type ) {
			$audit_event['error_type'] = $error_type;
		}

		return array_filter(
			$audit_event,
			static fn( $value ): bool => '' !== $value
		);
	}

	/**
	 * Redact tool parameters before hashing them for audit events.
	 *
	 * @param array      $parameters      Raw tool-call parameters.
	 * @param string     $tool_name       Tool identifier.
	 * @param array|null $tool_definition Tool declaration, when available.
	 * @param array      $context         Turn context.
	 * @return array Redacted parameters.
	 */
	private static function redact_tool_audit_parameters( array $parameters, string $tool_name, ?array $tool_definition, array $context ): array {
		$redacted = self::redact_sensitive_values( $parameters );

		if ( function_exists( 'apply_filters' ) ) {
			try {
				/**
				 * Filters parameters before Agents API hashes them into tool audit events.
				 *
				 * Callers can remove or normalize product-specific sensitive fields while
				 * keeping deterministic replay hashes. Returning a non-array falls back to
				 * the default redacted parameters.
				 *
				 * @param array      $redacted        Default redacted parameters.
				 * @param array      $parameters      Raw tool-call parameters.
				 * @param string     $tool_name       Tool identifier.
				 * @param array|null $tool_definition Tool declaration, when available.
				 * @param array      $context         Turn context.
				 */
				$redacted = apply_filters( 'agents_api_tool_audit_parameters', $redacted, $parameters, $tool_name, $tool_definition, $context );
			} catch ( \Throwable $error ) {
				// Audit redaction filters must not change loop results.
				unset( $error );
			}
		}

		return $redacted;
	}

	/**
	 * Redact obviously sensitive scalar fields in nested parameter arrays.
	 *
	 * @param mixed $value Value to redact.
	 * @param string $key Current key.
	 * @return mixed Redacted value.
	 */
	private static function redact_sensitive_values( $value, string $key = '' ) {
		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $item_key => $item_value ) {
				$redacted[ $item_key ] = self::redact_sensitive_values( $item_value, is_string( $item_key ) ? $item_key : '' );
			}
			return $redacted;
		}

		if ( '' !== $key && preg_match( '/(api[_-]?key|authorization|cookie|credential|nonce|password|secret|token)/i', $key ) ) {
			return '[redacted]';
		}

		return $value;
	}

	/**
	 * Keep the audit result hash focused on normalized status, not raw payloads.
	 *
	 * @param array $result Normalized tool result.
	 * @return array<string, mixed> Hashable result summary.
	 */
	private static function audit_result_summary( array $result ): array {
		$metadata = isset( $result['metadata'] ) && is_array( $result['metadata'] ) ? $result['metadata'] : array();

		$summary = array(
			'success'   => (bool) ( $result['success'] ?? false ),
			'tool_name' => is_string( $result['tool_name'] ?? null ) ? $result['tool_name'] : '',
			'metadata'  => $metadata,
		);

		if ( empty( $result['success'] ) ) {
			$summary['error_sha256'] = self::stable_sha256( is_string( $result['error'] ?? null ) ? $result['error'] : 'Tool execution failed.' );
		}

		return $summary;
	}

	/**
	 * Hash data after recursively sorting array keys for deterministic output.
	 *
	 * @param mixed $data Data to hash.
	 * @return string sha256-prefixed hash.
	 */
	private static function stable_sha256( $data ): string {
		$normalized = self::sort_for_hash( $data );
		$encoded    = self::json_encode_safe( $normalized );
		if ( false === $encoded ) {
			$encoded = '';
		}

		return 'sha256:' . hash( 'sha256', (string) $encoded );
	}

	/**
	 * Recursively sort associative arrays before hashing.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed Normalized value.
	 */
	private static function sort_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			$normalized[ $key ] = self::sort_for_hash( $item );
		}

		if ( array() !== $normalized && array_keys( $normalized ) !== range( 0, count( $normalized ) - 1 ) ) {
			ksort( $normalized );
		}

		return $normalized;
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
	 * When tool mediation is enabled, defaults to a callable that returns true
	 * only while the latest turn emitted `tool_calls` — so the loop stops on
	 * natural completion and `max_turns` + `completion_policy` + budgets remain
	 * upper bounds rather than the primary stop condition. In the caller-managed
	 * path (no mediation), preserves the historical break-after-1 behavior unless
	 * the caller supplies their own continuation policy.
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
		// "stop when the turn runner emitted no tool_calls" so the loop exits
		// on natural completion instead of re-running until max_turns. The
		// caller can still pass `'should_continue' => '__return_true'` to opt
		// into the historical continue-always behavior, and budgets +
		// `completion_policy` + `max_turns` continue to act as stop conditions.
		$mediation_enabled = null !== $tool_executor && ! empty( $tool_declarations );
		if ( $mediation_enabled ) {
			return static function ( array $result ): bool {
				return ! empty( $result['tool_calls'] ?? array() );
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
	 * Resolve the spin detector from options.
	 *
	 * @param array $options Loop options.
	 * @return WP_Agent_Spin_Detector|null
	 */
	private static function resolve_spin_detector( array $options ): ?WP_Agent_Spin_Detector {
		$detector = $options['spin_detector'] ?? null;
		return $detector instanceof WP_Agent_Spin_Detector ? $detector : null;
	}

	/**
	 * Resolve the identical failure tracker from options.
	 *
	 * @param array $options Loop options.
	 * @return WP_Agent_Identical_Failure_Tracker|null
	 */
	private static function resolve_identical_failure_tracker( array $options ): ?WP_Agent_Identical_Failure_Tracker {
		$tracker = $options['identical_failure_tracker'] ?? null;
		return $tracker instanceof WP_Agent_Identical_Failure_Tracker ? $tracker : null;
	}

	/**
	 * Resolve the tool result truncator from options.
	 *
	 * @param array $options Loop options.
	 * @return WP_Agent_Tool_Result_Truncator|null
	 */
	private static function resolve_tool_result_truncator( array $options ): ?WP_Agent_Tool_Result_Truncator {
		$truncator = $options['tool_result_truncator'] ?? null;
		return $truncator instanceof WP_Agent_Tool_Result_Truncator ? $truncator : null;
	}

	/**
	 * Resolve the interrupt source from options.
	 *
	 * @param array $options Loop options.
	 * @return callable|null
	 */
	private static function resolve_interrupt_source( array $options ): ?callable {
		$source = $options['interrupt_source'] ?? null;
		return is_callable( $source ) ? $source : null;
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
	 * Resolve the chat run ID used by generic run-control.
	 *
	 * @param array                         $options Loop options.
	 * @param WP_Agent_Conversation_Request $request Request object.
	 * @return string
	 */
	private static function resolve_run_id( array $options, WP_Agent_Conversation_Request $request ): string {
		foreach ( array( 'run_id', 'chat_run_id' ) as $key ) {
			$value = $options[ $key ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		$metadata = $request->metadata();
		foreach ( array( 'run_id', 'chat_run_id' ) as $key ) {
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
			self::emit_event( $on_event, 'budget_exceeded', self::budget_event_payload( $budget ) );

			return $name;
		}

		return null;
	}

	/**
	 * Check the opt-in wall-clock budget before starting a turn.
	 *
	 * @param array<string, WP_Agent_Iteration_Budget> $budgets             Named budgets.
	 * @param float                                    $started_at          Loop start timestamp.
	 * @param int                                      $initial_elapsed_sec Existing elapsed seconds carried by the budget.
	 * @param callable|null                            $on_event            Event sink.
	 * @return string|null Exceeded budget name, or null.
	 */
	private static function check_wall_clock_budget( array $budgets, float $started_at, int $initial_elapsed_sec, ?callable $on_event ): ?string {
		$name = 'wall_clock_seconds';
		if ( ! isset( $budgets[ $name ] ) ) {
			return null;
		}

		$budget  = $budgets[ $name ];
		$elapsed = $initial_elapsed_sec + max( 0, (int) floor( microtime( true ) - $started_at ) );
		$budget->set_current( $elapsed );

		if ( $budget->exceeded() ) {
			self::emit_event( $on_event, 'budget_exceeded', self::budget_event_payload( $budget ) );
			return $name;
		}

		return null;
	}

	/**
	 * Build the standard budget-exceeded event payload.
	 *
	 * @param WP_Agent_Iteration_Budget $budget Exceeded budget.
	 * @return array<string, mixed>
	 */
	private static function budget_event_payload( WP_Agent_Iteration_Budget $budget ): array {
		$name = $budget->name();

		return array(
			'budget'    => $name,
			'dimension' => self::budget_dimension( $name ),
			'current'   => $budget->current(),
			'ceiling'   => $budget->ceiling(),
		);
	}

	/**
	 * Map budget names to generic dimensions for observers.
	 *
	 * @param string $name Budget name.
	 * @return string Dimension label.
	 */
	private static function budget_dimension( string $name ): string {
		if ( 'wall_clock_seconds' === $name ) {
			return 'wall_clock';
		}

		if ( 0 === strpos( $name, 'tool_calls' ) ) {
			return 'tool_calls';
		}

		return $name;
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
			if ( WP_Agent_Message::TYPE_TOOL_CALL === ( $message['type'] ?? '' ) ) {
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
