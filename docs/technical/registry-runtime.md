# Agent Registry and Runtime

This page documents the developer-facing contracts for registering agents and running provider-neutral conversations. It covers `src/Registry`, `src/Runtime`, and `src/Transcripts`.

## Registration lifecycle

`agents-api.php` loads registry classes and wires `WP_Agents_Registry::init()` to WordPress `init` priority 10. `WP_Agents_Registry::init()` creates the singleton registry and fires:

```php
do_action( 'wp_agents_api_init', $registry );
```

Consumers should register agents from that hook:

```php
add_action( 'wp_agents_api_init', static function () {
	wp_register_agent(
		'example-agent',
		array(
			'label'       => 'Example Agent',
			'description' => 'Answers product questions.',
			'meta'        => array(
				'source_plugin'  => 'example/example.php',
				'source_type'    => 'bundled-agent',
				'source_package' => 'example-suite',
				'source_version' => '1.0.0',
			),
		)
	);
} );
```

Reads such as `wp_get_agent()` are safe after `init`. Calling the registry before `init` returns `null` and emits `_doing_it_wrong()` when WordPress helpers are available.

## `WP_Agent`

`WP_Agent` is a declarative, non-persistent agent definition. Constructing one does not create database rows, access records, directories, or scaffold files.

Core fields and getters:

| Field | Getter | Purpose |
| --- | --- | --- |
| `slug` | `get_slug()` | Sanitized unique agent slug. Empty slugs throw `InvalidArgumentException`. |
| `label` | `get_label()` | Human label; defaults to slug. |
| `description` | `get_description()` | Optional description. |
| `memory_seeds` | `get_memory_seeds()` | Map of scaffold filename to seed path. Filenames are sanitized. |
| `owner_resolver` | `get_owner_resolver()` | Optional callable for host-owned ownership resolution. |
| `default_config` | `get_default_config()` | Initial materialization/runtime config. |
| `supports_conversation_compaction` | `supports_conversation_compaction()` | Opt-in boolean for runtime compaction. |
| `conversation_compaction_policy` | `get_conversation_compaction_policy()` | Normalized compaction policy via `WP_Agent_Conversation_Compaction::normalize_policy()`. |
| `meta` | `get_meta()` | Optional metadata; source provenance keys are reserved for diagnostics. |
| `subagents` | `get_subagents()`, `is_coordinator()` | Slugs this agent can coordinate. |

`to_array()` exports a JSON-friendly registration shape. `__sleep()` and `__wakeup()` throw `LogicException`; agent definitions are not intended to be serialized.

## Registry behavior

`WP_Agents_Registry` stores registered agent definitions in memory, keyed by slug.

- `register( string|WP_Agent $agent, array $args = array() ): ?WP_Agent` returns `null` on invalid arguments or duplicate slugs.
- `get_all_registered(): array` returns all definitions.
- `get_registered( string $slug ): ?WP_Agent` returns `null` and emits invalid-usage diagnostics for unknown slugs.
- `is_registered( string $slug ): bool` checks presence after slug sanitization.
- `unregister( string $slug ): ?WP_Agent` removes and returns a definition.
- `reset_for_tests(): void` clears the singleton and initialization flag for smoke tests.

Duplicate registration diagnostics include source provenance from `meta.source_plugin`, `source_type`, `source_package`, and `source_version` when available.

## Conversation loop boundary

`AgentsAPI\AI\WP_Agent_Conversation_Loop::run()` is a generic multi-turn loop facade. It owns reusable sequencing and validation, while callers own prompt assembly, provider/model execution, durable storage, concrete tools, and product policy.

Signature:

```php
WP_Agent_Conversation_Loop::run(
	array $messages,
	callable $turn_runner,
	array $options = array()
): array
```

The turn runner receives `(array $messages, array $context)` and must return an array. The loop normalizes inbound messages with `WP_Agent_Message::normalize_many()` and returns a `WP_Agent_Conversation_Result`-normalized array.

Supported options include:

| Option | Type | Responsibility |
| --- | --- | --- |
| `max_turns` | `int` | Upper bound; defaults to 1. |
| `budgets` | `WP_Agent_Iteration_Budget[]` | Named ceilings such as `turns`, `tool_calls`, or `tool_calls_<name>`. |
| `context` | `array` | Caller-owned context passed to adapters. |
| `should_continue` | `callable|null` | Caller continuation policy. Tool mediation defaults to continue until natural completion or another stop condition. |
| `compaction_policy` + `summarizer` | `array` + `callable` | Optional transcript compaction before a turn. |
| `tool_executor` + `tool_declarations` | interface + array | Enables internal tool-call mediation. |
| `completion_policy` | `WP_Agent_Conversation_Completion_Policy` | Typed stop policy after tool results. |
| `transcript_lock` / `transcript_lock_store` | `WP_Agent_Conversation_Lock` | Optional session lock. |
| `transcript_session_id` / `session_id` / `transcript_id` | `string` | Session ID for locking. |
| `transcript_lock_ttl` | `int` | Lock TTL; defaults to 300 seconds. |
| `transcript_persister` | `WP_Agent_Transcript_Persister` | Optional transcript persistence adapter. |
| `on_event` | `callable` | Observability sink `fn( string $event, array $payload ): void`. |

## Loop lifecycle and failure modes

The loop emits lifecycle events to both the caller `on_event` sink and the WordPress `agents_api_loop_event` action. Observer exceptions are swallowed so logging cannot change model execution, tool mediation, persistence, or returned results.

Representative events include `turn_started`, `tool_call`, `tool_result`, `budget_exceeded`, `transcript_lock_contention`, `completed`, and `failed`.

Failure and stop behavior:

- A non-array turn-runner result throws `InvalidArgumentException` after emitting `failed` and attempting transcript persistence.
- Exceptions from the turn runner are rethrown after `failed` emission and best-effort persistence.
- Transcript lock contention returns a normalized result with `status: transcript_lock_contention` and no tool results.
- Explicit budget exceedance returns `status: budget_exceeded` and `budget: <name>`.
- Persister and lock-release exceptions are swallowed; they must not alter the loop result.

## Tool mediation inside the loop

When `tool_executor` and `tool_declarations` are provided, the loop expects the turn runner to return optional `tool_calls` entries:

```php
array(
	'messages'   => $messages,
	'content'    => 'I will check that.',
	'tool_calls' => array(
		array( 'name' => 'client/lookup', 'parameters' => array( 'id' => 42 ) ),
	),
)
```

The loop adds assistant/tool-call/tool-result messages, executes through `WP_Agent_Tool_Execution_Core`, emits events, records `tool_execution_results`, and consults budgets and completion policy.

## Transcript contracts

The runtime loop can work with transcript persistence and locking without selecting a store:

- `WP_Agent_Transcript_Persister` persists final messages, request, and result.
- `WP_Agent_Null_Transcript_Persister` is the no-op implementation.
- `WP_Agent_Conversation_Store` and lock contracts live in `src/Transcripts` for concrete stores to implement.
- `WP_Agent_Conversation_Lock` exposes session lock acquisition/release; `WP_Agent_Null_Conversation_Lock` is a no-op lock.

## Evidence

Source: `agents-api.php`, `src/Registry/class-wp-agent.php`, `src/Registry/class-wp-agents-registry.php`, `src/Registry/register-agents.php`, `src/Runtime/class-wp-agent-conversation-loop.php`, `src/Runtime/class-wp-agent-conversation-result.php`, `src/Runtime/class-wp-agent-message.php`, and `src/Transcripts/*`.

Tests: `tests/registry-smoke.php`, `tests/subagents-smoke.php`, `tests/conversation-loop-smoke.php`, `tests/conversation-loop-tool-execution-smoke.php`, `tests/conversation-loop-completion-policy-smoke.php`, `tests/conversation-loop-transcript-persister-smoke.php`, `tests/conversation-loop-events-smoke.php`, `tests/conversation-loop-budgets-smoke.php`, and `tests/conversation-transcript-lock-smoke.php`.
