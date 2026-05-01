# Agents API

Agents API is a WordPress-shaped backend substrate for durable agent runtime behavior.

Agents API is maintained by Automattic as a standalone WordPress substrate package.

It provides generic contracts and value objects that product plugins can build on without copying agent runtime primitives into every product. It is not a workflow product, an admin application, or a provider-specific AI client.

## Layer Boundary

```text
Abilities API  -> actions and tools
wp-ai-client   -> provider/model prompt execution
Agents API     -> durable agent runtime substrate
Data Machine   -> automation product consumer
```

Agents API sits between tool/action discovery and product-specific automation. It owns the reusable agent runtime contracts; product plugins own the user-facing product experience.

## What Agents API Owns

- Agent registration and lookup.
- Runtime message and result value objects.
- Agent execution principal/context value objects.
- Agent package and package-artifact contracts.
- Agent memory store contracts and value objects.
- Conversation compaction policy and transcript transformation contracts.
- Conversation transcript store contracts.
- Runtime tool declaration value objects.
- Tool source registration, parameter normalization, and execution result contracts.

## What Agents API Does Not Own

- Provider-specific request code. `wp-ai-client` owns provider/model prompt execution.
- Product workflows such as flows, pipelines, jobs, handlers, queues, retention, and content operations.
- Product UI such as admin pages, settings screens, dashboards, or onboarding.
- Product CLI commands beyond generic substrate needs.
- Public REST controllers in v1 unless they are separately designed.

Data Machine is an example consumer and proving ground for these contracts. Agents API must not depend on Data Machine, import Data Machine classes, mirror Data Machine's source tree, or encode Data Machine vocabulary as generic runtime API. Data Machine can require Agents API because it is a product plugin built on the substrate.

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
- `AgentsAPI\AI\AgentMessageEnvelope`
- `AgentsAPI\AI\AgentExecutionPrincipal`
- `AgentsAPI\AI\AgentConversationCompaction`
- `AgentsAPI\AI\AgentConversationResult`
- `AgentsAPI\AI\Tools\RuntimeToolDeclaration`
- `AgentsAPI\AI\Tools\ToolSourceRegistry`
- `AgentsAPI\AI\Tools\ToolParameters`
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
			'enabled'          => true,
			'max_messages'     => 40,
			'recent_messages'  => 12,
			'summary_provider' => 'example-provider',
			'summary_model'    => 'example-model',
		),
	)
);
```

`AgentsAPI\AI\AgentConversationCompaction::compact()` transforms a transcript before model dispatch. The caller supplies a summarizer callable, keeping low-level model execution outside Agents API. The result includes:

- `messages`: the transformed transcript, with a synthetic summary message followed by retained recent messages.
- `metadata.compaction`: status, compacted boundary, retained count, and summary metadata for persisted transcripts.
- `events`: `compaction_started`, `compaction_completed`, or `compaction_failed` lifecycle events that streaming clients can relay.

Boundary selection preserves tool-call/tool-result integrity by default. If summarization fails, the original normalized transcript is returned unchanged and a failure event is emitted rather than silently dropping history.

## Tests

```bash
composer test
```
