# Remote Bridge Protocol

Remote bridge clients are out-of-process processes that relay messages between an external surface and a WordPress agent runtime. They differ from direct `WP_Agent_Channel` subclasses because the client may be offline, webhook delivery may fail, and replies need a queue-first recovery path.

Agents API provides the generic PHP primitives for that protocol. It does not ship platform-specific clients, REST routes, product onboarding UI, or a chat runtime. See [Bridge Authorization And Onboarding](bridge-authorization.md) for the auth boundary.

## Relationship To Core Connectors

Bridge clients may include a `connector_id`. When the Core Connectors API is available, `WP_Agent_Bridge_Client::connector()` resolves that id through `wp_get_connector()`.

Connectors own product/service identity and settings metadata. Agents API stores only bridge runtime state that Connectors does not model: callback URL, opaque bridge context, pending queue items, and acknowledgements. Agents API should not duplicate the Core Connectors registry.

## Flow

1. A consumer registers a remote bridge client with `WP_Agent_Bridge::register_client()`.
2. The consumer executes inbound chat turns through the canonical `agents/chat` ability.
3. The consumer queues outbound bridge replies with `WP_Agent_Bridge::enqueue()`.
4. A remote bridge polls `WP_Agent_Bridge::pending()` when webhook delivery is unavailable or failed.
5. The remote bridge acknowledges accepted items with `WP_Agent_Bridge::ack()`.

Queued items remain pending until acknowledged. Best-effort webhook delivery must not delete an item; `ack()` is the removal boundary.

## Storage

The default store is option-backed and intentionally small. Hosts that need custom tables, external queues, leases, or retention policies can replace it with `WP_Agent_Bridge::set_store()`.
