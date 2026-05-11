# Agents API Developer Documentation

This developer documentation is a first-run, source-inventory-backed guide to the Agents API repository. It is intended for contributors extending the substrate and for consumer plugins implementing against its public contracts.

Agents API is a WordPress-shaped backend substrate for agent runtime behavior. It owns generic registries, value objects, dispatcher contracts, policy seams, memory/context/transcript interfaces, workflow/routine primitives, and external-client plumbing. Consumers own concrete model providers, prompt assembly, product UI, durable storage, workflow runtimes, concrete tools, platform integrations, and product policy.

## Committed documentation scope

Start with the architecture overview, then use the topic pages that match the module you are extending or integrating.

| Page | Use it for | Primary source areas |
| --- | --- | --- |
| [Architecture Overview](architecture.md) | Repository boundary, bootstrap sequence, module map, runtime data flow, and extension philosophy. | `README.md`, `agents-api.php`, `composer.json`, `src/**`, `tests/no-product-imports-smoke.php` |
| [Agent Registry And Definitions](registry-and-agents.md) | Registering agents, registry lifecycle, `WP_Agent`, public helper functions, duplicate/source diagnostics. | `src/Registry/**`, `tests/registry-smoke.php`, `tests/bootstrap-smoke.php`, `tests/subagents-smoke.php` |
| [Runtime Loop And Tool Mediation](runtime-and-tools.md) | Conversation loop sequencing, runtime value objects, lifecycle events, budgets, compaction, tool declarations, tool execution, visibility/action policy. | `src/Runtime/**`, `src/Tools/**`, conversation/tool smoke tests |
| [Channels, Bridges, And Canonical Abilities](channels-and-abilities.md) | `agents/chat`, channel subclassing, external message context, session continuity, bridge and idempotency primitives. | `src/Channels/**`, `tests/agents-chat-ability-smoke.php`, `tests/channels-smoke.php`, `tests/remote-bridge-smoke.php`, `tests/webhook-safety-smoke.php` |
| [Workflows And Routines](workflows-and-routines.md) | Workflow spec validation, sequential runner lifecycle, workflow abilities, Action Scheduler bridge, routine declarations. | `src/Workflows/**`, `src/Routines/**`, workflow/routine smoke tests |
| [Auth, Storage Contracts, Context, And Policy](auth-storage-and-policy.md) | Execution principals, bearer tokens, caller context, capability ceilings, workspace scope, memory/context/transcript stores, consent, approvals, guidelines. | `src/Auth/**`, `src/Workspace/**`, `src/Memory/**`, `src/Context/**`, `src/Transcripts/**`, `src/Consent/**`, `src/Approvals/**`, `src/Guidelines/**` |

Existing companion pages remain part of the docs surface and are linked from the relevant topic pages:

- [External Clients, Channels, And Bridges](../external-clients.md)
- [Remote Bridge Protocol](../remote-bridge-protocol.md)
- [Bridge Authorization](../bridge-authorization.md)
- [Default Stores Companion Proposal](../default-stores-companion.md)

## Source inventory reconciliation

The bootstrap pass inventoried the repository tree, README, existing docs, key source modules, and smoke tests. The committed pages cover the major source areas as follows:

- Agent registration and lookup: documented in [Agent Registry And Definitions](registry-and-agents.md).
- Public helpers, hooks, filters, and abilities: documented across registry, channel/ability, workflow, runtime/tool, and policy pages.
- Runtime message/request/result/principal contracts, loop sequencing, lifecycle events, compaction, budgets, and failure modes: documented in [Runtime Loop And Tool Mediation](runtime-and-tools.md) and [Auth, Storage Contracts, Context, And Policy](auth-storage-and-policy.md).
- Tool declarations, parameter normalization, executor contract, visibility policy, and action policy: documented in [Runtime Loop And Tool Mediation](runtime-and-tools.md).
- External channels, normalized external messages, bridge/session/idempotency primitives, and `agents/chat`: documented in [Channels, Bridges, And Canonical Abilities](channels-and-abilities.md), with links to existing bridge protocol/authorization docs.
- Workflow spec, validator, bindings, runner, registry, run recorder/store, canonical workflow abilities, and routines: documented in [Workflows And Routines](workflows-and-routines.md).
- Auth grants, tokens, caller context, authorization policy, capability ceilings, consent, approvals, workspace, memory, context, transcript, and guideline contracts: documented in [Auth, Storage Contracts, Context, And Policy](auth-storage-and-policy.md).
- Bootstrap, package boundaries, module ownership, tests, and contributor-facing philosophy: documented in [Architecture Overview](architecture.md).

## Future coverage

The committed scope covers the full repository at a practical module level. Future maintenance passes should add deeper reference pages when these areas become active integration targets or change materially:

- Package artifacts and adoption helpers (`src/Packages/**`): included in the architecture map but not given a dedicated reference page because the current bootstrap budget prioritized runtime, channel, workflow, storage, and policy contracts used by most consumers.
- Identity store value objects (`src/Identity/**`): included under auth/storage architecture, but a dedicated identity-materialization page would be useful once consumer adapters depend on the contract in detail.
- Markdown section compaction internals (`WP_Agent_Markdown_Section_Compaction_Adapter`, compaction conservation/item classes): summarized in runtime docs; a dedicated algorithm page should be added if contributors need to modify compaction behavior.
- Package/default-store proposals: existing proposal docs are linked, but they should be reconciled into permanent reference docs if those companion packages are implemented.
- Operational playbooks: `composer test` is documented, but release, versioning, and CI-troubleshooting playbooks should be added when repository release automation is formalized.

## Verification

The repository test command is:

```bash
composer test
```

The documentation links in this index point only to committed files in `docs/developer/` or existing committed pages in `docs/`.
