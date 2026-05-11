# Channels, Workflows, Routines, And Operations

This page documents the external-client, workflow, routine, operational, and contributor-facing contracts in Agents API. It is derived from `src/Channels/*`, `src/Workflows/*`, `src/Routines/*`, `composer.json`, and smoke tests including `tests/channels-smoke.php`, `tests/webhook-safety-smoke.php`, `tests/remote-bridge-smoke.php`, `tests/agents-chat-ability-smoke.php`, `tests/workflow-*.php`, `tests/agents-workflow-ability-smoke.php`, and `tests/routine-smoke.php`.

## External clients and channel boundary

Agents API owns the product-neutral channel substrate for connecting external message transports to an agent chat runtime. It does not own platform APIs, channel settings screens, tenant routing, concrete provider calls, or product-specific policies.

The channel module includes:

| Surface | Source | Purpose |
| --- | --- | --- |
| `AgentsAPI\AI\Channels\WP_Agent_Channel` | `src/Channels/class-wp-agent-channel.php` | Abstract base class for webhook/job pipeline, session continuity, canonical chat payload construction, and response delivery. |
| `WP_Agent_External_Message` | `src/Channels/class-wp-agent-external-message.php` | Normalized inbound external message with text, connector/provider IDs, conversation/message IDs, attachments, room kind, and raw payload metadata. |
| `WP_Agent_Channel_Session_Store` | `src/Channels/class-wp-agent-channel-session-store.php` | Interface for channel conversation-to-session mappings. |
| `WP_Agent_Option_Channel_Session_Store` | `src/Channels/class-wp-agent-option-channel-session-store.php` | Option-backed default session map store. |
| `WP_Agent_Channel_Session_Map` | `src/Channels/class-wp-agent-channel-session-map.php` | Shared facade for connector/external conversation/session mapping. |
| `WP_Agent_Webhook_Signature` | `src/Channels/class-wp-agent-webhook-signature.php` | Generic webhook signature helper. |
| `WP_Agent_Message_Idempotency` and stores | `src/Channels` | Generic duplicate-message guard. |
| Bridge client/store/value objects | `src/Channels/class-wp-agent-bridge-*.php` | Generic remote bridge queue/client contracts. |
| `register-agents-chat-ability.php` | `src/Channels/register-agents-chat-ability.php` | Canonical `agents/chat` ability dispatcher. |

## `WP_Agent_Channel` pipeline

Concrete channels subclass `WP_Agent_Channel` and implement the transport-specific seams:

- `get_external_id_provider()` returns a stable channel/provider identifier such as `slack` or `telegram_botname`.
- `get_external_id()` returns the transport conversation/thread ID, or `null` to disable per-conversation isolation.
- `get_client_name()` returns a lowercase client label for tracing and runtime context.
- `extract_message( array $data ): string` extracts user text from the inbound payload.
- `send_response( string $text ): void` sends assistant text back to the channel.
- `send_error( string $text ): void` sends an error response.
- `get_job_action(): string` returns the async action hook used by `receive()`.

The default lifecycle is:

1. `receive( $data )` schedules `wp_schedule_single_event()` for the job action, or runs synchronously if no action is configured.
2. `handle( $data )` validates input.
3. `extract_message()` returns user text; empty text returns `WP_Error( 'empty_message' )`.
4. The channel looks up a session ID for `(connector_id, external_id, agent_slug)`.
5. `on_processing_start()` fires.
6. `run_agent()` executes the chat ability.
7. `on_processing_end()` fires in a `finally` block.
8. `deliver_result()` persists any new session ID and sends replies or errors.
9. `on_complete()` fires in a `finally` block.

`WP_Agent_Channel::SILENT_SKIP_CODE` (`silent_skip`) lets validation drop loop-prevention or uninteresting webhook events without sending a user-facing error.

## Canonical chat payload

`build_chat_payload()` maps channel state into the `agents/chat` ability contract:

```php
array(
	'agent'          => $agent_slug,
	'message'        => $external_message->text,
	'session_id'     => $session_id_or_null,
	'attachments'    => $external_message->attachments,
	'client_context' => array(
		'source'                   => 'channel',
		'connector_id'             => $connector_id,
		'client_name'              => $client_name,
		'external_provider'        => $external_provider,
		'external_conversation_id' => $external_id,
		'external_message_id'      => $external_message_id,
		'room_kind'                => $room_kind,
	),
)
```

Subclasses can override `extract_attachments()`, `extract_external_message_id()`, `get_room_kind()`, `get_connector_id()`, and `client_context_source()` without replacing the whole payload builder.

`run_agent()` resolves the ability slug through the `wp_agent_channel_chat_ability` filter, defaulting to `agents/chat`, then calls `wp_get_ability( $slug )->execute( $payload )`. Missing Abilities API support or missing chat ability returns `WP_Error`.

## Remote bridge contracts

The bridge classes provide a generic substrate for remote clients and queued bridge work. Existing docs cover the protocol and authorization details:

- [External Clients, Channels, And Bridges](../external-clients.md)
- [Remote Bridge Protocol](../remote-bridge-protocol.md)
- [Bridge Authorization](../bridge-authorization.md)

The technical boundary is the same as channels: Agents API owns normalized value objects, stores, and dispatch contracts; consumers own concrete remote services, credentials, retry policies, tenant routing, and product UX.

## Workflow substrate

The workflow module provides a spec value object, structural validator, in-memory registry, abstract runner, store/recorder contracts, optional Action Scheduler bridge, and three canonical abilities. It does not ship a durable workflow runtime, workflow editor UI, product-specific step types, or durable run history.

Key contracts:

| Surface | Source | Purpose |
| --- | --- | --- |
| `WP_Agent_Workflow_Spec` | `src/Workflows/class-wp-agent-workflow-spec.php` | Workflow ID, inputs, steps, triggers, and metadata as a value object. |
| `WP_Agent_Workflow_Spec_Validator` | `src/Workflows/class-wp-agent-workflow-spec-validator.php` | Structural validation with errors containing `path`, `code`, and `message`. |
| `WP_Agent_Workflow_Bindings` | `src/Workflows/class-wp-agent-workflow-bindings.php` | Expands `${...}` references against inputs and prior step outputs. |
| `WP_Agent_Workflow_Runner` | `src/Workflows/class-wp-agent-workflow-runner.php` | Sequential step runner with default `ability` and `agent` handlers. |
| `WP_Agent_Workflow_Run_Result` | `src/Workflows/class-wp-agent-workflow-run-result.php` | Run result status, output, steps, error, timestamps, and metadata. |
| `WP_Agent_Workflow_Store` | `src/Workflows/class-wp-agent-workflow-store.php` | Consumer-owned workflow persistence interface. |
| `WP_Agent_Workflow_Run_Recorder` | `src/Workflows/class-wp-agent-workflow-run-recorder.php` | Consumer-owned run history recorder interface. |
| `WP_Agent_Workflow_Registry` | `src/Workflows/class-wp-agent-workflow-registry.php` | In-memory registry for registered specs. |
| `WP_Agent_Workflow_Action_Scheduler_Bridge` | `src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php` | Optional Action Scheduler bridge. |

## Workflow runner lifecycle

`WP_Agent_Workflow_Runner::run( WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() )` executes sequentially:

1. Generate or accept a `run_id`.
2. Create an initial `running` `WP_Agent_Workflow_Run_Result`.
3. Call recorder `start()` when configured. If `start()` returns `WP_Error`, return a failed result and do not run steps.
4. Validate required inputs.
5. For each step:
   - expand bindings with `WP_Agent_Workflow_Bindings::expand()`;
   - find a handler by step `type`;
   - call the handler with `( array $resolved_step, array $context )`;
   - record `running`, `succeeded`, `failed`, or `skipped` state;
   - update the recorder after each step;
   - stop on failure unless `continue_on_error` is true.
6. Return a terminal result with aggregate `output.steps` and, when the last step succeeded, `output.last`.

Default step handlers:

- `ability`: calls a registered Abilities API ability named by the step's `ability` field and passes resolved `args`.
- `agent`: calls the canonical `agents/chat` ability with `agent`, `message`, and optional `session_id`.

Consumers add or replace handlers with the `wp_agent_workflow_step_handlers` filter or constructor-provided handler map. Branching, parallelism, nested workflows, and product-specific step types are consumer extensions.

## Workflow abilities

`src/Workflows/register-agents-workflow-abilities.php` registers three abilities under the `agents-api` category:

| Ability | Purpose | Permission default |
| --- | --- | --- |
| `agents/run-workflow` | Dispatches a workflow run to a registered runtime handler. | `current_user_can( 'manage_options' )`, filterable by `agents_run_workflow_permission`. |
| `agents/validate-workflow` | Pure structural validation of an inline spec. | `is_user_logged_in()`, filterable by `agents_validate_workflow_permission`. |
| `agents/describe-workflow` | Returns a registered in-memory workflow spec and inputs. | Same permission callback as run-workflow. |

`agents/run-workflow` is a dispatcher. A consumer registers the first runtime handler through the `wp_agent_workflow_handler` filter, or by calling `AgentsAPI\AI\Workflows\register_workflow_handler( $callable )`.

Failure behavior:

- no runtime handler: `WP_Error( 'agents_run_workflow_no_handler' )` and `agents_run_workflow_dispatch_failed` action;
- handler returns `WP_Error`: failure action with the error code;
- handler returns a non-array: `WP_Error( 'agents_run_workflow_invalid_result' )`.

The canonical run input accepts `workflow_id`, inline `spec`, `inputs`, and `options`. The canonical output includes `run_id`, `workflow_id`, `status`, `output`, `steps`, `error`, `started_at`, `ended_at`, and `metadata`.

## Routine substrate

The routine module mirrors the workflow boundary for scheduled or triggerable routines. It includes:

- `WP_Agent_Routine`
- `WP_Agent_Routine_Registry`
- `WP_Agent_Routine_Action_Scheduler_Bridge`
- registration helpers/listeners in `src/Routines/register-*.php`

Agents API owns registration and optional Action Scheduler bridging. Consumers own concrete routine work, durable history, UI, policy, and storage.

## Build, test, and operational workflows

`composer.json` declares this package as a WordPress plugin requiring PHP `>=8.1`. The plugin header in `agents-api.php` declares WordPress `Requires at least: 7.0` and PHP `Requires PHP: 8.1`.

Action Scheduler is suggested, not required:

```json
"suggest": {
  "woocommerce/action-scheduler": "Required to actually run cron-triggered workflows..."
}
```

Run the full smoke suite with:

```bash
composer test
```

The test script runs pure-PHP smoke tests for bootstrap, registry, authorization, consent, tools, approvals, identity, memory metadata, workspace, compaction, context, conversation loop behavior, channels, remote bridge, guidelines, workflows, routines, subagents, and product-boundary checks.

## Operational failure modes

- Channel validation can return `silent_skip` to avoid noisy replies for deliberate drops.
- Missing Abilities API support returns `WP_Error` from channel and workflow default handlers.
- Missing `agents/chat` returns `WP_Error( 'agents_chat_missing' )` for workflow agent steps and channel chat ability errors for channels.
- Empty channel messages return `WP_Error( 'empty_message' )`.
- Workflow recorder start failure prevents step execution and returns a failed run result.
- Missing workflow step handlers mark the step `skipped`, set `no_step_handler`, and fail the run unless `continue_on_error` is enabled.
- Missing workflow runtime handler returns `agents_run_workflow_no_handler`.
- Action Scheduler-dependent behavior should no-op cleanly when Action Scheduler is absent; the dependency is optional.
