# Agents API technical documentation

This documentation is the developer-facing living map for Agents API. It is organized around the repository's source modules under `src/` and the operational contracts exercised by `tests/`.

Agents API is a WordPress-shaped substrate for durable agent behavior. It owns reusable contracts, value objects, registries, canonical ability dispatchers, and orchestration seams. Consumer plugins own concrete provider calls, product UI, storage adapters, workflow products, tools, policy UX, and runtime-specific behavior.

## Start here

1. [Architecture and boundaries](architecture.md) — the substrate/consumer/provider split and how source modules connect.
2. [Bootstrap lifecycle](bootstrap-lifecycle.md) — plugin load order, registration hooks, required files, and compatibility expectations.
3. [Extension points reference](extension-points-reference.md) — public functions, hooks, filters, abilities, and observer surfaces.
4. [Testing and operations](testing-and-operations.md) — local smoke suite, CI workflow, runtime dependencies, and rollout constraints.

## Source-module guides

- [Registry and packages](registry-and-packages.md) — declarative agents, in-memory registries, packages, artifacts, adoption contracts, and source provenance.
- [Auth, permissions, and principals](auth-permissions-and-principals.md) — access grants, bearer tokens, caller context, execution principals, capability ceilings, and WordPress authorization.
- [Runtime and conversation loop](runtime-conversation-loop.md) — message envelopes, requests/results, loop sequencing, compaction, budgets, transcript persistence, events, and failure behavior.
- [Tools, approvals, and consent](tools-approvals-and-consent.md) — tool declarations, visibility/action policy, tool execution mediation, pending actions, and explicit consent decisions.
- [Memory, context, and guidelines](memory-context-guidelines.md) — memory stores, provenance metadata, source registries, context sections, authority conflict resolution, and the `wp_guideline` polyfill.
- [Channels and bridges](channels-and-bridges.md) — external message normalization, canonical chat ability, direct channel base class, remote bridge queue, sessions, idempotency, and webhook signatures.
- [Workflows and routines](workflows-and-routines.md) — workflow specs, validation, bindings, runner lifecycle, canonical workflow abilities, Action Scheduler bridges, and scheduled agent routines.
- [Identity, workspace, and transcripts](identity-workspace-transcripts.md) — materialized agent identity, generic workspace scope, transcript store contracts, locks, provider continuity, and persistence boundaries.

## Design principles for contributors

- Keep the substrate generic. Do not import product plugins or encode product vocabulary in generic contracts.
- Prefer value objects, interfaces, registries, and adapter seams over concrete storage or provider implementations.
- Treat WordPress hooks and Abilities API entries as extension surfaces, not product UX.
- Fail closed for auth, caller-chain, permission, and schema validation failures.
- Keep persistence contracts narrow and JSON-friendly so consumers can use custom tables, posts, files, queues, remote services, or in-memory adapters.
- Document every new public function, class, hook, filter, ability, and wire contract in this documentation tree when source behavior changes.

## Coverage note

This bootstrap pass documents the full source tree present at commit `6ee7e43249c3cc6f7781c3ad9c953a72080740b9`: Registry, Packages, Auth, Runtime, Tools, Approvals, Consent, Memory, Context, Guidelines, Channels, Workflows, Routines, Transcripts, Identity, Workspace, bootstrap lifecycle, canonical abilities, hooks/filters, smoke tests, and operational workflows. Existing proposal documents under `docs/` remain as historical design notes; this `docs/technical/` tree is the navigable source-derived documentation surface.