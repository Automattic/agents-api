# Architecture Overview

Agents API is a WordPress-shaped agent runtime substrate. It provides reusable contracts, value objects, dispatchers, and policy seams for agent features, while consumer plugins own product UX, concrete storage, provider/model calls, concrete tools, and product policy.

Source evidence: `agents-api.php`, `README.md`, `composer.json`, `src/**`, and the smoke tests listed in `composer.json`.

## Layer boundary

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts, tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy, storage/materialization policy
```

Agents API must stay provider-neutral and product-neutral. It does not ship admin screens, REST controllers, concrete workflow persistence, model adapters, product step types, or concrete memory/materialization adapters. The bootstrap file (`agents-api.php`) includes all source modules directly and wires only two startup hooks:

- `init` priority `9`: `WP_Guidelines_Substrate::register()` for the guideline storage polyfill.
- `init` priority `10`: `WP_Agents_Registry::init()` to open the `wp_agents_api_init` registration window.

## Module inventory

| Area | Source paths | Public surface / responsibility |
| --- | --- | --- |
| Registry | `src/Registry/**` | `WP_Agent`, `WP_Agents_Registry`, `wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, `wp_unregister_agent()`, `wp_agents_api_init`. |
| Runtime | `src/Runtime/**` | Message/request/result/principal value objects, conversation loop, completion policy, compaction, transcript persister, iteration budgets. |
| Tools and approvals | `src/Tools/**`, `src/Approvals/**` | Runtime tool declaration/call/result contracts, tool visibility policy, action policy, execution core, pending action approval value objects and stores. |
| Auth | `src/Auth/**` | Access grants, token metadata/authentication, caller context headers, capability ceiling, WordPress authorization policy. |
| Channels and bridge | `src/Channels/**` | `agents/chat` ability dispatcher, abstract channel pipeline, external message shape, session map/store, webhook signatures, idempotency, remote bridge queue. |
| Workflows and routines | `src/Workflows/**`, `src/Routines/**` | Workflow spec/validator/runner/registry/store/recorder, three workflow abilities, Action Scheduler bridge, scheduled routine contracts. |
| Memory and context | `src/Memory/**`, `src/Context/**`, `src/Workspace/**`, `src/Identity/**`, `src/Guidelines/**` | Memory scope/store/metadata/query contracts, workspace identity, identity materialization, context registries, authority/conflict vocabulary, guideline polyfill. |
| Packages | `src/Packages/**` | Package and artifact value objects, artifact registry, adoption/diff contracts. |
| Transcripts | `src/Transcripts/**` | Conversation store and lock contracts, null lock implementation. |

## Bootstrap and dependency model

`composer.json` declares PHP `>=8.1`, package type `wordpress-plugin`, and suggests Action Scheduler only for scheduled workflow/routine execution. `composer test` runs pure-PHP smoke tests; many value objects include WordPress-aware fallbacks so tests can run without a fully booted WordPress site.

`agents-api.php` loads files in dependency order instead of relying on Composer autoloading. New public classes must be added to this bootstrap in dependency order, otherwise smoke tests and WordPress loading will miss them.

## Extension points and hooks

Core extension points documented by source:

- Agent registration: `wp_agents_api_init` action; helper functions in `src/Registry/register-agents.php`.
- Loop observability: `agents_api_loop_event` action and the `on_event` option to `WP_Agent_Conversation_Loop::run()`.
- Tool visibility: `agents_api_tool_policy_providers` and `agents_api_resolved_tools` filters.
- Action policy: `agents_api_tool_action_policy` filter.
- Chat runtime: `wp_agent_chat_handler` filter; failure action `agents_chat_dispatch_failed`.
- Workflow runtime: `wp_agent_workflow_handler` filter; failure action `agents_run_workflow_dispatch_failed`.
- Workflow step types: `wp_agent_workflow_step_handlers`, `wp_agent_workflow_known_step_types`, `wp_agent_workflow_known_trigger_types` filters.
- Workflow ability permissions: `agents_run_workflow_permission`, `agents_validate_workflow_permission` filters.
- Channel chat ability override: `wp_agent_channel_chat_ability` filter.
- Routine scheduler takeover: `wp_agent_routine_schedule_requested` action.
- Guideline polyfill enablement: `wp_guidelines_substrate_enabled` filter.

## Storage boundaries

Agents API defines storage contracts but avoids owning product materialization:

- `WP_Agent_Memory_Store` describes memory reads/writes/lists by scope and metadata capabilities, but does not define a filesystem path or table.
- `WP_Agent_Conversation_Store` and `WP_Agent_Conversation_Lock` describe transcript sessions and locking, but concrete stores belong to hosts.
- `WP_Agent_Pending_Action_Store`, `WP_Agent_Workflow_Store`, `WP_Agent_Workflow_Run_Recorder`, `WP_Agent_Access_Store`, `WP_Agent_Token_Store`, and channel/bridge stores are interfaces or option-backed defaults where the substrate can stay generic.
- The guideline polyfill provides a shared `wp_guideline`/`wp_guideline_type` substrate only when Core/Gutenberg do not provide one.

## Failure and safety principles

The codebase generally fails closed for auth and malformed inputs, while keeping observers and optional persistence from breaking core execution:

- `WP_Agent_Token_Authenticator` rejects empty, wrong-prefix, unknown, expired, or malformed caller-context token requests before touching tokens.
- `WP_Agent_WordPress_Authorization_Policy` denies empty capabilities, unauthenticated user IDs, and capabilities blocked by a token/client ceiling.
- `WP_Agent_Conversation_Loop` swallows event observer, transcript persistence, and lock-release failures so logging/storage failures do not change provider/tool results; it returns a `transcript_lock_contention` status when a requested lock cannot be acquired.
- Workflow dispatchers return `WP_Error` when no handler is registered or a handler returns an invalid shape.
- Channel validation can return the `silent_skip` sentinel to drop loop-prevention or allow-list misses without notifying the user.

## Test map

The smoke tests are the executable behavior inventory. Important coverage includes:

- Bootstrap and public registry: `tests/bootstrap-smoke.php`, `tests/registry-smoke.php`, `tests/subagents-smoke.php`.
- Runtime contracts: `tests/message-envelope-smoke.php`, `tests/conversation-loop-*.php`, `tests/iteration-budget-smoke.php`.
- Tools/actions/approvals: `tests/tool-runtime-smoke.php`, `tests/tool-policy-contracts-smoke.php`, `tests/action-policy-*.php`, `tests/approval-*.php`, `tests/pending-action-store-contract-smoke.php`.
- Auth and caller context: `tests/authorization-smoke.php`, `tests/caller-context-smoke.php`, `tests/execution-principal-smoke.php`.
- Channels/bridge: `tests/channels-smoke.php`, `tests/remote-bridge-smoke.php`, `tests/webhook-safety-smoke.php`, `tests/agents-chat-ability-smoke.php`.
- Workflows/routines: `tests/workflow-*.php`, `tests/agents-workflow-ability-smoke.php`, `tests/routine-smoke.php`.
- Memory/context/guidelines: `tests/memory-metadata-contract-smoke.php`, `tests/context-*.php`, `tests/guidelines-substrate-smoke.php`, `tests/workspace-scope-smoke.php`, `tests/identity-smoke.php`.

## Future coverage

The committed bootstrap docs cover the main architecture, registry, runtime/tooling, channels/bridge, workflows/routines, auth/storage boundaries, and test map. Future passes should add narrower reference pages for packages, identity, transcript store shapes, and the full guideline polyfill internals. Those areas are listed here because they are public modules, but this bootstrap keeps the first documentation surface focused on the contracts most consumers implement against.