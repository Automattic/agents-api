# Authorization and Consent

This page documents the authentication, authorization, caller-context, identity, workspace, and consent contracts loaded from `src/Auth`, `src/Consent`, `src/Identity`, and `src/Workspace`.

Agents API defines safe value shapes and policy interfaces. Consumers own concrete token/access stores, REST controllers, admin UX, trust decisions for remote callers, and audit persistence.

## Execution principals

`AgentsAPI\AI\WP_Agent_Execution_Principal` represents the actor for one runtime request. It can describe WordPress user sessions, agent bearer tokens, CLI/cron contexts, chat contexts, and host-provided request metadata.

Core fields include:

- `acting_user_id` — WordPress user ID whose capabilities are evaluated.
- `effective_agent_id` — agent slug/ID used for the request.
- `auth_source` — source such as user session or agent token.
- `request_context` — context such as `rest`, `cli`, `cron`, or `chat`.
- `token_id`, `workspace_id`, and `client_id` — optional bearer-token/client scope.
- `capability_ceiling` — optional `WP_Agent_Capability_Ceiling` that restricts allowed capabilities.
- `caller_context` — optional `WP_Agent_Caller_Context` for agent-to-agent calls.
- `metadata` — JSON-friendly request metadata.

Hosts may resolve principals with the `agents_api_execution_principal` filter and should keep product-specific trust and authorization policy outside this substrate.

## Bearer token authentication

`WP_Agent_Token_Authenticator` resolves a raw bearer token into an execution principal. It is constructed with a `WP_Agent_Token_Store` and optional token prefix.

Authentication flow from `authenticate_bearer_token()`:

1. Trim and reject blank tokens.
2. If an authenticator prefix is configured, reject tokens that do not start with that prefix.
3. Hash the raw token with `WP_Agent_Token::hash_token()` and ask the store to resolve it.
4. Reject missing or expired token records.
5. Parse caller-context headers with `WP_Agent_Caller_Context::from_headers()`.
6. Reject malformed caller context without touching the token.
7. Touch the token record through `WP_Agent_Token_Store::touch_token()`.
8. Return `WP_Agent_Execution_Principal::agent_token()` with owner user, agent ID, token ID, workspace/client IDs, token metadata, capability ceiling, and caller context.

Concrete stores implement `WP_Agent_Token_Store`; Agents API never persists raw token material.

## Token and access contracts

`WP_Agent_Token` models token metadata: token ID, owner user ID, agent ID, token hash/prefix, label, expiry, last-used timestamp, optional client/workspace IDs, and optional allowed capability list. Raw token material is not exported.

`WP_Agent_Access_Grant` models user access to an agent with ordered generic roles:

- `viewer`
- `operator`
- `admin`

`WP_Agent_Access_Store` is the host-provided storage interface for grants. `WP_Agent_WordPress_Authorization_Policy::can_access_agent()` checks either the principal's effective agent or a grant fetched from the access store.

## Capability ceilings and WordPress authorization

`WP_Agent_Capability_Ceiling` restricts a principal to a user ID and optional allow-list of WordPress capabilities. `WP_Agent_WordPress_Authorization_Policy` composes that ceiling with `user_can()`:

- Empty capability names are denied.
- If a ceiling exists and does not allow the capability, the request is denied.
- If no positive user ID is available, the request is denied.
- Otherwise the policy delegates to `user_can( $user_id, $capability )` or a test/host callback.

This means token/client restrictions and WordPress capability checks both have to pass.

## Caller context for agent-to-agent requests

`WP_Agent_Caller_Context` parses and exports canonical headers:

| Header | Purpose |
| --- | --- |
| `X-Agents-Api-Caller-Agent` | Caller agent slug/ID on the caller host. |
| `X-Agents-Api-Caller-User` | Caller-host user ID, or `0`. |
| `X-Agents-Api-Caller-Host` | `self` or an absolute HTTP(S) URL. |
| `X-Agents-Api-Chain-Depth` | Non-negative chain depth; `0` means top of chain. |
| `X-Agents-Api-Chain-Root` | Stable originating request ID. |

Missing headers produce a generated top-of-chain context. Malformed headers throw `InvalidArgumentException`, letting authenticators fail closed.

Validation rules include:

- User ID and chain depth must be non-negative integers.
- Chain depth cannot exceed the configured maximum (`DEFAULT_MAX_CHAIN_DEPTH` is 16).
- Top-of-chain context cannot include remote caller identity.
- Chained context must provide a remote absolute caller host and chain root.
- `self` is not allowed for chained remote context.
- Metadata must be JSON serializable.

`is_cross_site()` tells hosts when the caller host is remote, but Agents API does not decide whether that remote caller is trusted.

## Workspace and identity boundaries

`AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope` is the generic workspace identity used by memory, transcripts, persistence, and audit adapters. It is a pair:

```php
array(
	'workspace_type' => 'code_workspace',
	'workspace_id'   => 'Automattic/agents-api@main',
)
```

Consumers can map sites, networks, headless runtimes, code workspaces, pull requests, or ephemeral environments into that pair.

`src/Identity` contains value objects and store contracts for materialized identities. The substrate defines the shape; host adapters decide how identities are stored and resolved.

## Consent operations and default policy

Consent is separate from authorization. `WP_Agent_Consent_Policy` asks whether runtime operations are allowed for a specific context. Operation constants live in `AgentsAPI\AI\Consent\WP_Agent_Consent_Operation`:

- `store_memory`
- `use_memory`
- `store_transcript`
- `share_transcript`
- `escalate_to_human`

`WP_Agent_Default_Consent_Policy` is intentionally conservative:

1. Non-interactive contexts are denied by default.
2. Interactive contexts still require explicit operation-level consent.
3. Decisions include audit metadata with policy, operation, interactive flag, mode, agent ID, and user ID.

Interactive modes are detected from `interactive: true` or mode/context/request fields equal to `chat`, `interactive`, or `rest`.

Explicit consent can be supplied as either a top-level boolean keyed by the operation or under a `consent` array:

```php
$decision = ( new WP_Agent_Default_Consent_Policy() )->can_store_transcript(
	array(
		'mode'    => 'chat',
		'user_id' => 123,
		'agent_id' => 'example-agent',
		'consent' => array(
			'store_transcript' => true,
		),
	)
);
```

`WP_Agent_Consent_Decision` exports allowed/denied state, operation, reason, and audit metadata so products can persist the decision beside memory writes, transcript persistence/sharing, or escalation records.

## Evidence

Source: `src/Auth/class-wp-agent-token-authenticator.php`, `src/Auth/class-wp-agent-caller-context.php`, `src/Auth/class-wp-agent-wordpress-authorization-policy.php`, `src/Auth/class-wp-agent-token.php`, `src/Auth/class-wp-agent-access-grant.php`, `src/Auth/class-wp-agent-capability-ceiling.php`, `src/Runtime/class-wp-agent-execution-principal.php`, `src/Consent/class-wp-agent-default-consent-policy.php`, `src/Consent/*`, `src/Workspace/*`, and `src/Identity/*`.

Tests: `tests/authorization-smoke.php`, `tests/caller-context-smoke.php`, `tests/execution-principal-smoke.php`, `tests/identity-smoke.php`, `tests/workspace-scope-smoke.php`, and `tests/consent-policy-smoke.php`.
