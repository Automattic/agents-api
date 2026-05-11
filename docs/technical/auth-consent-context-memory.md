# Auth, Consent, Context, And Memory

This page documents the security, identity, consent, retrieved-context, memory, transcript, guideline, workspace, and identity contracts in Agents API. It is source-derived from `src/Auth/*`, `src/Consent/*`, `src/Context/*`, `src/Memory/*`, `src/Transcripts/*`, `src/Guidelines/*`, `src/Workspace/*`, `src/Identity/*`, and smoke tests including `tests/authorization-smoke.php`, `tests/caller-context-smoke.php`, `tests/consent-policy-smoke.php`, `tests/context-*.php`, `tests/memory-metadata-contract-smoke.php`, `tests/guidelines-substrate-smoke.php`, `tests/workspace-scope-smoke.php`, `tests/identity-smoke.php`, and `tests/conversation-transcript-lock-smoke.php`.

## Execution principal

`AgentsAPI\AI\WP_Agent_Execution_Principal` represents the actor for one runtime request. It is created by host code, by the token authenticator, or by the `agents_api_execution_principal` filter.

The principal records:

- acting WordPress user ID;
- effective agent ID/slug;
- auth source and request context (`rest`, `cli`, `cron`, `chat`, and similar host contexts);
- optional token ID, client ID, and workspace ID;
- optional `WP_Agent_Capability_Ceiling`;
- optional `WP_Agent_Caller_Context` for chained agent-to-agent calls;
- JSON-friendly request metadata.

Consumers should resolve principals at request boundaries, then pass them into runtime adapters rather than re-reading global state deep inside a run.

## Access grants, tokens, and authorization

The auth module defines generic contracts and value objects. It does not own product tables or token management UI.

| Surface | Source | Purpose |
| --- | --- | --- |
| `WP_Agent_Access_Grant` | `src/Auth/class-wp-agent-access-grant.php` | Role-based grant between a WordPress user and an agent, optionally workspace-scoped. Roles are `viewer`, `operator`, and `admin`. |
| `WP_Agent_Access_Store` | `src/Auth/class-wp-agent-access-store.php` | Host-owned grant persistence interface. |
| `WP_Agent_Token` | `src/Auth/class-wp-agent-token.php` | Bearer-token metadata: token hash, prefix, label, owner, agent, expiry, last-used timestamp, client/workspace IDs, and optional capability restrictions. Raw token material is not exported. |
| `WP_Agent_Token_Store` | `src/Auth/class-wp-agent-token-store.php` | Host-owned token lookup and touch interface. |
| `WP_Agent_Token_Authenticator` | `src/Auth/class-wp-agent-token-authenticator.php` | Resolves a raw bearer token to an execution principal. |
| `WP_Agent_Capability_Ceiling` | `src/Auth/class-wp-agent-capability-ceiling.php` | Optional allow-list that intersects token/client permissions with WordPress capabilities. |
| `WP_Agent_Authorization_Policy` | `src/Auth/class-wp-agent-authorization-policy.php` | Policy interface. |
| `WP_Agent_WordPress_Authorization_Policy` | `src/Auth/class-wp-agent-wordpress-authorization-policy.php` | Default WordPress-shaped policy using ceiling checks and `user_can()`. |

`WP_Agent_Token_Authenticator::authenticate_bearer_token()` follows this sequence:

1. Trim the raw token and reject an empty value.
2. Reject when a configured prefix is required and the raw token does not match it.
3. Hash the raw token with `WP_Agent_Token::hash_token()` and resolve it through `WP_Agent_Token_Store::resolve_token_hash()`.
4. Reject missing or expired tokens.
5. Parse caller-context headers with `WP_Agent_Caller_Context::from_headers()`; malformed headers fail closed and the token is not touched.
6. Touch the token through the store.
7. Return `WP_Agent_Execution_Principal::agent_token()` with owner, agent, token, workspace, client, ceiling, caller context, and metadata.

The default authorization policy denies unless the capability ceiling allows the requested capability, when a ceiling exists, and the owner user passes `user_can()`.

## Caller context headers

`WP_Agent_Caller_Context` is a parser/value object for cross-site or chained agent-to-agent claims. Hosts remain responsible for trust decisions.

Canonical inbound headers:

- `X-Agents-Api-Caller-Agent`
- `X-Agents-Api-Caller-User`
- `X-Agents-Api-Caller-Host`
- `X-Agents-Api-Chain-Depth`
- `X-Agents-Api-Chain-Root`

Requests without caller headers parse as top-of-chain context. Malformed caller headers are rejected by the token authenticator. The default max depth is `WP_Agent_Caller_Context::DEFAULT_MAX_CHAIN_DEPTH` (`16`).

## Consent policy boundary

Consent contracts separate memory, transcript, sharing, and escalation operations:

| Operation | Meaning |
| --- | --- |
| `store_memory` | Persist consolidated agent memory. |
| `use_memory` | Read/use existing agent memory during a run. |
| `store_transcript` | Persist a raw conversation transcript. |
| `share_transcript` | Share a raw transcript outside its owning context. |
| `escalate_to_human` | Escalate a run or transcript to a human/support adapter. |

`WP_Agent_Consent_Policy` implementations return `AgentsAPI\AI\Consent\WP_Agent_Consent_Decision` objects. Decisions include `allowed`, `operation`, `reason`, and `audit_metadata`, and can be exported to arrays for audit persistence.

`WP_Agent_Default_Consent_Policy` is conservative: non-interactive modes are denied by default, and interactive modes require explicit per-operation consent. Products should supply their own policies when UX, retention, support routing, or legal requirements differ.

## Workspace and identity contracts

`AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope` is the generic workspace identity shared by memory, transcripts, persistence, and audit adapters. It is only:

```php
array(
	'workspace_type' => 'code_workspace',
	'workspace_id'   => 'Automattic/agents-api@main',
)
```

Consumers map sites, networks, repositories, pull requests, Studio sites, or ephemeral environments into that pair.

Identity contracts in `src/Identity` provide:

- `WP_Agent_Identity_Scope` for scoping materialized identities;
- `WP_Agent_Materialized_Identity` for concrete identity snapshots;
- `WP_Agent_Identity_Store` as the host-owned persistence interface.

Agents API defines the shape; hosts decide where identities are stored and how they are refreshed.

## Memory and context source registry

The context module separates eligible sources, composable sections, and assembled context:

```text
WP_Agent_Memory_Registry          -> source eligibility and metadata
WP_Agent_Context_Section_Registry -> ordered sections and render callbacks
WP_Agent_Composable_Context       -> runtime assembly result
Consumer adapters                 -> files, database rows, guidelines, external stores
```

`WP_Agent_Memory_Registry::register()` accepts source definitions with layer, priority, protection/editability flags, modes, retrieval policy, composable flags, context slugs, convention paths, and adapter metadata. Retrieval-policy vocabulary is defined by `WP_Agent_Context_Injection_Policy`:

- `always`
- `on_intent`
- `on_tool_need`
- `manual`
- `never`

The registry only defines vocabulary and filtering. Dynamic retrieval heuristics are consumer/runtime policy.

`WP_Agent_Context_Section_Registry` registers composable sections by context slug and priority. Each section has a render callback and optional mode/retrieval metadata. `compose()` returns ordered rendered sections for a runtime context.

## Retrieved context authority and conflict resolution

Retrieved context may conflict across memory, identity, platform, workspace, support, and conversation sources. Agents API provides generic authority tiers and conflict contracts.

Highest authority first:

1. `platform_authority`
2. `support_authority`
3. `workspace_shared`
4. `user_workspace_private`
5. `user_global`
6. `agent_identity`
7. `agent_memory`
8. `conversation`

`AgentsAPI\AI\Context\WP_Agent_Context_Item` is the JSON-friendly retrieved-item shape. It includes `content`, `scope`, `authority_tier`, `provenance`, `conflict_kind`, `conflict_key`, and caller-owned `metadata`.

`WP_Agent_Default_Context_Conflict_Resolver` resolves authoritative facts by authority tier and preferences by specificity then authority. Consumers can replace it through `WP_Agent_Context_Conflict_Resolver`.

## Memory store contract

`AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store` is the generic persistence interface for agent memory. It deliberately avoids scaffolding, abilities, prompt injection, and product policy.

Memory identity is represented by `WP_Agent_Memory_Scope`:

```text
(layer, workspace_type, workspace_id, user_id, agent_id, filename)
```

Store methods:

| Method | Responsibility |
| --- | --- |
| `capabilities()` | Declare metadata support through `WP_Agent_Memory_Store_Capabilities`. |
| `read( $scope, $metadata_fields )` | Return `WP_Agent_Memory_Read_Result`, including not-found and unsupported metadata fields. |
| `write( $scope, $content, $if_match, $metadata )` | Write full content, optionally with compare-and-swap hash semantics. |
| `exists( $scope )` | Check existence. |
| `delete( $scope )` | Idempotent delete. |
| `list_layer( $scope_query, $query )` | List top-level files in one layer and identity. |
| `list_subtree( $scope_query, $prefix, $query )` | Recursively list files under a prefix such as `daily` or `contexts`. |

`WP_Agent_Memory_Metadata` standardizes provenance and trust fields: source type, source reference, creator user/agent IDs, workspace, confidence, validator, authority tier, created/updated timestamps. `WP_Agent_Memory_Query` carries retrieval filters and ranking hints.

Stores must declare unsupported metadata fields so callers can distinguish missing data from unsupported persistence.

## Transcript contracts and locking

`AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store` defines a provider-neutral conversation/session persistence boundary. It supports workspace-stamped sessions, agent slugs, provider/model metadata, provider response IDs for provider-side continuity, and recent pending-session lookup.

`WP_Agent_Conversation_Lock` defines session locking for concurrent transcript writes. `WP_Agent_Null_Conversation_Lock` is a no-op implementation. The runtime loop can use a lock via `transcript_lock`, `transcript_lock_store`, or `transcript_store` options.

## Guideline substrate polyfill

When WordPress core or Gutenberg does not provide a guideline substrate, Agents API can register `wp_guideline` and `wp_guideline_type` through `WP_Guidelines_Substrate::register()` on `init` priority `9`.

The polyfill uses explicit capabilities:

- `read_agent_memory`
- `edit_agent_memory`
- `read_private_agent_memory`
- `edit_private_agent_memory`
- `read_workspace_guidelines`
- `edit_workspace_guidelines`
- `promote_agent_memory`

Private user-workspace memory is identified by metadata such as `_wp_guideline_scope=private_user_workspace_memory`, owner user ID, and workspace ID. Workspace-shared guidance uses `_wp_guideline_scope=workspace_shared_guidance` plus workspace ID.

Hosts can disable the polyfill with the `wp_guidelines_substrate_enabled` filter or register `wp_guideline` before Agents API.

## Failure modes and safety behavior

- Token auth returns `null` for empty, wrong-prefix, missing, expired, or malformed-caller-context tokens.
- Malformed caller context fails closed before token touch.
- Capability ceilings restrict default WordPress authorization.
- Default consent denies non-interactive operations and requires explicit operation-level consent in interactive modes.
- Memory write conflicts should return a write-result failure with `error = conflict` when a store supports compare-and-swap.
- Missing memory reads return a not-found result rather than throwing.
- Transcript lock contention can stop a runtime loop before model/tool execution.
