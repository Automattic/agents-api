# Agents API Developer Documentation

Agents API is a WordPress-shaped backend substrate for durable agent runtime behavior. It provides provider-neutral contracts, value objects, registries, and orchestration seams that product plugins and runtime adapters can build on. It intentionally does not provide a concrete chat runtime, model/provider integration, product UI, product-specific workflow types, or durable stores beyond small option-backed helper defaults where noted.

This documentation index is a bootstrap map for developers working on, integrating with, or extending `Automattic/agents-api`. It complements the top-level [`README.md`](../README.md) and the more focused architecture notes already in `docs/`.

## Start here

- [`README.md`](../README.md) — canonical boundary statement, public surface, examples, and integration overview.
- [`coverage-map.md`](coverage-map.md) — source-derived coverage map showing which modules, contracts, extension points, tests, and existing docs are covered by this bootstrap surface.
- [`modules.md`](modules.md) — module-by-module developer guide for the public substrate.
- [`external-clients.md`](external-clients.md) — external direct channels, remote bridges, session mapping, webhook safety, and connector boundaries.
- [`remote-bridge-protocol.md`](remote-bridge-protocol.md) — queue-first remote bridge flow and storage seam.
- [`bridge-authorization.md`](bridge-authorization.md) — bridge credential/onboarding boundary and Core Connectors relationship.
- [`default-stores-companion.md`](default-stores-companion.md) — proposal for keeping concrete memory/transcript default stores outside this substrate.

## Repository shape

```text
agents-api.php              Plugin bootstrap and module loader.
src/Registry/               Agent registration facade and in-memory registry.
src/Packages/               Agent package and package-artifact declarations.
src/Auth/                   Access grants, bearer tokens, caller context, authorization policy.
src/Runtime/                Message envelopes, requests/results, conversation loop, compaction, budgets.
src/Tools/                  Tool declarations, policy, action policy, execution mediation.
src/Approvals/              Pending action and approval-resolution contracts.
src/Consent/                Consent operation and decision contracts.
src/Context/                Memory/context source registries, authority/conflict vocabulary.
src/Memory/                 Store-neutral memory scope, metadata, query, result, validator contracts.
src/Guidelines/             `wp_guideline` substrate polyfill and capability mapping.
src/Channels/               External message, channel base class, bridge queue/session/idempotency helpers.
src/Workflows/              Workflow spec, validator, registry, runner, abilities, Action Scheduler bridge.
src/Routines/               Persistent scheduled agent routine declarations and scheduler bridge.
src/Identity/               Materialized agent identity scope/store contracts.
src/Transcripts/            Transcript store and lock contracts.
src/Workspace/              Shared workspace identity value object.
tests/                      Pure-PHP/WordPress-shaped smoke tests for contracts and module behavior.
```

The plugin bootstrap (`agents-api.php`) loads the module files directly and wires the registry and guideline substrate to WordPress `init`. There is no Composer autoloading requirement in the plugin bootstrap beyond PHP 8.1 support declared in `composer.json`.

## Core lifecycle

1. WordPress loads `agents-api.php`.
2. The bootstrap defines `AGENTS_API_LOADED`, `AGENTS_API_PATH`, and `AGENTS_API_PLUGIN_FILE`, then requires module classes and registration helpers.
3. On WordPress `init`, `WP_Guidelines_Substrate::register()` runs at priority 9, then `WP_Agents_Registry::init()` runs at priority 10.
4. `WP_Agents_Registry::init()` fires `wp_agents_api_init`; consumers register agent definitions by calling `wp_register_agent()` inside that action.
5. Reads such as `wp_get_agent()`, `wp_get_agents()`, and `wp_has_agent()` are safe after `init` has fired.
6. Abilities are registered through WordPress Abilities API hooks when available:
   - `agents/chat`
   - `agents/run-workflow`
   - `agents/validate-workflow`
   - `agents/describe-workflow`
7. Runtime execution remains caller-owned. Consumers register chat/workflow handlers, tool executors, stores, policies, and transport adapters through the documented interfaces and WordPress hooks/filters.

## Stable integration seams

### Agent registration

Register inside `wp_agents_api_init`:

```php
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'example-agent',
			array(
				'label'          => 'Example Agent',
				'description'    => 'Handles example tasks.',
				'default_config' => array(
					'tool_policy' => array(
						'mode'       => 'allow',
						'categories' => array( 'read' ),
					),
				),
				'meta'           => array(
					'source_plugin' => 'example-plugin/example-plugin.php',
				),
			)
		);
	}
);
```

### Canonical chat ability

`agents/chat` is a dispatcher. Agents API validates/registers the ability shape, but a consumer supplies the runtime:

```php
add_filter(
	'wp_agent_chat_handler',
	static function ( $handler, array $input ) {
		if ( null !== $handler ) {
			return $handler;
		}

		return static function ( array $input ): array {
			return array(
				'session_id' => (string) ( $input['session_id'] ?? wp_generate_uuid4() ),
				'reply'      => 'Runtime-owned response.',
				'completed'  => true,
			);
		};
	},
	10,
	2
);
```

### Conversation loop

`AgentsAPI\AI\WP_Agent_Conversation_Loop::run()` sequences normalized messages, optional compaction, provider turns, optional tool mediation, completion policy, transcript persistence, budgets, and events around caller-supplied adapters. Provider/model dispatch, prompt assembly, concrete tools, and storage stay outside this package.

### Workflow dispatch

Workflow support is substrate-level: spec, structural validator, registry, abstract runner, canonical abilities, and optional Action Scheduler bridge. Consumers provide concrete runtime handlers or additional step types.

### External channels and bridges

Direct channels subclass `AgentsAPI\AI\Channels\WP_Agent_Channel` and implement transport I/O. Remote bridges use `WP_Agent_Bridge` for queue-first delivery (`register_client`, `enqueue`, `pending`, `ack`). Both shapes target the canonical `agents/chat` ability by default.

## Extension hooks and filters

The broad public extension surface includes:

- `wp_agents_api_init` — register agents.
- `wp_agent_package_artifacts_init` — register package artifact types.
- `agents_api_memory_sources` — register memory/context sources.
- `agents_api_context_sections` — register composable context sections.
- `agents_api_execution_principal` — resolve execution principals.
- `agents_api_resolved_tools` — inspect/filter resolved visible tools.
- `agents_api_tool_policy_providers` — add tool visibility policy providers.
- `agents_api_action_policy_providers` — add action policy providers.
- `agents_api_tool_action_policy` — final action-policy override.
- `wp_agent_chat_handler` — provide the canonical chat runtime handler.
- `agents_chat_permission` — authorize canonical chat requests.
- `agents_chat_dispatch_failed` — observe chat dispatcher failures.
- `wp_agent_channel_chat_ability` — choose the chat ability a channel targets.
- `wp_agent_workflow_handler` — provide workflow runtime handler.
- `agents_run_workflow_permission` and `agents_validate_workflow_permission` — authorize workflow abilities.
- `agents_run_workflow_dispatch_failed` — observe workflow dispatch failures.
- `wp_agent_workflow_known_step_types` and `wp_agent_workflow_known_trigger_types` — extend workflow validation vocabulary.
- `wp_agent_workflow_step_handlers` — register workflow runner step handlers.
- `wp_agent_routine_registered`, `wp_agent_routine_unregistered`, `wp_agent_routine_schedule_requested` — routine scheduling lifecycle.
- `wp_guidelines_substrate_enabled` — disable the guideline substrate polyfill when Core/Gutenberg supplies one.
- `agents_api_loop_event` — observe conversation-loop lifecycle events.

## Development and tests

The repository requires PHP 8.1+ and declares WordPress 7.0+ in plugin metadata. Run the full smoke-test suite with:

```bash
composer test
```

The test suite is intentionally broad and maps directly to modules: registry, auth, consent, tool policy/runtime, approvals, memory metadata, workspace, compaction, conversation loop, channels, webhooks, remote bridge, context, guidelines, workflows, routines, subagents, and a boundary test that prevents product imports.

## Ownership principles

- Agents API owns contracts, neutral value objects, dispatchers, vocabulary, and reusable orchestration seams.
- Consumers own product UX, provider/model execution, concrete tools, durable stores, consent UI, approval UI, product workflow semantics, scheduling policy, retention, analytics, and platform-specific channel code.
- Concrete persistence policy should not leak into this substrate unless it becomes a generic contract.
- Prefer stable JSON-friendly arrays and immutable value objects at module boundaries so stores, bridges, and runtimes can be swapped independently.
