# Default Stores Companion Proposal

Issue: https://github.com/Automattic/agents-api/issues/78

`agents-api` remains the canonical substrate for contracts, value objects, and provider-neutral runtime seams. Concrete persistence policy belongs outside this repository unless the policy itself becomes a generic contract.

## Boundary

| Stays in `Automattic/agents-api` | Belongs in the companion package |
| --- | --- |
| `WP_Agent_Memory_Store` | Guideline-backed memory store implementation |
| Memory value objects and metadata contracts | Markdown-file-backed memory store implementation |
| `WP_Agent_Conversation_Store` | CPT-backed transcript session store implementation |
| `WP_Agent_Transcript_Persister` | Wiring helpers that adapt default stores into consumer loops |
| `WP_Agent_Workspace_Scope` | Store-specific schema/bootstrap routines |
| `WP_Guidelines_Substrate` polyfill and capability constants | Store-specific migrations, retention defaults, and indexing policy |

The companion package should require `agents-api`. `agents-api` must not require, import, autoload, or feature-detect the companion package.

## Proposed Package Shape

Working package name: `automattic/agents-api-default-stores`.

Working WordPress plugin slug: `agents-api-default-stores`.

Suggested source layout:

```text
agents-api-default-stores/
+-- agents-api-default-stores.php
+-- composer.json
+-- README.md
+-- src/
|   +-- Memory/
|   |   +-- GuidelineMemoryStore.php
|   |   +-- MarkdownMemoryStore.php
|   +-- Transcripts/
|   |   +-- CptConversationTranscriptStore.php
|   +-- Runtime/
|       +-- StoreBackedTranscriptPersister.php
+-- tests/
    +-- guideline-memory-store-contract-smoke.php
    +-- markdown-memory-store-contract-smoke.php
    +-- cpt-transcript-store-contract-smoke.php
```

The class names above are placeholders for the companion repository. They are not canonical `agents-api` classes.

## Store Responsibilities

### Guideline Memory Store

- Implements `AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store`.
- Uses `WP_Guidelines_Substrate::POST_TYPE` and related constants as its storage substrate.
- Persists supported `WP_Agent_Memory_Metadata` fields as post meta or taxonomy data where appropriate.
- Honors `WP_Agent_Memory_Scope` identity dimensions without adding product-specific vocabulary.
- Defers capability semantics to the guideline substrate and WordPress capability checks.

### Markdown Memory Store

- Implements `AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store`.
- Encodes `WP_Agent_Memory_Scope` into deterministic filesystem paths owned by the companion.
- Documents path encoding as companion policy, not substrate policy.
- Supports compare-and-swap writes when the backing filesystem state can provide stable content hashes.
- Declares unsupported metadata fields through `WP_Agent_Memory_Store_Capabilities` instead of silently dropping them.

### CPT Transcript Store

- Implements `AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store`.
- Stores transcript sessions in a companion-owned CPT or table chosen by the companion.
- Preserves `WP_Agent_Workspace_Scope`, `user_id`, `agent_id`, title, metadata, provider, model, `provider_response_id`, timestamps, and pending-session dedup fields from the contract.
- Leaves chat UI listing, read state, retention scheduling, and analytics outside the generic store unless a future contract promotes them.

## Interop Tests

Each companion store should include a contract smoke test that imports `agents-api` as the ground truth and asserts:

- The implementation satisfies the canonical interface.
- Required methods return the canonical value objects or arrays described by the interface.
- Unsupported metadata fields are declared through capabilities/results.
- Workspace scope is preserved across write/read/list or create/read/update flows.
- Store-specific bootstrap can run without requiring a product plugin.

`agents-api` should not copy those implementation tests. Its own tests should continue to prove that the contracts load and remain backend-neutral.

## Extraction Sequence

1. Create the companion repository only after maintainers approve issue #78.
2. Add the package skeleton, plugin bootstrap, README, and test harness.
3. Extract the three concrete implementations from current consumers into the companion with no behavior changes beyond namespace and dependency wiring.
4. Add interop smoke tests against the canonical interfaces in `agents-api`.
5. Update each consumer in a focused PR to require the companion and delete its local duplicate stores.
6. Revisit schema, retention, and indexing policy in the companion after both consumers are using the extracted package.

## Open Decisions

- Whether the markdown-backed store should remain in the same companion package for v1 or split once its dependency surface diverges.
- Whether companion versioning should semver-lock to `agents-api` minor versions or declare a looser compatibility floor.
- Whether the guideline capability defaults should use the substrate's custom capability map or WordPress post capabilities; this should be decided in `agents-api` only if the substrate contract needs to change.
