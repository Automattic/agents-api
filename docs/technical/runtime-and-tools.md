# Runtime Loop And Tool Mediation

This page documents the developer-facing runtime and tools contracts in Agents API. It is derived from `src/Runtime/*`, `src/Tools/*`, `src/Approvals/*`, and smoke tests including `tests/conversation-loop-*.php`, `tests/tool-runtime-smoke.php`, `tests/tool-policy-contracts-smoke.php`, and `tests/approval-*.php`.

## Runtime boundary

Agents API owns reusable runtime sequencing and value-object contracts. It does not assemble prompts, select providers or models, implement concrete tools, create product UI, or choose durable storage. Consumers provide those adapters.

Core runtime contracts include:

| Contract | Source | Purpose |
| --- | --- | --- |
| `AgentsAPI\AI\WP_Agent_Message` | `src/Runtime/class-wp-agent-message.php` | Normalizes transcript message envelopes and creates text, tool-call, and tool-result messages. |
| `WP_Agent_Conversation_Request` | `src/Runtime/class-wp-agent-conversation-request.php` | Carries inbound messages, options, principal/workspace context, metadata, and max-turn configuration. |
| `WP_Agent_Conversation_Result` | `src/Runtime/class-wp-agent-conversation-result.php` | Normalizes loop return data: messages, tool execution results, events, status, turn count, final content, usage, and request metadata. |
| `WP_Agent_Conversation_Runner` | `src/Runtime/class-wp-agent-conversation-runner.php` | Interface boundary for caller-owned turn runners. |
| `WP_Agent_Execution_Principal` | `src/Runtime/class-wp-agent-execution-principal.php` | Actor, auth source, agent, token/client/workspace, capability ceiling, caller context, and request metadata for one execution. |
| `WP_Agent_Conversation_Loop` | `src/Runtime/class-wp-agent-conversation-loop.php` | Generic multi-turn loop facade around caller-owned adapters. |
| `WP_Agent_Iteration_Budget` | `src/Runtime/class-wp-agent-iteration-budget.php` | Stateful budget counter for turns, tool calls, retries, chain depth, and similar bounded dimensions. |
| `WP_Agent_Transcript_Persister` / `WP_Agent_Null_Transcript_Persister` | `src/Runtime` | Optional transcript persistence adapter boundary. |

## Conversation loop lifecycle

`WP_Agent_Conversation_Loop::run( array $messages, callable $turn_runner, array $options = array() ): array` sequences a run as follows:

1. Resolve options: max turns, context, tool executor/declarations, continuation policy, completion policy, transcript persister, transcript lock, event sink, request, lock session ID, lock TTL, and budgets.
2. Normalize inbound messages with `WP_Agent_Message::normalize_many()`.
3. Acquire an optional transcript lock. Lock contention returns a normalized result with `status = transcript_lock_contention`.
4. For each turn:
   - emit `turn_started`;
   - optionally compact messages via `WP_Agent_Conversation_Compaction::compact()`;
   - call the caller-owned turn runner;
   - accumulate optional `usage` and `request_metadata` fields;
   - either mediate tool calls or normalize the caller-managed result;
   - apply completion, budget, and continuation stop conditions.
5. Normalize the final result, persist the transcript if a persister is present, emit `completed`, and release any transcript lock in a `finally` block.

The turn runner must return an array. If it throws, the loop emits `failed`, persists the failure transcript when configured, and rethrows. If it returns a non-array, the loop throws `InvalidArgumentException`.

## Loop options

Important options supported by `WP_Agent_Conversation_Loop::run()`:

| Option | Type | Behavior |
| --- | --- | --- |
| `max_turns` | int | Maximum turns; defaults to 1. A synthesized `turns` budget preserves this ceiling. |
| `context` | array | Caller-owned runtime context passed to adapters, with `turn` added per iteration. |
| `should_continue` | callable|null | Caller-owned continuation policy. In mediated mode, omitted means continue until natural completion, completion policy, budget, or max turns. In caller-managed mode, omitted preserves break-after-one behavior. |
| `compaction_policy` / `summarizer` | array / callable | Opt-in transcript compaction before each turn. |
| `tool_executor` | `WP_Agent_Tool_Executor` | Concrete tool execution adapter. Required for loop-owned mediation. |
| `tool_declarations` | array | Tool declarations keyed by name. Required for loop-owned mediation. |
| `completion_policy` | `WP_Agent_Conversation_Completion_Policy` | Typed policy that can stop after tool results. |
| `transcript_lock`, `transcript_lock_store`, `transcript_store` | `WP_Agent_Conversation_Lock` | Optional session lock. |
| `transcript_session_id`, `session_id`, `transcript_id` | string | Session ID to lock. Metadata on the request can also supply these keys. |
| `transcript_persister` | `WP_Agent_Transcript_Persister` | Optional persistence adapter; failures are swallowed. |
| `budgets` | `WP_Agent_Iteration_Budget[]` | Named budgets for `turns`, `tool_calls`, and `tool_calls_<tool_name>`. |
| `on_event` | callable | Observability sink `fn( string $event, array $payload ): void`. |

## Lifecycle events

The loop emits events to the caller-owned `on_event` sink and to the WordPress action `agents_api_loop_event`. Observer failures are swallowed so logging or tracing cannot affect runtime results.

Common events include:

- `turn_started`
- `tool_call`
- `tool_result`
- `budget_exceeded`
- `transcript_lock_contention`
- `failed`
- `completed`

Payloads are read-only snapshots. Observers should not mutate payloads expecting to change loop behavior.

## Tool mediation contracts

Tools are represented by JSON-friendly declarations, normalized calls, and normalized results.

| Contract | Source | Purpose |
| --- | --- | --- |
| `WP_Agent_Tool_Declaration` | `src/Tools/class-wp-agent-tool-declaration.php` | Normalized tool definition, source, executor, scope, parameters, and policy metadata. |
| `WP_Agent_Tool_Call` | `src/Tools/class-wp-agent-tool-call.php` | Normalized runtime call envelope with `tool_name`, `parameters`, and metadata. |
| `WP_Agent_Tool_Parameters` | `src/Tools/class-wp-agent-tool-parameters.php` | Required-parameter validation and parameter construction from runtime context. |
| `WP_Agent_Tool_Executor` | `src/Tools/class-wp-agent-tool-executor.php` | Consumer adapter that executes a prepared tool call. |
| `WP_Agent_Tool_Execution_Core` | `src/Tools/class-wp-agent-tool-execution-core.php` | Validates declarations, prepares calls, catches executor exceptions, and normalizes results. |
| `WP_Agent_Tool_Result` | `src/Tools/class-wp-agent-tool-result.php` | Success/error result normalization. |
| `WP_Agent_Tool_Source_Registry` | `src/Tools/class-wp-agent-tool-source-registry.php` | Registry for tool sources. |

`WP_Agent_Tool_Execution_Core::executeTool()` performs three steps:

1. Look up the declaration by tool name; missing tools return an error result.
2. Validate required parameters; missing required fields return an error result with `missing_parameters` metadata.
3. Build a normalized `WP_Agent_Tool_Call` and call the consumer-provided `WP_Agent_Tool_Executor`.

Executor exceptions are caught and converted to normalized error results.

## Mediated loop example

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array( array( 'role' => 'user', 'content' => 'Summarize this' ) ),
	static function ( array $messages, array $context ): array {
		return array(
			'messages'   => $messages,
			'content'    => 'I will call a tool.',
			'tool_calls' => array(
				array(
					'name'       => 'client/summarize',
					'parameters' => array( 'text' => 'hello world' ),
				),
			),
		);
	},
	array(
		'max_turns'         => 5,
		'tool_executor'     => $executor,
		'tool_declarations' => $tool_declarations,
	)
);
```

`tests/conversation-loop-tool-execution-smoke.php` proves that mediated mode appends assistant, tool-call, and tool-result messages; returns `tool_execution_results`; validates missing parameters without invoking the executor; and continues across turns without an explicit `should_continue` option.

## Tool visibility and action policy

Visibility and action policy are separate:

- `WP_Agent_Tool_Policy` resolves which tools are visible to an agent/run.
- `WP_Agent_Action_Policy_Resolver` resolves whether an action is `direct`, `preview`, or `forbidden`.

Visibility policy can combine tool-declared modes, caller-owned access checks, agent/runtime `tool_policy`, host `WP_Agent_Tool_Access_Policy` providers, categories, `allow_only`, and deny lists. Explicit deny wins.

Action policy resolution checks runtime deny lists, agent/runtime tool policies, category policies, host `WP_Agent_Action_Policy_Provider` providers, tool defaults, mode-specific defaults, global default `direct`, and the final `agents_api_tool_action_policy` filter.

Tests proving this surface include `tests/tool-policy-contracts-smoke.php`, `tests/action-policy-values-smoke.php`, and `tests/action-policy-resolver-smoke.php`.

## Pending action approval

Approvals in `src/Approvals` are generic runtime primitives for proposed actions that must be accepted or rejected before a consumer applies them.

Key contracts:

- `WP_Agent_Pending_Action`
- `WP_Agent_Approval_Decision`
- `WP_Agent_Pending_Action_Status`
- `WP_Agent_Pending_Action_Store`
- `WP_Agent_Pending_Action_Resolver`
- `WP_Agent_Pending_Action_Handler`

The substrate defines value shapes, status vocabulary, resolver/handler interfaces, and store/audit boundaries. Consumers own database tables, REST routes, UI, queues, permission checks, and product-specific apply/reject handlers.

## Failure modes and safety behavior

- Invalid turn-runner return type throws `InvalidArgumentException`.
- Turn-runner exceptions emit `failed`, optionally persist, then rethrow.
- Tool declaration lookup and parameter failures return normalized tool error results.
- Tool executor exceptions are caught and returned as tool error results.
- Explicit budgets return final `status = budget_exceeded` and include the exceeded budget name.
- Transcript lock contention returns a normalized result without running the loop.
- Transcript persistence and lock release failures are swallowed so they do not change the loop result.
