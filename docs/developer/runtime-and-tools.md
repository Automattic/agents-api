# Runtime Loop And Tool Mediation

Agents API provides runtime primitives for multi-turn agent execution without owning prompt assembly, provider/model calls, concrete tools, or durable runtime storage.

## Core runtime contracts

Runtime classes live in `src/Runtime/**` under the `AgentsAPI\AI` namespace.

| Surface | Purpose | Key responsibilities |
| --- | --- | --- |
| `WP_Agent_Message` | Normalized message envelope. | Creates and normalizes text, tool-call, and tool-result messages. |
| `WP_Agent_Conversation_Request` | Runtime request value. | Carries messages, tools, principal/context, metadata, max turns, and optional workspace. |
| `WP_Agent_Conversation_Result` | Normalized loop result. | Normalizes messages, tool execution results, events, final content, usage, request metadata, status, and budget details. |
| `WP_Agent_Conversation_Runner` | Provider/runtime adapter interface. | Defines a caller-owned turn runner boundary. |
| `WP_Agent_Execution_Principal` | Actor and agent execution context. | Represents user/session/token/agent context, request source, workspace/client identifiers, capability ceiling, caller context, and metadata. |
| `WP_Agent_Conversation_Completion_Policy` and `WP_Agent_Conversation_Completion_Decision` | Typed stop policy. | Lets callers stop after tool results or runtime conditions. |
| `WP_Agent_Transcript_Persister` and `WP_Agent_Null_Transcript_Persister` | Transcript persistence seam. | Persists final messages/request/result when supplied; failures are swallowed by the loop. |
| `WP_Agent_Iteration_Budget` | Bounded execution primitive. | Counts dimensions such as `turns`, `tool_calls`, and `tool_calls_<name>`. |
| `WP_Agent_Conversation_Compaction` and related compaction classes | Transcript compaction. | Uses caller-supplied summarizers and preserves tool-call/tool-result integrity. |

## `WP_Agent_Conversation_Loop::run()`

`WP_Agent_Conversation_Loop` is the reusable sequencing facade. It owns neutral mechanics and delegates product decisions to adapters.

Supported options include:

- `max_turns`: maximum turns, default `1`.
- `budgets`: `WP_Agent_Iteration_Budget[]`; explicit `turns` budgets report `budget_exceeded`.
- `context`: caller-owned context passed to adapters.
- `should_continue`: optional continuation callable.
- `compaction_policy` and `summarizer`: optional compaction.
- `tool_executor` and `tool_declarations`: opt-in tool mediation.
- `completion_policy`: typed completion policy.
- `transcript_lock`, `transcript_lock_store`, or `transcript_store`: optional `WP_Agent_Conversation_Lock`.
- `transcript_session_id`, `session_id`, or `transcript_id`: lock target.
- `transcript_lock_ttl`: lock TTL, default `300` seconds.
- `transcript_persister`: optional persistence adapter.
- `on_event`: callable lifecycle observer.
- `request`: optional `WP_Agent_Conversation_Request` for persistence context.

### Loop sequence

1. Resolve adapters, budgets, lock, request, and event sink.
2. Normalize incoming messages with `WP_Agent_Message::normalize_many()`.
3. Acquire an optional transcript lock; if unavailable, return status `transcript_lock_contention`.
4. For each turn:
   - Emit `turn_started`.
   - Optionally compact messages.
   - Call the caller-owned turn runner.
   - Accumulate optional `usage` and `request_metadata` returned by the turn runner.
   - If tool mediation is enabled and `tool_calls` are present, mediate them through `WP_Agent_Tool_Execution_Core`.
   - Otherwise normalize the caller-managed result through `WP_Agent_Conversation_Result`.
   - Stop on budget exceedance, completion-policy decision, or `should_continue` returning false.
5. Normalize the final result, persist through the transcript persister if present, emit `completed`, and release any lock.

Turn-runner exceptions are rethrown after the loop emits `failed` and attempts transcript persistence. Observer, persister, and lock-release failures are swallowed so telemetry and cleanup cannot change the returned execution result.

## Lifecycle events

Events are emitted to two observer surfaces:

- The caller-owned `on_event` callable.
- The WordPress `agents_api_loop_event` action.

Observed event names include `turn_started`, `tool_call`, `tool_result`, `budget_exceeded`, `completed`, `failed`, and `transcript_lock_contention`. Event payloads are snapshots; observers should not rely on mutation.

## Tool declarations and execution

Tool contracts live in `src/Tools/**` under `AgentsAPI\AI\Tools`.

| Surface | Purpose |
| --- | --- |
| `WP_Agent_Tool_Declaration` | Normalizes tool declaration arrays, names, categories, modes, parameters, executor hints, and action policy defaults. |
| `WP_Agent_Tool_Call` | Normalized runtime call shape with tool name, parameters, and metadata. |
| `WP_Agent_Tool_Parameters` | Validates required parameters and builds execution parameters from runtime values/context. |
| `WP_Agent_Tool_Executor` | Consumer interface for applying a prepared tool call. |
| `WP_Agent_Tool_Execution_Core` | Validates a requested tool, prepares the call, invokes the executor, catches executor exceptions, and normalizes results. |
| `WP_Agent_Tool_Result` | Normalized success/error result shape. |
| `WP_Agent_Tool_Source_Registry` | Registers tool sources. |
| `WP_Agent_Tool_Policy`, `WP_Agent_Tool_Policy_Filter`, `WP_Agent_Tool_Access_Policy` | Resolve tool visibility across modes, access checks, allow/deny lists, categories, and provider policy fragments. |
| `WP_Agent_Action_Policy`, `WP_Agent_Action_Policy_Resolver`, `WP_Agent_Action_Policy_Provider` | Resolve whether a visible tool call is `direct`, `preview`, or `forbidden`. |

`WP_Agent_Tool_Execution_Core::prepareWP_Agent_Tool_Call()` returns a normalized error when the tool is unknown or required parameters are missing. `executePreparedTool()` catches thrown executor errors and returns a normalized `WP_Agent_Tool_Result::error()` instead of leaking adapter exceptions into the loop.

## Example: loop with mediated tools

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( AgentsAPI\AI\WP_Agent_Message::text( 'user', 'Summarize order 42.' ) ),
	static function ( array $messages, array $context ): array {
		$response = $provider->run_turn( $messages, $context );
		return array(
			'messages'   => $messages,
			'content'    => $response['text'] ?? '',
			'tool_calls' => $response['tool_calls'] ?? array(),
			'usage'      => $response['usage'] ?? array(),
		);
	},
	array(
		'context'           => array( 'agent_id' => 'support-agent' ),
		'max_turns'         => 6,
		'tool_declarations' => $visible_tools,
		'tool_executor'     => $tool_executor,
		'budgets'           => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 10 ),
		),
		'on_event'          => static function ( string $event, array $payload ): void {
			$logger->debug( $event, $payload );
		},
	)
);
```

## Failure modes

- Missing tool declaration returns a normalized tool error (`Tool '<name>' not found`).
- Missing required parameters return a normalized tool error with `missing_parameters` metadata.
- Executor exceptions are caught and normalized as tool errors.
- Non-array turn-runner output throws `InvalidArgumentException` after a `failed` event.
- Transcript lock contention returns an early normalized result with status `transcript_lock_contention`.
- Explicit budget exceedance returns status `budget_exceeded` and the budget name.

## Evidence

- Implementation: `src/Runtime/class-wp-agent-conversation-loop.php`, `src/Runtime/class-wp-agent-message.php`, `src/Runtime/class-wp-agent-conversation-result.php`, `src/Runtime/class-wp-agent-iteration-budget.php`, `src/Tools/class-wp-agent-tool-execution-core.php`.
- Tests: `tests/conversation-loop-smoke.php`, `tests/conversation-loop-tool-execution-smoke.php`, `tests/conversation-loop-completion-policy-smoke.php`, `tests/conversation-loop-transcript-persister-smoke.php`, `tests/conversation-loop-events-smoke.php`, `tests/conversation-loop-budgets-smoke.php`, `tests/tool-runtime-smoke.php`, `tests/iteration-budget-smoke.php`.
