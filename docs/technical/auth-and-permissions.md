# Auth, permissions, consent, and approvals

This page documents the security and approval contracts in `src/Auth/`, `src/Consent/`, and `src/Approvals/`. It is part of the [technical documentation index](index.md).

## Auth boundary

Agents API defines generic authentication and authorization value objects. It does not own product login UI, OAuth flows, token tables, REST controllers, access-management screens, support policy, or audit stores.

The source-level request-edge flow is:

```text
raw bearer token
  -> WP_Agent_Token_Authenticator
  -> WP_Agent_Token_Store::resolve_token_hash()
  -> WP_Agent_Token metadata + expiry checks
  -> WP_Agent_Caller_Context::from_headers()
  -> WP_Agent_Execution_Principal::agent_token()
  -> WP_Agent_WordPress_Authorization_Policy or host policy
```

Source evidence: `src/Auth/class-wp-agent-token-authenticator.php`, `src/Auth/class-wp-agent-token.php`, `src/Auth/class-wp-agent-caller-context.php`, `src/Auth/class-wp-agent-wordpress-authorization-policy.php`, `tests/authorization-smoke.php`, and `tests/caller-context-smoke.php`.

## Token metadata contract

`WP_Agent_Token` models metadata for a hashed bearer token. Raw token material is never stored or exported by the value object.

Constructor fields:

| Field | Purpose |
| --- | --- |
| `token_id` | Store-owned positive token identifier. |
| `agent_id` | Effective agent identifier. |
| `owner_user_id` | WordPress user ID whose capabilities bound execution. |
| `token_hash` | SHA-256 hash of the raw token. |
| `token_prefix` | Non-secret display/logging prefix. |
| `label` | Human-readable label. |
| `allowed_capabilities` | Optional capability allow-list that becomes a capability ceiling. |
| `expires_at`, `last_used_at`, `created_at` | UTC datetime metadata. |
| `client_id`, `workspace_id` | Optional client/workspace scoping identifiers. |
| `metadata` | JSON-serializable host-owned metadata. |

Important methods:

- `hash_token( string $raw_token ): string` returns a SHA-256 hash.
- `from_array( array $token ): self` reconstructs metadata from store arrays.
- `is_expired( ?int $now = null ): bool` treats unparsable expiry strings as expired.
- `capability_ceiling(): WP_Agent_Capability_Ceiling` builds the execution ceiling.
- `to_metadata_array(): array` exports metadata without `token_hash` or raw token material.

## Token store and authenticator responsibilities

`WP_Agent_Token_Store` is the host persistence boundary. `WP_Agent_Token_Authenticator` depends only on that interface.

`authenticate_bearer_token()` returns `null` when:

- the raw token is empty;
- a configured prefix does not match;
- the store cannot resolve the token hash;
- the token is expired;
- caller-context headers are malformed or exceed the configured depth ceiling.

Only after successful caller-context parsing does the authenticator call `touch_token( $token->token_id )`. Successful authentication returns `AgentsAPI\AI\WP_Agent_Execution_Principal::agent_token()` with token prefix/label, client/workspace IDs, capability restriction metadata, a `WP_Agent_Capability_Ceiling`, and optional `WP_Agent_Caller_Context`.

## Caller context headers

`WP_Agent_Caller_Context` carries agent-to-agent caller claims. It parses and exports these canonical headers:

| Header | Meaning |
| --- | --- |
| `X-Agents-Api-Caller-Agent` | Agent ID/slug on the caller host. |
| `X-Agents-Api-Caller-User` | User ID on the caller host, or `0`. |
| `X-Agents-Api-Caller-Host` | `self` or an absolute HTTP(S) URL for a remote host. |
| `X-Agents-Api-Chain-Depth` | Non-negative chain depth. |
| `X-Agents-Api-Chain-Root` | Stable originating request identifier. |

Missing headers produce `top_of_chain()`: no caller agent, caller user `0`, caller host `self`, depth `0`, and a generated chain root. Malformed headers throw `InvalidArgumentException` so authenticators can fail closed.

Validation rules include:

- chain depth must be non-negative and no greater than the configured maximum (`DEFAULT_MAX_CHAIN_DEPTH` is `16`);
- depth `0` cannot include remote caller identity;
- depth greater than `0` requires caller agent, remote caller host, and chain root;
- chained contexts cannot use `self` as caller host.

Hosts remain responsible for trust policy: shared keys, allow lists, mTLS, site identity, or product-specific cross-site validation.

## Access grants and authorization policy

`WP_Agent_Access_Grant` models role-based access from a WordPress user to an agent. Roles are ordered from lowest to highest privilege:

1. `viewer`
2. `operator`
3. `admin`

The value object validates positive user IDs, non-empty agent IDs, optional positive grant metadata, valid roles, and JSON-serializable metadata. `role_meets( $minimum_role )` compares the ordered roles.

`WP_Agent_WordPress_Authorization_Policy` provides the default WordPress-shaped policy:

- `can( $principal, $capability, $context = array() )` denies empty capabilities, denies when the capability ceiling does not allow the requested capability, requires a positive user ID, then delegates to `user_can()`.
- `can_access_agent( $principal, $agent_id, $minimum_role, $context = array() )` allows when the principal already targets the same effective agent, otherwise requires an access store and a grant whose role meets the minimum.

Consumers can replace the policy by implementing `WP_Agent_Authorization_Policy` or by supplying their own access/token stores while keeping these generic value objects.

## Consent policy contract

Consent is separate from authorization. `WP_Agent_Consent_Policy` and `WP_Agent_Default_Consent_Policy` model runtime decisions for operations with distinct user expectations:

- `store_memory`
- `use_memory`
- `store_transcript`
- `share_transcript`
- `escalate_to_human`

`AgentsAPI\AI\Consent\WP_Agent_Consent_Decision` carries `allowed`, `operation`, `reason`, and `audit_metadata` fields. The default policy is conservative: non-interactive modes are denied by default, and interactive modes require explicit operation-level consent. See `tests/consent-policy-smoke.php`.

## Pending action approval contract

Approvals in `src/Approvals/` model actions that cannot be applied directly by an agent or tool. The lifecycle is:

1. Runtime/tool proposes a `WP_Agent_Pending_Action` or returns an `approval_required` message envelope.
2. A store implementing `WP_Agent_Pending_Action_Store` persists/retrieves the proposal.
3. A resolver implementing `WP_Agent_Pending_Action_Resolver` accepts or rejects with resolver identity.
4. A product handler implementing `WP_Agent_Pending_Action_Handler` performs handler-level permission checks and applies or discards the proposal.
5. Terminal resolution metadata is recorded for audit.

`WP_Agent_Pending_Action_Status` defines the generic status vocabulary: `pending`, `accepted`, `rejected`, `expired`, and `deleted`. Approval value-shape and resolver contracts are covered by `tests/approval-action-value-shape-smoke.php`, `tests/pending-action-store-contract-smoke.php`, and `tests/approval-resolver-contract-smoke.php`.

## Failure and safety behavior

| Area | Behavior |
| --- | --- |
| Bearer authentication | Empty, unknown, expired, prefix-mismatched, or malformed caller-context requests return `null`. |
| Raw token material | Never exported from `WP_Agent_Token`; only hashes and prefixes are represented. |
| Capability ceilings | Deny when requested capability is outside the ceiling. |
| Access grants | Deny when no store/grant exists, role is invalid, or grant role is below the minimum. |
| Consent | Default policy denies by default unless explicit operation consent is present in an allowed mode. |
| Approvals | Agents API defines proposal/decision/store contracts only; product handlers own final permission and application semantics. |
