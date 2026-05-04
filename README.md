# Agents API

Agents API is a WordPress-shaped backend substrate for durable agent runtime behavior.

Agents API is maintained by Automattic as a standalone WordPress substrate package.

It provides generic contracts and value objects that product plugins can build on without copying agent runtime primitives into every product. It is not a workflow product, an admin application, or a provider-specific AI client.

## Layer Boundary

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts, tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy, storage/materialization policy
```

Agents API sits between tool/action discovery and product-specific automation. It owns the reusable agent runtime contracts; product plugins own the user-facing product experience.

## What Agents API Owns

- Agent registration and lookup.
- Runtime message, request, result, and completion value objects.
- Agent execution principal/context value objects.
- Agent access grant, token, token authenticator, authorization policy, and capability ceiling contracts.
- Multi-turn orchestration contracts.
- Agent package and package-artifact contracts.
- Shared `wp_guideline` / `wp_guideline_type` storage substrate polyfill when Core/Gutenberg do not provide it.
- Agent memory store contracts and value objects.
- Conversation compaction policy and transcript transformation contracts.
- Generic multi-turn conversation loop sequencing around caller-owned adapters.
- Iteration budget primitives for bounded execution across configurable dimensions.
- Tool-call mediation contracts and runtime tool declaration value objects.
- Conversation transcript store contracts.
- Tool source registration, parameter normalization, tool-call mediation, and execution result contracts.
- Session and persistence contracts where they are provider-neutral.
- Retrieved context authority vocabulary, context item shape, and conflict resolution contracts.

## What Agents API Does Not Own

- Provider-specific request code. `wp-ai-client` owns provider/model prompt execution.
- Product workflows such as flows, pipelines, jobs, handlers, queues, retention, and content operations.
- Product UI such as admin pages, settings screens, dashboards, or onboarding.
- Product CLI commands beyond generic substrate needs.
- Public REST controllers in v1 unless they are separately designed.
- Product runner adapters that assemble prompts, choose concrete tools, materialize storage, or decide product policy.
- Concrete tool execution adapters, prompt assembly policy, or product storage/materialization policy.

Products can require Agents API because they build on the substrate. Agents API must not depend on any product plugin, import product classes, mirror a product source tree, or encode product vocabulary as generic runtime API.

## Requirements

Agents API requires **WordPress 7.0 or higher**. The substrate itself is provider-agnostic and loads on earlier versions, but every realistic consumer needs an AI provider. The only WordPress-native provider story is `wp-ai-client`, which ships in WordPress 7.0 core. Sites running 6.8–6.9 can install Agents API without errors but won't have a working AI provider unless they manually install the deprecated `wp-ai-client` plugin.

## Consumer Integration

Product plugins should treat Agents API as an optional or required runtime dependency depending on their feature surface.

For hard requirements, declare the plugin dependency using normal WordPress/plugin-distribution mechanisms and fail clearly when Agents API is unavailable.

For optional integrations, feature-detect the public API before registering agent-backed features inside the registration hook:

```php
add_action(
	'wp_agents_api_init',
	static function () {
		if ( function_exists( 'wp_register_agent' ) ) {
			wp_register_agent( 'example-agent', array( /* ... */ ) );
		}
	}
);
```

Register agent definitions from inside a `wp_agents_api_init` callback. Reads such as `wp_get_agent()` and `wp_has_agent()` are safe after WordPress `init` has fired.

Agents can declare source provenance in `meta` so registration diagnostics can identify which plugin or package owns a slug:

```php
wp_register_agent(
	'example-agent',
	array(
		'label' => 'Example Agent',
		'meta'  => array(
			'source_plugin'  => 'example-plugin/example-plugin.php',
			'source_type'    => 'bundled-agent',
			'source_package' => 'example-package',
			'source_version' => '1.2.3',
		),
	)
);
```

## Public Surface

- `wp_agents_api_init`
- `wp_register_agent()` / `wp_get_agent()` / `wp_get_agents()` / `wp_has_agent()` / `wp_unregister_agent()`
- `WP_Agent`
- `WP_Agents_Registry`
- `WP_Agent_Package*` value objects and artifact registry helpers
- `WP_Agent_Access_Grant`
- `WP_Agent_Access_Store_Interface`
- `WP_Agent_Token`
- `WP_Agent_Token_Store_Interface`
- `WP_Agent_Token_Authenticator`
- `WP_Agent_Authorization_Policy_Interface`
- `WP_Agent_WordPress_Authorization_Policy`
- `WP_Agent_Capability_Ceiling`
- `wp_guideline_types()` and `WP_Guidelines_Substrate`
- `AgentsAPI\AI\AgentMessageEnvelope`
- `AgentsAPI\AI\AgentExecutionPrincipal`
- `AgentsAPI\AI\AgentConversationRequest`
- `AgentsAPI\AI\AgentConversationRunnerInterface`
- `AgentsAPI\AI\AgentConversationCompletionDecision`
- `AgentsAPI\AI\AgentConversationCompletionPolicyInterface`
- `AgentsAPI\AI\AgentConversationTranscriptPersisterInterface`
- `AgentsAPI\AI\NullAgentConversationTranscriptPersister`
- `AgentsAPI\AI\AgentConversationCompaction`
- `AgentsAPI\AI\IterationBudget`
- `AgentsAPI\AI\AgentConversationResult`
- `AgentsAPI\AI\AgentConversationLoop`
- `AgentsAPI\AI\Tools\RuntimeToolDeclaration`
- `AgentsAPI\AI\Tools\ToolCall`
- `AgentsAPI\AI\Tools\ToolSourceRegistry`
- `AgentsAPI\AI\Tools\ToolParameters`
- `AgentsAPI\AI\Tools\ToolExecutorInterface`
- `AgentsAPI\AI\Tools\ToolExecutionCore`
- `AgentsAPI\AI\Tools\ToolExecutionResult`
- `AgentsAPI\AI\Context\ContextAuthorityTier`
- `AgentsAPI\AI\Context\ContextConflictKind`
- `AgentsAPI\AI\Context\RetrievedContextItem`
- `AgentsAPI\AI\Context\ContextConflictResolution`
- `AgentsAPI\AI\Context\ContextConflictResolverInterface`
- `AgentsAPI\AI\Context\DefaultContextConflictResolver`
- `AgentsAPI\Core\Workspace\AgentWorkspaceScope`
- `AgentsAPI\Core\Database\Chat\ConversationTranscriptStoreInterface`
- `AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface` and memory value objects

## Workspace Scope

`AgentsAPI\Core\Workspace\AgentWorkspaceScope` is the generic workspace identity shared by memory, transcript, persistence, and audit adapters. It is deliberately broader than a WordPress site ID:

```php
$workspace = AgentsAPI\Core\Workspace\AgentWorkspaceScope::from_parts(
	'code_workspace',
	'Automattic/intelligence@contexta8c-read-coverage'
);

$workspace->to_array();
// array(
// 	'workspace_type' => 'code_workspace',
// 	'workspace_id'   => 'Automattic/intelligence@contexta8c-read-coverage',
// )
```

Consumers may map WordPress sites, networks, headless runtimes, Studio sites, code workspaces, pull requests, or ephemeral execution environments into that pair. Agents API keeps those mappings in consumer adapters; the generic contracts only depend on `workspace_type` + `workspace_id`.

Memory scope uses `(layer, workspace_type, workspace_id, user_id, agent_id, filename)` as its identity model:

```php
$scope = new AgentsAPI\Core\FilesRepository\AgentMemoryScope(
	'user',
	$workspace->workspace_type,
	$workspace->workspace_id,
	123,
	456,
	'MEMORY.md'
);
```

Transcript sessions are also workspace-stamped. `ConversationTranscriptStoreInterface::create_session()` and `::get_recent_pending_session()` both receive an `AgentWorkspaceScope`, and `AgentConversationRequest` can carry a workspace so runtime persisters can stamp the session they materialize.

## Retrieved Context Authority

Retrieved context is not only ordered text. Consumers may retrieve memory, identity, conversation, workspace, platform, or support-mode context that conflicts. Agents API provides generic vocabulary and value objects so products can preserve source authority without encoding product-specific policy into this substrate.

Authority tiers, highest authority first:

```text
platform_authority
support_authority
workspace_shared
user_workspace_private
user_global
agent_identity
agent_memory
conversation
```

`platform_authority` and `support_authority` are generic governance tiers. Consumers decide when those sources are enabled and mode-gated. Agents API does not define a WP.com-specific source, storage path, or activation condition.

`AgentsAPI\AI\Context\RetrievedContextItem` is the transport shape for one retrieved item:

```php
$item = new AgentsAPI\AI\Context\RetrievedContextItem(
	'Use concise replies.',
	array( 'workspace' => 'example', 'user_id' => 12 ),
	AgentsAPI\AI\Context\ContextAuthorityTier::USER_WORKSPACE_PRIVATE,
	array( 'source' => 'memory', 'uri' => 'memory:user/12/preferences.md' ),
	AgentsAPI\AI\Context\ContextConflictKind::PREFERENCE,
	'response_style'
);
```

The exported shape is JSON-friendly and includes:

- `content` - retrieved text or serialized context payload.
- `scope` - product-defined scope metadata such as workspace, user, agent, mode, or site.
- `authority_tier` - one of the generic authority tiers above.
- `provenance` - source metadata such as provider, URI, content hash, timestamp, or retrieval score.
- `conflict_kind` - `preference` or `authoritative_fact`.
- `conflict_key` - optional shared key for mutually conflicting items.
- `metadata` - optional caller-owned JSON-friendly metadata.

Conflict semantics are intentionally explicit:

- **Preferences** may resolve by specificity. A user workspace preference can override a broad platform default preference because it is more specific to the current run.
- **Authoritative facts** resolve by authority tier. Lower-scope memory, identity, or conversation context cannot override a higher platform/support/workspace fact.

`ContextConflictResolverInterface` defines the resolver contract. `DefaultContextConflictResolver` provides the generic behavior above: authoritative facts use `authority_tier`; preferences use `specificity_then_authority`.

## Execution Principals

`AgentsAPI\AI\AgentExecutionPrincipal` represents the actor and agent context for one runtime request. It records the acting WordPress user ID, effective agent ID/slug, auth source, request context, optional token ID, workspace ID, client ID, capability ceiling, and JSON-friendly request metadata.

Host plugins can resolve the current principal from REST, CLI, cron, bearer-token, or session state through the `agents_api_execution_principal` filter:

```php
add_filter(
	'agents_api_execution_principal',
	static function ( $principal, array $context ) {
		if ( 'rest' !== ( $context['request_context'] ?? '' ) ) {
			return $principal;
		}

		return AgentsAPI\AI\AgentExecutionPrincipal::user_session(
			get_current_user_id(),
			(string) ( $context['agent_id'] ?? '' ),
			'rest'
		);
	},
	10,
	2
);
```

## Agent Authorization

Agents API provides generic authorization substrate shapes without owning product tables, workflows, or UI.

```text
request bearer token
  -> WP_Agent_Token_Authenticator
  -> WP_Agent_Token_Store_Interface resolves hash only
  -> AgentExecutionPrincipal records actor, agent, token, workspace, client
  -> WP_Agent_Capability_Ceiling intersects token/client restrictions
  -> WP_Agent_WordPress_Authorization_Policy calls user_can() for the owner/user ceiling
```

`WP_Agent_Access_Grant` models a role-based grant between a WordPress user and an agent, optionally scoped by a host workspace. Roles are generic and ordered: `viewer`, `operator`, `admin`. Concrete storage belongs to hosts via `WP_Agent_Access_Store_Interface`.

`WP_Agent_Token` models token metadata for bearer-token authentication. It stores token hash, prefix, label, expiry, last-used timestamp, optional client/workspace identifiers, and optional capability restrictions. It never exposes raw token material in metadata exports.

`WP_Agent_Token_Authenticator` accepts a raw bearer token at the request edge, hashes it, asks a host token store to resolve the hash, rejects expired tokens, touches successful tokens, and returns an `AgentExecutionPrincipal` populated with token/client/workspace context.

`WP_Agent_WordPress_Authorization_Policy` is the default WordPress-shaped policy. It denies a capability unless both are true:

- The token/client ceiling allows the requested capability, when a ceiling allow-list exists.
- The acting/owner WordPress user has the requested capability via `user_can()`.

Hosts can replace this policy by implementing `WP_Agent_Authorization_Policy_Interface`, or pass host-owned access/token stores while keeping the generic value objects.

## Conversation Compaction

Agents can declare support for runtime conversation compaction without tying Agents API to a provider or model executor:

```php
wp_register_agent(
	'example-agent',
	array(
		'supports_conversation_compaction' => true,
		'conversation_compaction_policy'   => array(
			'enabled'         => true,
			'max_messages'    => 40,
			'recent_messages' => 12,
		),
	)
);
```

`AgentsAPI\AI\AgentConversationCompaction::compact()` transforms a transcript before model dispatch. The caller supplies a summarizer callable, keeping low-level model execution outside Agents API. The result includes:

- `messages`: the transformed transcript, with a synthetic summary message followed by retained recent messages.
- `metadata.compaction`: status, compacted boundary, retained count, and summary metadata for persisted transcripts.
- `events`: `compaction_started`, `compaction_completed`, or `compaction_failed` lifecycle events that streaming clients can relay.

Boundary selection preserves tool-call/tool-result integrity by default. If summarization fails, the original normalized transcript is returned unchanged and a failure event is emitted rather than silently dropping history.

## Conversation Loop Boundary

`AgentsAPI\AI\AgentConversationLoop` is a generic loop facade. It owns the reusable mechanics that every multi-turn agent run needs:

- Normalizing inbound messages to `AgentMessageEnvelope`.
- Optionally applying caller-supplied compaction before each turn.
- Calling a runner adapter once per turn.
- Validating each runner response with `AgentConversationResult`.
- Tool-call mediation through `ToolExecutionCore` + `ToolExecutorInterface` when enabled.
- Typed completion policy via `AgentConversationCompletionPolicyInterface`.
- Transcript persistence via `AgentConversationTranscriptPersisterInterface`.
- Lifecycle event emission via an `on_event` callable.
- Asking a caller-supplied `should_continue` continuation policy whether another turn is needed.

It does not assemble prompts, select a provider/model, implement concrete tools, choose durable storage, expose admin UI, or define product workflow semantics. Consumers provide adapters for those concerns and pass them into the loop.

### Minimal usage (legacy, fully backwards compatible)

```php
$result = AgentsAPI\AI\AgentConversationLoop::run(
	$messages,
	static function ( array $messages, array $context ): array {
		return $runner->run_turn( $messages, $context );
	},
	array(
		'max_turns'       => 4,
		'should_continue' => static function ( array $turn_result, array $context ): bool {
			return $policy->should_continue( $turn_result, $context );
		},
		'compaction_policy' => $agent->conversation_compaction_policy,
		'summarizer'         => $summarizer,
	)
);
```

### Full usage with tool mediation, completion policy, persistence, and events

When `tool_executor` and `tool_declarations` are provided, the loop handles the tool-call → validate → execute → message assembly cycle internally. The turn runner becomes the AI request adapter only — it sends messages to the provider and returns a response with optional `tool_calls`:

```php
$result = AgentsAPI\AI\AgentConversationLoop::run(
	$messages,
	static function ( array $messages, array $context ): array {
		// Turn runner dispatches to the AI provider and returns:
		// - 'messages': current transcript
		// - 'content': assistant text response (optional)
		// - 'tool_calls': array of {name, parameters} (optional)
		$response = $ai_client->prompt( $messages );
		return array(
			'messages'   => $messages,
			'content'    => $response->text(),
			'tool_calls' => $response->tool_calls(),
		);
	},
	array(
		'max_turns'         => 10,
		'context'           => array( 'agent_id' => 'my-agent' ),

		// Tool execution mediation (#45)
		'tool_executor'     => $my_tool_executor,      // ToolExecutorInterface
		'tool_declarations' => $available_tools,        // array keyed by tool name

		// Typed completion policy (#42)
		'completion_policy' => $my_completion_policy,   // AgentConversationCompletionPolicyInterface

		// Transcript persistence (#43)
		'transcript_persister' => $my_persister,        // AgentConversationTranscriptPersisterInterface

		// Iteration budgets (#47)
		'budgets' => array(
			new AgentsAPI\AI\IterationBudget( 'tool_calls', 20 ),
			new AgentsAPI\AI\IterationBudget( 'tool_calls_progress_story', 5 ),
		),

		// Lifecycle events (#44)
		'on_event' => static function ( string $event, array $payload ): void {
			// Events: turn_started, tool_call, tool_result, budget_exceeded, completed, failed
			$logger->log( $event, $payload );
		},

		// Legacy escape hatch — both can coexist, typed policy takes precedence
		'should_continue' => static function ( array $result, array $context ): bool {
			return ! empty( $result['tool_execution_results'] );
		},

		// Compaction (unchanged)
		'compaction_policy' => $agent->conversation_compaction_policy,
		'summarizer'         => $summarizer,

		// Optional: pass the original request for transcript persistence context
		'request' => $conversation_request,            // AgentConversationRequest
	)
);
```

All new options are opt-in. Existing callers passing only the original options continue to work identically.

The loop treats all adapter inputs and outputs as JSON-friendly arrays so products can map them to their own storage, streaming, audit, and transport layers without Agents API owning those layers.

## Pending Action Approval Boundary

Agents API owns generic approval primitives for runtime actions that need explicit user or policy approval before a consumer applies them. The lifecycle is:

- A runtime or tool proposes an action instead of applying it immediately.
- The proposal is emitted or stored as a generic pending action value.
- A UI or user accepts or rejects the pending action.
- The consumer adapter resolves the decision and applies or discards the proposal through its own product-specific handler.

Agents API owns the reusable contract shape only: value objects and interfaces for pending actions, the JSON-friendly proposal and decision shape, policy vocabulary for approval requirements, and a typed `approval_required` envelope that runtimes can return without knowing where the proposal will be stored or displayed.

Consuming products own the concrete materialization: durable storage, REST routes, abilities or tool surfaces, chat/admin UI, permission ceilings, audit records, queues, jobs, workflows, and product-specific apply/reject handlers. Those concerns belong in adapters because they depend on each product's UX, authorization model, and operational semantics.

Package artifacts can also describe a `diff_callback` so packages can generate reviewable diffs for installer or updater flows. That artifact is related to approval because it helps produce human-reviewable change previews, but it is not the same primitive as runtime pending-action approval. `diff_callback` belongs to package artifact review; `approval_required` belongs to a live runtime/tool proposal that must be accepted or rejected before the consumer applies it.

## Iteration Budgets

`AgentsAPI\AI\IterationBudget` is a generic bounded-iteration primitive. It counts a named dimension (turns, tool calls, chain depth, retries) and exposes a uniform API for checking exceedance. A budget is a stateful value object — call `increment()` at each iteration, then `exceeded()` to decide whether to continue.

```php
$budget = new AgentsAPI\AI\IterationBudget( 'chain_depth', 3 );

$budget->name();      // 'chain_depth'
$budget->ceiling();   // 3
$budget->current();   // 0
$budget->exceeded();  // false
$budget->remaining(); // 3

$budget->increment();
$budget->current();   // 1
$budget->exceeded();  // false

$budget->increment();
$budget->increment();
$budget->exceeded();  // true (current >= ceiling)
$budget->remaining(); // 0
```

### Loop integration

Pass budgets to `AgentConversationLoop::run()` via the `budgets` option. The loop enforces them at the appropriate seams:

- **`turns`** — incremented after each turn. When an explicit `IterationBudget('turns', N)` is provided, it overrides `max_turns` and produces a `budget_exceeded` status when tripped.
- **`tool_calls`** — incremented after each tool call when tool mediation is enabled.
- **`tool_calls_<name>`** — incremented per tool name for ping-pong protection (e.g. `tool_calls_progress_story`).

```php
$result = AgentsAPI\AI\AgentConversationLoop::run(
	$messages,
	$turn_runner,
	array(
		'max_turns' => 10,
		'budgets'   => array(
			new AgentsAPI\AI\IterationBudget( 'tool_calls', 20 ),
			new AgentsAPI\AI\IterationBudget( 'tool_calls_progress_story', 5 ),
		),
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
	)
);

if ( ( $result['status'] ?? null ) === 'budget_exceeded' ) {
	// $result['budget'] contains the name of the exceeded budget.
	$logger->warn( 'Budget exceeded: ' . $result['budget'] );
}
```

When a budget trips, the loop returns early with `status: 'budget_exceeded'` and `budget: '<name>'` in the result. A `budget_exceeded` event is also emitted through the `on_event` sink with `budget`, `current`, and `ceiling` in the payload.

External observers tracking exotic dimensions (token cost, wall-clock, custom chain depth) can use the `on_event` hook to increment their own `IterationBudget` instances and signal the loop through the existing `should_continue` or completion policy escape hatches.

The substrate ships only the per-execution value object. Registries, configuration persistence, and ceiling policies are consumer concerns.

## Tests

```bash
composer test
```
