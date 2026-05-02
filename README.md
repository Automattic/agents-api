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
- Multi-turn orchestration contracts.
- Agent package and package-artifact contracts.
- Shared `wp_guideline` / `wp_guideline_type` storage substrate polyfill when Core/Gutenberg do not provide it.
- Agent memory store contracts and value objects.
- Conversation compaction policy and transcript transformation contracts.
- Generic multi-turn conversation loop sequencing around caller-owned adapters.
- Tool-call mediation contracts and runtime tool declaration value objects.
- Conversation transcript store contracts.
- Tool source registration, parameter normalization, tool-call mediation, and execution result contracts.
- Session and persistence contracts where they are provider-neutral.

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
- `AgentsAPI\AI\AgentConversationResult`
- `AgentsAPI\AI\AgentConversationLoop`
- `AgentsAPI\AI\Tools\RuntimeToolDeclaration`
- `AgentsAPI\AI\Tools\ToolCall`
- `AgentsAPI\AI\Tools\ToolSourceRegistry`
- `AgentsAPI\AI\Tools\ToolParameters`
- `AgentsAPI\AI\Tools\ToolExecutorInterface`
- `AgentsAPI\AI\Tools\ToolExecutionCore`
- `AgentsAPI\AI\Tools\ToolExecutionResult`
- `AgentsAPI\Core\Database\Chat\ConversationTranscriptStoreInterface`
- `AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface` and memory value objects

## Execution Principals

`AgentsAPI\AI\AgentExecutionPrincipal` represents the actor and agent context for one runtime request. It records the acting WordPress user ID, effective agent ID/slug, auth source, request context, optional token ID, and JSON-friendly request metadata.

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

`AgentsAPI\AI\AgentConversationLoop` is a thin generic loop facade. It owns the reusable mechanics that every multi-turn agent run needs:

- Normalizing inbound messages to `AgentMessageEnvelope`.
- Optionally applying caller-supplied compaction before each turn.
- Calling a runner adapter once per turn.
- Validating each runner response with `AgentConversationResult`.
- Asking a caller-supplied continuation policy whether another turn is needed.

It does not assemble prompts, select a provider/model, implement concrete tools, choose durable storage, expose admin UI, or define product workflow semantics. Consumers provide adapters for those concerns and pass them into the loop:

```php
$result = AgentsAPI\AI\AgentConversationLoop::run(
	$messages,
	static function ( array $messages, array $context ): array {
		// Consumer adapter assembles prompts, dispatches the model, invokes concrete
		// tools through runtime contracts, materializes storage as needed, and
		// returns an AgentConversationResult-shaped array.
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

The loop treats all adapter inputs and outputs as JSON-friendly arrays so products can map them to their own storage, streaming, audit, and transport layers without Agents API owning those layers.

## Tests

```bash
composer test
```
