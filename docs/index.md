# Agents API Developer Documentation

Agents API is a WordPress-shaped backend substrate for durable agent runtime behavior. It provides provider-neutral contracts, value objects, registries, policy resolvers, and dispatcher seams that product plugins and runtime adapters can build on. It intentionally does **not** ship a concrete chat runtime, provider/model adapter, product UI, default durable stores, workflow editor, or platform-specific channel client.

This docs surface was bootstrapped from the repository at `d919fea0e79b1cc39c3d85f976d836165e8813c1`, using the source tree, smoke-test inventory, existing docs, issues, and pull requests listed in [Coverage Map](coverage-map.md).

## Start here

- [Coverage Map](coverage-map.md) — evidence map for source areas, tests, public hooks, existing docs, issues, PRs, and intentional gaps.
- [Agent Registry And Packages](agent-registry-packages.md) — registering agents, package artifacts, identity declarations, and subagent declarations.
- [Runtime Loop And Tools](runtime-loop-tools.md) — message envelopes, conversation loop, compaction, budgets, tool mediation, visibility policy, and action policy.
- [Auth, Consent, And Approvals](auth-consent-approvals.md) — access grants, bearer tokens, capability ceilings, caller context, consent decisions, and pending actions.
- [Memory, Context, Guidelines, And Storage](memory-context-storage.md) — workspace identity, memory contracts, metadata/provenance, context composition, guideline substrate, transcript store, and locks.
- [Channels And Bridges](channels-bridges.md) — canonical `agents/chat`, direct channel base class, normalized external messages, session mapping, webhooks, idempotency, and remote bridge queues.
- [Workflows And Routines](workflows-routines.md) — workflow specs, validators, bindings, runner, workflow abilities, Action Scheduler bridge, scheduled routines, and subagents.
- [Development And Testing](development-testing.md) — local requirements, load order, smoke tests, CI/docs-agent context, and contributor maintenance checklist.

Existing focused docs remain authoritative for their narrower topics:

- [`README.md`](../README.md) — high-level boundary, public surface, examples, and requirements.
- [External Clients, Channels, And Bridges](external-clients.md)
- [Remote Bridge Protocol](remote-bridge-protocol.md)
- [Bridge Authorization And Onboarding](bridge-authorization.md)
- [Default Stores Companion Proposal](default-stores-companion.md)

## Repository map

```text
agents-api.php              Plugin bootstrap and direct module loader.
composer.json               PHP requirement, Action Scheduler suggestion, smoke-test script.
src/Registry/               Declarative agent objects and in-memory registry.
src/Packages/               Agent package and artifact contracts.
src/Auth/                   Access grants, tokens, caller context, authorization policies.
src/Runtime/                Messages, requests/results, loop, compaction, budgets, principals.
src/Tools/                  Tool declarations, tool execution, visibility and action policy.
src/Approvals/              Pending action storage/resolution contracts.
src/Consent/                Consent operation and decision contracts.
src/Context/                Memory/context registries, authority tiers, conflict resolution.
src/Memory/                 Store-neutral memory scopes, metadata, query/result contracts.
src/Guidelines/             `wp_guideline` substrate polyfill and capabilities.
src/Channels/               Chat ability, channel base, session/idempotency/bridge helpers.
src/Workflows/              Workflow specs, registry, runner, abilities, scheduler bridge.
src/Routines/               Persistent scheduled agent routines and scheduler bridge.
src/Identity/               Materialized identity scope/store contracts.
src/Transcripts/            Conversation transcript store and lock contracts.
src/Workspace/              Shared workspace identity value object.
tests/                      Smoke tests for contracts, modules, and boundary rules.
```

## Load and registration lifecycle

1. WordPress loads `agents-api.php`.
2. The bootstrap defines `AGENTS_API_LOADED`, `AGENTS_API_PATH`, and `AGENTS_API_PLUGIN_FILE`, then requires module files directly.
3. On WordPress `init`, `WP_Guidelines_Substrate::register()` runs at priority 9 and `WP_Agents_Registry::init()` runs at priority 10.
4. `WP_Agents_Registry::init()` fires `wp_agents_api_init`; consumers call `wp_register_agent()` inside that action.
5. Agent reads (`wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`) are safe after `init` has fired.
6. Abilities are registered through Abilities API hooks when available: `agents/chat`, `agents/run-workflow`, `agents/validate-workflow`, and `agents/describe-workflow`.
7. Runtime execution remains caller-owned. Consumers register chat/workflow handlers, tool executors, stores, policies, and transport adapters through the documented interfaces and WordPress hooks/filters.

## Public extension points

Common extension points include:

- `wp_agents_api_init` — register agent definitions.
- `wp_agent_package_artifacts_init` — register package artifact types.
- `agents_api_execution_principal` — resolve execution principals.
- `agents_api_loop_event` — observe conversation-loop lifecycle events.
- `agents_api_memory_sources` and `agents_api_context_sections` — register memory/context sources and composable sections.
- `agents_api_resolved_tools`, `agents_api_tool_policy_providers`, `agents_api_action_policy_providers`, `agents_api_tool_action_policy` — tool visibility/action policy extension.
- `wp_agent_chat_handler`, `agents_chat_permission`, `agents_chat_dispatch_failed`, `wp_agent_channel_chat_ability` — chat/channel runtime, authorization, and observability.
- `wp_agent_workflow_handler`, `agents_run_workflow_permission`, `agents_validate_workflow_permission`, `agents_run_workflow_dispatch_failed`, `wp_agent_workflow_known_step_types`, `wp_agent_workflow_known_trigger_types`, `wp_agent_workflow_step_handlers` — workflow runtime and validation extension.
- `wp_agent_routine_registered`, `wp_agent_routine_unregistered`, `wp_agent_routine_schedule_requested` — routine lifecycle.
- `wp_guidelines_substrate_enabled` — disable the guideline substrate polyfill when Core/Gutenberg provides one.

See [Coverage Map](coverage-map.md#public-extension-point-coverage) for source ownership of these hooks.

## Ownership principles

- Agents API owns contracts, neutral value objects, dispatchers, vocabulary, and reusable orchestration seams.
- Consumers own product UX, provider/model execution, concrete tools, durable stores, consent UI, approval UI, product workflow semantics, scheduling policy, retention, analytics, and platform-specific channel code.
- Concrete persistence policy should not enter this substrate unless it becomes a generic contract.
- Public boundaries favor JSON-friendly arrays and immutable value objects so stores, bridges, tools, and runtimes can be swapped independently.
