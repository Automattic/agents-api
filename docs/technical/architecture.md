# Architecture Overview

Agents API is a WordPress-shaped backend substrate for durable agent runtime behavior. It provides contracts, value objects, registries, ability dispatchers, and shared mediation primitives; consumers provide concrete provider calls, product UI, storage implementations, workflow runtimes, and platform-specific policy.

This page is the architectural map for the bootstrap documentation surface. Follow the topic pages for reference-level contracts and examples.

## Layer boundary

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts, tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy, storage/materialization policy
```

Agents API must stay provider-neutral and product-neutral. The root plugin file (`agents-api.php`) loads the contract surface and wires only substrate hooks such as `WP_Agents_Registry::init` and the Guidelines substrate polyfill on WordPress `init`.

## Source inventory and committed coverage

| Area | Primary source | Committed docs coverage |
| --- | --- | --- |
| Bootstrap and module loading | `agents-api.php`, `composer.json`, `tests/bootstrap-smoke.php` | This page and [Development and operations](development-and-operations.md) |
| Agent registration and lookup | `src/Registry/*`, `tests/registry-smoke.php`, `tests/subagents-smoke.php` | [Agents and packages](agents-and-packages.md) |
| Package and artifact value objects | `src/Packages/*`, `tests/bootstrap-smoke.php` | [Agents and packages](agents-and-packages.md) |
| Conversation loop, message envelopes, compaction, budgets, events, transcript locks | `src/Runtime/*`, `src/Transcripts/*`, conversation loop and compaction smoke tests | [Runtime conversation loop](runtime-conversation-loop.md) |
| Tool declarations, tool execution, visibility, action policy | `src/Tools/*`, tool/action policy smoke tests | [Tools, authorization, and approvals](tools-authorization-and-approvals.md) |
| Authorization, tokens, caller context, execution principals, consent | `src/Auth/*`, `src/Consent/*`, authorization/caller-context/consent smoke tests | [Tools, authorization, and approvals](tools-authorization-and-approvals.md) |
| Pending actions and approval contracts | `src/Approvals/*`, approval smoke tests, issue #128 | [Tools, authorization, and approvals](tools-authorization-and-approvals.md) |
| Workspace, memory store contracts, memory metadata, context registries, authority conflict resolution, guidelines | `src/Workspace/*`, `src/Memory/*`, `src/Context/*`, `src/Guidelines/*`, memory/context/guidelines smoke tests | [Memory, context, and storage contracts](memory-context-and-storage.md) |
| External clients, direct channels, remote bridges, chat ability | `src/Channels/*`, existing `docs/external-clients.md`, `docs/bridge-authorization.md`, channel/remote-bridge/webhook smoke tests | [Channels, workflows, and routines](channels-workflows-and-routines.md) plus existing bridge docs |
| Workflow specs, abilities, runner, registries, Action Scheduler bridge | `src/Workflows/*`, workflow smoke tests | [Channels, workflows, and routines](channels-workflows-and-routines.md) |
| Routines and recurring agent wakes | `src/Routines/*`, `tests/routine-smoke.php` | [Channels, workflows, and routines](channels-workflows-and-routines.md) |
| Development, CI, tests, dependency expectations | `composer.json`, `.github/workflows/ci.yml`, test list | [Development and operations](development-and-operations.md) |

## Module relationships

```text
Consumer plugin
  -> registers WP_Agent definitions on wp_agents_api_init
  -> optionally registers chat/workflow handlers through filters
  -> supplies memory/transcript/token/pending-action stores
  -> supplies provider and tool execution adapters

Agents API substrate
  -> registries: agents, packages, memory/context sources, workflow specs, routines
  -> value objects: agent definitions, principals, messages, memory metadata, workflow specs, bridge items
  -> runtime loops: conversation loop, workflow runner, routine bridge, channel bridge
  -> policies: tool visibility, action policy, auth ceilings, consent, context conflict resolution
  -> store interfaces: memory, transcript, token, access grant, pending action, bridge, workflow

WordPress primitives
  -> actions/filters for registration and extension points
  -> Abilities API for agents/chat and workflow ability dispatchers
  -> options/transients for small default stores where provided
  -> Action Scheduler when installed for scheduled workflow/routine bridges
  -> wp_guideline polyfill when Core/Gutenberg do not provide it
```

The important design decision is composition over a heavy base class. `WP_Agent` is a thin declarative value object. Runtime behavior is assembled by consumers from `WP_Agent_Conversation_Loop`, tool executors, transcript persisters, memory/context adapters, and provider runners.

## Public extension surfaces

Agents API exposes extension seams through WordPress actions/filters and PHP contracts:

- `wp_agents_api_init` for agent registration.
- `wp_abilities_api_init` and `wp_abilities_api_categories_init` registration of canonical abilities.
- `wp_agent_chat_handler` and `wp_agent_workflow_handler` for consumer runtimes.
- `wp_agent_channel_chat_ability` to override a channel's chat ability slug.
- `agents_api_loop_event` for read-only conversation-loop observability.
- `agents_api_memory_sources` and `agents_api_context_sections` for context registries.
- Tool policy filters: `agents_api_tool_policy_providers`, `agents_api_resolved_tools`, `agents_api_action_policy_providers`, and `agents_api_tool_action_policy`.
- Workflow filters: `wp_agent_workflow_known_step_types`, `wp_agent_workflow_known_trigger_types`, and `wp_agent_workflow_step_handlers`.
- Permission filters: `agents_chat_permission`, `agents_run_workflow_permission`, and `agents_validate_workflow_permission`.
- Polyfill control: `wp_guidelines_substrate_enabled`.

## Failure and safety model

The substrate prefers explicit contracts and fail-closed behavior at trust boundaries:

- Agent registration outside `wp_agents_api_init` returns `null` and emits `_doing_it_wrong()`.
- Token authentication returns `null` for empty, wrong-prefix, unknown, expired, or malformed caller-chain requests.
- Caller context rejects malformed cross-site headers and enforces a chain-depth ceiling.
- Tool execution returns normalized error arrays for missing tools, missing required parameters, and executor exceptions.
- Conversation loop observer, transcript persister, and lock-release failures are swallowed so observability and persistence failures do not change the model/tool result; runner exceptions still propagate.
- Workflow dispatchers return `WP_Error` when no runtime handler exists or when a handler returns an invalid shape.
- The default consent policy denies non-interactive operations by default and requires explicit per-operation consent in interactive modes.

## Contributor design principles

- Keep Agents API as the generic substrate. Do not import product plugins or encode product vocabulary.
- Prefer value objects, interfaces, and filters over concrete storage or provider implementations.
- Treat workspace identity as `workspace_type` + `workspace_id`; do not assume a WordPress site is the only boundary.
- Preserve JSON-friendly shapes for contracts that may cross REST, queues, transcripts, or external clients.
- Make policy explicit and replaceable. Consumers own concrete authorization, approval UI, prompts, storage, and runtime behavior.
- Add tests as standalone smoke scripts under `tests/` and include them in `composer test` when adding a public contract.

## Future coverage

The committed bootstrap covers the major source inventory at contract/reference level. Future documentation passes should expand:

- Per-class API reference tables for every value object and interface. This pass documents grouped public contracts rather than every method of every class to stay navigable.
- Default-store companion implementation details after issue #78 creates the separate repository. This repository intentionally contains contracts and the companion proposal only.
- Typed lifecycle event objects if issue #77/#75 lands them in a future source change. They are not present in this ref's `src/Runtime/` tree.
- Abilities API lifecycle filter adoption from issue #94 once the upstream WordPress core filters land. The current source does not implement those hooks.
