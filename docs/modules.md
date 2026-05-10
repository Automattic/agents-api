# Agents API Module Guide

This guide documents the initial developer-facing module surface for `Automattic/agents-api`, derived from the source tree, smoke tests, README, existing docs, issues, and recent pull requests. It focuses on how modules connect and where consumers provide adapters.

Agents API is intentionally a substrate: it owns WordPress-shaped contracts, value objects, registries, policy resolvers, dispatchers, and orchestration seams. Product plugins own provider/model execution, product UX, durable storage implementations, concrete tools, channel platform APIs, and product-specific workflow semantics.

## Bootstrap and loading

**Source:** `agents-api.php`

The plugin bootstrap defines `AGENTS_API_LOADED`, `AGENTS_API_PATH`, and `AGENTS_API_PLUGIN_FILE`, requires module files directly, and registers two `init` hooks:

- `WP_Guidelines_Substrate::register()` at priority 9.
- `WP_Agents_Registry::init()` at priority 10.

Public files follow WordPress-style naming (`class-wp-agent-*.php`) and PHP 8.1+ syntax. Optional integrations, such as Action Scheduler, are runtime-detected and no-op when absent.

## Registry: agent definitions

**Sources:** `src/Registry/class-wp-agent.php`, `src/Registry/class-wp-agents-registry.php`, `src/Registry/register-agents.php`

The registry module provides declarative agent definitions and an in-memory registry for a request.

### Public API

- `wp_register_agent( string|WP_Agent $agent, array $args = array() ): ?WP_Agent`
- `wp_get_agent( string $slug ): ?WP_Agent`
- `wp_get_agents(): array<string, WP_Agent>`
- `wp_has_agent( string $slug ): bool`
- `wp_unregister_agent( string $slug ): ?WP_Agent`
- `WP_Agent`
- `WP_Agents_Registry`
- `wp_agents_api_init` action

`WP_Agent` is a thin value object. It stores slug, label, description, memory seeds, owner resolver, default config, conversation-compaction support/policy, source metadata, and subagent declarations. Constructing or registering an agent does not create database rows, files, access grants, directories, or jobs.

Agents must be registered during `wp_agents_api_init`; `wp_register_agent()` returns `null` and emits `_doing_it_wrong()` when called outside that action.

`WP_Agent::get_subagents()` returns sanitized subagent slugs, and `WP_Agent::is_coordinator()` indicates whether the declaration is non-empty. Consumers map those slugs to registered agents and expose delegation through tools or abilities; the substrate only persists the declaration.

## Packages and package artifacts

**Sources:** `src/Packages/*`

Packages describe a declarative agent plus portable artifacts. The module is storage/runtime neutral and does not apply artifacts by itself.

Main types:

- `WP_Agent_Package`
- `WP_Agent_Package_Artifact`
- `WP_Agent_Package_Artifact_Type`
- `WP_Agent_Package_Artifacts_Registry`
- `WP_Agent_Package_Adopter`
- `WP_Agent_Package_Adoption_Diff`
- `WP_Agent_Package_Adoption_Result`

A package contains a slug, version, agent definition, capability strings, artifacts, and metadata. Artifacts have a namespaced type, slug, label, description, relative source path, checksum, requirements, and metadata. Source paths are package-relative and cannot contain parent-directory traversal. Artifact type registration happens through `wp_agent_package_artifacts_init`.

## Runtime: messages, principals, requests, results, loop, compaction, budgets

**Sources:** `src/Runtime/*`

### Message envelopes

`AgentsAPI\AI\WP_Agent_Message` normalizes role/content arrays and typed envelopes into a JSON-friendly shape with schema, version, type, role, content, payload, and metadata fields. Supported message types include `text`, `tool_call`, `tool_result`, `input_required`, `approval_required`, `final_result`, `error`, `delta`, and `multimodal_part`. Helper constructors include `text()`, `toolCall()`, `toolResult()`, and `approvalRequired()`.

### Execution principal and effective agent

`AgentsAPI\AI\WP_Agent_Execution_Principal` records the actor and context for one request: acting WordPress user, effective agent, auth source, request context, optional token, workspace/client identifiers, capability ceiling, caller context, and JSON-friendly metadata. Hosts can resolve principals through `agents_api_execution_principal`.

`WP_Agent_Effective_Agent_Resolver` resolves an agent for scoped operations without global mutable active-agent state. The order is explicit input, execution principal, persisted context, then an unambiguous owner fallback; ambiguous owner fallback is an error.

### Conversation request/result

`WP_Agent_Conversation_Request` carries initial messages, tool declarations, principal, runtime context, metadata, max turns, single-turn flag, and optional workspace. `WP_Agent_Conversation_Result::normalize()` validates result arrays from the loop and caller-owned runners.

### Conversation loop

`WP_Agent_Conversation_Loop::run( array $messages, callable $turn_runner, array $options = array() ): array` sequences multi-turn execution around caller-owned adapters.

The loop owns:

- message normalization;
- optional compaction before each turn;
- turn sequencing and result validation;
- optional tool mediation through `WP_Agent_Tool_Execution_Core`;
- completion policy dispatch;
- transcript persistence;
- optional transcript locking through `WP_Agent_Conversation_Lock`;
- iteration budgets;
- lifecycle events through `on_event` and `agents_api_loop_event`.

The caller owns prompt assembly, provider/model dispatch, concrete tool execution, durable transcript storage/persistence, product policy, and UX.

Important options include `max_turns`, `budgets`, `context`, `should_continue`, `compaction_policy`, `summarizer`, `tool_executor`, `tool_declarations`, `completion_policy`, `transcript_lock`, `transcript_session_id`, `transcript_persister`, `on_event`, and `request`.

When tool mediation is enabled (`tool_executor` plus `tool_declarations`) and no `should_continue` is supplied, the loop defaults to continuing until natural completion, completion policy, budget exhaustion, or max turns.

### Loop events

The loop emits `turn_started`, `tool_call`, `tool_result`, `budget_exceeded`, `completed`, `failed`, and `transcript_lock_contention` events. Observer failures are swallowed so telemetry cannot break execution.

### Compaction and conservation

`WP_Agent_Conversation_Compaction` transforms transcripts before model dispatch using caller-owned summarizers. It preserves tool-call/tool-result integrity by default and fails closed by returning the original transcript when summarization or conservation checks fail.

`WP_Agent_Compaction_Item`, `WP_Agent_Compaction_Conservation`, and `WP_Agent_Markdown_Section_Compaction_Adapter` support generic compaction item metadata, deterministic markdown-section planning, and byte-conservation checks for non-transcript flows.

### Iteration budgets

`WP_Agent_Iteration_Budget` is a stateful per-execution counter. The loop natively understands `turns`, `tool_calls`, and `tool_calls_<tool_name>`. When a budget is exceeded, the loop emits `budget_exceeded` and returns `status: budget_exceeded` with the budget name.

## Tools: declarations, visibility policy, action policy, execution mediation

**Sources:** `src/Tools/*`

The tools module is generic tool-call plumbing. It does not discover product tools by itself or execute side effects without a consumer adapter.

Main types:

- `WP_Agent_Tool_Declaration`
- `WP_Agent_Tool_Call`
- `WP_Agent_Tool_Parameters`
- `WP_Agent_Tool_Result`
- `WP_Agent_Tool_Executor`
- `WP_Agent_Tool_Execution_Core`
- `WP_Agent_Tool_Source_Registry`
- `WP_Agent_Tool_Policy`
- `WP_Agent_Action_Policy_Resolver`

`WP_Agent_Tool_Execution_Core` checks that a requested tool exists, validates required parameters, prepares a normalized call, dispatches to a caller-supplied `WP_Agent_Tool_Executor`, and converts executor exceptions into normalized error results.

`WP_Agent_Tool_Policy::resolve()` filters an already gathered tool map by tool mode, access checkers, registered agent/runtime policy, host policy providers, categories, allow-only lists, deny lists, and the final `agents_api_resolved_tools` filter. Providers can be supplied directly, through runtime context, or through `agents_api_tool_policy_providers`.

`WP_Agent_Action_Policy_Resolver` returns `direct`, `preview`, or `forbidden`. Resolution considers runtime deny lists, per-tool/per-category policy, host providers, tool-declared defaults, mode-specific defaults, the global default, and `agents_api_tool_action_policy`.

## Auth: access grants, tokens, caller context, authorization policy

**Sources:** `src/Auth/*`

The auth module describes who can act for an agent and how capability ceilings compose with WordPress permissions. It does not ship credential tables, onboarding UI, or product-specific authorization flows.

- `WP_Agent_Access_Grant` models a user-to-agent grant with generic `viewer`, `operator`, and `admin` roles, optionally workspace-scoped.
- `WP_Agent_Access_Store` is the host-provided persistence contract.
- `WP_Agent_Token` stores hashed bearer-token metadata only; raw token material is not exported.
- `WP_Agent_Token_Store` resolves token hashes and touches successful usage.
- `WP_Agent_Token_Authenticator` resolves bearer tokens, rejects expired tokens, parses caller context, touches successful tokens, and returns a `WP_Agent_Execution_Principal`.
- `WP_Agent_Capability_Ceiling` represents token/client capability restrictions.
- `WP_Agent_WordPress_Authorization_Policy` composes ceilings with `user_can()`.

`WP_Agent_Caller_Context` parses cross-site agent-to-agent headers and carries caller agent, caller user, caller host, chain depth, chain root, and metadata. Hosts must still verify trust; caller headers are claims, not proof. Malformed caller-context headers fail closed before a token is touched.

## Consent and approvals

**Sources:** `src/Consent/*`, `src/Approvals/*`

`WP_Agent_Consent_Policy` splits consent by operation: `store_memory`, `use_memory`, `store_transcript`, `share_transcript`, and `escalate_to_human`. `WP_Agent_Default_Consent_Policy` is conservative: non-interactive contexts are denied by default, and interactive contexts require explicit per-operation consent. `WP_Agent_Consent_Decision` exports allowed/denied decisions with reason and audit metadata.

Approvals describe staged consequential actions. `WP_Agent_Pending_Action` carries action ID, kind, summary, preview, apply input, optional workspace/agent/creator, status, timestamps, resolver/resolution audit fields, and metadata. Associated contracts include `WP_Agent_Pending_Action_Store`, `WP_Agent_Pending_Action_Resolver`, and `WP_Agent_Pending_Action_Handler`. `WP_Agent_Message::approvalRequired()` creates the typed runtime envelope. Concrete storage, UI, REST routes, signed URLs, and product-specific handlers remain consumer responsibilities.

Open issue #128 proposes a future pending-action observer contract; it is intentionally not documented as shipped behavior.

## Workspace, identity, memory, context, guidelines, and transcripts

### Workspace

**Source:** `src/Workspace/class-wp-agent-workspace-scope.php`

`WP_Agent_Workspace_Scope` is a generic `(workspace_type, workspace_id)` identity shared by memory, transcripts, persistence, and audit adapters. Consumers map sites, networks, code workspaces, pull requests, or ephemeral runtimes into this pair.

### Identity

**Sources:** `src/Identity/*`

Materialized identity resolves a registered agent definition into a consumer-owned durable instance:

- `WP_Agent_Identity_Scope`: agent slug, owner user ID, and instance key.
- `WP_Agent_Materialized_Identity`: durable ID plus scope, config, meta, and timestamps.
- `WP_Agent_Identity_Store`: resolve/get/materialize/update/delete contract.

### Memory contracts and metadata

**Sources:** `src/Memory/*`

`WP_Agent_Memory_Store` is a generic persistence contract keyed by `WP_Agent_Memory_Scope`:

```text
layer + workspace_type + workspace_id + user_id + agent_id + filename
```

The store interface supports capabilities, read, write with optional compare-and-swap, exists, delete, list by layer, and list subtree. Concrete stores decide physical keys, concurrency behavior, ranking/filtering support, and metadata persistence.

`WP_Agent_Memory_Metadata` standardizes provenance/trust fields including source type/ref, creating user/agent, workspace, confidence, validator, authority tier, and timestamps. Store capabilities and read/write/list result objects let stores declare unsupported metadata fields so callers can distinguish missing metadata from unsupported persistence.

### Memory/context source registry

**Sources:** `src/Context/class-wp-agent-memory-registry.php`, `src/Context/class-wp-agent-context-section-registry.php`, related context files

`WP_Agent_Memory_Registry` registers memory/context sources by layer, mode, priority, retrieval policy, editability, capability, convention path, and metadata. Supported retrieval policies are `always`, `on_intent`, `on_tool_need`, `manual`, and `never`.

`WP_Agent_Context_Section_Registry` registers composable context sections independently of files or stores and composes them into `WP_Agent_Composable_Context`. Extension hooks are `agents_api_memory_sources` and `agents_api_context_sections`.

### Retrieved context authority

Authority tiers are `platform_authority`, `support_authority`, `workspace_shared`, `user_workspace_private`, `user_global`, `agent_identity`, `agent_memory`, and `conversation`. `WP_Agent_Context_Item` carries content, scope, authority, provenance, conflict kind/key, and metadata. `WP_Agent_Default_Context_Conflict_Resolver` resolves authoritative facts by authority tier and preferences by specificity then authority.

### Guidelines substrate

**Sources:** `src/Guidelines/*`

`WP_Guidelines_Substrate` registers a `wp_guideline` CPT and `wp_guideline_type` taxonomy when Core/Gutenberg do not provide them and `wp_guidelines_substrate_enabled` allows it. Capability mapping intentionally avoids relying on ordinary private-post semantics for private user-workspace memory.

### Transcripts and locks

**Sources:** `src/Transcripts/*`

`WP_Agent_Conversation_Store` is the transcript/session persistence seam. It supports creating, reading, updating, deleting, pending-session deduplication, and title updates. The session shape includes workspace, user, `agent_slug`, title, messages, metadata, provider, model, opaque `provider_response_id`, context/mode, and timestamps.

`WP_Agent_Conversation_Lock` provides optional single-writer locking with token-based release semantics. `WP_Agent_Null_Conversation_Lock` is the no-op implementation.

## Channels and bridges

**Sources:** `src/Channels/*`, `docs/external-clients.md`, `docs/remote-bridge-protocol.md`, `docs/bridge-authorization.md`

### Canonical chat ability

`agents/chat` is registered by `src/Channels/register-agents-chat-ability.php`. It is a dispatcher, not a runtime. Consumers register the runtime through `wp_agent_chat_handler` or `register_chat_handler()`.

Input requires `agent` and `message`; optional fields include `session_id`, `attachments`, and `client_context` (`source`, `client_name`, `connector_id`, `external_provider`, `external_conversation_id`, `external_message_id`, `room_kind`). Output requires `session_id` and `reply`, with optional `messages`, `completed`, and `metadata`.

Default permission is `current_user_can( 'manage_options' )`, filtered by `agents_chat_permission`. Dispatch failures fire `agents_chat_dispatch_failed`.

### Direct channels

`WP_Agent_Channel` is an abstract base for webhook-style transports. Subclasses implement channel identity, message extraction, response/error delivery, and job scheduling. The base pipeline is:

```text
receive -> handle -> validate -> extract message -> session lookup -> run agents/chat -> persist session -> deliver replies -> lifecycle hooks
```

`SILENT_SKIP_CODE` lets a channel drop self/noise/non-chat events without user-visible errors.

### External messages, sessions, webhook safety

`WP_Agent_External_Message` normalizes transport facts. `WP_Agent_Channel_Session_Map` maps `connector_id + external_conversation_id + agent` to `session_id`; its default store is option-backed and replaceable.

`WP_Agent_Webhook_Signature` verifies HMAC SHA-256 signatures with empty-secret rejection, `hash_equals()`, and `sha256=` header support. `WP_Agent_Message_Idempotency` provides TTL-backed duplicate suppression through a replaceable store, with a transient-backed default.

### Remote bridges

`WP_Agent_Bridge` is the facade for out-of-process clients: `register_client()`, `get_client()`, `enqueue()`, `pending()`, `ack()`, and `set_store()`. Queued items remain pending until acknowledged; best-effort webhook delivery must not remove an item. The default bridge store is option-backed and intentionally small. Bridge authorization and Core Connectors boundaries are documented in the focused bridge docs.

## Workflows

**Sources:** `src/Workflows/*`

Workflows are unopinionated orchestration plumbing, not a product workflow engine.

Main types:

- `WP_Agent_Workflow_Spec`
- `WP_Agent_Workflow_Spec_Validator`
- `WP_Agent_Workflow_Bindings`
- `WP_Agent_Workflow_Run_Result`
- `WP_Agent_Workflow_Store`
- `WP_Agent_Workflow_Run_Recorder`
- `WP_Agent_Workflow_Runner`
- `WP_Agent_Workflow_Registry`
- `WP_Agent_Workflow_Action_Scheduler_Bridge`

A spec contains `id`, `version`, `inputs`, `steps`, `triggers`, and `meta`. The validator checks required fields, step/trigger shape, duplicate step IDs, known step/trigger types, and forward/unknown `${steps.<id>.output.*}` binding references. It does not verify referenced agents or abilities exist.

Built-in step types are `ability` and `agent`; built-in trigger types are `on_demand`, `wp_action`, and `cron`. Consumers extend known types with `wp_agent_workflow_known_step_types` and `wp_agent_workflow_known_trigger_types`.

`WP_Agent_Workflow_Runner` validates inputs, walks steps sequentially, expands bindings, dispatches step handlers, records per-step state, and returns `WP_Agent_Workflow_Run_Result`. Default handlers dispatch Abilities API abilities and the canonical `agents/chat` ability. Consumers add/replace handlers with `wp_agent_workflow_step_handlers` or constructor-provided handlers.

Canonical workflow abilities:

- `agents/run-workflow`
- `agents/validate-workflow`
- `agents/describe-workflow`

`agents/run-workflow` dispatches to the first callable returned by `wp_agent_workflow_handler`. `agents/validate-workflow` is pure structural validation. `agents/describe-workflow` reads the in-memory registry. Branching, parallelism, nested workflows, pause/resume, approvals, editor UI, durable storage, and run history are consumer concerns.

The optional Action Scheduler bridge registers scheduled workflow actions when Action Scheduler is available. The listener dispatches fired scheduled actions through the same `agents/run-workflow` path and widens permission only for scheduled dispatch.

## Routines

**Sources:** `src/Routines/*`

A routine is a persistent scheduled invocation of an agent. Unlike a workflow, it reuses the same conversation session across wakes.

Main types and helpers:

- `AgentsAPI\AI\Routines\WP_Agent_Routine`
- `WP_Agent_Routine_Registry`
- `WP_Agent_Routine_Action_Scheduler_Bridge`
- `wp_register_routine()` and related registration helpers

A routine has an ID, label, agent slug, either `interval` seconds or `expression` cron string, prompt, session ID (default `routine:<id>`), and metadata. The value object rejects empty IDs/agents, missing triggers, and double trigger declarations.

The registry emits `wp_agent_routine_registered` and `wp_agent_routine_unregistered`. The bridge schedules or unschedules Action Scheduler events when available and always fires `wp_agent_routine_schedule_requested` so custom schedulers can take over. The listener dispatches `agents/chat` with the routine prompt and persistent session ID and reports failure/completion through actions rather than throwing into scheduler retry semantics.

## Tests and validation map

Run all tests with:

```bash
composer test
```

The suite is a set of PHP smoke tests rather than a PHPUnit suite. It covers module contracts, value-object validation, loop behavior, policy ordering, channel/bridge helpers, workflows, routines, and source-boundary constraints. Important files include `tests/bootstrap-smoke.php`, `tests/registry-smoke.php`, `tests/authorization-smoke.php`, `tests/caller-context-smoke.php`, `tests/tool-*.php`, `tests/conversation-loop-*.php`, `tests/channels-smoke.php`, `tests/webhook-safety-smoke.php`, `tests/remote-bridge-smoke.php`, `tests/context-*.php`, `tests/memory-metadata-contract-smoke.php`, `tests/workflow-*.php`, `tests/routine-smoke.php`, `tests/subagents-smoke.php`, and `tests/no-product-imports-smoke.php`.

## Current open design threads

This guide reflects source at the bootstrap target ref. Open issues point to future docs updates:

- #128: pending-action observer contract.
- #94: WordPress Abilities API lifecycle filter adoption.
- #93: downstream consumption/versioning mechanism.
- #87: cross-project lessons from Personalos.
- #78: default stores companion package.
- #77: convergence audit and typed lifecycle events.
- #55: broader scoped memory/permissions/transcripts/approvals umbrella.

See [`coverage-map.md`](coverage-map.md) for the source/test/doc evidence map used for this bootstrap pass.
