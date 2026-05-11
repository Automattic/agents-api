# Channels, Bridges, And Canonical Abilities

Agents API provides external-client plumbing without owning platform APIs, webhook routes, settings screens, or a concrete agent runtime. Channels and bridges normalize transport context, preserve session continuity, and dispatch to canonical Abilities API contracts.

## Canonical `agents/chat` ability

`src/Channels/register-agents-chat-ability.php` registers `agents/chat` during `wp_abilities_api_init`. The ability is a dispatcher: it validates the stable input/output contract and asks consumers for a runtime handler through the `wp_agent_chat_handler` filter.

```php
use function AgentsAPI\AI\Channels\register_chat_handler;

register_chat_handler(
	static function ( array $input ) use ( $runtime ) {
		return $runtime->chat( $input );
	}
);
```

The first registered handler wins. If no handler is registered, the dispatcher returns `WP_Error( 'agents_chat_no_handler', ... )` and fires `agents_chat_dispatch_failed` with reason `no_handler`. If a handler returns `WP_Error`, the error is propagated and the failure action receives that error code. If a handler returns a non-array value, the dispatcher returns `agents_chat_invalid_result`.

### Input contract

`agents_chat_input_schema()` defines the required payload:

```json
{
  "agent": "support-agent",
  "message": "Can you summarize this order?",
  "session_id": "optional-existing-session",
  "attachments": [],
  "client_context": {
    "source": "channel",
    "client_name": "slack-support",
    "connector_id": "slack_workspace_123",
    "external_provider": "slack",
    "external_conversation_id": "C123:T456",
    "external_message_id": "1700000000.000100",
    "room_kind": "channel"
  }
}
```

Required fields are `agent` and `message`. `client_context.source` is one of `channel`, `bridge`, `rest`, or `block`. Transport-specific fields are intentionally generic so runtimes can route, audit, or ignore them without depending on a concrete channel class.

### Output contract

`agents_chat_output_schema()` requires:

```json
{
  "session_id": "session-123",
  "reply": "Here is the summary.",
  "messages": [
    { "role": "assistant", "content": "Here is the summary." }
  ],
  "completed": true,
  "metadata": { "model": "provider-model", "latency_ms": 850 }
}
```

`reply` remains required even when `messages` contains richer output so simple clients can display one string.

### Permission model

`agents_chat_permission()` defaults to `current_user_can( 'manage_options' )` and is filterable through `agents_chat_permission`. Channel plugins, signed webhooks, OAuth adapters, and bridge clients can widen this gate per request after applying their own authentication.

## `WP_Agent_Channel` pipeline

`src/Channels/class-wp-agent-channel.php` is an abstract base class for transports such as Slack, Telegram, WhatsApp, email, or a custom bridge. A channel subclass owns transport I/O and supplies:

- `get_external_id_provider()`: stable provider/connector namespace.
- `get_external_id()`: external conversation/thread identity, or `null` to disable per-conversation continuity.
- `get_client_name()`: human-readable client name.
- `extract_message( array $data )`: convert webhook payload to user text.
- `send_response( string $text )` and `send_error( string $text )`: deliver results.
- `get_job_action()`: async WordPress action used by `receive()`.

`receive()` schedules `handle()` through `wp_schedule_single_event()` when a job action is configured; an empty action runs synchronously.

`handle()` runs this sequence:

1. `validate()`; `WP_Agent_Channel::SILENT_SKIP_CODE` drops messages without notifying the user.
2. `extract_message()` and reject empty text with `WP_Error( 'empty_message', ... )`.
3. Resolve session continuity with `lookup_session_id()`.
4. Run `on_processing_start()`.
5. Dispatch the canonical chat payload through `run_agent()`.
6. Run `on_processing_end()` in a `finally` block.
7. Deliver assistant messages or errors through `deliver_result()`.
8. Run `on_complete()` in a `finally` block.

`run_agent()` uses `wp_get_ability()` and the filterable ability slug `wp_agent_channel_chat_ability`, defaulting to `agents/chat`. Override it only when a channel must bypass or pin a different runtime.

## External message and session continuity

`build_chat_payload()` creates a `WP_Agent_External_Message` and exports its client context. Subclasses can fill transport-specific fields by overriding:

- `extract_attachments()`.
- `extract_external_message_id()`.
- `get_room_kind()`.
- `client_context_source()`.
- `get_connector_id()`.

Session continuity is keyed by connector ID, external conversation ID, and agent slug. The default path uses `WP_Agent_Channel_Session_Map`; subclasses that override `session_storage_key()` keep the legacy option-based behavior.

## Bridge and idempotency primitives

The channel module also includes generic bridge and safety contracts:

- `WP_Agent_Webhook_Signature`: webhook signature value helper.
- `WP_Agent_Message_Idempotency`, `WP_Agent_Message_Idempotency_Store`, and `WP_Agent_Transient_Message_Idempotency_Store`: duplicate-message protection.
- `WP_Agent_Bridge_Client`, `WP_Agent_Bridge_Queue_Item`, `WP_Agent_Bridge_Store`, `WP_Agent_Option_Bridge_Store`, and `WP_Agent_Bridge`: remote bridge queue/client/store primitives.
- `WP_Agent_Channel_Session_Store`, `WP_Agent_Option_Channel_Session_Store`, and `WP_Agent_Channel_Session_Map`: session map storage seams.

Protocol and authorization details are expanded in the existing companion pages: [`../external-clients.md`](../external-clients.md), [`../remote-bridge-protocol.md`](../remote-bridge-protocol.md), and [`../bridge-authorization.md`](../bridge-authorization.md).

## Evidence

- Implementation: `src/Channels/register-agents-chat-ability.php`, `src/Channels/class-wp-agent-channel.php`, `src/Channels/class-wp-agent-external-message.php`, `src/Channels/class-wp-agent-message-idempotency.php`, `src/Channels/class-wp-agent-bridge.php`.
- Tests: `tests/agents-chat-ability-smoke.php`, `tests/channels-smoke.php`, `tests/remote-bridge-smoke.php`, `tests/webhook-safety-smoke.php`.
