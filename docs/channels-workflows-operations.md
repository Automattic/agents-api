# Channels, Workflows, and Operations

This page documents the external-client, workflow, routine, transcript, approval, and operational contracts that let consumers connect Agents API to real transports and background execution while keeping product behavior outside the substrate.

Source evidence: `src/Channels/*`, `src/Workflows/*`, `src/Routines/*`, `src/Transcripts/*`, `src/Approvals/*`, existing docs in `docs/external-clients.md`, `docs/bridge-authorization.md`, `docs/remote-bridge-protocol.md`, `tests/channels-smoke.php`, `tests/remote-bridge-smoke.php`, `tests/webhook-safety-smoke.php`, `tests/workflow-*.php`, `tests/agents-workflow-ability-smoke.php`, `tests/routine-smoke.php`, `tests/approval-*.php`, and `tests/conversation-transcript-lock-smoke.php`.

## External clients and direct channels

`AgentsAPI\AI\Channels\WP_Agent_Channel` is the abstract base class for WordPress-hosted messaging channel plugins. It maps external surfaces such as Slack, Telegram, Discord, WhatsApp, email, or local relays onto the canonical chat ability contract.

The job-side channel pipeline is:

```text
validate inbound payload
-> extract message text
-> look up external conversation session
-> on_processing_start()
-> run agents/chat ability
-> on_processing_end()
-> persist returned session_id
-> deliver assistant replies or errors
-> on_complete()
```

Subclasses provide transport identity and I/O:

| Method | Responsibility |
| --- | --- |
| `get_external_id_provider()` | Stable provider/channel id such as `slack` or `telegram_bot`. |
| `get_external_id()` | External conversation/thread id, or `null` when isolation is unavailable. |
| `get_client_name()` | Human-readable lowercase client name for context. |
| `extract_message( array $data )` | Convert transport payload into user text. |
| `send_response( string $text )` | Deliver assistant text. |
| `send_error( string $text )` | Deliver processing errors. |
| `get_job_action()` | Async job hook used by `receive()`. |

Optional overrides include `validate()`, `on_processing_start()`, `on_processing_end()`, `on_complete()`, `extract_attachments()`, `extract_external_message_id()`, `get_room_kind()`, `get_connector_id()`, `client_context_source()`, and `send_notification()`.

`WP_Agent_Channel::SILENT_SKIP_CODE` lets validation drop well-formed but intentionally ignored events, such as self messages or allow-list misses, without sending a user-facing error.

## Canonical chat payload

`build_chat_payload()` dispatches to the ability selected by the `wp_agent_channel_chat_ability` filter, defaulting to `AGENTS_CHAT_ABILITY` / `agents/chat`.

Representative input:

```php
array(
	'agent'          => 'example-agent',
	'message'        => 'Hello from Slack',
	'session_id'     => 'previous-session-id-or-null',
	'attachments'    => array(),
	'client_context' => array(
		'source'                       => 'channel',
		'connector_id'                 => 'slack',
		'client_name'                  => 'slack',
		'external_provider'            => 'slack',
		'external_conversation_id'     => 'opaque-thread-id',
		'external_message_id'          => 'opaque-message-id',
		'room_kind'                    => 'dm',
	),
)
```

The channel can consume either a single `reply` or assistant `messages` from the ability result. It stores a returned `session_id` before sending replies so delivery failures do not lose session continuity. `agents/chat` also exposes a canonical `run_id`; runtimes receive a generated `run_id` in the input when callers omit one, and responses include that same ID unless the runtime returns its own.

## Chat run control

Agents API owns the generic run-control ability contracts and default run-control storage. Every `agents/chat` run receives a `run_id`, stores status when a session is known, accepts best-effort cancellation, and accepts queued follow-up messages. Runtimes may override the hooks below only when they can provide stronger behavior such as immediate provider aborts or a custom queue worker.

| Ability | Purpose | Runtime hook |
| --- | --- | --- |
| `agents/get-chat-run` | Return status for a known chat run. | `wp_agent_chat_run_status_handler` |
| `agents/cancel-chat-run` | Request best-effort cancellation for a known chat run. | `wp_agent_chat_run_cancel_handler` |
| `agents/queue-chat-message` | Accept a next user message while a session has an active run. | `wp_agent_chat_message_queue_handler` |

Clients can expose status, Stop, and Queue controls whenever these canonical abilities are available and the caller has permission for the selected agent. The default handlers preserve safe behavior for synchronous runtimes; runtime-specific handlers are enhancements, not prerequisites.

Run status vocabulary is bounded to `queued`, `running`, `cancelling`, `cancelled`, `completed`, and `failed`. The canonical run payload is:

```php
array(
	'run_id'     => 'run_opaque',
	'session_id' => 'session_opaque',
	'status'     => 'running',
	'started_at' => '2026-01-01T00:00:00Z',
	'updated_at' => '2026-01-01T00:00:01Z',
	'metadata'   => array(),
)
```

Cancellation is best-effort. A runtime that can abort provider work immediately may do so; a runtime that cannot should mark the run `cancelling` and let its conversation loop stop at the next interrupt check. `WP_Agent_Chat_Run_Control::cancellation_interrupt_message()` builds the message shape expected by `WP_Agent_Conversation_Loop` `interrupt_source` callbacks.

Queued messages return the same run payload plus `queued_message_id` and `position`. Async runtimes can drain queued messages through their worker, cron, or Action Scheduler integration. Synchronous runtimes can expose queued state and require polling or an explicit continue operation in the consuming product; the substrate does not force a background runner.

## Session, webhook, and idempotency helpers

Shared channel primitives include:

- `WP_Agent_External_Message`: normalized external message value object for text, connector, provider, conversation id, message id, sender, room kind, attachments, and raw payload.
- `WP_Agent_Channel_Session_Map`: maps `(connector_id, external_conversation_id, agent)` to a session id.
- `WP_Agent_Channel_Session_Store` and `WP_Agent_Option_Channel_Session_Store`: replaceable/default session storage.
- `WP_Agent_Webhook_Signature`: HMAC SHA-256 signature verification helper.
- `WP_Agent_Message_Idempotency`, `WP_Agent_Message_Idempotency_Store`, and `WP_Agent_Transient_Message_Idempotency_Store`: TTL-backed duplicate suppression.

## Remote bridge protocol

Remote bridge clients are out-of-process processes that relay messages between an external surface and WordPress. `WP_Agent_Bridge` is the facade for generic queue-first delivery.

Flow:

```text
register client
-> execute inbound chat through agents/chat
-> enqueue outbound assistant replies
-> remote client polls pending items or receives webhook delivery
-> remote client acknowledges accepted queue ids
```

Public facade methods:

- `set_store( ?WP_Agent_Bridge_Store $store )`
- `store(): WP_Agent_Bridge_Store`
- `register_client( string $client_id, ?string $callback_url = null, array $context = array(), ?string $connector_id = null ): WP_Agent_Bridge_Client`
- `get_client( string $client_id ): ?WP_Agent_Bridge_Client`
- `enqueue( array $args ): WP_Agent_Bridge_Queue_Item`
- `pending( string $client_id, int $limit = 25, array $session_ids = array() ): array`
- `ack( string $client_id, array $queue_ids ): int`

Queued items remain pending until acknowledged. The default store is option-backed; hosts that need custom tables, queues, leases, retention, or multi-worker behavior replace it with `set_store()`.

Bridge authorization is intentionally separate from queue state. Connectors can own service metadata; Agents API owns bridge runtime state and expects consumers to map credentials to client, connector, agent, workspace, and operation scopes.

## Workflow specs and validation

Workflows are deterministic recipes composed of inputs, triggers, and steps. Agents API provides the spec value object, structural validator, in-memory registry, runner skeleton, store/recorder interfaces, optional Action Scheduler bridge, and canonical abilities. It does not ship a concrete durable workflow runtime, editor UI, product step types, or run-history database.

`WP_Agent_Workflow_Spec_Validator::validate( array $spec ): array` performs structural validation only:

- `id` is required and non-empty;
- `inputs`, when present, must be a map;
- `steps` must be a non-empty list;
- each step needs an `id` and `type`;
- built-in step types are `ability` and `agent`;
- consumers can extend known step types through `wp_agent_workflow_known_step_types`;
- `wp_action` and `cron` trigger shapes are checked;
- forward or unknown `${steps.<id>.output.*}` binding references are reported.

The validator does not check whether referenced agents or abilities exist; the runner handles that at execution time.

## Workflow runner

`WP_Agent_Workflow_Runner` executes one `WP_Agent_Workflow_Spec` in order:

```text
start run record
-> validate required inputs
-> resolve bindings against inputs and prior step outputs
-> dispatch step handler
-> record per-step status/output/error/timing
-> short-circuit on failure unless continue_on_error is enabled
-> return WP_Agent_Workflow_Run_Result
```

Default step handlers:

- `ability`: calls a registered Abilities API ability with resolved `args`.
- `agent`: calls the canonical `agents/chat` ability with `agent`, `message`, and optional `session_id`.

Consumers extend the runner through the constructor or the `wp_agent_workflow_step_handlers` filter. Product-specific steps such as `branch`, `parallel`, or nested `workflow` belong in consumers.

Recorder behavior is conservative: `start()` runs before input validation, per-step `update()` calls follow state changes, and recorder-start failure returns a failed run result without executing steps.

## Workflow abilities and permissions

`src/Workflows/register-agents-workflow-abilities.php` registers three abilities:

| Ability | Purpose | Default permission |
| --- | --- | --- |
| `agents/run-workflow` | Dispatch a registered or inline workflow to a consumer runtime. | `current_user_can( 'manage_options' )`, filterable with `agents_run_workflow_permission`. |
| `agents/validate-workflow` | Structurally validate a supplied workflow spec. | `is_user_logged_in()`, filterable with `agents_validate_workflow_permission`. |
| `agents/describe-workflow` | Return an in-memory registered workflow spec and inputs. | Same permission callback as run workflow. |

`agents/run-workflow` dispatches to the first callable returned by the `wp_agent_workflow_handler` filter. `register_workflow_handler( callable $handler, int $priority = 10 )` is a convenience helper. If no handler is present or a handler returns the wrong type, the dispatcher returns `WP_Error` and fires `agents_run_workflow_dispatch_failed` for observability.

## Routines and scheduling

`AgentsAPI\AI\Routines\WP_Agent_Routine` models a persistent scheduled invocation of an agent. A routine differs from a workflow because it reuses the same conversation session across every wake, letting context accumulate.

Required shape:

- routine id slug;
- `agent` slug;
- exactly one trigger: positive `interval` seconds or non-empty cron `expression`.

Optional fields include `label`, `prompt`, `session_id`, and `meta`. When `session_id` is omitted, the default is `routine:<id>`.

Action Scheduler bridges and listeners are optional operational adapters. The substrate detects Action Scheduler at runtime and no-ops cleanly when absent; `composer.json` suggests `woocommerce/action-scheduler` for scheduled workflow/routine execution.

## Transcripts and approvals

Transcript contracts live in `src/Transcripts/` and runtime persister contracts live in `src/Runtime/`:

- `WP_Agent_Conversation_Store`: session and transcript persistence surface, including workspace-stamped sessions and provider continuity metadata.
- `WP_Agent_Conversation_Lock` and `WP_Agent_Null_Conversation_Lock`: lock contracts used by the conversation loop to avoid concurrent session mutation.
- `WP_Agent_Transcript_Persister` and `WP_Agent_Null_Transcript_Persister`: loop-level persistence adapters.

Approval primitives live in `src/Approvals/`:

- `WP_Agent_Pending_Action`: JSON-friendly proposal and audit value object.
- `WP_Agent_Pending_Action_Status`: pending/accepted/rejected/expired/deleted vocabulary.
- `WP_Agent_Approval_Decision`: resolver decision value object.
- `WP_Agent_Pending_Action_Store`: durable queue/audit interface.
- `WP_Agent_Pending_Action_Observer`: lifecycle observer contract for stores that emit stored/resolved/expired events.
- `WP_Agent_Pending_Action_Resolver`: accept/reject resolution contract.
- `WP_Agent_Pending_Action_Handler`: product handler contract for permission checks and applying/rejecting proposals.

Store implementations own observer registration and invocation. Observers should tolerate duplicate lifecycle calls and avoid throwing; stores should defensively catch observer failures so notification, logging, or metrics observers cannot break durable approval state transitions.

Consumers own the database tables, REST routes, admin/chat UI, queues, product-specific apply/reject handlers, event adapters, and authorization ceilings for transcript and approval materialization.

## Operational failure behavior

- Channel validation can silent-skip or return user-facing errors.
- Missing Abilities API or missing ability registrations return `WP_Error` from channel and workflow handlers.
- Bridge pending items are not deleted by best-effort delivery; `ack()` is the removal boundary.
- Workflow recorder-start failure prevents step execution; per-step failures short-circuit unless `continue_on_error` is enabled.
- Absent Action Scheduler means scheduled bridges no-op rather than hard-failing the substrate.

## Future coverage

Future pages should add full method-level references for bridge queue item fields, workflow spec/run-result fields, Action Scheduler bridge hooks, transcript store methods, and pending-action resolver semantics. This bootstrap documents the integration contracts and operational lifecycle required to implement consumers safely.
