# Agents API Technical Documentation

This documentation tree is the first-run technical bootstrap for the Agents API repository. It is source-derived from `README.md`, `agents-api.php`, `composer.json`, the `src/**` modules, existing `docs/**` bridge/client notes, and the pure-PHP smoke tests under `tests/**`.

Agents API is a WordPress-shaped substrate for durable agent runtime behavior. It provides contracts, value objects, registries, optional bridges, and generic runtime sequencing. It intentionally does not provide a product UI, provider-specific model client, concrete tool implementations, durable workflow history, or product storage policy.

## Start here

1. [Architecture overview](architecture.md) — repository boundaries, boot sequence, module inventory, and design principles.
2. [Agent registry and packages](registry-and-packages.md) — agent registration lifecycle, public helper functions, `WP_Agent`, registry behavior, and package artifact contracts.
3. [Runtime loop and tool mediation](runtime-and-tools.md) — conversation loop lifecycle, runtime options, events, tool declarations/execution, tool policy, and pending action approval.
4. [Auth, consent, context, and memory](auth-consent-context-memory.md) — execution principals, token auth, caller context, consent operations, workspace/identity, memory/context registries, memory stores, transcripts, and guideline substrate.
5. [Channels, workflows, routines, and operations](channels-workflows-operations.md) — external clients, channel pipeline, chat payloads, bridge boundary, workflow runner and abilities, routines, tests, and operational failure modes.

## Existing focused docs

These pre-existing pages remain useful for deeper protocol and proposal details:

- [External Clients, Channels, And Bridges](../external-clients.md)
- [Remote Bridge Protocol](../remote-bridge-protocol.md)
- [Bridge Authorization](../bridge-authorization.md)
- [Default Stores Companion Proposal](../default-stores-companion.md)

## Committed bootstrap scope

This bootstrap covers the major source inventory present at `73220bde012f2e736fd047b5640d0f4d4461e6f4`:

- Plugin bootstrap constants, require order, and WordPress hooks in `agents-api.php`.
- Module boundaries for `Registry`, `Packages`, `Auth`, `Runtime`, `Tools`, `Approvals`, `Consent`, `Context`, `Memory`, `Transcripts`, `Channels`, `Workflows`, `Routines`, `Guidelines`, `Identity`, and `Workspace`.
- Public registration helpers: `wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, `wp_unregister_agent()`.
- Public workflow helpers and abilities: `wp_register_workflow()`, `wp_get_workflow()`, `agents/run-workflow`, `agents/validate-workflow`, `agents/describe-workflow`, and `AgentsAPI\AI\Workflows\register_workflow_handler()`.
- WordPress hooks and filters surfaced by source: `wp_agents_api_init`, `agents_api_loop_event`, `agents_api_execution_principal`, `wp_agent_channel_chat_ability`, `wp_agent_workflow_handler`, `wp_agent_workflow_step_handlers`, `agents_run_workflow_permission`, `agents_validate_workflow_permission`, `agents_run_workflow_dispatch_failed`, `agents_api_tool_action_policy`, and guideline-substrate filters.
- Runtime value objects and contracts for messages, conversation requests/results, execution principals, completion policy, transcript persistence, compaction, iteration budgets, and conversation loop sequencing.
- Tool contracts for declarations, calls, parameter validation, execution, source registry, visibility policy, access policy, and action policy.
- Approval, consent, auth, caller-context, workspace, identity, memory, transcript, context authority, guideline, channel, bridge, workflow, and routine contracts.
- Storage boundaries: host-owned access/token stores, identity stores, memory stores, transcript stores/locks, pending action stores, workflow stores/run recorders, bridge stores, and session/idempotency stores.
- Failure behavior documented in source and smoke tests: lifecycle misuse, duplicate registration, token rejection, malformed caller context, missing abilities, missing workflow handlers, invalid workflow results, recorder start failure, transcript lock contention, tool validation errors, executor exceptions, budget exceedance, and optional Action Scheduler absence.
- Operational workflow: PHP `>=8.1`, WordPress `>=7.0`, optional Action Scheduler, and `composer test` smoke suite.

## Future coverage

The pages above intentionally prioritize a navigable source-derived surface over exhaustive per-class API reference. Future maintenance passes should add these focused references as the contracts stabilize:

- **Per-class method tables for every value object** — useful for generated API reference, but too verbose for this bootstrap's architecture-first scope.
- **Package artifact helper reference** — the current docs cover the boundary and lifecycle; a later pass should document every artifact type helper and result field from `src/Packages/*`.
- **Routine helper reference** — the current docs cover module ownership and operational boundary; a later pass should expand each routine registration helper and Action Scheduler listener contract.
- **Detailed workflow spec schema page** — workflow runner, abilities, and failure modes are covered here; a dedicated page should enumerate every accepted spec field and validator error code from `WP_Agent_Workflow_Spec_Validator`.
- **Detailed channel/bridge protocol examples** — this tree links to existing bridge docs; future work should add concrete subclass examples for common direct channels after source examples exist.
- **Generated hook/filter index** — major hooks and filters are listed in committed scope; a generated index would reduce drift as new hooks are added.

## Verification notes

The index links only to committed files under `docs/technical/` plus existing committed pages in `docs/`. Each major inventory item is documented in one of the topic pages above or listed in future coverage with the reason it remains out of scope for this first bootstrap.
