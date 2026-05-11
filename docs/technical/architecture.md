# Architecture Overview

Agents API is a WordPress plugin that provides provider-neutral agent runtime substrate primitives. It is intentionally a contract and orchestration layer, not a product application, concrete AI provider client, durable workflow engine, admin UI, REST controller set, or product-specific storage implementation.

## Layer boundary

```text
wp-ai-client / providers -> provider and model prompt execution
Agents API              -> agent definitions, runtime value objects, auth, policies, tools, memory, transcripts, channels, workflows
Consumers               -> product UX, concrete tools, concrete storage, prompts, provider adapters, scheduling/materialization policy
```

The plugin bootstrap in `agents-api.php` requires every public module and wires two WordPress lifecycle hooks:

- `WP_Guidelines_Substrate::register` on `init` priority 9.
- `WP_Agents_Registry::init` on `init` priority 10, which fires the public `wp_agents_api_init` registration hook.

## Source inventory covered in this documentation set

This bootstrap pass documents the major source modules that are loaded by `agents-api.php`:

| Area | Source directories | Topic page |
| --- | --- | --- |
| Agent definitions and runtime loop | `src/Registry`, `src/Runtime`, `src/Transcripts` | [Agent registry and runtime](registry-runtime.md) |
| Authentication, authorization, caller context, consent | `src/Auth`, `src/Consent`, `src/Workspace`, `src/Identity` | [Authorization and consent](auth-consent.md) |
| Tool declarations, visibility, action policy, approvals | `src/Tools`, `src/Approvals` | [Tools and approvals](tools-approvals.md) |
| Memory, retrieved context, guideline substrate | `src/Memory`, `src/Context`, `src/Guidelines` | [Memory, context, and guidelines](memory-context.md) |
| External channels, bridges, workflows, routines | `src/Channels`, `src/Workflows`, `src/Routines` | [Channels, workflows, and routines](workflows-channels.md) |
| Package artifacts | `src/Packages` | Covered at a high level below; future pass should add a dedicated package page. |

## Design principles for contributors

The following principles are visible across source comments, value-object validation, smoke tests, and the existing README:

1. **Substrate, not product.** Agents API defines reusable contracts and safe value shapes. Consumer plugins own UX, provider calls, durable product storage, support routing, and product policy.
2. **Provider-agnostic execution.** Runtime loops accept caller-owned turn runners, summarizers, tool executors, persisters, and completion policies instead of importing a model provider.
3. **WordPress-shaped lifecycle.** Registration happens on `init`; extensibility uses actions and filters such as `wp_agents_api_init`, `agents_api_loop_event`, `agents_api_resolved_tools`, and `wp_agent_workflow_step_handlers`.
4. **Fail closed at trust boundaries.** Token authentication rejects blank, mismatched, expired, or malformed caller-context requests. Consent is denied by default outside explicit interactive consent.
5. **JSON-friendly contracts.** Runtime, workflow, approval, memory, context, and channel objects normalize to arrays that consumers can persist, audit, stream, or map into other stores.
6. **Adapters own side effects.** Concrete storage, tool execution, scheduling, prompt assembly, and workflow step semantics are injected via interfaces, callbacks, filters, or registries.

## Public extension surfaces

Common extension surfaces include:

- `wp_agents_api_init` action for registering agents.
- `wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, and `wp_unregister_agent()` helpers from `src/Registry/register-agents.php`.
- `agents_api_loop_event` action for observing runtime loop lifecycle events.
- `agents_api_resolved_tools` and `agents_api_tool_policy_providers` filters for tool visibility.
- `agents_api_tool_action_policy` filter for final action-policy decisions.
- `agents_api_memory_sources` action for contributing memory/context source registrations.
- `wp_agent_workflow_step_handlers` filter for adding or replacing workflow step handlers.
- `wp_guidelines_substrate_enabled` filter for disabling the guideline post-type polyfill when Core/Gutenberg or a host supplies it.

## Storage and side-effect boundaries

Agents API ships interfaces and value objects rather than product tables:

- Agent registry state is in memory for the current WordPress process.
- Memory stores implement `WP_Agent_Memory_Store`; the substrate does not choose a physical path, row, or external database.
- Conversation stores and locks are interfaces in `src/Transcripts`; the runtime loop can use a lock/persister if provided.
- Pending approvals use `WP_Agent_Pending_Action_Store`; consumers materialize queues, UI, permissions, and handlers.
- Workflows can use `WP_Agent_Workflow_Store` and `WP_Agent_Workflow_Run_Recorder`; the default registry is in memory.
- Optional Action Scheduler bridges no-op cleanly when Action Scheduler is unavailable.

## Evidence

Primary source evidence: `agents-api.php`, `README.md`, `composer.json`, and the module files under `src/*`. Smoke-test coverage in `composer.json` includes bootstrap, registry, authorization, caller context, tool policy/runtime, approval contracts, memory metadata, context registry, conversation loop, channels, workflows, routines, and no-product-import tests.

## Future coverage

- A dedicated `Packages` page for package artifact registration, adoption diffs/results, and package adopter behavior. This pass identifies the boundary from `src/Packages/*` and README public surface but prioritizes runtime, auth, memory, tools, channels, and workflows.
- Full method-by-method reference for every value object. This pass documents the stable contract shapes and integration points; individual class references can be generated incrementally as APIs stabilize.
