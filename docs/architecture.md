# Architecture and Boundaries

Agents API is a WordPress-shaped backend substrate for agent runtime behavior. It is deliberately not a product application, admin UI, provider client, or concrete workflow engine. Its job is to define stable WordPress-friendly contracts that consumers can compose into products.

## Layer model

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> agent identity, runtime contracts, orchestration contracts,
                tool mediation, memory/transcript/session contracts
Consumers    -> product UX, concrete tools, workflows, prompt policy,
                storage/materialization policy, provider adapters
```

The plugin bootstrap (`agents-api.php`) loads source modules directly and registers two init-time substrates:

- `WP_Agents_Registry::init` on `init` priority `10`, which fires `wp_agents_api_init` for agent definitions.
- `WP_Guidelines_Substrate::register` on `init` priority `9`, which polyfills `wp_guideline`/`wp_guideline_type` when Core/Gutenberg has not registered them.

## Source module map

| Directory | Responsibility |
| --- | --- |
| `src/Registry` | Thin `WP_Agent` definitions and the process-local agent registry. |
| `src/Packages` | Agent package and artifact declarations plus artifact-type registry helpers. |
| `src/Auth` | Access grants, bearer-token metadata/authentication, authorization policy, capability ceilings, caller-chain context. |
| `src/Runtime` | Message envelopes, execution principals, conversation requests/results, conversation loop, compaction, budgets, transcript persister contracts. |
| `src/Tools` | Runtime tool declarations, source gathering, parameter normalization, execution mediation, visibility and action policy. |
| `src/Approvals` | Pending action and approval decision value objects plus store/resolver/handler contracts. |
| `src/Consent` | Consent operation vocabulary, decisions, policy interface, and conservative default policy. |
| `src/Memory` | Store-neutral memory scope, metadata, query, result, capabilities, validator, and store contracts. |
| `src/Context` | Memory/context source registry, context-section registry, composable context, authority tiers, conflict resolution. |
| `src/Guidelines` | Shared guideline substrate polyfill and helper functions. |
| `src/Channels` | Direct channel base class, normalized external messages, session map, webhook safety, idempotency, remote bridge queue. |
| `src/Workflows` | Workflow spec, structural validation, registry, bindings, runner, run results, store/recorder contracts, workflow abilities, Action Scheduler bridge. |
| `src/Routines` | Persistent scheduled agent routine value object, registry, Action Scheduler bridge/listener. |
| `src/Transcripts` | Conversation store and lock contracts. |
| `src/Identity` | Identity scope, materialized identity, and identity-store contracts. |
| `src/Workspace` | Generic workspace identity shared by memory, transcripts, auth, audit, and persistence adapters. |

The smoke tests assert this directory shape and verify that the package does not load Data Machine product namespaces or product runtime code.

## Public extension surfaces

Agents API exposes WordPress-native hooks, filters, helpers, and interfaces instead of a monolithic runtime class.

### Registration hooks

- `wp_agents_api_init` — registration window for `wp_register_agent()`.
- `wp_agent_package_artifacts_init` — registration window for package artifact types.
- `agents_api_memory_sources` — one-shot hook for memory/context source registration.
- `agents_api_context_sections` — one-shot hook for composable context sections.
- `wp_agent_workflow_known_step_types` and `wp_agent_workflow_known_trigger_types` — structural validator extensions for consumer-defined workflow types.
- `wp_agent_workflow_step_handlers` — runtime step handler map extension.
- `wp_agent_routine_registered` / `wp_agent_routine_unregistered` — routine registry lifecycle.

### Runtime hooks and filters

- `agents_api_execution_principal` resolves a request principal from REST, CLI, cron, session, or token context.
- `agents_api_loop_event` observes conversation-loop lifecycle events. Observer failures are swallowed.
- `agents_api_tool_sources` and `agents_api_tool_source_order` extend tool gathering.
- `agents_api_resolved_tools` filters the final visible tool map.
- `agents_api_tool_policy_providers` and `agents_api_action_policy_providers` register policy providers.
- `agents_api_tool_action_policy` applies the final action-policy override.
- `wp_agent_channel_chat_ability` changes which ability a channel dispatches to. Default: `agents/chat`.
- `wp_agent_chat_handler` installs the canonical chat runtime handler.
- `agents_chat_permission` widens or narrows `agents/chat` permission checks.
- `wp_agent_workflow_handler` installs the canonical workflow runtime handler.
- `agents_run_workflow_permission` and `agents_validate_workflow_permission` gate workflow abilities.
- `wp_guidelines_substrate_enabled` disables the guideline substrate polyfill.

## Ownership boundaries

Agents API owns reusable, provider-neutral shapes:

- Value objects and interfaces for agents, packages, memory, transcripts, tools, approvals, consent, channels, workflows, routines, identity, and workspaces.
- Canonical ability contracts for `agents/chat`, `agents/run-workflow`, `agents/validate-workflow`, and `agents/describe-workflow`.
- Optional Action Scheduler bridges that no-op cleanly when Action Scheduler is absent.
- WordPress-shaped hooks and filters for adapter injection.

Consumers own product-specific decisions:

- Provider/model calls, prompt assembly, streaming, token accounting interpretation, and provider-specific state.
- Concrete tools, ability implementations, workflow step types, approval UI, REST routes, settings screens, and product dashboards.
- Durable storage schemas for memory, transcripts, workflow history, pending actions, tokens, access grants, identities, and package adoption.
- Product-specific authorization, retention, support escalation, onboarding, and external-service credential UX.

## Design principles for contributors

1. **Keep the substrate provider-neutral.** Anything that talks to a specific model provider belongs in a consumer or adapter.
2. **Prefer contracts over concrete storage.** Interfaces and value objects live here; database tables, CPT stores, filesystem layouts, and queues usually live outside or in an optional companion.
3. **Use workspace terminology for generic scope.** Do not encode `site_id` as the universal boundary; map sites, networks, code workspaces, and ephemeral runtimes into `workspace_type` + `workspace_id`.
4. **Fail closed for auth and malformed caller context.** Token authentication rejects expired tokens, wrong prefixes, unknown hashes, and malformed caller headers before touching the token.
5. **Make observers non-disruptive.** Loop events and transcript persistence failures must not change provider execution or returned results.
6. **Keep product vocabulary out of source contracts.** Smoke tests enforce that the extracted package does not import or describe Data Machine product classes.
7. **Document new public seams.** Any new hook, filter, helper, ability, interface, or canonical array shape should be reflected in these docs and in method-level PHPDoc.

## Compatibility and requirements

The plugin metadata requires PHP `>=8.1` and WordPress `7.0` or higher. The substrate itself is provider-agnostic, but practical consumers need an AI provider such as `wp-ai-client`. Composer suggests `woocommerce/action-scheduler` for scheduled workflows/routines; Action Scheduler is optional at runtime and bridge code no-ops when unavailable.
