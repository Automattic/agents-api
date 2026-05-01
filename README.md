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
- Agent package and package-artifact contracts.
- Agent memory store contracts and value objects.
- Conversation transcript store contracts.
- Runtime tool declaration value objects.

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

## Public Surface

- `wp_agents_api_init`
- `wp_register_agent()` / `wp_get_agent()` / `wp_get_agents()` / `wp_has_agent()` / `wp_unregister_agent()`
- `WP_Agent`
- `WP_Agents_Registry`
- `WP_Agent_Package*` value objects and artifact registry helpers
- `AgentsAPI\AI\AgentMessageEnvelope`
- `AgentsAPI\AI\AgentConversationResult`
- `AgentsAPI\AI\Tools\RuntimeToolDeclaration`
- `AgentsAPI\Core\Database\Chat\ConversationTranscriptStoreInterface`
- `AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface` and memory value objects

## Tests

```bash
composer test
```
