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

`AgentsAPI\AI\WP_Agent_Conversation_Result` normalizes loop output. Replay consumers can pin to `schema: agents-api.conversation-result` and `version: 1` for the stable result envelope. Typical keys include:

```php
array(
	'schema'                 => 'agents-api.conversation-result',
	'version'                => 1,
	'messages'               => $messages,
	'tool_execution_results' => $tool_results,
	'tool_audit_events'      => $tool_audit_events,
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

### Replay-critical result contract

The version 1 conversation result contract is the product-neutral replay surface for downstream orchestrators, regraders, and transcript stores. Consumers should read the versioned fields below instead of inferring behavior from incidental provider adapter fields:

| Field | Stability | Notes |
| --- | --- | --- |
| `schema` | Required, stable | Always `agents-api.conversation-result`. |
| `version` | Required, stable | Integer contract version. Current value is `1`. |
| `messages[]` | Required, stable | Normalized `agents-api.message` envelopes. Tool-call and tool-result envelopes carry matching `metadata.tool_call_id` when mediation knows the id. |
| `tool_execution_results[]` | Required, stable | Raw caller-owned tool result data. Mediated entries include `tool_name`, `tool_call_id`, `parameters`, `result`, `runtime` when present, and `turn_count`. |
| `tool_audit_events[]` | Required, stable | Safe generic replay trace for mediated tool calls. Entries include `schema_version`, `type`, `turn_count`, `tool_name`, `tool_call_id`, hashes, status, and `error_type` when classified. |
| `events[]` | Required, stable | Loop lifecycle events such as `interrupt_received`, `tool_result_truncated`, and `completion_policy_stop`. |
| `turn_count` | Required from the loop | Number of turns executed. |
| `final_content` | Required from the loop | Last assistant text message, excluding synthetic tool-call/tool-result messages. |
| `usage` | Required from the loop | Aggregated token usage when provided by the caller adapter. |
| `request_metadata` | Required from the loop | Caller-supplied metadata preserved for transcript persisters and replay. |
| `completed` | Required from the loop | Boolean completion signal. `false` when stopped by a budget, stall, or cancel interrupt. |
| `status` | Optional | Machine-readable stop reason such as `budget_exceeded`, `stalled`, `interrupted`, or `transcript_lock_contention`. |
| `interrupted` | Optional | Interrupt metadata copied from the normalized interrupt message. Includes `action` and any caller metadata such as `source`. |

Transcript persisters receive the normalized versioned result after loop completion and before the `completed` event is emitted. Persisters should persist the result envelope as-is and use `schema` plus `version` to choose replay logic.

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

When mediated tool execution consults `completion_policy`, complete decisions stop the loop and record `completion_policy_stop` in `events[]`. Incomplete decisions with an empty message preserve the existing continue behavior. Incomplete decisions with a non-empty message append a normalized `user` text continuation message, record a `completion_policy_continue` lifecycle event in `events[]`, and emit the same event through `on_event`; the event metadata includes `tool_name`, `turn`, `message`, and caller-owned policy `context`. Agents API does not persist these diagnostics beyond the returned transcript/result surfaces.

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

`AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalize()` validates per-run client tool declarations. The runtime-client shape is intentionally narrow:

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
		'runtime'     => array(
			'duplicate_policy' => 'repeatable',
			'completion_signal' => 'progress',
		),
	)
);
```

Invalid declarations produce machine-readable invalid field names through `validate()` or an `InvalidArgumentException` from `normalize()`.

Conversation requests use `normalizeForConversationRequest()` so replay/audit records can carry the full model-facing tool catalog without weakening the client runtime contract. Client tools still pass through strict `normalize()`. Host-owned tools use `executor => 'host'`, `scope => 'run'`, a namespaced stable name, matching source metadata, description, parameters schema, and optional runtime metadata:

```php
$tool = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalizeForConversationRequest(
	array(
		'name'        => 'ability/search_posts',
		'source'      => 'ability',
		'description' => 'Search host-owned posts.',
		'parameters'  => array(
			'required' => array( 'query' ),
		),
		'executor'    => 'host',
		'scope'       => 'run',
	)
);
```

### Tool runtime metadata

Tool declarations and tool results may include optional `runtime` metadata. This metadata is product-neutral execution policy data for the agent loop and host runtime; it is not a concrete tool implementation detail and should not encode product-specific tool names.

Canonical keys:

| Key | Value | Meaning |
| --- | --- | --- |
| `duplicate_policy` | `repeatable` | The same tool may be called repeatedly with the same parameters when the host considers that safe. |
| `completion_signal` | `progress` | A successful tool result is progress toward completion and may be used by caller-owned completion policy. |

The substrate treats `runtime` as a JSON-friendly associative array. It preserves scalar and nested array values with string keys, drops unsupported values, and leaves product-specific interpretation to callers.

When the conversation loop mediates tool calls, declaration runtime metadata is propagated into the normalized tool result and exposed on the corresponding `tool_execution_results[]` entry:

```php
array(
	'tool_name'    => 'client/search_docs',
	'tool_call_id' => 'call_123',
	'parameters'   => array( 'query' => 'runtime metadata' ),
	'result'       => array(
		'success'   => true,
		'tool_name' => 'client/search_docs',
		'result'    => array( 'matches' => array() ),
		'runtime'   => array(
			'duplicate_policy' => 'repeatable',
			'completion_signal' => 'progress',
		),
	),
	'runtime'      => array(
		'duplicate_policy' => 'repeatable',
		'completion_signal' => 'progress',
	),
	'turn_count'   => 1,
)
```

If a concrete executor returns its own `runtime` metadata, the normalized result merges it over declaration metadata for that execution result. This lets the declaration advertise generic defaults while an executor refines result-scoped signals without changing the declaration.

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

## Tool Audit Events

When the conversation loop mediates tool calls, the result includes
`tool_audit_events` alongside the backwards-compatible `tool_execution_results`.
The audit events are the safe replay surface for generic observers: they include
stable hashes and normalized status, but do not include raw tool parameters.

Representative event shape:

```php
array(
	'schema_version'      => 1,
	'type'                => 'tool_call',
	'turn_count'          => 1,
	'tool_name'           => 'client/search_docs',
	'tool_call_id'        => 'call_123',
	'tool_source'         => 'client',
	'parameters_sha256'   => 'sha256:...',
	'parameters_redacted' => true,
	'success'             => true,
	'result_status'       => 'success',
	'result_sha256'       => 'sha256:...',
)
```

Failed calls include `error_type` when the loop can classify the failure. The
core classifications are `tool_not_found`, `missing_required_parameters`, and
`executor_exception`.

Sensitive parameter keys such as `token`, `secret`, `password`, `authorization`,
`cookie`, `credential`, `nonce`, and `api_key` are redacted before hashing. Hosts
can customize deterministic redaction with the
`agents_api_tool_audit_parameters` filter. The legacy `tool_execution_results`
field still contains raw parameters for existing callers and should be treated as
caller-owned runtime data, not as the generic replay artifact surface.

## Visibility and action policy

The tool policy layer resolves which tools are visible and how each tool may execute. Public policy classes include:

- `WP_Agent_Tool_Policy`
- `WP_Agent_Tool_Policy_Filter`
- `WP_Agent_Tool_Access_Policy`
- `WP_Agent_Action_Policy_Resolver`
- `WP_Agent_Action_Policy_Provider`
- `AgentsAPI\AI\Tools\WP_Agent_Action_Policy`

Caller-provided runtime tools are opt-in. Declarations marked with neutral
runtime metadata such as `runtime_tool: true`, `executor: client`, or
`scope: run` are excluded from the visible tool set unless policy explicitly
allows them by name or category. Explicit opt-in can come from `runtime_tools`,
`runtime_categories`, allow-mode `tools` / `categories`, `allow_only`, or
mandatory policy returned by a `WP_Agent_Tool_Access_Policy` provider. The final
explicit `deny` list still wins after opt-in.

This policy only decides model visibility. Required argument sourcing remains
auditable through each tool declaration: runtime context keys fill required
parameters only when listed in `client_context_bindings`. Sensitive ambient keys
such as `api_key`, `token`, or `authorization` never satisfy required
parameters by name alone.

Canonical action-policy values are `direct`, `preview`, and `forbidden`. Resolution considers explicit runtime denies, agent/runtime tool and category policy, host providers, tool defaults, mode-specific tool defaults, and the final `agents_api_tool_action_policy` filter.

## Ability lifecycle bridge

`WP_Agent_Ability_Lifecycle_Bridge` (in `src/Abilities/`) adopts the WordPress 7.1 `WP_Ability` execution lifecycle filters on behalf of the substrate so each ability author does not have to opt in.

- `wp_ability_execute_result` -> `agents_api_ability_executed` action. The bridge emits the post-execute observer signal without modifying the result.
- `wp_pre_execute_ability` -> decision-driven approval gate. Hosts opt into per-call approval by hooking `agents_api_ability_pre_execute_decision` and returning either a `WP_Agent_Pending_Action` or an array shape `WP_Agent_Pending_Action::from_array()` accepts. The bridge mints (when needed), best-effort stages through the `wp_agent_pending_action_store` filter, and short-circuits with a `WP_Agent_Message::approvalRequired` envelope. Sentinel pass-through preserves any earlier short-circuit so stacked consumers coexist.

On WordPress < 7.1 the underlying filters are never applied, so registered handlers stay idle.

## Compaction and conservation

Conversation compaction is opt-in. Agents can declare `supports_conversation_compaction` and a `conversation_compaction_policy`; callers provide the summarizer. `WP_Agent_Conversation_Compaction::compact()` returns transformed messages, compaction metadata, and lifecycle events. If summarization fails, the original transcript is preserved and a failure event is emitted.

`WP_Agent_Compaction_Item`, `WP_Agent_Compaction_Conservation`, and `WP_Agent_Markdown_Section_Compaction_Adapter` provide helper contracts for preserving boundaries and section semantics. Tests cover item shape, conservation, full conversation compaction, and Markdown-section compaction.

## Budgets, events, and failure behavior

`WP_Agent_Iteration_Budget` tracks a named dimension such as `turns`, `tool_calls`, or `tool_calls_<tool_name>`. Budgets increment during the loop and produce `budget_exceeded` events and status when explicit budgets trip.

When a tool ability returns an `approval_required` envelope (typically via the lifecycle bridge's pre-execute gate), the loop halts mediation, emits an `approval_required` event with the action_id, and surfaces `status: 'approval_required'` on the final result with `completed: false`. The envelope is preserved on the final result so callers can persist or relay the pending action and resume after a decision.

The loop emits lifecycle events to two observer surfaces:

- caller-owned `on_event` option;
- WordPress `agents_api_loop_event` action.

Observer exceptions, transcript persister exceptions, and lock-release exceptions are swallowed so telemetry and persistence failures do not mutate provider/tool execution results. Lock acquisition failure returns a normalized `transcript_lock_contention` result before running turns.

## Future coverage

Future documentation should expand the individual value-object field reference for `WP_Agent_Message`, `WP_Agent_Conversation_Request`, `WP_Agent_Conversation_Result`, and the action-policy classes. This bootstrap focuses on integration flow and the major public contracts.
