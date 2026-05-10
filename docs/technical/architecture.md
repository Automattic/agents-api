# Architecture and boundaries

Agents API is a WordPress-shaped backend substrate for agent runtime behavior. It provides stable contracts, value objects, registries, and dispatcher abilities that product plugins can compose. It deliberately does **not** provide provider-specific model execution, product UI, concrete storage, workflow editors, or product-specific runtime policy.

## Layer boundary

```text
wp-ai-client / provider adapters  -> model calls and provider capability mapping
Agents API                        -> neutral agent runtime contracts and orchestration seams
Consumer plugins                  -> product UX, tools, stores, prompts, policies, workflows
```

The source tree mirrors that boundary:

- `src/Registry/` defines declarative agent registration and lookup.
- `src/Packages/` defines portable agent package manifests and artifact type registries.
- `src/Auth/` and `src/Runtime/class-wp-agent-execution-principal.php` define execution identity and authorization primitives.
- `src/Runtime/` defines message/request/result contracts, compaction, budgets, transcript persistence, and the conversation loop facade.
- `src/Tools/`, `src/Approvals/`, and `src/Consent/` define tool mediation, action policy, staged action approval, and consent policy contracts.
- `src/Memory/`, `src/Context/`, and `src/Guidelines/` define memory persistence contracts, context composition, authority conflict vocabulary, and the optional `wp_guideline` substrate polyfill.
- `src/Channels/` defines external-client/channel contracts and the canonical `agents/chat` ability dispatcher.
- `src/Workflows/` and `src/Routines/` define workflow specs, dispatch abilities, runner skeletons, and scheduled routine contracts.
- `src/Identity/`, `src/Workspace/`, and `src/Transcripts/` define durable identity, generic workspace identity, and conversation transcript storage seams.

## Core runtime flow

A typical consumer runtime composes these pieces as follows:

1. A plugin registers declarative agents on `wp_agents_api_init` with `wp_register_agent()`.
2. A caller reaches the runtime through an ability, channel, REST route, CLI command, webhook, cron action, or custom adapter.
3. The caller resolves an `WP_Agent_Execution_Principal` from WordPress session state, a bearer token, or another host-owned auth mechanism.
4. The consumer builds a `WP_Agent_Conversation_Request` with normalized messages, runtime context, tool declarations, optional principal, and optional `WP_Agent_Workspace_Scope`.
5. `WP_Agent_Conversation_Loop::run()` sequences turn execution around a caller-supplied provider/runner adapter.
6. Optional loop adapters handle compaction, tool mediation, completion policy, budgets, transcript persistence, locks, and lifecycle events.
7. The result is normalized through `WP_Agent_Conversation_Result` and returned to the caller or channel.

Agents API owns steps 1, 3 shape, 4 shape, 5 sequencing, and the neutral contracts for optional adapters. Consumers own actual provider dispatch, prompt assembly, concrete tools, durable stores, UI, and policy decisions.

## Public integration surfaces

Agents API exposes multiple integration layers:

- **WordPress hooks/actions/filters** for registration, policy providers, loop events, ability dispatch handlers, permissions, and context/memory sections. See [Extension points reference](extension-points-reference.md).
- **WordPress-style global functions** for registering agents, package artifact types, workflows, and routines.
- **Canonical Abilities API entries** for chat and workflow dispatch. See [Channels and bridges](channels-and-bridges.md) and [Workflows and routines](workflows-and-routines.md).
- **Interfaces** for stores, executors, policies, persisters, handlers, and recorders.
- **Immutable or normalized value objects** for portable contracts such as messages, tokens, grants, pending actions, memory metadata, workflow specs, routine specs, identity scopes, and workspace scopes.

## Persistence boundaries

The canonical repository defines persistence contracts, not concrete stores, except for small WordPress-option/transient helpers used by the generic channel bridge/session/idempotency implementations and the `wp_guideline` compatibility substrate.

Concrete persistence remains consumer-owned for:

- Memory store implementations.
- Transcript/session stores.
- Token and access grant stores.
- Pending action stores.
- Workflow stores and run history.
- Agent identity stores.
- Product-specific queues, audit records, and analytics.

Related design notes live in the existing proposal documents such as `docs/default-stores-companion.md`, but those documents are not the canonical contract surface.

## Failure model

The substrate generally follows these rules:

- Auth, token, caller-chain, schema, and value-object validation fail closed.
- Observer failures in lifecycle hooks are swallowed when observation must not affect model/tool execution.
- Store and adapter interfaces return explicit result values, booleans, `WP_Error`, or normalized error arrays depending on the contract.
- The conversation loop preserves transcript/tool integrity and returns early for bounded conditions such as budget exceedance or transcript lock contention.
- Consumers remain responsible for retries, durable queue policy, user-facing errors, and product-specific fallback behavior.

## Design constraints

Contributors should preserve these architectural constraints:

- Do not add provider-specific SDK calls to this repository.
- Do not add product admin screens, settings screens, dashboards, or product workflows.
- Do not depend on Data Machine, WordPress.com-only classes, or other product plugins.
- Prefer generic names such as `workspace`, `agent`, `tool`, `workflow`, and `routine` over site/product-specific language.
- Keep contracts JSON-friendly so they can cross REST, webhook, queue, and file boundaries.
- Keep concrete runtime policy behind filters, callbacks, or interfaces so consumers can replace it.