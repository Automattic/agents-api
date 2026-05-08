# External Clients, Channels, And Bridges

Agents API should provide the generic substrate that lets external conversation
surfaces talk to WordPress agents without every consumer rebuilding the same
transport, session, and delivery primitives.

This document describes the boundary. It is intentionally architectural: it
connects the merged `WP_Agent_Channel` base class to the follow-up bridge and
Connectors work without committing to a full REST implementation in this slice.

## Why This Belongs In Agents API

External clients are a common agent runtime requirement. Users should be able to
talk to WordPress agents from surfaces such as Telegram, Slack, Discord,
WhatsApp, SMS, email, Matrix, Beeper, CLI relays, browser extensions, and mobile
apps.

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

Agents API should own the shape and shared infrastructure. Product plugins and
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
Beeper / Matrix / external daemon / mobile client
-> Agents API bridge endpoint
-> chat ability
-> queue assistant response
-> webhook or poll delivery
-> client ack
```

The Data Machine chat bridge currently implements this pattern for Data Machine.
The generic parts should move toward Agents API so other runtimes do not need to
copy bridge registration, queue-first delivery, pending polling, and ack logic.

## Connectors API Boundary

WordPress Connectors API should be the default registry and settings layer for
external services when available.

Connectors should own service metadata:

- connector ID
- display name and description
- logo
- connector type
- credential setting names
- environment/constant/database key source metadata
- plugin install/activate metadata
- connected status metadata where the auth method fits

Agents API should own agent semantics:

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
wacli
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

The following primitives are candidates for Agents API because they are shared
by direct channels and remote bridges.

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

The bridge-ready extension should preserve those fields and add optional
metadata:

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
	),
)
```

Runtime adapters can decide which metadata affects prompt policy, routing,
storage, or observability.

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

Agents API should define this normalized shape as a reusable value contract.
Channel plugins should still own vendor-specific parsing.

### Session Mapping

Direct channels and remote bridges both need this mapping:

```text
connector_id + external_conversation_id + agent
-> session_id
```

`WP_Agent_Channel` has an option-backed default. A follow-up service or
interface should make the mapping reusable across channel subclasses and remote
bridge services while preserving override points for custom stores.

### Webhook Safety

Most webhook channels need the same small safety helpers:

- HMAC SHA-256 verification with `hash_equals()`
- empty-secret rejection
- `sha256=<hex>` header support
- TTL-backed inbound duplicate suppression keyed by external message ID
- explicit silent-skip results for self messages and non-chat events

Agents API should provide these helpers so every channel does not copy slightly
different security-sensitive code.

### Remote Bridge Delivery

Remote bridge clients need durable delivery semantics:

```text
assistant response generated
-> enqueue queue item
-> attempt webhook delivery
-> keep pending until client acknowledges
-> allow polling as recovery path
```

This implies generic services or REST endpoints for:

```text
register bridge callback
send inbound message
list pending outbound messages
ack delivered messages
```

The bridge protocol should call the canonical chat ability contract rather than
any runtime-specific ability shape.

## What Stays Out Of Agents API

Agents API should not own product or platform details:

- Slack-specific event envelope parsing
- Discord-specific interaction handling
- Telegram-specific bot setup
- WhatsApp Graph API settings pages
- wacli process management or QR pairing UI
- Data Machine token table names
- Data Machine mode prompts or bridge guidance copy
- Roadie/Beeper onboarding copy
- product-specific approval UX

Those belong in channel plugins, bridge clients, or runtime adapters.

## Implementation Sequence

The next slices should be small and reviewable:

1. Document and extend the `WP_Agent_Channel` chat ability contract for bridge
   metadata.
2. Add normalized external message and session mapping primitives.
3. Add webhook signature and inbound idempotency helpers.
4. Design the remote bridge register/send/pending/ack services.
5. Design bridge authorization around Connectors-backed service identity and
   Agents API-owned scoped credentials.

Related tracking issues:

- https://github.com/Automattic/agents-api/issues/100
- https://github.com/Automattic/agents-api/issues/101
- https://github.com/Automattic/agents-api/issues/102
- https://github.com/Automattic/agents-api/issues/103
- https://github.com/Automattic/agents-api/issues/104
