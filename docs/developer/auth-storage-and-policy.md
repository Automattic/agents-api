# Auth, Storage Contracts, Context, And Policy

Agents API defines the generic security, storage, consent, context, memory, transcript, and approval contracts that runtime consumers can compose. It deliberately avoids owning product-specific tables, consent UX, support routing, token issuance screens, or retrieval heuristics.

## Execution principals and bearer tokens

`AgentsAPI\AI\WP_Agent_Execution_Principal` represents the actor and agent context for one runtime request. It can carry:

- Acting WordPress user ID.
- Effective agent ID/slug.
- Auth source and request context (`rest`, `cli`, `cron`, `chat`, or another caller-defined source).
- Optional token ID, workspace ID, client ID, caller context, capability ceiling, and request metadata.

`WP_Agent_Token_Authenticator` resolves a raw bearer token into an execution principal:

1. Trim the token and reject empty strings.
2. Enforce an optional token prefix.
3. Hash the raw token with `WP_Agent_Token::hash_token()`.
4. Ask a consumer-owned `WP_Agent_Token_Store` to resolve the hash.
5. Reject missing or expired tokens.
6. Parse caller headers through `WP_Agent_Caller_Context::from_headers()`; malformed caller context fails closed before the token is touched.
7. Touch the token in the store.
8. Return `WP_Agent_Execution_Principal::agent_token()` populated with token, workspace, client, capability-ceiling, caller-context, and metadata.

Token metadata (`WP_Agent_Token`) includes token hash, prefix, label, owner user, agent, expiry, last-used time, optional client/workspace identifiers, and optional capability restrictions. Raw token material is not exposed by metadata exports.

## Caller context and cross-site chains

`WP_Agent_Caller_Context` parses agent-to-agent caller claims from canonical headers:

- `X-Agents-Api-Caller-Agent`.
- `X-Agents-Api-Caller-User`.
- `X-Agents-Api-Caller-Host`.
- `X-Agents-Api-Chain-Depth`.
- `X-Agents-Api-Chain-Root`.

Requests without caller headers parse as top-of-chain. Malformed headers or excessive depth throw and cause token authentication to fail closed. The default max depth is `WP_Agent_Caller_Context::DEFAULT_MAX_CHAIN_DEPTH` (`16`). Hosts still own trust policy for remote caller hosts.

## Capability ceilings and authorization

`WP_Agent_Capability_Ceiling` models optional allow-lists imposed by tokens or clients. `WP_Agent_WordPress_Authorization_Policy` denies a requested capability unless both conditions pass:

- The token/client ceiling allows the capability, when a ceiling exists.
- The acting/owner WordPress user has the capability via `user_can()`.

Hosts can replace the policy by implementing `WP_Agent_Authorization_Policy`.

`WP_Agent_Access_Grant` models a role-based user-to-agent grant, optionally scoped by workspace. Roles are generic and ordered: `viewer`, `operator`, `admin`. Storage belongs to `WP_Agent_Access_Store` implementations supplied by consumers.

## Workspace identity

`AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope` is the generic workspace identity shared by memory, transcripts, persistence, and audit adapters. It is a pair:

```php
$scope = AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_parts(
	'code_workspace',
	'Automattic/agents-api@main'
);

$scope->to_array();
// array(
//   'workspace_type' => 'code_workspace',
//   'workspace_id'   => 'Automattic/agents-api@main',
// )
```

Consumers map WordPress sites, networks, headless runtimes, Studio sites, repositories, pull requests, or ephemeral environments into that generic pair.

## Memory store contracts

Memory contracts live in `src/Memory/**` under `AgentsAPI\Core\FilesRepository`.

| Surface | Purpose |
| --- | --- |
| `WP_Agent_Memory_Scope` | Identifies memory by `layer`, `workspace_type`, `workspace_id`, `user_id`, `agent_id`, and `filename`. |
| `WP_Agent_Memory_Metadata` | Provenance/trust metadata: source type/ref, creator IDs, workspace, confidence, validator, authority tier, timestamps. |
| `WP_Agent_Memory_Store_Capabilities` | Declares metadata fields a store can persist, read, filter, or rank. |
| `WP_Agent_Memory_Query` | Retrieval filters and ranking hints. |
| `WP_Agent_Memory_Read_Result`, `WP_Agent_Memory_Write_Result`, `WP_Agent_Memory_List_Entry` | Result shapes for store operations. |
| `WP_Agent_Memory_Validator` and `WP_Agent_Memory_Validation_Result` | Workspace-aware validation seam for stale/invalid memories. |
| `WP_Agent_Memory_Store` | Persistence contract for read/write/delete/list operations. |

`WP_Agent_Memory_Store` requires implementations to translate scope to physical storage, return stable content hashes for compare-and-swap writes, honor the scope identity model, and declare metadata support. The contract includes:

- `capabilities()`.
- `read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS )`.
- `write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?WP_Agent_Memory_Metadata $metadata = null )`.
- `exists()`.
- `delete()`.
- `list_layer()`.
- `list_subtree()`.

Section parsing, scaffolding, editability gating, ability permissions, prompt-injection policy, and registry-driven convention-path semantics are intentionally higher-level consumer concerns.

## Context registries and conflict resolution

Context contracts live in `src/Context/**`.

- `WP_Agent_Memory_Registry` registers memory/context sources by layer, mode, priority, protection/editability flags, retrieval policy, composability, context slug, and convention-path metadata.
- `WP_Agent_Context_Section_Registry` registers renderable context sections and composes sections for a runtime target.
- `WP_Agent_Context_Injection_Policy` defines retrieval policy vocabulary: `always`, `on_intent`, `on_tool_need`, `manual`, and `never`.
- `WP_Agent_Context_Item` represents one retrieved item with content, scope, authority tier, provenance, conflict kind/key, and metadata.
- `WP_Agent_Default_Context_Conflict_Resolver` resolves authoritative facts by authority tier and preferences by specificity-then-authority.

Authority tiers, highest first, are defined by `WP_Agent_Context_Authority_Tier`: `platform_authority`, `support_authority`, `workspace_shared`, `user_workspace_private`, `user_global`, `agent_identity`, `agent_memory`, and `conversation`.

## Transcript contracts

Transcript contracts live in `src/Transcripts/**` under `AgentsAPI\Core\Database\Chat`.

- `WP_Agent_Conversation_Store` defines session creation, lookup, update, and message persistence surfaces for concrete stores.
- `WP_Agent_Conversation_Lock` defines per-session lock acquisition/release.
- `WP_Agent_Null_Conversation_Lock` is a no-op lock implementation.

The runtime loop can use a lock via `transcript_lock`, `transcript_lock_store`, or `transcript_store`, and a transcript persister via `WP_Agent_Transcript_Persister`. Concrete transcript tables, retention, provider response ID persistence, and operational cleanup are consumer-owned.

## Consent and approvals

Consent contracts live in `src/Consent/**`.

`WP_Agent_Consent_Operation` defines operation names such as `store_memory`, `use_memory`, `store_transcript`, `share_transcript`, and `escalate_to_human`. `WP_Agent_Default_Consent_Policy` is conservative: non-interactive modes are denied by default, and interactive modes require explicit per-operation consent.

Approval contracts live in `src/Approvals/**`.

- `WP_Agent_Pending_Action` is the JSON-friendly pending proposal/audit value.
- `WP_Agent_Pending_Action_Status` defines `pending`, `accepted`, `rejected`, `expired`, and `deleted`.
- `WP_Agent_Approval_Decision` models accept/reject decisions.
- `WP_Agent_Pending_Action_Store` defines durable queue/audit operations.
- `WP_Agent_Pending_Action_Resolver` and `WP_Agent_Pending_Action_Handler` split decision resolution from product-specific apply/reject behavior.

Consumers own durable queues, UI, permission checks, handler implementations, and audit persistence.

## Guideline substrate

`src/Guidelines/**` provides a shared `wp_guideline` / `wp_guideline_type` substrate polyfill when Core or Gutenberg do not provide one. Access is scoped through explicit agent-memory and workspace-guideline capabilities rather than ordinary private-post semantics. Hosts can disable the polyfill with `wp_guidelines_substrate_enabled` or register the post type earlier.

## Evidence

- Implementation: `src/Auth/**`, `src/Workspace/**`, `src/Memory/**`, `src/Context/**`, `src/Transcripts/**`, `src/Consent/**`, `src/Approvals/**`, `src/Guidelines/**`.
- Tests: `tests/authorization-smoke.php`, `tests/caller-context-smoke.php`, `tests/execution-principal-smoke.php`, `tests/workspace-scope-smoke.php`, `tests/memory-metadata-contract-smoke.php`, `tests/context-registry-smoke.php`, `tests/context-authority-smoke.php`, `tests/conversation-transcript-lock-smoke.php`, `tests/consent-policy-smoke.php`, `tests/pending-action-store-contract-smoke.php`, `tests/approval-resolver-contract-smoke.php`, `tests/guidelines-substrate-smoke.php`.
