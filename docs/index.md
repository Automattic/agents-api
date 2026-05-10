# Agents API Technical Documentation

Agents API is a WordPress-shaped substrate for durable agent runtime behavior. It provides contracts, value objects, registries, policy seams, and canonical ability dispatchers that consumer plugins can compose into concrete agent products.

Use this documentation as the living developer map for the repository. Source code remains the authority; these pages describe the code paths, extension points, contracts, storage seams, and operational workflows exposed by the package.

## Start here

- [Architecture overview](architecture.md) — package boundaries, source tree, runtime layers, and design principles.
- [Bootstrap and lifecycle](bootstrap-lifecycle.md) — plugin load order, WordPress hooks, ability registration, optional integrations, and testing bootstrap behavior.
- [Extension points and public surface](extension-points.md) — public helper functions, WordPress hooks and filters, canonical abilities, and consumer-owned seams.

## Module documentation

- [Registry, agents, packages, and artifacts](registry-packages.md) — `WP_Agent`, `WP_Agents_Registry`, package manifests, artifact declarations, and adoption boundaries.
- [Runtime, messages, conversation loop, and compaction](runtime-conversation.md) — message envelopes, execution principals, conversation requests/results, loop sequencing, budgets, transcript persistence, events, and compaction.
- [Tools, action policy, approvals, and consent](tools-approvals-consent.md) — runtime tools, tool visibility, direct/preview/forbidden action policy, pending actions, and consent policy.
- [Auth, permissions, caller context, and bridge authorization](auth-permissions.md) — access grants, bearer tokens, capability ceilings, WordPress authorization, cross-site caller context, and bridge credential boundaries.
- [Memory, context, guidelines, and workspace scope](memory-context-guidelines.md) — memory registry, composable context, retrieved-context authority, memory metadata, store capabilities, guideline substrate, and workspace identity.
- [Channels, external clients, and remote bridges](channels-bridges.md) — direct channel base class, canonical chat ability, external message normalization, session maps, webhook/idempotency helpers, and remote bridge queue semantics.
- [Workflows and routines](workflows-routines.md) — workflow specs, validation, bindings, runner, stores/recorders, canonical workflow abilities, Action Scheduler bridges, and persistent routines.
- [Identity, transcripts, storage, and persistence boundaries](identity-transcripts-storage.md) — materialized identity contracts, transcript stores/locks, provider continuity fields, and store ownership rules.
- [Testing, CI, and operational workflows](testing-operations.md) — smoke test suite, Composer scripts, CI, docs-agent workflow, and operational failure behavior.

## Existing companion documents

These pre-existing documents remain part of the documentation set and are linked from the module pages where relevant:

- [External Clients, Channels, And Bridges](external-clients.md)
- [Bridge Authorization And Onboarding](bridge-authorization.md)
- [Remote Bridge Protocol](remote-bridge-protocol.md)
- [Default Stores Companion Proposal](default-stores-companion.md)

## Source coverage

This bootstrap scaffold is organized around the repository source tree at `src/**` and the current smoke tests under `tests/**`. It covers the major public modules requested for the initial documentation pass: Registry, Packages, Auth, Runtime, Tools, Approvals, Consent, Memory, Context, Guidelines, Channels, Workflows, Routines, Transcripts, Identity, Workspace, bootstrap lifecycle, canonical abilities, hooks/filters, testing, and operational workflows.
