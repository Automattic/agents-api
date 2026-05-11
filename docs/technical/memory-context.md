# Memory, Context, and Guidelines

This page documents the developer-facing contracts for memory stores, memory/context source registration, composable context, retrieved-context authority, and the guideline substrate. It covers `src/Memory`, `src/Context`, and `src/Guidelines`.

Agents API defines identity, metadata, retrieval vocabulary, and storage interfaces. Consumers decide how memory is physically stored, ranked, projected, edited, and audited.

## Memory store boundary

`AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store` is the generic persistence interface for agent memory. It deliberately does not define workflows, jobs, abilities, prompt injection, scaffold creation, or UI.

Implementations are responsible for:

- Translating `WP_Agent_Memory_Scope` into a physical key such as a path, row, or external record.
- Returning stable content hashes so callers can implement compare-and-swap writes through `if_match`.
- Honoring the identity model: `layer`, `workspace_type`, `workspace_id`, `user_id`, `agent_id`, and `filename`.
- Declaring which metadata fields they can persist, read, filter, and rank.

Interface methods:

| Method | Purpose |
| --- | --- |
| `capabilities()` | Return `WP_Agent_Memory_Store_Capabilities`. |
| `read( $scope, $metadata_fields )` | Read a scoped memory file or return `WP_Agent_Memory_Read_Result::not_found()`. |
| `write( $scope, $content, $if_match, $metadata )` | Persist content, optionally with compare-and-swap and provenance metadata. |
| `exists( $scope )` | Check whether scoped content exists. |
| `delete( $scope )` | Idempotently delete scoped content. |
| `list_layer( $scope_query, $query )` | List top-level files in one layer and identity. |
| `list_subtree( $scope_query, $prefix, $query )` | Recursively list files under a layer-relative prefix. |

`WP_Agent_Memory_Write_Result`, `WP_Agent_Memory_Read_Result`, and `WP_Agent_Memory_List_Entry` carry result data and unsupported metadata-field information so callers can distinguish absent metadata from unsupported store capabilities.

## Memory identity and metadata

`WP_Agent_Memory_Scope` identifies one memory item by layer, workspace, user, agent, and filename. `WP_Agent_Workspace_Scope` provides the shared workspace pair used by memory and transcript adapters.

`WP_Agent_Memory_Metadata` standardizes provenance and trust fields including:

- `source_type` such as `user_asserted`, `agent_inferred`, `workspace_extracted`, `system_generated`, `curated`, or `imported`.
- `source_ref` for a URL, file path, record ID, content hash, or caller-owned reference.
- `created_by_user_id` and `created_by_agent_id`.
- `workspace` for revalidation context.
- `confidence` from `0.0` to `1.0`.
- `validator` ID.
- `authority_tier` such as `low`, `medium`, `high`, or `canonical`.
- `created_at` and `updated_at` timestamps.

`WP_Agent_Memory_Query` provides metadata filters and ranking hints for list operations. `WP_Agent_Memory_Validator` lets consumers re-check stored memories against current workspace state and return `WP_Agent_Memory_Validation_Result` values.

## Memory and context source registry

`WP_Agent_Memory_Registry` registers memory/context sources without prescribing physical storage. A source registration includes:

| Field | Meaning |
| --- | --- |
| `id` | Sanitized source ID, for example `workspace/instructions`. |
| `layer` | Normalized `WP_Agent_Memory_Layer` value. |
| `priority` | Sort order; lower values come first. |
| `protected` | Whether the source is protected from ordinary editing. |
| `editable` | Boolean or string capability/editability marker. Composable sources are forced non-editable. |
| `capability` | Optional WordPress capability string. |
| `modes` | Runtime modes or `all`. |
| `retrieval_policy` | Normalized `WP_Agent_Context_Injection_Policy`. |
| `composable` | Whether the source contributes to composed context. |
| `context_slug` | Section/context slug. |
| `convention_path` | Optional adapter metadata such as `AGENTS.md`. |
| `external_projection_target` | Optional caller-owned target metadata. |
| `label`, `description`, `meta` | Human and extension metadata. |

The registry fires `agents_api_memory_sources` once before returning resolved sources, then sorts by priority and ID.

Common reads:

- `get_all()` returns all sources.
- `get_by_layer( $layer )` filters by memory layer.
- `get_for_mode( $mode, $retrieval_policy )` filters by mode and optional retrieval policy.
- `get_always_injected( $mode )` returns sources with `always` injection.
- `get_composable()` returns composable sources.

## Context injection and composition

`WP_Agent_Context_Injection_Policy` defines generic retrieval vocabulary:

- `always`
- `on_intent`
- `on_tool_need`
- `manual`
- `never`

`WP_Agent_Context_Section_Registry` lets consumers register ordered context sections with render callbacks. `WP_Agent_Composable_Context` represents the assembled context result. Agents API does not decide dynamic retrieval heuristics; consumers interpret policies for their runtime.

## Retrieved context authority and conflicts

`AgentsAPI\AI\Context\WP_Agent_Context_Item` is the JSON-friendly shape for retrieved context. It includes content, scope, authority tier, provenance, conflict kind/key, and optional metadata.

Authority tiers are generic and intentionally product-neutral. The default resolver distinguishes:

- **Authoritative facts**, resolved by authority tier.
- **Preferences**, resolved by specificity and then authority.

Contracts:

- `WP_Agent_Context_Authority_Tier` defines tier vocabulary.
- `WP_Agent_Context_Conflict_Kind` defines conflict categories.
- `WP_Agent_Context_Conflict_Resolver` is the resolver interface.
- `WP_Agent_Default_Context_Conflict_Resolver` provides generic behavior.
- `WP_Agent_Context_Conflict_Resolution` exports resolution results.

## Guideline substrate polyfill

`WP_Guidelines_Substrate` registers a shared guideline post type and taxonomy when Core/Gutenberg or another host has not already provided them.

Constants:

- Post type: `wp_guideline`.
- Taxonomy: `wp_guideline_type`.
- Metadata keys: `_wp_guideline_scope`, `_wp_guideline_user_id`, `_wp_guideline_workspace_id`.
- Scope values: `private_user_workspace_memory` and `workspace_shared_guidance`.

Registration is gated by the `wp_guidelines_substrate_enabled` filter. The post type is non-public, not publicly queryable, available in REST as `guidelines`, and uses explicit guideline/workspace capabilities such as `read_workspace_guidelines` and `edit_workspace_guidelines`.

The substrate also wires helpers from `src/Guidelines/guidelines.php` to ensure default type terms, map labels, and map meta capabilities for private user-workspace memory and shared workspace guidance.

## Evidence

Source: `src/Memory/class-wp-agent-memory-store.php`, `src/Memory/*`, `src/Context/class-wp-agent-memory-registry.php`, `src/Context/class-wp-agent-context-section-registry.php`, `src/Context/class-wp-agent-default-context-conflict-resolver.php`, `src/Context/*`, `src/Guidelines/class-wp-guidelines-substrate.php`, and `src/Guidelines/guidelines.php`.

Tests: `tests/memory-metadata-contract-smoke.php`, `tests/context-registry-smoke.php`, `tests/context-authority-smoke.php`, `tests/guidelines-substrate-smoke.php`, and `tests/workspace-scope-smoke.php`.
