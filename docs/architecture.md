# Architecture Overview

Agents API is a WordPress-shaped backend substrate for agent runtime behavior. It provides reusable contracts, value objects, dispatchers, registries, and policy seams that consumer plugins compose into product-specific agent experiences. It intentionally does not ship a concrete model provider, product UI, concrete workflow runtime, durable run-history implementation, or product-specific storage policy.

Source evidence: `README.md`, `agents-api.php`, `composer.json`, `src/**`, and the smoke-test list in `composer.json`.

## Layer boundary

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts,
                tool mediation, memory/transcripts/sessions, channels,
                workflow/routine contracts, auth/consent/approval contracts
Consumers    -> product UX, concrete tools, concrete workflows, prompt policy,
                durable stores, provider adapters, onboarding, and storage policy
```

The plugin bootstrap (`agents-api.php`) loads all substrate modules with `require_once`, defines `AGENTS_API_LOADED`, `AGENTS_API_PATH`, and `AGENTS_API_PLUGIN_FILE`, and wires two WordPress lifecycle hooks:

- `init` priority `9`: `WP_Guidelines_Substrate::register()`.
- `init` priority `10`: `WP_Agents_Registry::init()`.

The package is a WordPress plugin (`type: wordpress-plugin`) requiring PHP `>=8.1`; `composer test` runs the smoke-test suite listed in `composer.json`.

## Module map

| Directory | Responsibility | Primary public surfaces |
| --- | --- | --- |
| `src/Registry/` | Declarative agent registration and lookup. | `WP_Agent`, `WP_Agents_Registry`, `wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, `wp_unregister_agent()`, `wp_agents_api_init`. |
| `src/Runtime/` | Message/request/result objects, execution principals, generic conversation loop, compaction, budgets, transcript persistence seams. | `AgentsAPI\AI\WP_Agent_Conversation_Loop`, `WP_Agent_Message`, `WP_Agent_Conversation_Request`, `WP_Agent_Conversation_Result`, `WP_Agent_Execution_Principal`, `WP_Agent_Iteration_Budget`, `agents_api_loop_event`. |
| `src/Tools/` | Runtime tool declarations, parameter normalization, execution mediation, visibility policy, action policy. | `WP_Agent_Tool_Declaration`, `WP_Agent_Tool_Execution_Core`, `WP_Agent_Tool_Executor`, `WP_Agent_Tool_Policy`, `WP_Agent_Action_Policy_Resolver`. |
| `src/Channels/` | Canonical chat ability, external message normalization, direct channel base class, webhook safety helpers, remote bridge queue/pending/ack primitives. | `agents/chat`, `register_chat_handler()`, `WP_Agent_Channel`, `WP_Agent_External_Message`, `WP_Agent_Bridge`. |
| `src/Workflows/` | Workflow spec value object, structural validator, bindings, runner skeleton, registry/store/recorder contracts, workflow abilities, Action Scheduler bridge. | `wp_register_workflow()`, `agents/run-workflow`, `agents/validate-workflow`, `agents/describe-workflow`, `WP_Agent_Workflow_*`. |
| `src/Routines/` | Persistent scheduled agent invocation contracts that reuse a conversation session across wakes. | `WP_Agent_Routine`, `WP_Agent_Routine_Registry`, Action Scheduler bridge/listener. |
| `src/Auth/` | Access grants, bearer tokens, caller-chain context, authorization policies, capability ceilings. | `WP_Agent_Access_Grant`, `WP_Agent_Token`, `WP_Agent_Token_Authenticator`, `WP_Agent_Caller_Context`, `WP_Agent_WordPress_Authorization_Policy`. |
| `src/Consent/` | Consent operations, decisions, conservative default policy. | `WP_Agent_Consent_Policy`, `WP_Agent_Default_Consent_Policy`, `WP_Agent_Consent_Decision`, `WP_Agent_Consent_Operation`. |
| `src/Approvals/` | Pending action value objects and durable approval contracts. | `WP_Agent_Pending_Action`, `WP_Agent_Approval_Decision`, `WP_Agent_Pending_Action_Store`, `WP_Agent_Pending_Action_Resolver`, `WP_Agent_Pending_Action_Handler`. |
| `src/Context/` | Memory/context source registry, section registry, composable context, authority tiers, conflict resolution. | `WP_Agent_Memory_Registry`, `WP_Agent_Context_Section_Registry`, `WP_Agent_Composable_Context`, `WP_Agent_Context_Item`, `WP_Agent_Default_Context_Conflict_Resolver`. |
| `src/Memory/` | Store-neutral memory contracts and value objects with provenance/trust metadata. | `WP_Agent_Memory_Store`, `WP_Agent_Memory_Scope`, `WP_Agent_Memory_Metadata`, `WP_Agent_Memory_Query`, result/capability objects. |
| `src/Transcripts/` | Conversation store and lock contracts. | `WP_Agent_Conversation_Store`, `WP_Agent_Conversation_Lock`, `WP_Agent_Null_Conversation_Lock`. |
| `src/Workspace/` | Generic workspace identity shared across memory/transcripts/audit adapters. | `WP_Agent_Workspace_Scope`. |
| `src/Guidelines/` | `wp_guideline` / `wp_guideline_type` compatibility substrate and capability mapping. | `WP_Guidelines_Substrate`, `wp_guideline_types()`, guideline capability constants. |
| `src/Packages/` | Agent package manifests and artifact registry/adoption contracts. | `WP_Agent_Package`, `WP_Agent_Package_Artifact`, `WP_Agent_Package_Artifacts_Registry`. |
| `src/Identity/` | Materialized agent identity value objects and store contract. | `WP_Agent_Identity_Scope`, `WP_Agent_Materialized_Identity`, `WP_Agent_Identity_Store`. |

## Runtime flow at a glance

```text
consumer registers an agent on wp_agents_api_init
  -> caller reaches Agents API through chat ability, channel, bridge, workflow, routine, REST, CLI, cron, or host code
  -> host resolves execution principal / auth / consent / policy
  -> consumer adapter invokes WP_Agent_Conversation_Loop or workflow runner
  -> loop normalizes messages, optionally compacts transcript, calls provider adapter
  -> optional tool mediation validates/executes tools through caller-owned executor
  -> optional transcript persister/store records the result
  -> loop emits observer events and returns JSON-friendly result arrays
```

The substrate keeps runtime mechanics reusable while leaving provider execution, prompt assembly, concrete tools, stores, UI, and product policy to consumers.

## Storage boundaries

Agents API defines contracts and a guideline substrate polyfill, but concrete durable stores are generally consumer concerns:

- Agent registration is in memory through `WP_Agents_Registry`; materialization is a consumer/store concern.
- Memory stores implement `WP_Agent_Memory_Store`; concrete guideline/markdown implementations are proposed for a companion package in `docs/default-stores-companion.md` and issue #78.
- Transcript stores implement `WP_Agent_Conversation_Store`; the loop accepts `WP_Agent_Transcript_Persister` adapters.
- Bridge defaults use option-backed stores (`WP_Agent_Option_Bridge_Store`, `WP_Agent_Option_Channel_Session_Store`) for small generic state, with replacement store interfaces for hosts that need custom tables or queues.
- Workflow stores/recorders are interfaces; the runner and abilities do not prescribe persistence.

## Extension philosophy

Design principles reflected across source and tests:

- Keep Agents API provider-neutral; `wp-ai-client` or consumer adapters own model calls.
- Prefer declarative value objects and narrow interfaces over a heavy inherited runtime base class.
- Use WordPress hooks/filters for registration, policy, and observation where the substrate is WordPress-shaped.
- Fail closed for auth and malformed caller context; swallow observer/persister failures where they must not alter runtime results.
- Preserve JSON-friendly array contracts at runtime seams so consumers can map them to REST, stores, streams, queues, and audit systems.
- Keep product vocabulary and product UX out of the substrate.

## Tests that prove module boundaries

The `composer.json` test script runs smoke tests for bootstrap, registry, execution principal, caller context, authorization, consent, tool policy/runtime, approvals, identity, memory metadata, workspace scope, compaction, context registry, conversation loop, channels, bridge/webhook safety, context authority, guidelines, workflows, routines, and `no-product-imports-smoke.php`. These tests are the current executable guardrail for the substrate boundary.