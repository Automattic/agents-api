# External Clients, Channels, And Bridges

Agents API provides the generic substrate that lets external conversation
surfaces talk to WordPress agents without every consumer rebuilding the same
transport, session, and delivery primitives.

This document describes the boundary. It is intentionally architectural: it
connects `WP_Agent_Channel`, bridge primitives, session mapping, webhook safety,
and Connectors metadata without committing to a full REST implementation in this
slice.

## Why This Belongs In Agents API

External clients are a common agent runtime requirement. Users should be able to
talk to WordPress agents from surfaces such as Telegram, Slack, Discord,
WhatsApp, SMS, email, Matrix, CLI relays, browser extensions, and mobile apps.

Those integrations repeat the same infrastructure:

```text
external event
-> authenticate or verify the caller
-> normalize the inbound message
-> ignore self/noise events
-> map external conversation to agent session
-> run the chat ability
-> deliver assistant output
-> suppress duplicates and recover from delivery failures
```

Agents API owns the shape and shared infrastructure. Product plugins and
channel plugins should own platform details, product policy, and UI.

## Two Integration Shapes

There are two recurring ways to connect an external conversation surface.

### Direct Channels

A direct channel is a WordPress plugin that receives the platform webhook and
sends the response back to the platform.

```text
Telegram / Slack / Discord / WhatsApp webhook
-> WordPress REST endpoint
-> WP_Agent_Channel subclass
-> chat ability
-> platform send API
```

`AgentsAPI\AI\Channels\WP_Agent_Channel` is the base class for this shape. It
owns the portable loop:

```text
validate
-> extract message
-> look up session_id
-> run chat ability
-> persist returned session_id
-> extract assistant replies
-> send responses
-> lifecycle hooks
```

By default, channels dispatch to the canonical `agents/chat` ability. Consumer
runtimes register a handler for that ability instead of asking every channel to
target a runtime-specific ability slug.

Concrete channel plugins still own platform-specific details such as Slack event
verification, Discord payload parsing, Telegram API calls, WhatsApp Graph API
settings, or local CLI process management.

### Remote Bridges

A remote bridge is an external daemon or client that talks to WordPress over a
generic bridge protocol. This is useful when the chat surface is not implemented
as a WordPress plugin process.

```text
external daemon / mobile client / message relay
-> Agents API bridge endpoint
-> chat ability
-> queue assistant response
-> webhook or poll delivery
-> client ack
```

Agents API owns the generic bridge registration, queue-first delivery, pending
polling, and ack logic so runtimes do not copy product-specific bridge code.

### JSON-RPC Protocol Endpoint

A protocol client is a browser or app component that speaks a generic JSON-RPC
chat wire, rather than a WordPress plugin (direct channel) or a bridge daemon.
This is the shape consumed by JSON-RPC chat UIs that send `message/send` and
`message/stream` over HTTP/SSE.

```text
JSON-RPC chat client
-> POST agents-api/v1/agent/{agent_id}   (JSON-RPC 2.0)
-> agents/chat ability  (message/send)   or
-> streaming runtime    (message/stream, Server-Sent Events)
-> Task / message/delta frames
```

`register-agents-chat-jsonrpc-route.php` registers this endpoint. It is a thin
envelope over the same `agents/chat` ability the direct channels use, so it
inherits the same handler, session store, run-control, and permissions.

**Wire (mapped onto the canonical chat contract):**

| Method | Transport | Request | Response |
| --- | --- | --- | --- |
| `message/send` | JSON | `{jsonrpc, id, method, params}` | `{jsonrpc, id, result: Task}` |
| `message/stream` | SSE | same, `Accept: text/event-stream` | `data: {…}` frames |

`params` is `MessageSendParams` (`{id?, sessionId?, message, metadata?}`); the
user's text is the concatenation of the message's `text` parts (parts with
`contentType: "context"` are excluded from the visible message). The response
`Task` is derived from the canonical `agents/chat` output:

```text
agents/chat output   JSON-RPC Task
------------------   -------------
run_id            -> id
session_id        -> sessionId
reply             -> status.message.parts[0].text
completed===false -> status.state: "input-required" (else "completed")
```

**Streaming (`message/stream`).** The endpoint emits two SSE frame types:
per-token `message/delta` notifications, then a terminal `result: Task` frame.

Token streaming requires a streaming runtime registered via the
`wp_agent_chat_stream_handler` filter (or the `register_chat_stream_handler()`
helper). The streaming handler is the token-by-token sibling of the synchronous
`wp_agent_chat_handler`:

```php
use function AgentsAPI\AI\Channels\register_chat_stream_handler;

register_chat_stream_handler(
	function ( array $input, callable $emit ): array {
		// Emit canonical deltas as tokens arrive.
		$emit( array( 'type' => 'content', 'text' => 'Hel' ) );
		$emit( array( 'type' => 'content', 'text' => 'lo' ) );
		// Tool deltas are also supported:
		// array( 'type' => 'tool_call',     'tool_call_id' => 't1', 'tool_name' => 'search', 'index' => 0 )
		// array( 'type' => 'tool_argument', 'tool_call_id' => 't1', 'text' => '{"q":"…"}', 'index' => 0 )

		// Return the canonical agents/chat output once complete.
		return array(
			'session_id' => $input['session_id'] ?? '',
			'reply'      => 'Hello',
			'run_id'     => $input['run_id'] ?? '',
			'completed'  => true,
		);
	}
);
```

When no streaming runtime is registered, `message/stream` degrades gracefully:
it runs the synchronous `agents/chat` handler and emits a single terminal Task
frame. The endpoint therefore works with any sync handler, just without
token-level granularity.

The provider/turn-runner itself stays out of Agents API — both the sync and
streaming handlers are consumer territory (the same boundary as `agents/chat`).

**End-to-end wiring.** A runnable endpoint composes three pieces:

```php
// 1. Durable sessions: opt into the default WordPress-native conversation store.
add_filter( 'agents_api_enable_default_conversation_store', '__return_true' );

// 2. A runtime: register a chat handler (sync) and/or a stream handler (tokens).
AgentsAPI\AI\Channels\register_chat_handler( $my_sync_handler );
AgentsAPI\AI\Channels\register_chat_stream_handler( $my_stream_handler );

// 3. Point the client at agents-api/v1/agent/{agent_id}.
```

Pieces 1 (the conversation store) and 3 (the endpoint) ship in Agents API; the
store is the persistence example, and the endpoint is the transport. Piece 2 —
the runtime that calls a provider — is supplied by a consumer plugin.

## Connectors API Boundary

WordPress Connectors API is the default registry and settings layer for
external services when available.

Connectors own service metadata:

- connector ID
- display name and description
- logo
- connector type
- credential setting names
- environment/constant/database key source metadata
- plugin install/activate metadata
- connected status metadata where the auth method fits

Agents API owns agent semantics:

- chat ability contract
- channel and bridge message context
- external conversation to session mapping
- webhook verification and inbound idempotency helpers
- queue-first remote bridge delivery
- pending and ack semantics
- bridge authorization state that Connectors cannot represent yet

Potential connector types:

```text
agent_channel
agent_bridge
messaging_provider
```

Potential connector IDs:

```text
telegram
slack
discord
whatsapp-cloud
local-relay
matrix
beeper
```

Connectors currently supports `api_key` and `none` authentication methods. That
is enough for many webhook secrets, bot tokens, and access tokens. It is not yet
enough to model OAuth2, PKCE, scoped per-agent bridge credentials, callback URL
registration, or per-client send/pending/ack scopes. Those richer bridge auth
states should remain in Agents API services unless or until Connectors grows a
native representation. The detailed boundary is defined in [Bridge Authorization And Onboarding](bridge-authorization.md).

## Shared Primitives

The following primitives are part of Agents API because they are shared by
direct channels and remote bridges.

### Chat Ability Contract

Agents API already registers `agents/chat` as the canonical runtime-agnostic
chat dispatcher. `WP_Agent_Channel` builds and dispatches this input shape:

```php
array(
	'agent'      => 'agent-slug',
	'message'    => 'user message text',
	'session_id' => 'optional-existing-session-id',
)
```

and expects either a single reply:

```php
array(
	'session_id' => 'session-id',
	'reply'      => 'assistant text',
	'completed'  => true,
)
```

or assistant messages:

```php
array(
	'messages' => array(
		array( 'role' => 'assistant', 'content' => 'assistant text' ),
	),
)
```

The bridge-ready input preserves those fields and adds optional metadata:

```php
array(
	'agent'          => 'agent-slug',
	'message'        => 'user message text',
	'session_id'     => 'optional-existing-session-id',
	'attachments'    => array(),
	'client_context' => array(
		'source'                       => 'channel',
		'connector_id'                 => 'slack',
		'client_name'                  => 'slack',
		'external_provider'            => 'slack',
		'external_conversation_id'     => 'opaque-channel-or-thread-id',
		'external_message_id'          => 'opaque-message-id',
		'room_kind'                    => 'dm',
		'context_source_type'          => 'optional-opaque-source-type',
		'context_source_id'            => 'optional-opaque-source-id',
		'context_scope_id'             => 'optional-opaque-scope-id',
		'context_selection_policy'     => 'optional-policy-name-or-object',
		'current_context_item_id'      => 'optional-opaque-item-id',
	),
)
```

Runtime adapters can decide which metadata affects prompt policy, routing,
storage, or observability.

Clients may include additional opaque `client_context` metadata when a turn
should be associated with selected material, whether that material is docs,
memory, tool output, search results, files, CRM records, support articles, or
another host-defined source. The example `context_*` keys above are conventions,
not schema requirements. Agents API preserves the envelope and forwards it to
host-owned permission/access hooks; it does not define the source model, lookup
algorithm, authorization policy, or prompt assembly rules. Hosts that support
scoped context selection should validate any opaque ids and policy values
against their own workspace/access model before using them.

### Normalized External Message

Transport plugins should parse platform payloads into a common message shape
before calling the agent loop:

```php
array(
	'text'                     => 'user message text',
	'connector_id'             => 'telegram',
	'external_provider'        => 'telegram',
	'external_conversation_id' => 'opaque-chat-id',
	'external_message_id'      => 'opaque-message-id',
	'sender_id'                => 'opaque-sender-id',
	'from_self'                => false,
	'room_kind'                => 'dm',
	'attachments'              => array(),
	'raw'                      => array(),
)
```

Agents API defines this normalized shape with `WP_Agent_External_Message`.
Channel plugins still own vendor-specific parsing.

### Session Mapping

Direct channels and remote bridges both need this mapping:

```text
connector_id + external_conversation_id + agent
-> session_id
```

`WP_Agent_Channel_Session_Map` provides this mapping with an option-backed
default and a replaceable store contract for channel subclasses and remote
bridge services.

### Webhook Safety

Most webhook channels need the same small safety helpers:

- HMAC SHA-256 verification with `hash_equals()`
- empty-secret rejection
- `sha256=<hex>` header support
- TTL-backed inbound duplicate suppression keyed by external message ID
- explicit silent-skip results for self messages and non-chat events

Agents API provides these helpers with `WP_Agent_Webhook_Signature` and
`WP_Agent_Message_Idempotency` so every channel does not copy slightly different
security-sensitive code.

### Remote Bridge Delivery

Remote bridge clients need durable delivery semantics:

```text
assistant response generated
-> enqueue queue item
-> attempt webhook delivery
-> keep pending until client acknowledges
-> allow polling as recovery path
```

Agents API provides generic PHP services for:

```text
register bridge callback
queue outbound bridge messages
list pending outbound messages
ack delivered messages
```

The bridge protocol calls the canonical chat ability contract rather than
any runtime-specific ability shape.

## What Stays Out Of Agents API

Agents API does not own product or platform details:

- Slack-specific event envelope parsing
- Discord-specific interaction handling
- Telegram-specific bot setup
- WhatsApp Graph API settings pages
- local bridge process management or pairing UI
- runtime-specific token table names
- runtime-specific mode prompts or bridge guidance copy
- product-specific onboarding copy
- product-specific approval UX

Those belong in channel plugins, bridge clients, or runtime adapters.

Related tracking issues:

- https://github.com/Automattic/agents-api/issues/100
- https://github.com/Automattic/agents-api/issues/101
- https://github.com/Automattic/agents-api/issues/102
- https://github.com/Automattic/agents-api/issues/103
- https://github.com/Automattic/agents-api/issues/104
