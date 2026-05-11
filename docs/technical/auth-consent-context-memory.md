# Auth, Consent, Context, and Memory

This page documents the security, consent, context, and memory contracts that bound agent execution without prescribing product storage or UX.

Source evidence: `src/Auth/*`, `src/Consent/*`, `src/Context/*`, `src/Memory/*`, `src/Workspace/*`, `src/Guidelines/*`, `tests/authorization-smoke.php`, `tests/caller-context-smoke.php`, `tests/consent-policy-smoke.php`, `tests/context-*.php`, `tests/memory-metadata-contract-smoke.php`, `tests/workspace-scope-smoke.php`, and `tests/guidelines-substrate-smoke.php`.

## Auth boundary

Agents API provides generic auth value objects and policies. Hosts provide request routing, token storage, trust decisions, and product-specific authorization UX.

The bearer-token flow is:

```text
raw bearer token
-> WP_Agent_Token::hash_token()
-> WP_Agent_Token_Store::resolve_token_hash()
-> reject missing/expired/prefix-mismatched tokens
-> parse WP_Agent_Caller_Context from headers
-> touch token only after caller context is valid
-> return WP_Agent_Execution_Principal::agent_token()
```

`WP_Agent_Token_Authenticator::authenticate_bearer_token()` returns `null` on empty tokens, prefix mismatches, unresolved tokens, expired tokens, or malformed caller-context headers. It does not expose raw token material.

## Token and access value objects

`WP_Agent_Token` stores token metadata for hashed bearer credentials:

| Field | Purpose |
| --- | --- |
| `token_id` | Store-owned positive integer id. |
| `agent_id` | Effective agent identifier. |
| `owner_user_id` | WordPress user whose capabilities bound execution. |
| `token_hash` | SHA-256 hash of raw token material. Not exported by `to_metadata_array()`. |
| `token_prefix` | Non-secret display/logging prefix. |
| `allowed_capabilities` | Optional allow-list used to build a capability ceiling. |
| `expires_at`, `last_used_at`, `created_at` | UTC lifecycle timestamps. |
| `client_id`, `workspace_id` | Optional client and workspace scope. |
| `metadata` | JSON-serializable host metadata. |

`WP_Agent_Access_Grant` models a role-based grant between a WordPress user and an agent. Roles are ordered from lowest to highest privilege: `viewer`, `operator`, `admin`. `role_meets()` compares a grant with a required role. Concrete stores implement `WP_Agent_Access_Store`.

`WP_Agent_Capability_Ceiling` intersects token/client restrictions with a user's WordPress capabilities. `WP_Agent_WordPress_Authorization_Policy` denies unless the ceiling allows the requested capability and `user_can()` allows it for the acting/owner user.

## Caller context headers

`WP_Agent_Caller_Context` carries cross-site agent-to-agent caller claims. It is a parser and value object; hosts decide whether to trust the remote host and token.

Canonical headers:

| Header | Meaning |
| --- | --- |
| `X-Agents-Api-Caller-Agent` | Agent id/slug on the caller host. |
| `X-Agents-Api-Caller-User` | User id on the caller host, or `0`. |
| `X-Agents-Api-Caller-Host` | `self` or an absolute HTTP(S) URL. |
| `X-Agents-Api-Chain-Depth` | Non-negative chain depth. |
| `X-Agents-Api-Chain-Root` | Stable originating request id. |

Missing headers produce a top-of-chain context. Malformed headers throw `InvalidArgumentException`, and the token authenticator fails closed before touching the token. Default max depth is `WP_Agent_Caller_Context::DEFAULT_MAX_CHAIN_DEPTH` (`16`).

## Execution principals and workspace scope

`AgentsAPI\AI\WP_Agent_Execution_Principal` represents one runtime actor: acting user id, effective agent id/slug, auth source, request context, optional token id, workspace id, client id, capability ceiling, caller context, and JSON-friendly metadata.

`AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope` is the generic workspace identity shared by memory, transcripts, persistence, and audit adapters. It is deliberately `(workspace_type, workspace_id)` rather than a WordPress site id so consumers can map sites, networks, code workspaces, pull requests, or ephemeral environments without changing generic contracts.

## Consent policy

Consent is separate from authorization. `WP_Agent_Consent_Policy` implementations answer whether a runtime operation is allowed under user expectations and product policy.

Operations are defined by `AgentsAPI\AI\Consent\WP_Agent_Consent_Operation`:

- `store_memory`
- `use_memory`
- `store_transcript`
- `share_transcript`
- `escalate_to_human`

`WP_Agent_Default_Consent_Policy` is conservative:

1. Non-interactive modes are denied by default.
2. Interactive contexts are allowed only when explicit per-operation consent is present.
3. Decisions return `WP_Agent_Consent_Decision` with `allowed`, `operation`, `reason`, and `audit_metadata`.

```php
$decision = ( new WP_Agent_Default_Consent_Policy() )->can_store_transcript(
	array(
		'mode'    => 'chat',
		'user_id' => get_current_user_id(),
		'agent_id' => 'example-agent',
		'consent' => array( 'store_transcript' => true ),
	)
);
```

Products should persist consent decision arrays beside memory writes, transcript persistence/share events, or escalation records they apply.

## Memory and context source registry

`WP_Agent_Memory_Registry` registers memory/context sources without assuming those sources are files. Its normalized metadata includes:

| Field | Purpose |
| --- | --- |
| `id` | Source identifier such as `workspace/instructions`. |
| `layer` | Normalized `WP_Agent_Memory_Layer`. |
| `priority` | Sort order. |
| `protected`, `editable`, `capability` | Generic editability and access metadata. |
| `modes` | Eligible runtime modes, or `all`. |
| `retrieval_policy` | `always`, `on_intent`, `on_tool_need`, `manual`, or `never`. |
| `composable`, `context_slug` | Whether this source contributes to composable context. |
| `convention_path` | Optional adapter hint such as `AGENTS.md`; not storage identity. |
| `external_projection_target` | Optional adapter projection hint. |
| `label`, `description`, `meta` | Display and extension metadata. |

`agents_api_memory_sources` lets extensions register sources lazily when sources are first resolved.

`WP_Agent_Context_Section_Registry` composes ordered context sections. `WP_Agent_Composable_Context` carries assembled context output.

## Retrieved context authority and conflict resolution

Context items carry both content and provenance:

- `content`
- `scope`
- `authority_tier`
- `provenance`
- `conflict_kind`
- `conflict_key`
- `metadata`

Authority tiers are generic, with platform/support/workspace/user/agent/conversation ordering defined in `WP_Agent_Context_Authority_Tier`. `WP_Agent_Default_Context_Conflict_Resolver` resolves authoritative facts by authority tier and preferences by specificity then authority.

## Memory store contract

`AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store` is the persistence interface for agent memory. Implementations map a `WP_Agent_Memory_Scope` to physical storage and declare metadata support.

Methods:

- `capabilities()` returns `WP_Agent_Memory_Store_Capabilities`.
- `read( WP_Agent_Memory_Scope $scope, array $metadata_fields )` returns `WP_Agent_Memory_Read_Result` or not-found.
- `write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match, ?WP_Agent_Memory_Metadata $metadata )` supports compare-and-swap when implemented.
- `exists()` checks scope existence.
- `delete()` is idempotent.
- `list_layer()` lists top-level files by layer and identity.
- `list_subtree()` recursively lists a path prefix.

Memory scope identity is `(layer, workspace_type, workspace_id, user_id, agent_id, filename)`. Section parsing, scaffold creation, prompt injection policy, ability permissions, and convention-path semantics remain higher-level concerns.

## Memory metadata

`WP_Agent_Memory_Metadata` standardizes provenance and trust fields:

- `source_type`: `user_asserted`, `agent_inferred`, `workspace_extracted`, `system_generated`, `curated`, or `imported`;
- `source_ref`;
- `created_by_user_id` and `created_by_agent_id`;
- `workspace`;
- `confidence` from `0.0` to `1.0`;
- `validator`;
- `authority_tier`: `low`, `medium`, `high`, or `canonical`;
- `created_at` and `updated_at`.

`with_defaults()` applies conservative trust defaults. Agent-inferred memories default to lower confidence and authority than user-asserted, curated, or system-generated memories.

## Guidelines substrate

When Core/Gutenberg does not provide `wp_guideline`, Agents API can register a guideline substrate. It uses explicit capabilities such as `read_agent_memory`, `edit_agent_memory`, `read_private_agent_memory`, `edit_private_agent_memory`, `read_workspace_guidelines`, `edit_workspace_guidelines`, and `promote_agent_memory`. Hosts can disable the polyfill with `wp_guidelines_substrate_enabled` or register `wp_guideline` before Agents API.

## Future coverage

Future passes should add method-by-method reference pages for `WP_Agent_Execution_Principal`, guideline post/taxonomy internals, identity materialization stores, and each memory result value object. This page documents the integration-level contracts and storage/auth boundaries needed by new consumers.