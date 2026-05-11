# Runtime and Tools

This page documents the runtime loop and tool-mediation contracts that consumers use to run multi-turn agents while keeping provider execution, concrete tools, and product policy outside Agents API.

Source evidence: `src/Runtime/*`, `src/Tools/*`, `tests/conversation-loop-*.php`, `tests/tool-runtime-smoke.php`, `tests/tool-policy-contracts-smoke.php`, `tests/action-policy-*.php`, `tests/iteration-budget-smoke.php`, and `README.md`.

## Runtime boundary

Agents API owns reusable runtime mechanics:

- normalized message envelopes;
- conversation request/result value objects;
- execution-principal context;
- optional transcript compaction;
- multi-turn loop sequencing;
- tool-call validation and mediation;
- completion-policy and iteration-budget stop conditions;
- transcript persister and transcript lock contracts;
- lifecycle event emission.

Consumers own provider/model dispatch, prompt assembly, concrete tools, durable storage, streaming UX, product-specific continuation policy, and final business semantics.

## Message and request shapes

`AgentsAPI\AI\WP_Agent_Message` normalizes transcript entries into JSON-friendly message arrays. The loop accepts arrays and normalizes them before passing them to caller adapters.

Representative message roles include user, assistant, tool-call, and tool-result entries. The loop appends assistant content, tool-call messages, and tool-result messages when tool mediation is enabled.

`AgentsAPI\AI\WP_Agent_Conversation_Request` carries the original messages, tool declarations, execution principal, caller-owned context, metadata, and max-turn settings. Runtime and transcript adapters can use the request object to stamp storage or audit records without Agents API choosing storage.

`AgentsAPI\AI\WP_Agent_Conversation_Result` normalizes loop output. Typical keys include:

```php
array(
	'messages'               => $messages,
	'tool_execution_results' => $tool_results,
	'events'                 => $events,
	'turn_count'             => $turns_run,
	'final_content'          => $last_assistant_text,
	'usage'                  => array(
		'prompt_tokens'     => 0,
		'completion_tokens' => 0,
		'total_tokens'      => 0,
	),
	'request_metadata'       => $request_metadata,
)
```

When an explicit budget trips, the result includes `status => 'budget_exceeded'` and `budget => '<budget-name>'`.

## Conversation loop

`WP_Agent_Conversation_Loop::run( array $messages, callable $turn_runner, array $options = array() ): array` is the main loop facade.

Important options:

| Option | Purpose |
| --- | --- |
| `max_turns` | Maximum loop turns, default `1`. |
| `budgets` | `WP_Agent_Iteration_Budget[]` keyed by budget name after resolution. |
| `context` | Caller-owned context passed to turn runners and tools. |
| `should_continue` | Optional continuation policy. With tool mediation enabled, the default continues until natural completion or a stop condition. |
| `compaction_policy` / `summarizer` | Optional caller-supplied compaction behavior. |
| `tool_executor` | `WP_Agent_Tool_Executor` adapter for concrete execution. |
| `tool_declarations` | Runtime tools keyed by name. |
| `completion_policy` | `WP_Agent_Conversation_Completion_Policy` implementation. |
| `transcript_lock` / `transcript_lock_store` | Optional `WP_Agent_Conversation_Lock`. |
| `transcript_session_id` / `session_id` / `transcript_id` | Session id used for lock acquisition. |
| `transcript_persister` | Optional `WP_Agent_Transcript_Persister`. |
| `on_event` | Caller-owned event sink `fn( string $event, array $payload ): void`. |

### Minimal caller-managed loop

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	$messages,
	static function ( array $messages, array $context ): array {
		return $provider_adapter->run_turn( $messages, $context );
	},
	array(
		'max_turns' => 1,
		'context'   => array( 'agent_id' => 'example-agent' ),
	)
);
```

### Loop with tool mediation

When `tool_executor` and `tool_declarations` are provided, the turn runner returns provider output plus `tool_calls`; the loop validates and executes those calls and appends tool-result messages.

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
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
		'budgets'           => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 20 ),
		),
	)
);
```

## Runtime tool declarations

`AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration` validates per-run tool declarations. The current substrate shape is intentionally narrow:

- tool names must be namespaced as `client/tool_slug`;
- `source` must match the name prefix and currently resolves to `client`;
- `description` is required;
- `parameters`, when present, must be an array;
- `executor` must be `client`;
- `scope` must be `run`.

```php
$tool = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalize(
	array(
		'name'        => 'client/search_docs',
		'description' => 'Search project documentation.',
		'parameters'  => array(
			'required' => array( 'query' ),
		),
		'executor'    => 'client',
		'scope'       => 'run',
	)
);
```

Invalid declarations produce machine-readable invalid field names through `validate()` or an `InvalidArgumentException` from `normalize()`.

## Tool execution core

`WP_Agent_Tool_Execution_Core` mediates calls without owning any concrete tool implementation.

Execution flow:

```text
tool name + parameters
-> find declaration in available tools
-> validate required parameters
-> build normalized WP_Agent_Tool_Call
-> call WP_Agent_Tool_Executor::executeWP_Agent_Tool_Call()
-> normalize WP_Agent_Tool_Result
```

Failure modes are normalized rather than thrown to the loop:

- missing tool returns `success: false` with `Tool '<name>' not found`;
- missing required parameters returns an error with `missing_parameters` metadata;
- executor exceptions are caught and returned as tool errors;
- executor arrays without `success` are wrapped as successful results.

## Visibility and action policy

The tool policy layer resolves which tools are visible and how each tool may execute. Public policy classes include:

- `WP_Agent_Tool_Policy`
- `WP_Agent_Tool_Policy_Filter`
- `WP_Agent_Tool_Access_Policy`
- `WP_Agent_Action_Policy_Resolver`
- `WP_Agent_Action_Policy_Provider`
- `AgentsAPI\AI\Tools\WP_Agent_Action_Policy`

Canonical action-policy values are `direct`, `preview`, and `forbidden`. Resolution considers explicit runtime denies, agent/runtime tool and category policy, host providers, tool defaults, mode-specific tool defaults, and the final `agents_api_tool_action_policy` filter.

## Compaction and conservation

Conversation compaction is opt-in. Agents can declare `supports_conversation_compaction` and a `conversation_compaction_policy`; callers provide the summarizer. `WP_Agent_Conversation_Compaction::compact()` returns transformed messages, compaction metadata, and lifecycle events. If summarization fails, the original transcript is preserved and a failure event is emitted.

`WP_Agent_Compaction_Item`, `WP_Agent_Compaction_Conservation`, and `WP_Agent_Markdown_Section_Compaction_Adapter` provide helper contracts for preserving boundaries and section semantics. Tests cover item shape, conservation, full conversation compaction, and Markdown-section compaction.

## Budgets, events, and failure behavior

`WP_Agent_Iteration_Budget` tracks a named dimension such as `turns`, `tool_calls`, or `tool_calls_<tool_name>`. Budgets increment during the loop and produce `budget_exceeded` events and status when explicit budgets trip.

The loop emits lifecycle events to two observer surfaces:

- caller-owned `on_event` option;
- WordPress `agents_api_loop_event` action.

Observer exceptions, transcript persister exceptions, and lock-release exceptions are swallowed so telemetry and persistence failures do not mutate provider/tool execution results. Lock acquisition failure returns a normalized `transcript_lock_contention` result before running turns.

## Future coverage

Future documentation should expand the individual value-object field reference for `WP_Agent_Message`, `WP_Agent_Conversation_Request`, `WP_Agent_Conversation_Result`, and the action-policy classes. This bootstrap focuses on integration flow and the major public contracts.
