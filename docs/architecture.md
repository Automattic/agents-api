# Architecture Overview

Agents API is a provider-agnostic WordPress plugin that supplies reusable agent runtime substrate. It sits between provider/model execution (`wp-ai-client`) and product plugins that own UI, product policy, concrete tools, workflow persistence, and storage materialization.

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts, tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy, storage/materialization policy
```

## Repository layout

- `agents-api.php` is the plugin bootstrap. It defines `AGENTS_API_LOADED`, `AGENTS_API_PATH`, `AGENTS_API_PLUGIN_FILE`, requires every source module, and attaches init-time hooks.
- `src/Registry` defines agent registration and lookup.
- `src/Packages` defines portable package manifests and artifact declarations.
- `src/Auth` defines access grants, token metadata/authentication, caller context, authorization policy, and capability ceilings.
- `src/Runtime` defines message envelopes, principals, conversation requests/results, conversation loop, completion/compaction contracts, budgets, and transcript persister seams.
- `src/Tools` defines runtime tool declarations, tool-call normalization, parameter mediation, execution adapters, visibility policy, and action policy.
- `src/Approvals` defines pending action proposal, storage, resolver, handler, status, and approval decision contracts.
- `src/Consent` defines consent operation, decision, policy, and conservative default policy.
- `src/Context` defines memory/context source registration, composable sections, retrieved-context authority vocabulary, and conflict resolution.
- `src/Memory` defines store-neutral memory persistence contracts, metadata, capabilities, queries, validators, and read/write/list result objects.
- `src/Guidelines` provides an optional `wp_guideline` / `wp_guideline_type` substrate polyfill and capability mapping.
- `src/Channels` defines direct external channel helpers, canonical chat ability registration, external message/session/idempotency helpers, and remote bridge queue contracts.
- `src/Workflows` defines workflow specs, structural validation, bindings, runner, registry, stores/recorders, abilities, and Action Scheduler bridge.
- `src/Routines` defines persistent scheduled agent routines and an optional Action Scheduler bridge.
- `src/Transcripts` defines complete transcript session store and lock contracts.
- `src/Identity` defines durable materialized identity contracts.
- `src/Workspace` defines the generic workspace identity shared across stores.
- `tests` contains PHP smoke tests that exercise the contracts without requiring a full product runtime.

## Layering rules

Agents API owns shared shapes and reusable mechanics, not product behavior. The code consistently keeps these concerns out of the substrate:

- Provider-specific request execution.
- Admin screens, onboarding, settings pages, workflow editors, dashboards, and product UX.
- Concrete tool adapters, workflow runtimes, approval UI, product storage, and product prompt policy.
- Product-specific vocabulary or imports from downstream plugins.

The `tests/no-product-imports-smoke.php` test reinforces this boundary.

## Runtime flow at a glance

A typical consumer-composed agent run uses these layers:

1. A consumer registers an agent through `wp_register_agent()` during `wp_agents_api_init`.
2. A caller reaches a runtime through a canonical ability such as `agents/chat` or `agents/run-workflow`, a channel subclass, a remote bridge, REST, CLI, cron, or a product adapter.
3. The consumer resolves an `WP_Agent_Execution_Principal` from user session, token, CLI, cron, or other request context.
4. The runtime assembles prompt/context using consumer policy plus Agents API memory/context/guideline contracts.
5. `WP_Agent_Conversation_Loop` can normalize messages, compact history, invoke a caller-owned turn runner, mediate tools, enforce budgets, emit events, and persist transcripts.
6. Consumer-owned stores materialize identities, transcripts, memory, pending approvals, workflow run history, and bridge state.

## Design principles

- **Contracts over concrete product behavior.** Interfaces and value objects define durable shapes; products provide adapters.
- **WordPress-shaped lifecycle.** Public registration uses WordPress actions, filters, capabilities, and Abilities API conventions.
- **Provider agnostic.** Provider/model execution is delegated to `wp-ai-client` or consumer adapters.
- **Storage agnostic.** Stores expose contracts; physical tables, files, posts, options, queues, or external services are implementation details.
- **Fail closed for auth and safety.** Malformed caller context, expired tokens, invalid capabilities, empty webhook secrets, and invalid runtime shapes are rejected or normalized conservatively.
- **Observers must not break runtime execution.** Conversation loop event observer failures and transcript persister failures are swallowed where the source code explicitly treats them as observational.
- **Additive contracts.** Many runtime shapes accept optional metadata to support consumers without forcing a product-specific schema into the substrate.

## Related pages

- [Bootstrap and lifecycle](bootstrap-lifecycle.md)
- [Extension points and public surface](extension-points.md)
- [Runtime, messages, conversation loop, and compaction](runtime-conversation.md)
