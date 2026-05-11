# Architecture Overview

Agents API is a WordPress-shaped substrate for agent runtime primitives. It is intentionally not a product plugin, provider client, admin UI, or durable runtime implementation. The bootstrap file (`agents-api.php`) loads a flat set of value objects, registries, interfaces, and optional bridges, then wires only two WordPress `init` callbacks: `WP_Agents_Registry::init` and `WP_Guidelines_Substrate::register`.

## Layer boundary

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts,
                tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy,
                storage/materialization policy
```

This boundary is enforced by source shape and tests:

- `agents-api.php` requires only files from this repository's `src/` tree.
- `tests/bootstrap-smoke.php` asserts that legacy Data Machine namespaces and product classes are not loaded and that the `src/` directory contains the expected agent-native modules.
- `tests/no-product-imports-smoke.php` guards against product imports.

## Boot sequence

1. WordPress loads `agents-api.php` as the plugin entry point.
2. The plugin exits early if `AGENTS_API_LOADED` is already defined.
3. It defines:
   - `AGENTS_API_LOADED`
   - `AGENTS_API_PATH`
   - `AGENTS_API_PLUGIN_FILE`
4. It requires source modules in dependency order: registry and packages first, then auth, context, runtime, tools, memory, guidelines, channels, workflows, routines, and workspace support.
5. It hooks:
   - `init` priority `10`: `WP_Agents_Registry::init`
   - `init` priority `9`: `WP_Guidelines_Substrate::register`

The bootstrap does not create agent rows, create storage, register product UI, or dispatch provider requests.

## Module inventory

| Module | Source directory | Responsibility |
| --- | --- | --- |
| Registry | `src/Registry` | Agent definition value object, in-memory registry, and `wp_register_agent()` helpers. |
| Packages | `src/Packages` | Agent package and package artifact contracts, artifact type registry, adoption/diff result value objects. |
| Auth | `src/Auth` | Access grants, bearer tokens, token stores, caller context, capability ceilings, and authorization policy contracts. |
| Runtime | `src/Runtime` | Messages, execution principals, conversation request/result contracts, compaction, iteration budgets, and conversation loop sequencing. |
| Tools | `src/Tools` | Tool declarations, tool calls, parameter normalization, execution core, execution result, source registry, visibility and action policy. |
| Approvals | `src/Approvals` | Pending action, approval decision, pending action status, resolver, handler, and store contracts. |
| Consent | `src/Consent` | Consent operation vocabulary, decision value object, policy interface, and conservative default policy. |
| Context | `src/Context` | Memory/context source registry, section registry, injection policy, retrieved context authority, conflict kinds and resolver. |
| Memory | `src/Memory` | Store interface, scope, metadata, query, capabilities, validation, list/read/write result value objects. |
| Transcripts | `src/Transcripts` | Conversation store and lock contracts plus a null lock. |
| Channels | `src/Channels` | External message normalization, channel base class, session maps, webhook signatures, idempotency, and remote bridge primitives. |
| Workflows | `src/Workflows` | Workflow specs, validator, bindings, runner, registry, store/recorder contracts, Action Scheduler bridge, and workflow abilities. |
| Routines | `src/Routines` | Routine value object, registry, Action Scheduler bridge, and listener/sync helpers. |
| Guidelines | `src/Guidelines` | Optional `wp_guideline` / `wp_guideline_type` substrate polyfill and capability mapping. |
| Identity | `src/Identity` | Identity scope, materialized identity, and identity store contract. |
| Workspace | `src/Workspace` | Workspace identity value object shared across memory, transcripts, persistence, and audit adapters. |

## Design principles for contributors

- Keep the substrate provider-neutral. Provider/model prompt execution belongs to `wp-ai-client` or a consumer adapter.
- Keep product policy out of shared contracts. Product UX, concrete tools, workflow editors, durable histories, retention, and storage adapters belong to consumers.
- Prefer value objects, interfaces, registries, and filters over product-specific implementations.
- Preserve WordPress lifecycle conventions. Registration happens during explicit init-style hooks; reads are safe only after the relevant lifecycle has run.
- Fail closed at request boundaries. Auth, malformed caller context, missing handlers, and invalid workflow specs return `null`, `WP_Error`, or structured errors rather than guessing.
- Treat observer surfaces as non-authoritative. Event hooks are for logging/tracing and their failures are swallowed where source comments say they cannot change runtime behavior.

## Related pages

- [Agent registry and packages](registry-and-packages.md)
- [Runtime loop and tool mediation](runtime-and-tools.md)
- [Auth, consent, context, and memory](auth-consent-context-memory.md)
- [Channels, workflows, routines, and operations](channels-workflows-operations.md)
