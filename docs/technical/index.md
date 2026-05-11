# Agents API Technical Documentation

This documentation tree is a first-run, developer-facing bootstrap for Agents API. It is generated from the repository source, existing docs, and smoke-test coverage at `73220bde012f2e736fd047b5640d0f4d4461e6f4`.

Agents API is a WordPress-shaped substrate for durable agent runtime behavior. It provides generic contracts, value objects, registries, and dispatch plumbing for agent runtimes while keeping provider execution, product UI, concrete storage, concrete tools, and product-specific policy in consumer plugins.

## Documentation map

Read these pages in order when onboarding to the codebase, or jump to the contract family you are integrating:

1. [Architecture](architecture.md) — repository module inventory, bootstrap lifecycle, ownership boundaries, extension principles, operational/test workflow, and future coverage inventory.
2. [Registry and Packages](registry-and-packages.md) — `WP_Agent`, agent registration helpers, package manifests, package artifacts, artifact type registry, and registry failure modes.
3. [Runtime and Tools](runtime-and-tools.md) — conversation loop, messages/requests/results, runtime tool declarations, tool execution core, tool/action policy, compaction, budgets, events, and runtime failure behavior.
4. [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) — bearer-token authentication, access grants, caller context, execution principals, consent policy, context/memory registries, memory store contracts, memory metadata, and guideline substrate boundary.
5. [Channels, Workflows, and Operations](channels-workflows-operations.md) — direct channels, canonical chat payloads, webhook/session/idempotency helpers, remote bridge queues, workflow specs/runners/abilities, routines, transcripts, approvals, scheduling, and operational failure behavior.

## Committed bootstrap scope

This bootstrap intentionally creates one documentation tree under `docs/technical/` with one index and five topic pages. The topic pages cover the major source boundaries requested for this pass:

- architecture;
- registry and packages;
- runtime and tools;
- auth, consent, context, and memory;
- channels, workflows, and operations.

The documentation reconciles the source inventory from `README.md`, existing `docs/*.md`, `agents-api.php`, `composer.json`, all `src/*` module families, and the smoke-test list in `composer.json`. Existing specialized docs remain in place outside this generated technical tree, but this index links only to pages committed in `docs/technical/`.

## Public surfaces covered

The committed pages document the public and extension-facing surfaces that appear in source and README inventory, including:

- WordPress hooks and filters such as `wp_agents_api_init`, `wp_agent_package_artifacts_init`, `agents_api_loop_event`, `agents_api_memory_sources`, `wp_agent_channel_chat_ability`, workflow handler/permission filters, and workflow dispatch failure actions.
- Registration helpers for agents, package artifact types, and workflow handlers.
- Core value objects and interfaces for agents, packages, runtime messages/requests/results, execution principals, tools, auth, consent, memory/context, channels/bridges, workflows, routines, transcripts, and approvals.
- Canonical payload examples for agent registration, runtime loop usage, tool declarations, consent checks, channel chat dispatch, and workflow/bridge lifecycles.
- Failure modes and non-goals that preserve the substrate boundary: provider execution, prompt assembly, concrete storage, product UX, product policy, and product-specific adapters remain consumer responsibilities.

## Source and tests used as evidence

Primary source areas:

- `agents-api.php`
- `composer.json`
- `README.md`
- `docs/external-clients.md`
- `docs/bridge-authorization.md`
- `docs/remote-bridge-protocol.md`
- `src/Registry/`
- `src/Packages/`
- `src/Runtime/`
- `src/Tools/`
- `src/Auth/`
- `src/Consent/`
- `src/Context/`
- `src/Memory/`
- `src/Guidelines/`
- `src/Identity/`
- `src/Workspace/`
- `src/Channels/`
- `src/Workflows/`
- `src/Routines/`
- `src/Transcripts/`
- `src/Approvals/`

Representative test evidence comes from the smoke-test suite in `composer.json`, including registry, subagents, authorization, caller context, consent, context registry/authority, memory metadata, workspace scope, guidelines substrate, conversation loop/compaction/budgets/events/tool execution, tool policy/action policy, channels, remote bridge, webhook safety, workflow bindings/spec/runner/abilities, routines, approvals, transcript locks, and product-boundary tests.

## Future coverage inventory

The committed pages document each major module family at integration-contract depth. Future maintenance/bootstrap passes should add deeper reference pages for these items when more detail is needed:

- `Approvals/` pending action lifecycle and handler permission semantics — currently covered from the operations page at contract level, but not method-by-method.
- `Guidelines/` substrate internals — currently covered through capabilities and memory/context boundary, but not post type/taxonomy registration internals.
- `Transcripts/` store and lock contracts — currently covered through runtime/operations integration, but not every method and storage shape.
- `Identity/` materialization details — currently covered indirectly through auth/runtime/workspace boundaries.
- `WP_Agent_Message`, `WP_Agent_Conversation_Request`, `WP_Agent_Conversation_Result`, `WP_Agent_Execution_Principal`, memory result value objects, workflow spec/run-result fields, bridge queue item fields, and Action Scheduler bridge hooks — currently covered at integration level, not exhaustive field-by-field reference.

## Keeping this tree current

When source behavior changes, update the topic page that owns the relevant module boundary and then update this index if navigation or committed scope changes. Keep generated technical documentation inside `docs/technical/` and link every generated page from this index.