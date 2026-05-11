# Channels And Remote Bridge

Agents API exposes transport-neutral channel and bridge contracts for moving external conversation traffic into the canonical `agents/chat` ability. Channel plugins own platform APIs, webhook routing, secrets, UI, delivery retries, and transport-specific policy.

Source evidence: `src/Channels/**`, `tests/channels-smoke.php`, `tests/agents-chat-ability-smoke.php`, `tests/remote-bridge-smoke.php`, and `tests/webhook-safety-smoke.php`.

## Canonical chat ability

`src/Channels/register-agents-chat-ability.php` registers `agents/chat` on `wp_abilities_api_init` and registers the `agents-api` category on `wp_abilities_api_categories_init`.

The ability is a dispatcher, not a runtime. It validates the canonical contract, finds a runtime handler through the `wp_agent_chat_handler` filter, and returns the handler result or a `WP_Error`.

### Input contract

Required fields:

- `agent` — registered agent slug or ID.
- `message` — user-side text for the turn.

Optional fields:

- `session_id` — existing session ID or `null`.
- `attachments` — runtime-defined array of channel-side attachments.
- `client_context` — transport context with `source`, `client_name`, `connector_id`, `external_provider`, `external_conversation_id`, `external_message_id`, and `room_kind`.

Representative payload:

```php
array(
	'agent'          => 'support-agent',
	'message'        => 'Can you check my order?',
	'session_id'     => 'session_123',
	'attachments'    => array(),
	'client_context' => array(
		'source'                   => 'channel',
		'client_name'              => 'slack',
		'connector_id'             => 'slack-workspace-a',
		'external_provider'        => 'slack',
		'external_conversation_id' => 'C123',
		'external_message_id'      => '1710000000.000100',
		'room_kind'                => 'channel',
	),
)
```

### Output contract

Required fields:

- `session_id` — conversation session ID for continuation.
- `reply` — primary assistant text.

Optional fields:

- `messages` — multi-message expansion, each with `role` and `content`.
- `completed` — whether the runtime considers the turn complete.
- `metadata` — runtime-defined usage, model, latency, tool-call, or audit data.

### Runtime handler registration

```php
add_filter(
	'wp_agent_chat_handler',
	static function ( $handler, array $input ) {
		if ( null !== $handler ) {
			return $handler;
		}

		return array( My_Plugin\Chat_Runtime::class, 'execute' );
	},
	10,
	2
);
```

`AgentsAPI\AI\Channels\register_chat_handler()` is a convenience wrapper for the same filter. If no handler is registered, or a handler returns an invalid shape, the dispatcher emits `agents_chat_dispatch_failed` and returns a `WP_Error`.

Default permission is `current_user_can( 'manage_options' )`, filtered by `agents_chat_permission`.

## `WP_Agent_Channel` pipeline

`WP_Agent_Channel` is an abstract base class for direct webhook-style transports such as Slack, Telegram, WhatsApp, or email. It has two entry points:

1. `receive( array $data ): void` — webhook side. The default schedules one async job with `wp_schedule_single_event()`. Subclasses can override for locking, queueing, or debounce behavior.
2. `handle( array $data ): array|WP_Error` — job side. Runs validation, message extraction, session lookup, agent dispatch, session persistence, response delivery, and completion hooks.

Subclasses implement:

- `get_external_id_provider()` — stable channel/provider name.
- `get_external_id()` — external conversation/thread ID or `null`.
- `get_client_name()` — lower-case client name for tracing.
- `extract_message()` — convert transport payload into user text.
- `send_response()` and `send_error()` — deliver output through the transport.
- `get_job_action()` — scheduled action name used by `receive()`.

Overridable lifecycle hooks include `validate()`, `on_processing_start()`, `on_processing_end()`, `on_complete()`, `send_notification()`, `extract_attachments()`, `extract_external_message_id()`, `get_room_kind()`, and `client_context_source()`.

`validate()` may return a `WP_Error` with code `silent_skip` to intentionally drop allow-list misses, loop-prevention events, or well-formed irrelevant events without sending an error to the user.

## Channel session continuity

Channels map `(connector_id, external_id, agent_slug)` to `session_id` through `WP_Agent_Channel_Session_Map`. The default map uses `WP_Agent_Option_Channel_Session_Store`, while `WP_Agent_Channel_Session_Store` lets hosts provide another implementation.

`WP_Agent_Channel::lookup_session_id()` reads the map before dispatch. `deliver_result()` persists a changed `session_id` before sending replies, so a slow or failing `send_response()` is less likely to lose continuity.

## External message shape

`WP_Agent_External_Message` normalizes a channel-side message into reusable runtime context. It carries text, connector ID, provider, conversation ID, message ID, room kind, attachments, and raw metadata. `WP_Agent_Channel::build_chat_payload()` converts it to the canonical `agents/chat` input.

## Webhook signature and idempotency

The channels module includes generic safety primitives:

- `WP_Agent_Webhook_Signature` for verifying signed webhook requests.
- `WP_Agent_Message_Idempotency` plus `WP_Agent_Message_Idempotency_Store` for deduplicating incoming message IDs.
- `WP_Agent_Transient_Message_Idempotency_Store` as a WordPress transient-backed default.

These primitives are transport-neutral. A concrete channel still decides which headers, secrets, and replay windows apply.

## Remote bridge

The bridge module supports out-of-process clients that poll or acknowledge queued assistant messages.

Public facade: `AgentsAPI\AI\Channels\WP_Agent_Bridge`.

Core operations:

- `set_store()` and `store()` configure the backing `WP_Agent_Bridge_Store`; default is `WP_Agent_Option_Bridge_Store`.
- `register_client( $client_id, $callback_url, $context, $connector_id )` registers or updates a `WP_Agent_Bridge_Client`.
- `enqueue( array $args )` creates a `WP_Agent_Bridge_Queue_Item`.
- `pending( $client_id, $limit, $session_ids )` lists pending queue items.
- `ack( $client_id, $queue_ids )` acknowledges delivered items.

`WP_Agent_Bridge_Queue_Item` fields include `queue_id`, `client_id`, `connector_id`, `agent`, `session_id`, `role`, `content`, `completed`, `created_at`, `delivery_status`, and `metadata`. Delivery status is one of `pending`, `delivered`, or `failed`; role is one of `assistant`, `system`, or `tool`.

## Boundary summary

Agents API owns the normalized contracts, dispatchers, and default option-backed stores. Channel and bridge consumers own remote network APIs, authentication material, retries, durable delivery guarantees, user-facing settings, support escalation, and the concrete chat runtime registered behind `agents/chat`.
