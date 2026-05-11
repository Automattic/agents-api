# Architecture

Agents API is a WordPress-shaped substrate for durable agent runtime behavior. It provides contracts, value objects, registries, and dispatch plumbing that product plugins can build on without importing product-specific runtimes into the substrate.

Source evidence: `README.md`, `agents-api.php`, `composer.json`, and smoke tests listed in `composer.json`.

## Layer boundary

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts, tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy, storage/materialization policy
```

Agents API intentionally does **not** own provider calls, admin UI, product-specific workflows, concrete storage tables, concrete tool adapters, or product onboarding flows. The bootstrap file (`agents-api.php`) wires the substrate by requiring the module files and registering init hooks for the agent registry and guideline substrate.

## Module inventory

The source tree is organized by contract area:

- `Registry/`: agent definition and registration lifecycle.
- `Packages/`: portable agent package manifests and package artifact types.
- `Runtime/`: message/request/result value objects, execution principals, conversation loop, compaction, completion, budgets, transcript persister contracts, and loop events.
- `Tools/`: runtime tool declaration, parameter, execution, visibility, action policy, and tool source contracts.
- `Auth/`: access grants, bearer tokens, token authentication, caller context, authorization policy, and capability ceilings.
- `Consent/`: operation vocabulary and conservative default consent policy.
- `Context/` and `Memory/`: memory/context source registry, composable context, authority/conflict vocabulary, memory scope/query/metadata/store contracts.
- `Guidelines/`: `wp_guideline` substrate polyfill and capabilities for memory/guidance storage.
- `Identity/` and `Workspace/`: materialized identity and workspace scope values used by memory, transcripts, and runtime requests.
- `Channels/`: direct channel base class, normalized external messages, webhook/idempotency helpers, channel-session map, and remote bridge queue facade.
- `Workflows/`: workflow spec, validator, registry, runner, store/recorder contracts, Action Scheduler bridge, and canonical workflow abilities.
- `Routines/`: persistent scheduled agent invocations that reuse a session across wakes.
- `Transcripts/` and `Approvals/`: transcript store/lock contracts and pending-action approval primitives.

## Bootstrap and lifecycle

`agents-api.php` defines `AGENTS_API_LOADED`, `AGENTS_API_PATH`, and `AGENTS_API_PLUGIN_FILE`, then requires modules in dependency order. Runtime extension happens through WordPress hooks rather than global side effects:

- `WP_Agents_Registry::init()` runs on `init` priority `10` and fires `wp_agents_api_init` for agent registration.
- `WP_Guidelines_Substrate::register()` runs on `init` priority `9` so the guideline substrate is available before higher-level consumers need it.
- Abilities, workflow ability categories, workflow abilities, routine listeners, channel chat ability, and Action Scheduler listeners register from their module files through WordPress hooks.

## Public extension principles

Agents API follows several design constraints that show up across the source and tests:

1. **Substrate, not product runtime.** Product plugins provide prompt assembly, provider/model dispatch, concrete tools, storage, UX, onboarding, and product policy.
2. **Typed value objects at boundaries.** Public shapes such as `WP_Agent`, `WP_Agent_Token`, `WP_Agent_Memory_Metadata`, and `WP_Agent_Workflow_Spec` normalize and validate data before it crosses module boundaries.
3. **Hook-based registration windows.** Agents and package artifact types must be registered inside their public init hooks, matching WordPress and Abilities API lifecycle expectations.
4. **Fail closed at request edges.** Token authentication rejects missing, expired, prefix-mismatched, or malformed caller-context requests before touching a token.
5. **Observer failures are non-fatal.** Conversation loop events and transcript persistence failures are swallowed where they should not change model/tool execution outcomes.
6. **Consumer-owned durability.** Stores and recorders are interfaces; option/transient stores are small defaults only for shared generic behavior.

## Operational and test workflow

The package requires PHP `>=8.1` and declares WordPress plugin metadata requiring WordPress `7.0` or higher. `composer.json` defines `composer test`, a smoke-test suite that exercises each major contract family: registry, authorization, caller context, consent, tool policies/runtime, approvals, identity, memory metadata, workspace scope, compaction, context registry, conversation loop, channels, bridge/webhook safety, workflows, routines, subagents, and product-boundary checks.

## Future coverage

This bootstrap covers the major source modules and public contracts under `docs/technical`. Future passes should add deeper reference pages for:

- `Approvals/` pending action lifecycle and handler permissions, because this pass only references it from the architecture inventory.
- `Guidelines/` substrate internals, because this pass covers the memory/context boundary but not post type/taxonomy registration details.
- `Transcripts/` store and lock contracts, because this pass covers transcript persistence from runtime and workflow perspectives but not every method contract.
- `Identity/` materialization details, because identity is currently documented through auth/runtime/workspace boundaries.
