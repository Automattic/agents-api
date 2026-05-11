# Channels, Workflows, and Routines

This page documents the developer-facing contracts for external message channels, remote bridges, workflow specs/runs, and routines. It covers `src/Channels`, `src/Workflows`, and `src/Routines`.

Agents API provides generic transport, orchestration, and scheduling substrate. Consumers still own platform APIs, product UX, concrete workflow storage, concrete run history, step types beyond the defaults, and operational policy.

## External channel boundary

`AgentsAPI\AI\Channels\WP_Agent_Channel` is the base class for mapping an external transport such as Slack, Telegram, WhatsApp, email, or another bridge into the canonical `agents/chat` ability surface.

The channel layer owns reusable mechanics:

- Normalizing inbound external messages.
- Mapping external conversation identifiers to agent session IDs.
- Calling the canonical chat ability.
- Preserving session continuity through a session store.
- Running webhook signature and idempotency checks when adapters use those helpers.
- Providing lifecycle hooks for concrete channel adapters.

The channel layer does **not** own platform-specific API clients, settings screens, product policy, moderation policy, or concrete delivery UX.

## External message shape

`WP_Agent_External_Message` is the normalized message envelope for channel adapters. It carries transport-neutral message data such as:

- external message/conversation/user identifiers;
- message text/content;
- channel/platform metadata;
- timestamps and raw payload metadata;
- context needed to map the message into an agent run.

The exact channel payload remains caller-owned metadata. Channel adapters translate platform events into this normalized value before dispatching to an agent.

## Session continuity

Channel sessions are separated from the runtime transcript store:

- `WP_Agent_Channel_Session_Map` maps external conversation identity to internal agent session identity.
- `WP_Agent_Channel_Session_Store` defines the persistence contract.
- `WP_Agent_Option_Channel_Session_Store` is an option-backed implementation for simple WordPress storage.

This lets a channel plugin maintain continuity across webhook calls without requiring Agents API to define a product transcript database.

## Webhook safety and idempotency

Channel helpers include:

- `WP_Agent_Webhook_Signature` for shared webhook signature behavior.
- `WP_Agent_Message_Idempotency` for duplicate-message protection.
- `WP_Agent_Message_Idempotency_Store` as the storage interface.
- `WP_Agent_Transient_Message_Idempotency_Store` as a transient-backed implementation.

Adapters should verify signatures before dispatch and record idempotency keys before performing side effects. Concrete replay windows and platform-specific signature rules remain adapter policy.

## Remote bridge queue

The bridge classes support remote clients that cannot call the local runtime directly:

- `WP_Agent_Bridge_Client` is the client-side bridge interface.
- `WP_Agent_Bridge_Queue_Item` is the JSON-friendly queued message shape.
- `WP_Agent_Bridge_Store` defines queue persistence.
- `WP_Agent_Option_Bridge_Store` is an option-backed store.
- `WP_Agent_Bridge` coordinates queue operations.

The existing `docs/remote-bridge-protocol.md` and `docs/bridge-authorization.md` describe bridge protocol and authorization details. The technical boundary is the same as other modules: Agents API provides generic queue/client shapes, while hosts own deployment, credentials, and product-specific dispatch.

## Canonical chat ability

`src/Channels/register-agents-chat-ability.php` registers the canonical `agents/chat` ability. It is the bridge between external clients/channels and a registered agent runtime.

Callers should treat the ability as the normalized ingress point for chat-style agent interaction. It accepts agent/session/message context, resolves the effective agent, and delegates execution to caller-owned runtime components rather than embedding provider-specific model calls in the substrate.

## Workflow specs

Workflows are declarative orchestration specs. `WP_Agent_Workflow_Spec` stores the workflow ID, label/description metadata, input declarations, trigger declarations, steps, and metadata. `WP_Agent_Workflow_Spec_Validator` validates structure before a workflow is registered or run.

`WP_Agent_Workflow_Bindings` expands `${...}` bindings against runtime context. The runner uses this to pass outputs from earlier steps into later steps.

Workflow storage and registry contracts:

- `WP_Agent_Workflow_Registry` is the in-memory registry for workflow specs.
- `WP_Agent_Workflow_Store` is the host persistence interface.
- Helper registration functions live in `src/Workflows/register-workflows.php`.

## Workflow runner lifecycle

`WP_Agent_Workflow_Runner::run( WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() )` executes a workflow sequentially.

Lifecycle from source:

1. Generate or accept a `run_id`.
2. Build an initial `WP_Agent_Workflow_Run_Result` with `running` status.
3. Call `WP_Agent_Workflow_Run_Recorder::start()` when a recorder is present.
4. Validate required inputs against the spec.
5. For each step:
   - expand bindings against `inputs` and previous step outputs;
   - resolve the step handler by `type`;
   - call the handler;
   - record status, output, error, and timing;
   - update the recorder;
   - stop on failure unless `continue_on_error` is true.
6. Aggregate final output by step ID and a `last` shortcut when the last step succeeded.
7. Return a terminal `WP_Agent_Workflow_Run_Result` with `succeeded` or `failed` status and update the recorder.

Options include:

- `run_id` — caller-suggested run ID.
- `continue_on_error` — continue after a failed step.
- `metadata` — forwarded to the run result.

## Workflow step handlers

The runner ships two default step types:

- `ability` — calls a registered Abilities API ability with resolved `args`.
- `agent` — calls the canonical `agents/chat` ability with `agent`, `message`, and optional `session_id`.

Consumers extend or replace step handling with the `wp_agent_workflow_step_handlers` filter or the runner constructor.

Failure modes:

- Missing Abilities API returns `WP_Error` with `abilities_api_missing`.
- Unknown ability returns `unknown_ability`.
- Missing `agents/chat` ability returns `agents_chat_missing`.
- No handler for a step type records a skipped step with `no_step_handler` and fails the workflow unless `continue_on_error` is enabled.
- Recorder start failure returns a failed result before running steps.

Agents API intentionally does not ship branch, parallel, nested workflow, or product-specific step semantics. Those belong in consumer handler maps.

## Workflow abilities and Action Scheduler bridge

`src/Workflows/register-agents-workflow-abilities.php` registers canonical workflow abilities:

- `agents/run-workflow`
- `agents/validate-workflow`
- `agents/describe-workflow`

`WP_Agent_Workflow_Action_Scheduler_Bridge` and the workflow Action Scheduler listener provide optional scheduled execution. The substrate detects Action Scheduler at runtime; the Composer `suggest` entry notes that `woocommerce/action-scheduler` is required to actually run cron-triggered workflows.

## Routines

Routines are a lighter scheduling/registration layer in `src/Routines`:

- `WP_Agent_Routine` models a routine definition.
- `WP_Agent_Routine_Registry` stores routines in memory.
- `WP_Agent_Routine_Action_Scheduler_Bridge` adapts routines to Action Scheduler when available.
- `register-routines.php`, `register-routine-bridge-sync.php`, and the Action Scheduler listener wire registration and scheduling hooks.

As with workflows, concrete schedule policy, durable history, admin UI, and product-specific side effects remain consumer concerns.

## Evidence

Source: `src/Channels/*`, `src/Channels/register-agents-chat-ability.php`, `src/Workflows/class-wp-agent-workflow-runner.php`, `src/Workflows/*`, `src/Routines/*`, `composer.json`, `docs/external-clients.md`, `docs/remote-bridge-protocol.md`, and `docs/bridge-authorization.md`.

Tests: `tests/channels-smoke.php`, `tests/webhook-safety-smoke.php`, `tests/remote-bridge-smoke.php`, `tests/agents-chat-ability-smoke.php`, `tests/workflow-bindings-smoke.php`, `tests/workflow-spec-validator-smoke.php`, `tests/workflow-runner-smoke.php`, `tests/agents-workflow-ability-smoke.php`, and `tests/routine-smoke.php`.
