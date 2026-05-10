# Agents API Module Guide

This guide documents the initial developer-facing module surface for `Automattic/agents-api`, derived from the source tree, smoke tests, README, existing docs, issues, and recent pull requests. It focuses on how the modules connect and where consumers are expected to provide adapters.

Agents API is intentionally a substrate: it owns WordPress-shaped contracts, value objects, registries, policy resolvers, and dispatcher seams. Product plugins own provider/model execution, product UX, durable storage implementations, concrete tools, channel platform APIs, and product-specific workflow semantics.

## Bootstrap and loading

**Source:** `agents-api.php`

The plugin bootstrap defines `AGENTS_API_LOADED`, `AGENTS_API_PATH`, and `AGENTS_API_PLUGIN_FILE`, requires all module files directly, and registers two `init` hooks:

- `WP_Guidelines_Substrate::register()` at priority 9.
- `WP_Agents_Registry::init()` at priority 10.

The bootstrap has no PSR-4 autoload dependency. Public files follow WordPress-style naming (`class-wp-agent-*.php`) and PHP 8.1+ syntax.

### Load-time boundaries

- The substrate must not import product plugin classes.
- Provider-specific prompt execution is delegated to `wp-ai-client` or consumer adapters.
- Optional integrations, such as Action Scheduler, are detected at runtime and no-op when absent.

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

### `WP_Agent` fields

`WP_Agent` is a thin declarative value object. It stores:

- `slug`, `label`, `description`
- `memory_seeds`
- `owner_resolver`
- `default_config`
- `supports_conversation_compaction`
- `conversation_compaction_policy`
- `meta`
- `subagents`

Constructing or registering an agent does not create database rows, files, access grants, directories, or jobs. Consumers decide whether and how a registered definition is materialized.

### Registration lifecycle

Agents must be registered during `wp_agents_api_init`:

```php
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'example-agent',
			array(
				'label'          => 'Example Agent',
				'description'    => 'Handles example requests.',
				'default_config' => array(
					'tool_policy' => array(
						'mode'       => 'allow',
						'categories' => array( 'read' ),
					),
				),
				'meta'           => array(
					'source_plugin'  => 'example-plugin/example-plugin.php',
					'source_type'    => 'bundled-agent',
					'source_version' => '1.0.0',
				),
			)
		);
	}
);
```

The registry is available only after WordPress `init` has started. `wp_register_agent()` emits `_doing_it_wrong()` and returns `null` if called outside `wp_agents_api_init`.

### Subagents

`WP_Agent::get_subagents()` returns sanitized subagent slugs, and `WP_Agent::is_coordinator()` indicates whether the list is non-empty. This is a declaration only: consumers map subagent slugs to registered agents and expose delegation through tools or abilities.

## Packages and package artifacts

**Sources:** `src/Packages/*`

Packages describe a declarative agent plus portable artifacts. The module is storage/runtime neutral and does not apply artifacts by itself.

### Main types

- `WP_Agent_Package`
- `WP_Agent_Package_Artifact`
- `WP_Agent_Package_Artifact_Type`
- `WP_Agent_Package_Artifacts_Registry`
- `WP_Agent_Package_Adopter`
- `WP_Agent_Package_Adoption_Diff`
- `WP_Agent_Package_Adoption_Result`

### Package shape

A package contains:

- `slug`
- `version`
- `agent` definition
- capability strings
- artifact declarations
- free-form `meta`

Artifacts have a namespaced `type`, slug, label, description, relative source path, checksum, requirements, and metadata. Source paths are validated as package-relative and cannot contain parent-directory traversal.

### Extension boundary

Artifact type registration happens through `wp_agent_package_artifacts_init`. Consumers decide when callbacks run and how package artifacts map to their product runtime.

## Runtime: messages, principals, requests, results, loop, compaction, budgets

**Sources:** `src/Runtime/*`

The runtime module provides provider-neutral execution primitives.

### Message envelopes

`AgentsAPI\AI\WP_Agent_Message` normalizes plain role/content arrays and typed envelopes into a canonical JSON-friendly shape:

```php
array(
	'schema'   => 'agents-api.message',
	'version'  => 1,
	'type'     => 'text',
	'role'     => 'assistant',
	'content'  => 'Hello',
	'payload'  => array(),
	'metadata' => array(),
)
```

Supported types:

- `text`
- `tool_call`
- `tool_result`
- `input_required`
- `approval_required`
- `final_result`
- `error`
- `delta`
- `multimodal_part`

Helper constructors include `text()`, `toolCall()`, `toolResult()`, and `approvalRequired()`. `to_provider_message()` projects envelopes to provider-facing role/content arrays while preserving type/payload metadata when needed.

### Execution principal

`AgentsAPI\AI\WP_Agent_Execution_Principal` records the actor and context for one execution:

- acting WordPress user ID
- effective agent ID/slug
- auth source (`user`, `application_password`, `agent_token`, `system`)
- request context (`rest`, `cli`, `cron`, `chat`)
- optional token ID
- workspace/client IDs
- capability ceiling
- caller context for cross-site agent-to-agent chains
- JSON-serializable request metadata

Hosts can resolve principals via the `agents_api_execution_principal` filter.

### Conversation request/result

`WP_Agent_Conversation_Request` carries initial messages, tool declarations, principal, runtime context, metadata, max turns, single-turn flag, and optional workspace.

`WP_Agent_Conversation_Result::normalize()` validates result arrays from the loop and caller-owned runners. It requires `messages`, normalizes optional `tool_execution_results`, and permits optional status/budget fields such as `budget_exceeded` results.

### Conversation loop

`WP_Agent_Conversation_Loop::run( array $messages, callable $turn_runner, array $options = array() ): array` sequences multi-turn execution around caller-owned adapters.

The loop owns:

- message normalization
- optional compaction before each turn
- turn sequencing
- result validation
- optional tool mediation through `WP_Agent_Tool_Execution_Core`
- completion policy dispatch
- transcript persistence
- transcript locking when a `WP_Agent_Conversation_Lock` is supplied
- iteration budgets
- lifecycle events through `on_event` and `agents_api_loop_event`

The caller owns:

- prompt assembly
- provider/model dispatch
- concrete tool execution
- durable transcript store/persister
- product policy and UX

Important options include:

- `max_turns`
- `budgets`
- `context`
- `should_continue`
- `compaction_policy`
- `summarizer`
- `tool_executor`
- `tool_declarations`
- `completion_policy`
- `transcript_lock`, `transcript_lock_store`, or `transcript_store`
- `transcript_session_id`, `session_id`, or `transcript_id`
- `transcript_persister`
- `on_event`
- `request`

When tool mediation is enabled (`tool_executor` plus `tool_declarations`) and no `should_continue` is supplied, the loop defaults to continue until natural completion, completion policy, budget exhaustion, or max turns. This behavior was added after issue #96 and PR #97.

### Loop events

The loop emits events through both:

- `on_event`: caller-owned `fn( string $event, array $payload ): void`
- `agents_api_loop_event`: WordPress action for independent observers

Observed event names include `turn_started`, `tool_call`, `tool_result`, `budget_exceeded`, `completed`, `failed`, and `transcript_lock_contention`. Observer failures are swallowed so telemetry cannot break execution.

### Compaction

`WP_Agent_Conversation_Compaction` and related compaction classes transform transcripts before model dispatch using caller-owned summarizers. Compaction preserves tool-call/tool-result integrity by default and fails closed by returning the original transcript when summarization or conservation checks fail.

`WP_Agent_Markdown_Section_Compaction_Adapter` and conservation primitives support deterministic markdown-section planning and byte-conservation metadata for non-transcript compaction flows.

### Iteration budgets

`WP_Agent_Iteration_Budget` is a per-execution stateful counter with a name and ceiling. The loop natively understands:

- `turns`
- `tool_calls`
- `tool_calls_<tool_name>`

When a budget is exceeded, the loop emits `budget_exceeded` and returns a normalized result with `status: budget_exceeded` and `budget: <name>`.

## Tools: declarations, visibility policy, action policy, execution mediation

**Sources:** `src/Tools/*`

The tools module is generic tool-call plumbing. It does not discover product tools by itself or execute concrete side effects without a consumer adapter.

### Tool declarations and calls

- `AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Call`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Result`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Executor`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry`

`WP_Agent_Tool_Execution_Core` prepares calls by checking the requested tool exists and validating required parameters, then dispatches to a caller-supplied `WP_Agent_Tool_Executor`. Exceptions from the executor are converted into normalized error results.

### Visibility policy

`WP_Agent_Tool_Policy::resolve()` filters an already gathered tool map by:

1. tool-declared runtime `mode`/`modes`
2. caller-owned `tool_access_checker`
3. registered agent or runtime `tool_policy`
4. host-provided `WP_Agent_Tool_Access_Policy` providers
5. runtime `categories`
6. runtime and provider `allow_only`
7. runtime and provider explicit `deny`
8. final `agents_api_resolved_tools` filter

Policy providers can be supplied in the constructor, through runtime context, or through `agents_api_tool_policy_providers`.

### Action policy

`WP_Agent_Action_Policy_Resolver::resolve_for_tool()` returns one canonical action policy from `AgentsAPI\AI\Tools\WP_Agent_Action_Policy`:

- `direct`
- `preview`
- `forbidden`

Resolution order:

1. explicit runtime deny list
2. agent/runtime per-tool override
3. agent/runtime per-category override
4. host-provided `WP_Agent_Action_Policy_Provider`
5. tool-declared `action_policy`
6. tool-declared mode-specific `action_policy_<mode>`
7. default `direct`
8. final `agents_api_tool_action_policy` filter

Consumers use this resolver before applying side effects or deciding whether to stage a pending action.

## Auth: access grants, tokens, capability ceilings, caller context, authorization policy

**Sources:** `src/Auth/*`

The auth module describes who can act for an agent and how capability ceilings compose with WordPress permissions. It does not ship credential tables, onboarding UI, or product-specific authorization flows.

### Access grants

`WP_Agent_Access_Grant` models a role-based relationship between a WordPress user and an agent, optionally scoped to a workspace. Roles, lowest to highest:

- `viewer`
- `operator`
- `admin`

`WP_Agent_Access_Store` is the host-provided persistence interface.

### Tokens

`WP_Agent_Token` stores hashed bearer-token metadata only. Raw token material is never exposed. Fields include token ID, agent ID, owner user ID, SHA-256 token hash, non-secret prefix, label, optional allowed capabilities, expiry/last-used/created timestamps, client/workspace IDs, and metadata.

`WP_Agent_Token_Store` resolves token hashes and touches successful token usage.

`WP_Agent_Token_Authenticator` accepts a raw bearer token at the request edge, checks an optional prefix, hashes it, resolves token metadata, rejects expired tokens, parses caller-context headers, touches successful tokens, and returns a `WP_Agent_Execution_Principal`.

Malformed caller-context headers fail closed before the token is touched.

### Capability ceilings and authorization

`WP_Agent_Capability_Ceiling` represents optional token/client capability restrictions. It answers whether the local ceiling allows a capability, but does not call WordPress.

`WP_Agent_WordPress_Authorization_Policy` composes the ceiling with `user_can()` so a capability is allowed only if both the token/client ceiling and the acting/owner WordPress user allow it.

### Caller context

`WP_Agent_Caller_Context` parses cross-site agent-to-agent headers and carries:

- caller agent
- caller user
- caller host
- chain depth
- chain root
- metadata

Hosts remain responsible for trust policy: caller headers are claims, not proof.

## Consent and approvals

**Sources:** `src/Consent/*`, `src/Approvals/*`

### Consent

`WP_Agent_Consent_Policy` splits consent by operation:

- `store_memory`
- `use_memory`
- `store_transcript`
- `share_transcript`
- `escalate_to_human`

`WP_Agent_Default_Consent_Policy` is conservative. It denies non-interactive contexts by default and requires explicit per-operation consent for interactive contexts.

`AgentsAPI\AI\Consent\WP_Agent_Consent_Decision` exports allowed/denied decisions with reason and audit metadata.

### Approvals

Approvals describe staged consequential actions. The core value shape is `AgentsAPI\AI\Approvals\WP_Agent_Pending_Action`, with associated status, decision, store, resolver, and handler contracts.

A pending action includes:

- `action_id`, `kind`, `summary`
- `preview` and `apply_input`
- optional workspace, agent, creator
- status (`pending`, `accepted`, `rejected`, `expired`, `deleted`)
- created/expiry/resolution timestamps
- resolver and resolution audit fields
- JSON-friendly metadata

`WP_Agent_Message::approvalRequired()` provides the typed message envelope for runtimes that need to ask for approval. Concrete storage, UI, REST routes, signed URLs, and product-specific handlers remain consumer responsibilities.

Open issue #128 proposes a future `WP_Agent_Pending_Action_Observer` contract for store lifecycle observers.

## Workspace, identity, memory, context, guidelines, and transcripts

### Workspace

**Source:** `src/Workspace/class-wp-agent-workspace-scope.php`

`AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope` is the generic workspace identity shared by memory, transcripts, persistence, and audit adapters. It is a `(workspace_type, workspace_id)` pair, where `workspace_type` is a lowercase slug. Examples include `site`, `network`, `runtime`, `code_workspace`, and `pull_request`.

### Identity

**Sources:** `src/Identity/*`

Materialized identity resolves a declarative registered agent into a durable consumer-owned instance:

- `WP_Agent_Identity_Scope`: `(agent_slug, owner_user_id, instance_key)`
- `WP_Agent_Materialized_Identity`: durable ID plus scope, config, meta, timestamps
- `WP_Agent_Identity_Store`: resolve/get/materialize/update/delete contract

The identity store contract is intentionally narrow. Access grants, scoped policy, token binding, and runtime behavior stay above it.

### Memory store contracts

**Sources:** `src/Memory/*`

`AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store` is a generic persistence contract keyed by `WP_Agent_Memory_Scope`:

```text
layer + workspace_type + workspace_id + user_id + agent_id + filename
```

The interface supports:

- `capabilities()`
- `read()`
- `write()` with optional compare-and-swap `if_match`
- `exists()`
- `delete()`
- `list_layer()`
- `list_subtree()`

Concrete stores decide physical keys, metadata persistence, concurrency behavior, and ranking/filtering support.

### Memory metadata

`WP_Agent_Memory_Metadata` standardizes provenance/trust fields:

- `source_type`
- `source_ref`
- `created_by_user_id`
- `created_by_agent_id`
- `workspace`
- `confidence`
- `validator`
- `authority_tier`
- `created_at`
- `updated_at`

Default trust is conservative: agent-inferred memory defaults to low authority and 0.5 confidence.

`WP_Agent_Memory_Store_Capabilities`, query, list/read/write result, validator, and validation result objects let stores declare support and callers distinguish missing metadata from unsupported fields.

### Memory/context source registry

**Sources:** `src/Context/class-wp-agent-memory-registry.php`, `src/Context/class-wp-agent-context-section-registry.php`, related context files

`WP_Agent_Memory_Registry` registers memory/context sources by layer, mode, priority, retrieval policy, editability, capability, convention path, and metadata. Supported retrieval policies are:

- `always`
- `on_intent`
- `on_tool_need`
- `manual`
- `never`

`WP_Agent_Context_Section_Registry` registers composable context sections independently of files or stores and composes them into `WP_Agent_Composable_Context`.

Extension hooks:

- `agents_api_memory_sources`
- `agents_api_context_sections`

### Retrieved context authority

Context authority types define how retrieved items resolve conflicts:

- `platform_authority`
- `support_authority`
- `workspace_shared`
- `user_workspace_private`
- `user_global`
- `agent_identity`
- `agent_memory`
- `conversation`

`WP_Agent_Context_Item` carries content, scope, authority tier, provenance, conflict kind, conflict key, and metadata. `WP_Agent_Default_Context_Conflict_Resolver` treats authoritative facts differently from preferences: authoritative facts resolve by authority tier, while preferences resolve by specificity then authority.

### Guidelines substrate

**Sources:** `src/Guidelines/*`

`WP_Guidelines_Substrate` registers a `wp_guideline` CPT and `wp_guideline_type` taxonomy when Core/Gutenberg do not provide them and when `wp_guidelines_substrate_enabled` allows it.

Key constants:

- `POST_TYPE = 'wp_guideline'`
- `TAXONOMY = 'wp_guideline_type'`
- `META_SCOPE`
- `META_USER_ID`
- `META_WORKSPACE_ID`
- `SCOPE_PRIVATE_MEMORY`
- `SCOPE_WORKSPACE_GUIDANCE`

Capability mapping intentionally avoids relying on ordinary private-post semantics for private user-workspace memory.

### Transcripts and locks

**Sources:** `src/Transcripts/*`

`AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store` is the transcript/session persistence seam. It supports creating, reading, updating, deleting, pending-session deduplication, and updating stored titles.

The session shape includes workspace, user, `agent_slug`, title, messages, metadata, provider, model, `provider_response_id`, context/mode, and timestamps. `provider_response_id` is opaque pass-through for stateful provider APIs.

`WP_Agent_Conversation_Lock` provides optional single-writer locking with token-based release semantics. `WP_Agent_Null_Conversation_Lock` is the no-op implementation.

## Channels and bridges

**Sources:** `src/Channels/*`, `docs/external-clients.md`, `docs/remote-bridge-protocol.md`, `docs/bridge-authorization.md`

The channels module connects external conversation surfaces to the canonical chat dispatcher.

### Canonical chat ability

`agents/chat` is registered by `src/Channels/register-agents-chat-ability.php`. It is a dispatcher, not a runtime. Consumers register the runtime through the `wp_agent_chat_handler` filter or `register_chat_handler()` helper.

Input schema requires:

- `agent`
- `message`

Optional input includes:

- `session_id`
- `attachments`
- `client_context` (`source`, `client_name`, `connector_id`, `external_provider`, `external_conversation_id`, `external_message_id`, `room_kind`)

Output schema requires:

- `session_id`
- `reply`

Optional output includes `messages`, `completed`, and `metadata`.

Default permission is `current_user_can( 'manage_options' )`, filtered by `agents_chat_permission`. Dispatch failures fire `agents_chat_dispatch_failed`.

### Direct channels

`AgentsAPI\AI\Channels\WP_Agent_Channel` is an abstract base for webhook-style transports. Subclasses implement:

- `get_external_id_provider()`
- `get_external_id()`
- `get_client_name()`
- `extract_message()`
- `send_response()`
- `send_error()`
- `get_job_action()`

The base pipeline is:

```text
receive -> handle -> validate -> extract message -> session lookup -> run agents/chat -> persist session -> deliver replies -> lifecycle hooks
```

`SILENT_SKIP_CODE` lets a channel drop self/noise/non-chat events without user-visible errors.

### Normalized external message and session map

`WP_Agent_External_Message` normalizes transport facts into a shared shape. `WP_Agent_Channel_Session_Map` maps:

```text
connector_id + external_conversation_id + agent -> session_id
```

The default session store is option-backed and replaceable.

### Webhook safety

`WP_Agent_Webhook_Signature` verifies HMAC SHA-256 signatures with empty-secret rejection, `hash_equals()`, and `sha256=` header support. `WP_Agent_Message_Idempotency` provides TTL-backed duplicate suppression through a replaceable store, with a transient-backed default.

### Remote bridges

`WP_Agent_Bridge` is the facade for out-of-process clients:

- `register_client()`
- `get_client()`
- `enqueue()`
- `pending()`
- `ack()`
- `set_store()`

Queued items remain pending until acknowledged. Best-effort webhook delivery must not remove an item; `ack()` is the deletion boundary. The default bridge store is option-backed and intentionally small.

Bridge authorization is documented as a boundary: Core Connectors should own service/credential metadata where possible; Agents API owns bridge runtime state and agent/chat semantics; consumers own onboarding UX and policy.

## Workflows

**Sources:** `src/Workflows/*`

Workflows are unopinionated orchestration plumbing, not a product workflow engine.

### Main types

- `WP_Agent_Workflow_Spec`
- `WP_Agent_Workflow_Spec_Validator`
- `WP_Agent_Workflow_Bindings`
- `WP_Agent_Workflow_Run_Result`
- `WP_Agent_Workflow_Store`
- `WP_Agent_Workflow_Run_Recorder`
- `WP_Agent_Workflow_Runner`
- `WP_Agent_Workflow_Registry`
- `WP_Agent_Workflow_Action_Scheduler_Bridge`

### Spec model

A workflow spec includes:

- `id`
- `version`
- `inputs`
- `steps`
- `triggers`
- `meta`

The structural validator checks required fields, step/trigger shapes, duplicate step IDs, known step/trigger types, and forward/unknown `${steps.<id>.output.*}` binding references. It does not verify that referenced agents or abilities exist; that is a runtime concern.

Built-in step types:

- `ability`
- `agent`

Built-in trigger types:

- `on_demand`
- `wp_action`
- `cron`

Consumers can extend known types with `wp_agent_workflow_known_step_types` and `wp_agent_workflow_known_trigger_types`.

### Runner

`WP_Agent_Workflow_Runner` validates inputs, walks steps sequentially, expands bindings, dispatches step handlers, records per-step state, and returns a `WP_Agent_Workflow_Run_Result`.

Default handlers:

- `ability`: dispatches a registered Abilities API ability.
- `agent`: dispatches the canonical `agents/chat` ability.

Consumers can add or replace handlers with `wp_agent_workflow_step_handlers` or constructor-provided handlers. Branching, parallelism, nested workflows, pause/resume, approvals, editor UI, durable storage, and run history are consumer concerns.

### Abilities

The module registers three canonical abilities:

- `agents/run-workflow`
- `agents/validate-workflow`
- `agents/describe-workflow`

`agents/run-workflow` dispatches to the first callable returned by `wp_agent_workflow_handler`. `agents/validate-workflow` is pure structural validation. `agents/describe-workflow` reads the in-memory registry.

Default permissions:

- run/describe: `manage_options`, filtered by `agents_run_workflow_permission`
- validate: logged-in users, filtered by `agents_validate_workflow_permission`

Failures emit `agents_run_workflow_dispatch_failed`.

### Action Scheduler bridge

The optional Action Scheduler bridge registers scheduled workflow actions when Action Scheduler is available. The listener dispatches fired scheduled actions through the same `agents/run-workflow` ability path and widens permission only for the scheduled dispatch.

## Routines

**Sources:** `src/Routines/*`

A routine is a persistent scheduled invocation of an agent. Unlike a workflow, it reuses the same conversation session across wakes.

### Main types and helpers

- `AgentsAPI\AI\Routines\WP_Agent_Routine`
- `WP_Agent_Routine_Registry`
- `WP_Agent_Routine_Action_Scheduler_Bridge`
- `wp_register_routine()` / related registration helpers in `register-routines.php`

### Routine shape

A routine has:

- `id`
- `label`
- `agent`
- either `interval` seconds or `expression` cron string
- `prompt`
- `session_id` (defaults to `routine:<id>`)
- `meta`

The value object rejects empty IDs/agents, missing triggers, and double trigger declarations.

### Scheduling lifecycle

The registry is in-memory and emits:

- `wp_agent_routine_registered`
- `wp_agent_routine_unregistered`

The bridge schedules or unschedules Action Scheduler events when available and always fires `wp_agent_routine_schedule_requested` so custom schedulers can take over.

The Action Scheduler listener looks up the routine, dispatches `agents/chat` with the routine prompt and persistent session ID, and emits failure/completion actions instead of throwing into scheduler retry semantics.

## Tests and validation map

Run all tests with:

```bash
composer test
```

The suite is a set of PHP smoke tests rather than a PHPUnit suite. The scripts cover module contracts, value-object validation, loop behavior, policy ordering, channel/bridge helpers, workflows, routines, and source-boundary constraints.

Important coverage files include:

- `tests/bootstrap-smoke.php`
- `tests/registry-smoke.php`
- `tests/authorization-smoke.php`
- `tests/caller-context-smoke.php`
- `tests/tool-policy-contracts-smoke.php`
- `tests/tool-runtime-smoke.php`
- `tests/conversation-loop-*.php`
- `tests/channels-smoke.php`
- `tests/webhook-safety-smoke.php`
- `tests/remote-bridge-smoke.php`
- `tests/context-*.php`
- `tests/memory-metadata-contract-smoke.php`
- `tests/workflow-*.php`
- `tests/agents-*-ability-smoke.php`
- `tests/routine-smoke.php`
- `tests/subagents-smoke.php`
- `tests/no-product-imports-smoke.php`

## Current open design threads

The initial documentation surface reflects the code at the target ref. Open issues point to future changes that should update this guide when implemented:

- #128: pending-action observer contract.
- #94: adoption of proposed WordPress Abilities API lifecycle filters.
- #93: recommended downstream consumption/versioning mechanism.
- #87: cross-project lessons from Personalos.
- #86: provider response ID is implemented in the transcript contract at this ref, but the issue remains open in GitHub metadata.
- #78: default stores companion package.
- #77: convergence audit and typed lifecycle events.
- #55: broader parent issue for scoped memory/permissions/transcripts/approvals.

See [`coverage-map.md`](coverage-map.md) for the source/test/doc evidence map used for this bootstrap pass.
