# Runtime, Tools, And Approvals

Agents API provides a generic multi-turn runtime loop and tool mediation contracts. It does not assemble prompts, call providers, implement product tools, or decide product policy.

Source evidence: `src/Runtime/**`, `src/Tools/**`, `src/Approvals/**`, `tests/conversation-loop-*.php`, `tests/tool-runtime-smoke.php`, `tests/tool-policy-contracts-smoke.php`, `tests/action-policy-*.php`, `tests/approval-*.php`, and `tests/pending-action-store-contract-smoke.php`.

## Runtime value objects

Important runtime classes under `src/Runtime`:

- `AgentsAPI\AI\WP_Agent_Message` normalizes transcript messages.
- `AgentsAPI\AI\WP_Agent_Conversation_Request` carries messages, tools, principal/context, metadata, and turn limits into a run.
- `AgentsAPI\AI\WP_Agent_Conversation_Result` normalizes loop output, including messages, tool results, events, status, final content, usage, request metadata, and budget information.
- `AgentsAPI\AI\WP_Agent_Execution_Principal` represents the actor/effective agent, request context, token/client/workspace identifiers, capability ceiling, caller context, and request metadata.
- `AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy` and `WP_Agent_Conversation_Completion_Decision` let callers stop a run based on typed tool-result policy.
- `AgentsAPI\AI\WP_Agent_Iteration_Budget` bounds named dimensions such as `turns`, `tool_calls`, and `tool_calls_<tool_name>`.
- `AgentsAPI\AI\WP_Agent_Transcript_Persister` is an optional persistence seam; `WP_Agent_Null_Transcript_Persister` is the no-op implementation.

## Conversation loop

`WP_Agent_Conversation_Loop::run( array $messages, callable $turn_runner, array $options = array() ): array` sequences execution around caller-owned adapters.

The loop owns:

1. message normalization;
2. optional transcript locking;
3. optional pre-turn compaction;
4. calling a turn runner once per turn;
5. validating/normalizing results;
6. optional tool-call mediation;
7. optional completion-policy evaluation;
8. budget enforcement;
9. optional transcript persistence;
10. lifecycle event emission.

The caller owns provider/model dispatch, prompt assembly, concrete tool execution, persistent storage, and product policy.

Common options include `max_turns`, `budgets`, `context`, `should_continue`, `compaction_policy`, `summarizer`, `tool_executor`, `tool_declarations`, `completion_policy`, `transcript_lock`/`transcript_lock_store`, `transcript_session_id`, `transcript_lock_ttl`, `transcript_persister`, `on_event`, and `request`.

### Minimal caller-managed run

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	$messages,
	static function ( array $messages, array $context ): array {
		return $runner->run_turn( $messages, $context );
	},
	array(
		'max_turns'       => 4,
		'should_continue' => static fn( array $result, array $context ): bool => false,
	)
);
```

### Tool-mediated run

When `tool_executor` and `tool_declarations` are provided, the loop interprets `tool_calls` returned by the turn runner and executes them through `WP_Agent_Tool_Execution_Core`.

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	$messages,
	static function ( array $messages, array $context ): array {
		$response = $ai_client->prompt( $messages );
		return array(
			'messages'   => $messages,
			'content'    => $response->text(),
			'tool_calls' => $response->tool_calls(),
		);
	},
	array(
		'max_turns'         => 10,
		'tool_executor'     => $executor,
		'tool_declarations' => $declarations,
		'completion_policy' => $completion_policy,
		'budgets'           => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 20 ),
		),
	)
);
```

Tool mediation appends tool-call and tool-result messages to the transcript, emits `tool_call` and `tool_result` events, records tool execution results, and checks total/per-tool budgets.

## Events and failure handling

Events are emitted to both the caller-owned `on_event` callback and the WordPress `agents_api_loop_event` action. Observer exceptions are swallowed. Key event names include `turn_started`, `tool_call`, `tool_result`, `budget_exceeded`, `completed`, `failed`, and `transcript_lock_contention`.

If a transcript lock cannot be acquired, the loop returns a normalized result with status `transcript_lock_contention`. Transcript persistence errors and lock-release errors do not change loop results.

## Runtime tool declaration contract

`AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration` validates run-scoped client tools. Valid declarations must use namespaced names whose source prefix is `client`:

```php
$tool = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalize(
	array(
		'name'        => 'client/search',
		'description' => 'Search the current client workspace.',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'query' => array( 'type' => 'string' ),
			),
		),
		'executor'    => 'client',
		'scope'       => 'run',
	)
);
```

Invalid fields are returned by `validate()` or included in an `invalid_runtime_tool_declaration` exception from `normalize()`.

## Tool visibility policy

`WP_Agent_Tool_Policy::resolve( array $tools, array $context = array() ): array` filters an already-collected tool map. Resolution considers:

- runtime mode (`chat`, `pipeline`, `system`);
- optional `tool_access_checker` callback;
- registered agent or runtime `tool_policy` fragments;
- `WP_Agent_Tool_Access_Policy` providers from constructor, context, and `agents_api_tool_policy_providers`;
- context categories, `allow_only`, and `deny` lists;
- final `agents_api_resolved_tools` filter.

Explicit deny removes tools even if they were mandatory.

## Action policy

`WP_Agent_Action_Policy_Resolver` returns canonical action policies from `AgentsAPI\AI\Tools\WP_Agent_Action_Policy`: `direct`, `preview`, or `forbidden`.

The resolver checks runtime deny lists, agent/runtime `action_policy`, category policy, host providers, tool defaults, mode-specific tool defaults, then the `agents_api_tool_action_policy` filter. Consumers use this to decide whether a tool result may be applied directly, must become a preview/pending action, or is forbidden.

## Pending approval actions

The approvals module defines generic reviewable action contracts:

- `WP_Agent_Pending_Action` is the durable value shape.
- `WP_Agent_Pending_Action_Status` normalizes `pending`, `accepted`, `rejected`, `expired`, and `deleted`.
- `WP_Agent_Approval_Decision` represents accept/reject decisions.
- `WP_Agent_Pending_Action_Store` describes storage operations.
- `WP_Agent_Pending_Action_Resolver` resolves pending records with explicit resolver identity.
- `WP_Agent_Pending_Action_Handler` lets product handlers enforce permission checks before applying or rejecting proposals.

Required pending-action fields include `action_id`, `kind`, `summary`, `preview`, `apply_input`, and `created_at`. Optional audit fields include `workspace`, `agent`, `creator`, `expires_at`, `resolved_at`, `resolver`, `resolution_result`, `resolution_error`, `resolution_metadata`, and `metadata`.

Terminal statuses require `resolved_at`; accepted/rejected/deleted terminal records also require a `resolver`. The substrate does not provide UI, REST routes, durable tables, or product-specific apply handlers.