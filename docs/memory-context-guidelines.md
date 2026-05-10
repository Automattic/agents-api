# Memory, Context, Guidelines, and Workspace Scope

These modules define memory storage contracts, retrieved/composable context, authority conflict vocabulary, and the optional `wp_guideline` substrate. They keep memory identity and context assembly generic so consumers can choose storage and product UX.

## Memory store contracts

`WP_Agent_Memory_Store` is the generic full-file memory persistence interface. It supports read, write, exists, delete, layer listing, subtree listing, and capability reporting. Memory identity is built from layer, workspace type, workspace ID, user ID, agent ID, and filename.

`WP_Agent_Memory_Scope` provides the stable scope key. `WP_Agent_Memory_Metadata` carries provenance and trust fields such as source type/ref, creator IDs, workspace, confidence, validator, authority tier, and timestamps. `WP_Agent_Memory_Store_Capabilities` lets stores declare which metadata fields are persisted, read, filterable, or rankable.

Result objects include read, write, and list-entry values. `WP_Agent_Memory_Query` carries filters and ranking hints. `WP_Agent_Memory_Validator` and `WP_Agent_Memory_Validation_Result` define validation seams.

## Context registry and composition

`WP_Agent_Memory_Registry` registers memory/context sources with layer, priority, capability, modes, retrieval policy, composable flag, context slug, convention path, and projection target. `WP_Agent_Context_Section_Registry` registers composable sections by context slug and priority.

`WP_Agent_Composable_Context` is the composed context payload plus included-section metadata. `WP_Agent_Context_Injection_Policy` defines when context should be injected: always, on intent, on tool need, manual, or never.

Hooks:

- `agents_api_memory_sources`
- `agents_api_context_sections`
- `agents_api_composable_context_content`

## Authority and conflict resolution

Retrieved context uses authority tiers such as platform authority, support authority, workspace shared, user workspace private, user global, agent identity, agent memory, and conversation. Conflict kinds distinguish authoritative facts from preferences.

The default resolver keeps authoritative facts aligned with higher authority. Preferences favor specificity first, then authority. This prevents lower-authority memory from overriding platform or workspace facts while still allowing specific user preferences to win in preference conflicts.

## Guidelines substrate

`WP_Guidelines_Substrate` registers the optional `wp_guideline` post type and `wp_guideline_type` taxonomy unless disabled with `wp_guidelines_substrate_enabled`. Guideline metadata includes scope, user ID, and workspace ID. Default scopes include private user-workspace memory and workspace-shared guidance.

`wp_guideline_types` extends the guideline type vocabulary. Capability mapping keeps private memory owner-only and applies editorial/admin thresholds to workspace-shared guidance.

## Storage boundaries

The memory store contract does not define scaffolding, sections, ability permissions, prompt injection, physical paths, or product-specific convention semantics. `convention_path` is metadata, not identity. Guidelines use WordPress posts/taxonomy; memory stores may use custom tables, files, posts, or remote systems.

## Tests

Memory metadata, context registry, context authority, guidelines substrate, and workspace scope smoke tests cover default metadata, unsupported metadata reporting, source normalization, composition ordering, conflict resolution, capability boundaries, and workspace identity.

## Related pages

- [Auth, permissions, caller context, and bridge authorization](auth-permissions.md)
- [Runtime, messages, conversation loop, and compaction](runtime-conversation.md)
- [Identity, transcripts, storage, and persistence boundaries](identity-transcripts-storage.md)
