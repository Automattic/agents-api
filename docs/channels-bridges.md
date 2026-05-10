# Channels, External Clients, and Remote Bridges

Channels normalize inbound external messages and dispatch them to the canonical chat ability. Bridges model out-of-process clients and outbound queue state. Both are runtime seams; products own connectors, credentials, delivery workers, and UI.

## Direct channels

`WP_Agent_Channel` is the abstract transport base for webhook-style channels. Its receive pipeline is:

1. validate the request.
2. extract a `WP_Agent_External_Message`.
3. map the external conversation to a session ID.
4. run lifecycle hooks.
5. dispatch the agent through `agents/chat` by default.
6. deliver the result.
7. complete or send an error.

Channels provide override points for attachments, external message IDs, room kind, connector ID, source, session key, lifecycle hooks, response delivery, and job action. `SILENT_SKIP_CODE` lets channels drop non-chat or loop-prevention events without user-facing errors.

## Session maps

`WP_Agent_Channel_Session_Map` maps provider/conversation/client identity to runtime session IDs. `WP_Agent_Channel_Session_Store` is the store contract; `WP_Agent_Option_Channel_Session_Store` is the option-backed default.

Session continuity is separate from transcripts. A channel maps external conversations to sessions; transcript stores persist the full conversation.

## Chat ability

`agents/chat` is registered as a canonical Abilities API dispatcher. It accepts agent, message, optional session ID, attachments, and client context. It returns a session ID, reply, optional normalized messages, completed flag, and metadata.

The runtime handler is supplied through `wp_agent_chat_handler`. Permissions are filtered with `agents_chat_permission`; default permission is `current_user_can( 'manage_options' )`. Dispatch failures fire `agents_chat_dispatch_failed`.

## Remote bridges

`WP_Agent_Bridge` is the facade for out-of-process bridge clients. `WP_Agent_Bridge_Client` normalizes client ID, connector ID, callback URL, and opaque context. `WP_Agent_Bridge_Queue_Item` represents a pending outbound item scoped by client, agent, and session.

`WP_Agent_Bridge_Store` is the storage contract; `WP_Agent_Option_Bridge_Store` is the option-backed default. Queue items remain pending until acknowledged, and ack is scoped by client ID. Best-effort delivery must not delete work before ack.

## Webhook safety and idempotency

`WP_Agent_Webhook_Signature` verifies HMAC SHA-256 signatures with `hash_equals` and rejects empty secrets, malformed signatures, and wrong prefixes. `WP_Agent_Message_Idempotency` records provider-scoped seen markers through `WP_Agent_Message_Idempotency_Store`; `WP_Agent_Transient_Message_Idempotency_Store` provides TTL-backed defaults.

## Companion protocol docs

See [External Clients, Channels, And Bridges](external-clients.md), [Bridge Authorization And Onboarding](bridge-authorization.md), and [Remote Bridge Protocol](remote-bridge-protocol.md) for the longer protocol notes.

## Tests

`tests/channels-smoke.php` covers the channel pipeline, canonical payload shape, session mapping, custom session keys, silent skip, and async receive. `tests/remote-bridge-smoke.php` covers client normalization, connector lookup, pending queue filtering, and ack scoping. `tests/webhook-safety-smoke.php` covers signature and idempotency behavior.

## Related pages

- [Auth, permissions, caller context, and bridge authorization](auth-permissions.md)
- [Runtime, messages, conversation loop, and compaction](runtime-conversation.md)
- [Identity, transcripts, storage, and persistence boundaries](identity-transcripts-storage.md)
