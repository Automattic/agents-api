# Agents API Technical Documentation

This directory is the developer-facing living documentation surface for Agents API. It is generated from the repository source, smoke tests, existing README, and module layout at `main` head `73220bde012f2e736fd047b5640d0f4d4461e6f4`.

Agents API is a WordPress-shaped, provider-neutral substrate for agent runtime behavior. It owns reusable contracts, value objects, registries, dispatchers, and policy seams; consumer plugins own product UX, concrete provider/model calls, concrete tools, persistent materialization, and product-specific policy.

## Committed documentation scope

Read these pages in order when onboarding to the codebase:

1. [Architecture Overview](architecture.md) — package boundary, bootstrap model, module inventory, extension points, storage boundaries, failure principles, and test map.
2. [Registry And Agent Definitions](registry-and-agents.md) — the `WP_Agent` value object, `WP_Agents_Registry`, helper functions, lifecycle hook, subagents, and consumer materialization boundary.
3. [Runtime, Tools, And Approvals](runtime-tools-and-approvals.md) — conversation loop, runtime value objects, tool declaration/visibility/action policy, iteration budgets, events, transcript persistence seams, and pending approvals.
4. [Channels And Remote Bridge](channels-and-bridge.md) — the canonical `agents/chat` ability, `WP_Agent_Channel` pipeline, session continuity, external message shape, webhook/idempotency primitives, and bridge queue contracts.
5. [Workflows And Routines](workflows-and-routines.md) — workflow specs, validation, runner lifecycle, workflow abilities, storage/scheduling seams, and long-running scheduled routine contracts.

Existing focused docs remain part of the documentation set:

- [Bridge Authorization](bridge-authorization.md)
- [Default Stores Companion Proposal](default-stores-companion.md)
- [External Clients, Channels, And Bridges](external-clients.md)
- [Remote Bridge Protocol](remote-bridge-protocol.md)

## Source inventory reconciliation

The bootstrap pass inventoried the source tree and documented the major consumer-facing surfaces:

- **Bootstrap and registry:** `agents-api.php`, `src/Registry/**`, and registry smoke tests are covered in [Architecture Overview](architecture.md) and [Registry And Agent Definitions](registry-and-agents.md).
- **Runtime orchestration:** `src/Runtime/**` and conversation-loop tests are covered in [Runtime, Tools, And Approvals](runtime-tools-and-approvals.md).
- **Tools and approvals:** `src/Tools/**`, `src/Approvals/**`, and action/tool/approval tests are covered in [Runtime, Tools, And Approvals](runtime-tools-and-approvals.md).
- **Channels and bridge:** `src/Channels/**`, chat ability tests, channel tests, bridge tests, and webhook safety tests are covered in [Channels And Remote Bridge](channels-and-bridge.md), with additional protocol detail in [Remote Bridge Protocol](remote-bridge-protocol.md) and [Bridge Authorization](bridge-authorization.md).
- **Workflows and routines:** `src/Workflows/**`, `src/Routines/**`, workflow tests, ability tests, and routine tests are covered in [Workflows And Routines](workflows-and-routines.md).
- **Auth, permissions, and failure behavior:** `src/Auth/**` and authorization/caller-context tests are summarized in [Architecture Overview](architecture.md) and referenced by the runtime/channel/workflow pages where permissions are applied.
- **Memory, context, workspace, identity, guidelines, packages, and transcripts:** the architecture page inventories these modules and documents their boundaries at a high level; existing README sections and companion docs contain additional detail for memory/context/guidelines/default stores.

## Future coverage

The following areas are intentionally left for follow-up topic pages because this bootstrap focused on the contracts most consumers implement first:

- **Memory and context reference:** full method-by-method coverage for `src/Memory/**`, `src/Context/**`, `src/Workspace/**`, and context authority/conflict resolution. Reason: broad public surface with enough value objects for a dedicated page.
- **Auth reference:** detailed `WP_Agent_Access_Grant`, token, caller-context header, capability-ceiling, and authorization-policy examples. Reason: summarized here, but security-sensitive integrations deserve a standalone reference.
- **Guideline substrate internals:** `wp_guideline`/`wp_guideline_type` registration, capabilities, metadata, and polyfill disablement. Reason: already summarized in the root README; should be split into a dedicated storage page.
- **Packages and artifacts:** package, artifact, adoption, and diff callback contracts. Reason: public but less central to first-run runtime/channel/workflow integration.
- **Transcripts and identity stores:** conversation store/lock and identity materialization interfaces. Reason: storage-adapter implementers need a focused reference with concrete implementation notes.

## Development and tests

Run the smoke suite with:

```bash
composer test
```

The smoke tests listed in `composer.json` are the current executable contract suite. When adding a public class, update `agents-api.php` so the direct bootstrap loads it in dependency order, and add or update the relevant docs page when behavior changes.
