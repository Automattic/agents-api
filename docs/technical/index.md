# Agents API Technical Documentation

This documentation set is a first-pass, source-derived developer guide for Agents API. It is organized by the plugin's source-module boundaries so a new contributor can move from the architecture overview into the contracts they need to implement or extend.

Agents API is a WordPress-shaped agent runtime substrate. It provides provider-neutral contracts and orchestration primitives for agents, tools, auth, memory, channels, workflows, routines, approvals, transcripts, and package artifacts. Consumer plugins own product UX, concrete AI provider calls, concrete storage, prompt assembly, scheduling policy, support/escalation policy, and product-specific side effects.

## Read this first

1. [Architecture overview](architecture.md) — layer boundary, bootstrap lifecycle, source inventory, design principles, storage boundaries, and future coverage.
2. [Agent registry and runtime](registry-runtime.md) — `WP_Agent`, `WP_Agents_Registry`, `wp_agents_api_init`, conversation loop lifecycle, transcript adapters, events, budgets, and loop failure modes.
3. [Authorization and consent](auth-consent.md) — execution principals, bearer-token authentication, caller context, WordPress authorization policy, access grants, capability ceilings, workspace identity, and consent operations.
4. [Tools and approvals](tools-approvals.md) — runtime tool declaration validation, tool visibility policy, action policy, tool execution contracts, and pending-action approval shapes.
5. [Memory, context, and guidelines](memory-context.md) — memory store interface, scope and provenance metadata, memory/context registry, context composition, retrieved-context authority, and the guideline substrate polyfill.
6. [Channels, workflows, and routines](workflows-channels.md) — external channel contracts, session continuity, webhook safety, bridge queues, `agents/chat`, workflow specs/runs/abilities, Action Scheduler bridges, and routines.

## Committed coverage

This bootstrap pass documents the major loaded modules from `agents-api.php` and reconciles them against the source tree:

- `src/Registry` and public registration helpers.
- `src/Runtime` and transcript lock/persister boundaries.
- `src/Auth`, `src/Consent`, `src/Workspace`, and `src/Identity`.
- `src/Tools` and `src/Approvals`.
- `src/Memory`, `src/Context`, and `src/Guidelines`.
- `src/Channels`, `src/Workflows`, and `src/Routines`.
- Existing bridge/external-client docs are cross-referenced from the channel/workflow page.
- Test and operational workflow coverage is grounded in `composer.json`'s `composer test` script and the smoke-test files named on each topic page.

## Public extension points covered

The topic pages cover these public surfaces and extension points:

- Agent registration: `wp_agents_api_init`, `wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, `wp_unregister_agent()`.
- Runtime observation: `agents_api_loop_event` and caller-owned `on_event` callbacks.
- Principal resolution and auth/consent contracts: `agents_api_execution_principal`, token/auth stores, access stores, authorization policies, caller-context headers, and consent policies.
- Tool policy: runtime tool declarations, `WP_Agent_Tool_Policy`, `agents_api_resolved_tools`, `agents_api_tool_policy_providers`, action-policy resolver contracts, and approval stores/handlers.
- Memory and context: `WP_Agent_Memory_Store`, metadata/query/validator contracts, `agents_api_memory_sources`, context section registry, conflict resolver contracts, and guideline substrate capability mapping.
- Channels and workflows: `agents/chat`, channel session/idempotency/bridge contracts, `wp_agent_workflow_step_handlers`, workflow store/run recorder contracts, workflow abilities, and optional Action Scheduler bridges.

## Future coverage

The following inventory items are intentionally listed for future expansion rather than hidden:

- **Dedicated package-artifact documentation.** `src/Packages` is loaded by the plugin and represented in the README public surface. This pass documents its boundary in the architecture overview, but a future page should cover `WP_Agent_Package`, artifacts, artifact types, artifact registries, adoption diffs/results, and adopter workflows in detail.
- **Method-level class reference.** This pass provides contract-level details, representative fields, lifecycle, failure behavior, and evidence. A future generated reference can enumerate every public method for each value object once the API settles further.
- **End-to-end examples with concrete adapters.** Agents API deliberately avoids product-owned storage/provider/tool implementations. Future examples can show minimal fake adapters for memory stores, transcript stores, tool executors, workflow recorders, and channels without implying that the substrate owns production implementations.

## Source and test evidence

Each topic page includes source files and smoke tests that prove the documented behavior. For the full current test suite, run:

```bash
composer test
```
