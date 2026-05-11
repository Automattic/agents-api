# Architecture Overview

Agents API is a WordPress-shaped substrate for durable agent runtime behavior. It provides reusable contracts, value objects, registries, dispatchers, and mediation primitives; product plugins provide concrete model providers, storage, product UI, prompt policy, tools, and workflow runtimes.

## Layer boundary

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts, tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy, storage/materialization policy
```

Evidence: `README.md`, `agents-api.php`, and `tests/no-product-imports-smoke.php` define and verify this boundary. `composer.json` requires PHP `>=8.1`, suggests Action Scheduler for scheduled workflow runs, and runs the pure-PHP smoke-test suite with `composer test`.

## Bootstrap and module loading

`agents-api.php` is the plugin entry point. It:

- Defines `AGENTS_API_LOADED`, `AGENTS_API_PATH`, and `AGENTS_API_PLUGIN_FILE`.
- Requires module files in dependency order rather than using Composer autoloading.
- Wires `WP_Guidelines_Substrate::register` to WordPress `init` at priority 9.
- Wires `WP_Agents_Registry::init` to WordPress `init` at priority 10.

The require order mirrors the architecture: registry and package value objects, auth, context, workspace/identity/transcripts, approvals/consent, runtime, tools, memory/guidelines, channels, workflows, then routines.

## Major modules

| Module | Source | Responsibilities | Consumer-owned concerns |
| --- | --- | --- | --- |
| Registry | `src/Registry/**` | Agent definition object, in-memory registry, `wp_agents_api_init`, `wp_register_agent()` helpers. | Product registration timing, materialization, UI. |
| Packages | `src/Packages/**` | Package and artifact value objects, artifact registry, adoption result/diff contracts. | Installers, package catalogs, artifact application. |
| Auth | `src/Auth/**` | Access grants, bearer token metadata/authentication, caller context parsing, capability ceilings, WordPress authorization policy. | Token storage, trust policy, REST/CLI wiring. |
| Runtime | `src/Runtime/**` | Message/request/result value objects, execution principal, compaction, loop sequencing, iteration budgets, transcript persistence interface. | Prompt assembly, model calls, concrete persistence. |
| Tools | `src/Tools/**` | Tool declarations, parameter normalization, executor interface, execution core, visibility/action policy. | Concrete tools, side-effect application, approvals UI. |
| Approvals | `src/Approvals/**` | Pending action value, statuses, decisions, resolver/handler/store contracts. | Durable queues, user interface, product permission checks. |
| Consent | `src/Consent/**` | Operation vocabulary and conservative default policy. | Product consent UX, audit stores, retention rules. |
| Context and memory | `src/Context/**`, `src/Memory/**` | Context source registry, section registry, retrieved-context authority vocabulary, memory store contracts and metadata. | Retrieval heuristics, physical stores, ranking/materialization. |
| Workspace and identity | `src/Workspace/**`, `src/Identity/**` | Generic workspace identity and identity-store value objects. | Mapping sites/networks/code workspaces to identities. |
| Transcripts | `src/Transcripts/**` | Conversation store and lock interfaces. | Concrete transcript tables, provider continuity storage. |
| Channels | `src/Channels/**` | External message shape, abstract channel pipeline, bridge/session/idempotency stores, canonical `agents/chat` ability. | Platform APIs, webhook routes, channel settings. |
| Workflows | `src/Workflows/**` | Workflow spec, validator, bindings, registry, runner skeleton, abilities, optional Action Scheduler bridge. | Durable workflow store/runtime, product step types, editor UI. |
| Routines | `src/Routines/**` | Routine declaration/registry and Action Scheduler bridge sync. | Routine authoring UX and operational policy. |
| Guidelines | `src/Guidelines/**` | `wp_guideline`/`wp_guideline_type` substrate polyfill and capability mapping. | Hosts that already provide guideline substrate can disable/replace it. |

## Runtime data flow

A typical chat flow uses these seams:

1. A product or channel registers agent definitions during `wp_agents_api_init`.
2. An external channel subclasses `WP_Agent_Channel` and receives a webhook payload.
3. The channel builds the canonical `agents/chat` payload and executes the Abilities API ability.
4. The `agents/chat` dispatcher calls a consumer-registered handler from the `wp_agent_chat_handler` filter.
5. The handler may call `WP_Agent_Conversation_Loop::run()` with a provider adapter, tool declarations, tool executor, completion policy, budgets, transcript persister, and event sink.
6. The loop normalizes messages, optionally compacts history, calls the turn runner, mediates tool calls through `WP_Agent_Tool_Execution_Core`, emits events, persists transcripts, and returns a normalized result.
7. The channel delivers the `reply`/assistant messages and persists external-session continuity.

## Extension philosophy

Agents API keeps extension seams generic and narrow:

- Dispatchers select runtime handlers through filters (`wp_agent_chat_handler`, `wp_agent_workflow_handler`).
- Policies are interfaces or filterable resolvers, not hardcoded product rules.
- Storage is represented by contracts (`WP_Agent_Memory_Store`, `WP_Agent_Conversation_Store`, workflow stores/recorders, pending action stores), not bundled tables.
- Runtime arrays are JSON-friendly so consumers can map them to REST, webhooks, queues, and logs.
- Observer failures are swallowed in runtime event surfaces so telemetry cannot change execution outcomes.

## Source and test evidence

- Bootstrap: `agents-api.php`.
- Module inventory: repository tree under `src/**`.
- Product-boundary guard: `tests/no-product-imports-smoke.php`.
- Smoke suite and command: `composer.json` (`composer test`).
- Integration examples and public surface list: `README.md`.
