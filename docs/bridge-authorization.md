# Bridge Authorization And Onboarding

Remote bridge authorization answers one question:

```text
Can this out-of-process client act for this connector and send/receive messages for this agent scope?
```

This document defines the generic boundary. It intentionally does not add platform-specific clients, product copy, REST routes, or a required onboarding UI.

## Ownership Boundary

Use Core Connectors as the default external-service registry/settings layer whenever it can represent the service.

Connectors owns:

- connector ID, display name, description, logo, and type
- connector type names such as `agent_channel` and `agent_bridge`
- credential setting names for simple `api_key` and `none` authentication methods
- environment variable / constant / database setting metadata
- plugin install/activation metadata
- admin settings UI where the auth model fits Core Connectors

Agents API owns:

- canonical `agents/chat` semantics
- external message context and session mapping
- webhook verification and inbound idempotency helpers
- remote bridge queue, pending, and ack semantics
- per-agent bridge authorization state that Connectors does not model today
- runtime/host policy hooks deciding which agents a bridge may expose

Do not create a second connector registry in Agents API. Reference Core Connectors with `connector_id` and store only bridge runtime state that Connectors does not model.

## Connector Types

Recommended connector types:

```text
agent_channel
agent_bridge
messaging_provider
```

Use `agent_channel` for in-process WordPress plugins that receive webhooks directly and subclass `WP_Agent_Channel`.

Use `agent_bridge` for out-of-process clients that use `WP_Agent_Bridge` queue/pending/ack semantics.

Use `messaging_provider` only when the connector represents the provider rather than a concrete channel or bridge client.

Example connector metadata:

```php
add_action(
	'wp_connectors_init',
	static function ( WP_Connector_Registry $registry ): void {
		$registry->register(
			'matrix-bridge',
			array(
				'name'           => 'Matrix Bridge',
				'description'    => 'Matrix bridge for WordPress agents.',
				'type'           => 'agent_bridge',
				'authentication' => array( 'method' => 'api_key' ),
			)
		);
	}
);
```

## Authorization Model

Bridge authorization should be scoped by these dimensions:

- `connector_id`: optional Core Connectors service identity
- `client_id`: concrete bridge client registration in Agents API
- `agent`: agent slug the bridge may invoke
- capabilities: allowed bridge operations such as `send`, `pending`, and `ack`
- workspace/user scope when the host has scoped resources

The `WP_Agent_Bridge_Client` value object stores `client_id`, optional `connector_id`, optional callback URL, and opaque context. It is not a credential. Credentials and authorization decisions should be represented with existing auth primitives where possible.

Preferred credential path:

1. Use Connectors `api_key` metadata for simple service-level secrets, webhook secrets, bot tokens, or callback secrets.
2. Use `WP_Agent_Token` and `WP_Agent_Access_Grant` style primitives for scoped agent credentials.
3. Let hosts provide a bridge onboarding UI that mints scoped credentials after a WordPress user approves the connector/client/agent pairing.
4. Keep product-specific token tables, prompt guidance, and onboarding copy outside Agents API.

## Onboarding Flow Shape

A generic remote bridge onboarding flow can be implemented by consumers using these steps:

```text
client requests metadata
-> WordPress returns connector/client/agent authorization URL
-> user reviews requested agent + connector scope in WordPress
-> host policy validates the user may expose that agent
-> host mints scoped credential for client_id + connector_id + agent
-> client exchanges authorization proof for bridge credential
-> client uses credential for send/pending/ack routes
```

Agents API does not need to own every step yet. The important contract is that the credential is scoped to bridge operations and an agent/workspace boundary, not a site-wide admin session.

## What Connectors Cannot Model Yet

Core Connectors currently supports `api_key` and `none`. That is enough for many static secrets and settings screens, but not enough for the full remote bridge onboarding shape.

Keep these in Agents API or host/plugin code until Connectors grows native support:

- OAuth2 / PKCE details
- authorization code issuance and exchange
- per-agent scoped bridge tokens
- callback URL registrations
- send/pending/ack operation scopes
- host policy around which agents can be exposed

If multiple consumers need the same richer auth metadata, upstream that into Connectors instead of growing a parallel registry in Agents API.

## Route Guidance

Future REST routes should compose the existing primitives rather than inventing new state:

```text
POST /agents-api/v1/bridge/register  -> WP_Agent_Bridge::register_client()
POST /agents-api/v1/bridge/send      -> agents/chat + WP_Agent_Bridge::enqueue()
GET  /agents-api/v1/bridge/pending   -> WP_Agent_Bridge::pending()
POST /agents-api/v1/bridge/ack       -> WP_Agent_Bridge::ack()
```

Route auth should be a separate layer that validates the bridge credential and maps it to a `client_id`, `connector_id`, agent scope, and allowed operation. The queue layer should not need to know how the credential was minted.

## Non-Goals

- No platform-specific clients.
- No Data Machine token table names.
- No Roadie/Beeper/Matrix-specific copy.
- No required onboarding UI.
- No duplicate connector registry.
- No bridge route implementation until auth policy is settled.
