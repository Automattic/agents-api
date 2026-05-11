# Runtime and tool mediation

This page documents the developer-facing runtime contracts in `src/Runtime/` and `src/Tools/`. It is part of the [technical documentation index](index.md).

## Runtime boundary

`AgentsAPI\AI\WP_Agent_Conversation_Loop` is a generic multi-turn loop facade. It owns reusable mechanics only:

- normalize inbound messages with `WP_Agent_Message::normalize_many()`;
- optionally compact transcripts through caller-supplied summarization;
- call a caller-owned turn runner once per turn;
- validate normalized results with `WP_Agent_Conversation_Result`;
- optionally mediate tool calls through `WP_Agent_Tool_Execution_Core`;
- apply optional completion policy, iteration budgets, transcript locks, transcript persistence, and event emission.

It does not assemble prompts, choose a provider/model, implement concrete tools, persist durable transcripts, or define product workflow semantics. Consumers pass those concerns as adapters.

Source evidence: `src/Runtime/class-wp-agent-conversation-loop.php`, `tests/conversation-loop-smoke.php`, `tests/conversation-loop-tool-execution-smoke.php`, `tests/conversation-loop-completion-policy-smoke.php`, `tests/conversation-loop-transcript-persister-smoke.php`, `tests/conversation-loop-events-smoke.php`, and `tests/conversation-loop-budgets-smoke.php`.

## Message envelope contract

`AgentsAPI\AI\WP_Agent_Message` defines the canonical JSON-friendly envelope:

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

Important methods:

| Method | Purpose |
| --- | --- |
| `text( $role, $content, $metadata = array() )` | Build a text envelope. |
| `toolCall( $content, $tool_name, $parameters, $turn, $metadata = array() )` | Build an assistant tool-call envelope. |
| `toolResult( $content, $tool_name, $payload, $metadata = array() )` | Build a tool-result envelope. |
| `approvalRequired( $content, $payload, $metadata = array() )` | Build an approval-required envelope for pending actions. |
| `normalize( $message )` / `normalize_many( $messages )` | Validate plain messages or envelopes and produce canonical envelopes. |
| `to_provider_message()` / `to_provider_messages()` | Project envelopes into provider-facing `role`/`content` arrays, preserving type/payload in metadata when needed. |

Validation failures throw `InvalidArgumentException` for invalid roles, unsupported versions/types, non-string/non-array content, or non-JSON-serializable envelopes. This behavior is covered by `tests/message-envelope-smoke.php`.

## Conversation loop options

`WP_Agent_Conversation_Loop::run( array $messages, callable $turn_runner, array $options = array() )` supports these notable options:

| Option | Contract |
| --- | --- |
| `max_turns` | Maximum turns. Defaults to `1`; also synthesizes a `turns` budget when no explicit turns budget exists. |
| `context` | Caller-owned context passed to runner and tool executor. |
| `should_continue` | Optional continuation callback. In tool mediation mode, defaults to continue until natural completion, budget stop, or completion policy. |
| `compaction_policy` + `summarizer` | Optional transcript compaction through `WP_Agent_Conversation_Compaction`. |
| `tool_executor` + `tool_declarations` | Enables internal tool-call mediation. |
| `completion_policy` | Optional `WP_Agent_Conversation_Completion_Policy`. |
| `transcript_lock`, `transcript_session_id`, `transcript_lock_ttl` | Optional session lock boundary using `WP_Agent_Conversation_Lock`. Lock contention returns `status: transcript_lock_contention`. |
| `transcript_persister` | Optional `WP_Agent_Transcript_Persister`. Persister failures are swallowed. |
| `budgets` | Array of `WP_Agent_Iteration_Budget` objects; supports `turns`, `tool_calls`, and `tool_calls_<tool_name>`. |
| `on_event` | Optional observer callback `fn( string $event, array $payload ): void`. |

The loop emits events through both the caller sink and the WordPress action `agents_api_loop_event`. Observer exceptions are swallowed to preserve runtime results.

## Representative loop usage

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
    array( array( 'role' => 'user', 'content' => 'Summarize this.' ) ),
    static function ( array $messages, array $context ): array {
        // Dispatch to wp-ai-client, a remote runtime, or another consumer adapter.
        return array(
            'messages' => $messages,
            'content'  => 'Summary text.',
            'usage'    => array( 'total_tokens' => 42 ),
        );
    },
    array(
        'max_turns' => 2,
        'context'   => array( 'agent_id' => 'example-agent' ),
        'on_event'  => static function ( string $event, array $payload ): void {
            // Logging/metrics only; mutations do not affect the loop.
        },
    )
);
```

## Tool declaration contract

`AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration` validates scoped runtime tool declarations. It intentionally validates shape only; it does not register, expose, or execute tools.

A normalized declaration has this shape:

```php
array(
    'name'        => 'client/search',
    'source'      => 'client',
    'description' => 'Search available documents.',
    'parameters'  => array(
        'type'       => 'object',
        'properties' => array(
            'query' => array( 'type' => 'string' ),
        ),
        'required' => array( 'query' ),
    ),
    'executor'    => 'client',
    'scope'       => 'run',
)
```

Validation rules in `WP_Agent_Tool_Declaration::validate()`:

- `name` must match `^[a-z][a-z0-9_-]*/[a-z][a-z0-9_-]*$`.
- `source` must match the prefix and currently be `client`.
- `description` must be a non-empty string.
- `parameters`, when present, must be an array.
- `executor` must be `client`.
- `scope` must be `run`.

Invalid normalization throws `invalid_runtime_tool_declaration: <fields>`.

## Tool execution mediation

`AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core` prepares and executes tool calls through a consumer-provided `WP_Agent_Tool_Executor`:

1. Look up the tool definition by name.
2. Validate required parameters with `WP_Agent_Tool_Parameters`.
3. Normalize the prepared call with `WP_Agent_Tool_Call::normalize()`.
4. Invoke `WP_Agent_Tool_Executor::executeWP_Agent_Tool_Call()`.
5. Normalize the result with `WP_Agent_Tool_Result::normalize()`.

Failure modes are normalized rather than thrown for common runtime failures:

- missing tool returns `success: false` with `Tool '<name>' not found`;
- missing required parameters returns an error with `missing_parameters` metadata;
- executor exceptions are caught and returned through `WP_Agent_Tool_Result::error()`;
- executor arrays without a `success` field are wrapped as successful results.

Tool mediation behavior is covered by `tests/tool-runtime-smoke.php` and `tests/conversation-loop-tool-execution-smoke.php`.

## Tool and action policy

The tool policy classes in `src/Tools/` separate visibility from execution action policy:

- `WP_Agent_Tool_Policy` resolves which tools are visible to a run using tool modes, caller access checks, agent/runtime allow-deny lists, categories, and host `WP_Agent_Tool_Access_Policy` fragments.
- `WP_Agent_Action_Policy_Resolver` resolves a called tool to one of `direct`, `preview`, or `forbidden` from `AgentsAPI\AI\Tools\WP_Agent_Action_Policy`.
- `WP_Agent_Action_Policy_Provider` and the `agents_api_tool_action_policy` filter are host extension seams.

Policy contracts are proven by `tests/tool-policy-contracts-smoke.php`, `tests/action-policy-values-smoke.php`, and `tests/action-policy-resolver-smoke.php`.

## Runtime failure handling summary

| Failure | Behavior |
| --- | --- |
| Turn runner throws | Loop emits `failed`, persists a failure snapshot when possible, then rethrows. |
| Turn runner returns non-array | Loop emits `failed`, persists a failure snapshot when possible, then throws `InvalidArgumentException`. |
| Transcript lock contention | Loop returns normalized result with `status: transcript_lock_contention`. |
| Tool not found / invalid parameters / executor exception | Tool execution core returns normalized error result. |
| Explicit budget exceeded | Loop returns `status: budget_exceeded` and `budget: <name>`, and emits `budget_exceeded`. |
| Observer or transcript persister throws | Exception is swallowed; runtime result is unchanged. |
