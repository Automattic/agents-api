# Identity, Transcripts, Storage, and Persistence Boundaries

Agents API keeps identity, transcript, memory, workflow, bridge, token, grant, and approval storage behind contracts. The repository defines durable shapes and ownership boundaries, not product tables or UI.

## Materialized identity

`WP_Agent_Identity_Scope` identifies a durable agent instance by registered agent slug, owner user ID, and instance key. `WP_Agent_Materialized_Identity` wraps a positive store ID, normalized scope, config, metadata, and timestamps. It provides copy helpers for replacement config or metadata and exports a normalized array.

`WP_Agent_Identity_Store` is the storage contract for resolving, creating, updating, and deleting materialized identities. Products decide whether identities live in posts, custom tables, options, files, or remote services.

## Conversation transcripts

`WP_Agent_Conversation_Store` is the narrow transcript/session persistence contract. It covers:

- creating a session for workspace, user, agent slug, metadata, and context.
- retrieving a full session by ID.
- replacing complete messages and metadata.
- deleting a session.
- finding recent pending sessions for retry deduplication.
- updating the stored display title.

The store returns complete session arrays including workspace, user, agent slug, title, messages, metadata, provider/model continuity fields, context/mode, timestamps, read state, and expiry when available.

The contract deliberately excludes chat UI listing, unread/read-state policy, retention scheduling, reporting, metrics, and title-generation policy. Those belong above the transcript store.

## Transcript locking

`WP_Agent_Conversation_Lock` is the optional concurrency seam used by the conversation loop. Lock contention returns a normalized runtime result instead of running concurrently against the same transcript. Lock release failures are swallowed during cleanup.

## Provider continuity

Transcript updates include optional provider, model, and provider response ID. These fields allow a consumer to preserve provider-side continuity without coupling the substrate to a specific model provider or SDK.

## Storage ownership map

Agents API defines contracts for these persistence concerns:

- tokens and access grants.
- memory records and metadata.
- transcript sessions and locks.
- pending actions and approval audit.
- workflow specs and run history.
- routine scheduling declarations.
- materialized identities.
- external channel session maps.
- bridge clients and outbound queues.

Small option/transient helpers are included for channel session maps, bridge state, and idempotency markers. They are defaults for generic behavior, not product persistence mandates.

## Tests

Identity, workspace scope, conversation transcript lock, conversation loop transcript persister, remote bridge, pending action store, and memory metadata smoke tests cover these persistence seams.

## Related pages

- [Auth, permissions, caller context, and bridge authorization](auth-permissions.md)
- [Memory, context, guidelines, and workspace scope](memory-context-guidelines.md)
- [Channels, external clients, and remote bridges](channels-bridges.md)
