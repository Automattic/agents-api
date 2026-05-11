# Agents API Documentation

Developer-facing documentation for Agents API, a WordPress-shaped substrate for agent registration, runtime contracts, channels, workflows, auth, memory, and related extension points.

## Quick Navigation

### Architecture And Runtime

- [Architecture](architecture.md) - module inventory, bootstrap lifecycle, substrate boundary, extension principles, and operational workflow.
- [Registry and Packages](registry-and-packages.md) - agent registration, `WP_Agent`, package manifests, package artifacts, and artifact type registration.
- [Runtime and Tools](runtime-and-tools.md) - conversation loop, request/result objects, tool declarations, mediation, policies, budgets, compaction, and events.

### Security, Context, And Operations

- [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) - bearer-token auth, access grants, execution principals, consent decisions, memory/context registries, and guideline boundaries.
- [Channels, Workflows, and Operations](channels-workflows-operations.md) - direct channels, canonical chat payloads, bridge queues, workflows, routines, transcripts, approvals, and scheduling.

### Focused Design Notes

- [External Clients, Channels, And Bridges](external-clients.md) - external conversation surfaces and bridge/channel integration shapes.
- [Bridge Authorization And Onboarding](bridge-authorization.md) - connector identity, bridge authorization dimensions, and onboarding boundary.
- [Remote Bridge Protocol](remote-bridge-protocol.md) - queue-first remote bridge flow, storage, pending items, and acknowledgement semantics.
- [Default Stores Companion Proposal](default-stores-companion.md) - companion package boundary for guideline-backed memory, markdown memory, and transcript stores.

## Documentation Structure

```text
docs/
+-- README.md                         # This navigation index
+-- architecture.md                   # Substrate architecture and module inventory
+-- registry-and-packages.md          # Agent and package registration contracts
+-- runtime-and-tools.md              # Runtime loop and tool mediation contracts
+-- auth-consent-context-memory.md    # Auth, consent, context, and memory contracts
+-- channels-workflows-operations.md  # Channels, workflows, routines, transcripts, approvals
+-- external-clients.md               # Existing external client architecture note
+-- bridge-authorization.md           # Existing bridge authorization note
+-- remote-bridge-protocol.md         # Existing remote bridge protocol note
+-- default-stores-companion.md       # Existing default stores companion proposal
```

Agents API has one docs audience: developers integrating with or extending the substrate. Keep new documentation in this topic-oriented `docs/` tree.

## Source And Test Evidence

Primary source areas covered by this documentation:

- `agents-api.php`
- `README.md`
- `composer.json`
- `src/Registry/`, `src/Packages/`, `src/Runtime/`, `src/Tools/`
- `src/Auth/`, `src/Consent/`, `src/Context/`, `src/Memory/`, `src/Guidelines/`
- `src/Channels/`, `src/Workflows/`, `src/Routines/`, `src/Transcripts/`, `src/Approvals/`

Representative smoke tests in `composer.json` cover registry, subagents, authorization, caller context, consent, context registry/authority, memory metadata, workspace scope, guidelines substrate, conversation loop, compaction, budgets, events, tool execution, policies, channels, remote bridge, webhook safety, workflows, routines, approvals, transcript locks, and product-boundary behavior.

## Future Coverage

The current topic pages document each major module family at integration-contract depth. Future updates should add focused reference pages when a module needs method-level or field-level detail, especially:

- `Approvals/` pending action lifecycle and handler permission semantics.
- `Guidelines/` substrate internals beyond the memory/context boundary.
- `Transcripts/` store and lock contracts.
- `Identity/` materialization details.
- Exhaustive field references for runtime value objects, workflow specs/results, bridge queue items, and Action Scheduler bridge hooks.
