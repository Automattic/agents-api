# Runtime Conversation Loop

Agents API provides a composable, provider-neutral runtime loop rather than a concrete agent runtime. Consumers supply the provider/model adapter, prompt policy, concrete tools, storage, and product UI.

Source: `src/Runtime/*`, `src/Tools/*`, `src/Transcripts/*`, and smoke tests for messages, conversation runners, loop mediation, events, budgets, compaction, transcript persistence, and locks.

## Core boundary

`AgentsAPI\AI\WP_Agent_Conversation_Loop::run()` owns reusable loop mechanics:

1. Normalize inbound messages with `WP_Agent_Message::normalize_many()`.
2. Optionally compact messages before each turn.
3. Call a caller-owned turn runner once per turn.
4. Normalize turn results through `WP_Agent_Conversation_Result` in the caller-managed path.
5. Optionally mediate `tool_calls` through `WP_Agent_Tool_Execution_Core`.
6. Apply an optional `WP_Agent_Conversation_Completion_Policy`.
7. Enforce turn and tool-call budgets.
8. Persist transcripts through an optional `WP_Agent_Transcript_Persister`.
9. Emit lifecycle events to a caller sink and the WordPress action surface.
10. Release transcript locks without changing the returned result when release fails.

It does **not** assemble prompts, call a provider, choose a model, implement tools, persist durable sessions, or decide product policy.

## `WP_Agent_Message` envelope

`WP_Agent_Message` defines a JSON-friendly message envelope:

```php
array(
	'schema'   => 'agents-api.message',
	'version'  => 1,
	'type'     => 'text',
	'role'     => 'user',
	'content'  => 'Hello',
	'payload'  => array(),
	'metadata' => array(),
)
```

Supported `type` values are:

- `text`
- `tool_call`
- `tool_result`
- `input_required`
- `approval_required`
- `final_result`
- `error`
- `delta`
- `multimodal_part`

Important helpers:

| Helper | Purpose |
| --- | --- |
| `text( $role, $content, $metadata = array() )` | Builds a canonical text envelope. |
| `toolCall( $content, $tool_name, $parameters, $turn, $metadata = array() )` | Builds an assistant tool-call envelope. |
| `toolResult( $content, $tool_name, $payload, $metadata = array() )` | Builds a tool-result envelope. |
| `approvalRequired( $content, $payload, $metadata = array() )` | Builds a generic approval-required envelope. |
| `normalize()` / `normalize_many()` | Converts plain role/content messages or typed envelopes to canonical envelopes. |
| `to_provider_message()` / `to_provider_messages()` | Projects envelopes to provider-facing role/content plus metadata. |

Validation fails with `InvalidArgumentException` when messages are not arrays, roles are empty, content is not a string or array, type is unsupported, version is unsupported, or the envelope is not JSON serializable.

## Loop options

Common `WP_Agent_Conversation_Loop::run()` options:

| Option | Type | Responsibility |
| --- | --- | --- |
| `max_turns` | `int` | Maximum turns; defaults to 1. Also creates an implicit `turns` budget when no explicit turns budget is provided. |
| `context` | `array` | Caller-owned runtime context passed to adapters. |
| `should_continue` | `callable|null` | Caller continuation policy. In the tool-mediation path, defaults to continue until a loop stop condition fires. |
| `compaction_policy` | `array|null` | Optional compaction policy. Requires `summarizer`. |
| `summarizer` | `callable|null` | Caller-owned summarizer for compaction. |
| `tool_executor` | `WP_Agent_Tool_Executor|null` | Concrete tool executor adapter. |
| `tool_declarations` | `array|null` | Tool declarations keyed by name. |
| `completion_policy` | `WP_Agent_Conversation_Completion_Policy|null` | Typed completion policy. |
| `transcript_lock` / `transcript_lock_store` / `transcript_store` | `WP_Agent_Conversation_Lock|null` | Optional session lock primitive. |
| `transcript_session_id` / `session_id` / `transcript_id` | `string` | Session ID to lock. Falls back to request metadata. |
| `transcript_lock_ttl` | `int` | Lock TTL in seconds; defaults to 300. |
| `transcript_persister` | `WP_Agent_Transcript_Persister|null` | Optional transcript persistence adapter. |
| `request` | `WP_Agent_Conversation_Request|null` | Request object supplied to persisters. |
| `budgets` | `WP_Agent_Iteration_Budget[]` | Named budgets for turns and tool calls. |
| `on_event` | `callable|null` | Observer `fn( string $event, array $payload ): void`. |

## Minimal caller-managed loop

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	array(
		array( 'role' => 'user', 'content' => 'Summarize the latest status.' ),
	),
	static function ( array $messages, array $context ): array {
		// Consumer adapter: assemble prompt, call provider, return normalized result.
		$response = $provider->complete( $messages, $context );

		return array(
			'messages' => array_merge(
				$messages,
				array( array( 'role' => 'assistant', 'content' => $response->text() ) )
			),
			'events'   => array(),
		);
	},
	array( 'max_turns' => 1 )
);
```

## Tool-mediated loop

When `tool_executor` and `tool_declarations` are supplied, the turn runner returns provider output plus `tool_calls`; the loop validates, executes, appends tool-call/tool-result messages, and continues until natural completion, completion policy, a budget, or `max_turns` stops execution.

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	$messages,
	static function ( array $messages, array $context ): array {
		$response = $ai_client->prompt( $messages );

		return array(
			'messages'   => $messages,
			'content'    => $response->text(),
			'tool_calls' => $response->tool_calls(),
			'usage'      => $response->usage(),
		);
	},
	array(
		'max_turns'         => 10,
		'context'           => array( 'agent_id' => 'example-agent' ),
		'tool_executor'     => $executor,
		'tool_declarations' => $visible_tools,
		'completion_policy' => $completion_policy,
		'budgets'           => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 20 ),
		),
	)
);
```

## Result shape

The final result is normalized by `WP_Agent_Conversation_Result` and includes, where available:

- `messages`: normalized transcript.
- `tool_execution_results`: all tool execution records accumulated across turns.
- `events`: normalized loop/compaction/completion events.
- `turn_count`: number of turns run.
- `final_content`: latest assistant text content.
- `usage`: accumulated numeric usage fields; non-numeric provider fields use the latest value.
- `request_metadata`: latest request metadata reported by the turn runner.
- `status` and `budget` when a budget stops execution.

If an explicit budget is exceeded, status is `budget_exceeded` and `budget` contains the budget name.

## Events and observability

The loop emits events through two read-only observer surfaces:

- caller-owned `on_event` callable;
- WordPress action `agents_api_loop_event`.

Observed event names include `turn_started`, `tool_call`, `tool_result`, `budget_exceeded`, `completed`, `failed`, and `transcript_lock_contention`. Compaction can add `compaction_started`, `compaction_completed`, or `compaction_failed` events to the result.

Observer exceptions are swallowed. Observers must not rely on mutating payloads to affect loop behavior.

## Budgets

`WP_Agent_Iteration_Budget` is a stateful value object with `name()`, `ceiling()`, `current()`, `remaining()`, `increment()`, and `exceeded()`.

The loop recognizes:

- `turns`: incremented after each completed turn. Explicit `turns` budgets produce `budget_exceeded`; implicit `max_turns` budgets preserve legacy stop behavior.
- `tool_calls`: incremented after each mediated tool call.
- `tool_calls_<tool_name>`: per-tool budget for ping-pong protection.

## Compaction

`WP_Agent_Conversation_Compaction::compact()` transforms transcripts before model dispatch when a compaction policy and caller-owned summarizer are supplied. The substrate preserves tool-call/tool-result integrity where possible and returns the original transcript with a failure event if summarization fails.

Compaction-related classes include:

- `WP_Agent_Conversation_Compaction`
- `WP_Agent_Compaction_Item`
- `WP_Agent_Compaction_Conservation`
- `WP_Agent_Markdown_Section_Compaction_Adapter`

Markdown-section compaction is covered by smoke tests and supports section-aware parsing/reconstruction without tying storage paths to the substrate.

## Transcript persistence and locks

`WP_Agent_Transcript_Persister` is the adapter interface for persisting final transcript data. `WP_Agent_Null_Transcript_Persister` provides a no-op implementation.

`WP_Agent_Conversation_Lock` and `WP_Agent_Null_Conversation_Lock` define transcript session locking. If a lock cannot be acquired, the loop returns a normalized result with status `transcript_lock_contention` and emits a matching event. Lock release failures are swallowed.

`WP_Agent_Conversation_Store` is the durable session contract. It stores session state, workspace, user, agent slug, provider/model, provider response ID, timestamps, and pending-session lookup fields; concrete stores are consumer or companion-package concerns.

## Failure behavior

- Non-array turn-runner results throw `InvalidArgumentException` after a `failed` event and best-effort transcript persistence.
- Turn-runner exceptions emit `failed`, persist a best-effort failure result, then rethrow.
- Persister exceptions are swallowed so persistence failures do not alter runtime results.
- Tool executor exceptions are converted into normalized tool errors by the tool execution core.
- Observer exceptions and lock-release exceptions are swallowed.
