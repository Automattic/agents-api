# Agents API developer documentation

Agents API is a WordPress-shaped substrate for durable agent runtime behavior. It provides contracts, value objects, registries, canonical abilities, and small bridge services that consumer plugins compose into product-specific agent runtimes.

This documentation surface is generated from the repository source at `main` (`387b12eed4e090ab4aef6ffe58a3f1fe5c255f97`). It complements the root [`README.md`](../README.md) by organizing the codebase into focused developer topics.

## Start here

1. [Architecture and boundaries](architecture.md) — package scope, bootstrap lifecycle, module map, design principles, and test strategy.
2. [Agent registry and packages](agent-registry-and-packages.md) — `WP_Agent`, registration helpers, package artifacts, identity stores, and adoption boundaries.
3. [Runtime conversation loop](runtime-conversation-loop.md) — message envelopes, requests/results, compaction, iteration budgets, transcript locks, events, and persisters.
4. [Tools, abilities, and policy](tools-abilities-and-policy.md) — canonical chat/workflow abilities, tool declarations/calls/results, visibility policy, and action policy resolution.
5. [Authentication, authorization, and consent](auth-authorization-consent.md) — access grants, tokens, execution principals, caller context headers, capability ceilings, authorization policy, and consent decisions.
6. [Memory, context, guidelines, and transcripts](memory-context-transcripts.md) — memory store contracts, provenance metadata, context registries, conflict resolution, workspace scope, guidelines substrate, and transcript stores.
7. [Channels and remote bridges](channels-and-bridges.md) — direct channel base class, external message/session/idempotency helpers, bridge queue/pending/ack primitives, and protocol docs.
8. [Workflows and routines](workflows-and-routines.md) — workflow specs, validation, runner lifecycle, canonical workflow abilities, Action Scheduler bridges, and long-running routines.
9. [Approvals and pending actions](approvals-and-pending-actions.md) — pending action value objects, store/resolver/handler contracts, approval envelopes, audit state, and open observer work.
10. [Source inventory and coverage](source-inventory.md) — bootstrap inventory, public surface map, tests used as evidence, and documented future coverage.

## Committed scope

This bootstrap pass documents the complete substrate surface present in the repository:

- plugin bootstrap and WordPress lifecycle hooks;
- agent registration, package, artifact, identity, and workspace contracts;
- runtime message/request/result/loop/compaction/budget/transcript contracts;
- tool-call mediation, visibility policy, action policy, and canonical abilities;
- access grants, token authentication, caller context, authorization, and consent;
- memory/context/source registries, provenance metadata, guidelines substrate, and transcript stores;
- channels, external client helpers, remote bridge primitives, workflows, routines, approvals, and pending actions;
- build/test workflow, optional Action Scheduler integration, and known future coverage from open issues.

Existing focused docs remain part of the documentation set:

- [External Clients, Channels, And Bridges](external-clients.md)
- [Bridge Authorization And Onboarding](bridge-authorization.md)
- [Remote Bridge Protocol](remote-bridge-protocol.md)
- [Default Stores Companion Proposal](default-stores-companion.md)

## Conventions used in these docs

- **Substrate** means code that belongs in `Automattic/agents-api`: reusable contracts, value objects, registries, neutral workflow plumbing, and generic helper services.
- **Consumer** means a product plugin or host runtime that supplies concrete providers, tools, storage, UI, policy, and scheduling/materialization behavior.
- Source evidence is cited as repository paths, with smoke tests named where they prove a behavior.
- Examples are intentionally JSON/PHP-array friendly because the substrate stores and transports neutral arrays rather than product-specific objects.
