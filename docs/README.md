# Agents API Developer Documentation

Agents API is a WordPress-shaped substrate for durable agent runtime behavior. It defines provider-neutral contracts, value objects, registries, and orchestration seams that product plugins and runtime adapters can build on without importing product code into the substrate.

This documentation is a first-pass technical bootstrap generated from the plugin bootstrap (`agents-api.php`), source modules under `src/`, smoke tests under `tests/`, and the existing README/docs. It is intended as living developer documentation: update the relevant topic page when changing a contract, lifecycle, ability, hook, filter, or storage boundary.

## Coverage map

| Area | Source modules | Documentation |
| --- | --- | --- |
| Bootstrap, package boundary, public hooks, testing | `agents-api.php`, `composer.json`, `tests/bootstrap-smoke.php`, `tests/no-product-imports-smoke.php` | [Architecture and boundaries](architecture.md), [Development and operations](development.md) |
| Agent and package registration | `src/Registry`, `src/Packages` | [Registries, packages, and identity](registries-packages-identity.md) |
| Execution principals, bearer tokens, grants, caller chain context, authorization | `src/Auth`, `src/Runtime/class-wp-agent-execution-principal.php` | [Authorization, principals, and consent](auth-consent.md) |
| Runtime loop, messages, results, compaction, budgets, transcript persisters | `src/Runtime`, `src/Transcripts` | [Runtime conversation lifecycle](runtime.md) |
| Tools, visibility policy, action policy, pending approvals | `src/Tools`, `src/Approvals` | [Tools and approvals](tools-approvals.md) |
| Memory, context registries, retrieved context authority, guideline polyfill | `src/Memory`, `src/Context`, `src/Guidelines`, `src/Workspace` | [Memory, context, and guidelines](memory-context-guidelines.md) |
| Direct channels, normalized external messages, remote bridges, webhook safety | `src/Channels`, existing bridge docs | [Channels and bridges](channels-bridges.md), [External clients](external-clients.md), [Remote bridge protocol](remote-bridge-protocol.md), [Bridge authorization](bridge-authorization.md) |
| Workflows, workflow abilities, Action Scheduler bridges, routines | `src/Workflows`, `src/Routines` | [Workflows and routines](workflows-routines.md) |
| Build/test workflow and CI docs-agent harness | `.github/workflows`, `tests/playground-ci`, `composer.json` | [Development and operations](development.md) |

## How to navigate

1. Start with [Architecture and boundaries](architecture.md) to understand what Agents API owns and what consumers own.
2. Use [Runtime conversation lifecycle](runtime.md) for message/result contracts, loop execution, compaction, budgets, and transcript seams.
3. Use [Authorization, principals, and consent](auth-consent.md) before exposing REST, channel, bridge, CLI, cron, or cross-site agent access.
4. Use [Tools and approvals](tools-approvals.md) when adding tool sources, execution adapters, action policy, or human review flows.
5. Use [Memory, context, and guidelines](memory-context-guidelines.md) when implementing memory stores, context injection, retrieved-context conflict resolution, or guideline-backed storage.
6. Use [Channels and bridges](channels-bridges.md) when connecting Slack, Telegram, Matrix, email, mobile clients, or other external conversation surfaces.
7. Use [Workflows and routines](workflows-routines.md) for workflow specs, validation, runner behavior, canonical workflow abilities, scheduled workflow triggers, and persistent scheduled routines.
8. Use [Registries, packages, and identity](registries-packages-identity.md) for registration helpers, package artifacts, adoption contracts, identity scopes, and workspace scopes.
9. Use [Development and operations](development.md) for local test commands, CI expectations, optional dependencies, and documentation maintenance.

## Existing focused documents

These existing pages remain canonical for their narrower topics and are cross-linked from the broader pages:

- [External Clients, Channels, And Bridges](external-clients.md)
- [Remote Bridge Protocol](remote-bridge-protocol.md)
- [Bridge Authorization And Onboarding](bridge-authorization.md)
- [Default Stores Companion Proposal](default-stores-companion.md)

## Bootstrap scope note

This bootstrap covers the repository at the module and public-contract level. It intentionally does not duplicate every inline PHPDoc block or list every getter/setter on every value object. When changing behavior, update the focused topic page and keep inline source comments as the method-level source of truth.
