# Auth, Permissions, Caller Context, and Bridge Authorization

The auth module defines token metadata, access grants, caller context, authorization policy, and capability ceilings. It provides contracts and fail-closed value objects; concrete token/grant persistence belongs to consumers.

## Tokens

`WP_Agent_Token` stores hashed bearer-token metadata. Raw token material is never stored or exported. Tokens include expiry, user/agent/client metadata, and capability ceilings.

`WP_Agent_Token_Store` is the persistence contract for creating, resolving by hash, touching, revoking, and listing tokens. `WP_Agent_Token_Authenticator` turns a raw bearer token into `WP_Agent_Execution_Principal` when the prefix, hash lookup, expiry, caller context, and store checks all pass.

Malformed caller headers fail closed and the token is not touched. Expired or missing tokens return no principal.

## Capability ceilings and access grants

`WP_Agent_Capability_Ceiling` intersects token/client permissions with the acting WordPress user’s capabilities. Empty capability sets deny.

`WP_Agent_Access_Grant` represents agent access with roles ordered as viewer, operator, and admin. Grants are scoped by agent ID, user ID, and optional workspace ID. `WP_Agent_Access_Store` is the grant persistence contract.

`WP_Agent_Authorization_Policy` is the host interface. `WP_Agent_WordPress_Authorization_Policy` checks the capability ceiling first, then WordPress user capabilities, and requires an explicit access grant for cross-agent access unless the principal is already acting as the target effective agent.

## Caller context

`WP_Agent_Caller_Context` carries chain metadata for agent-to-agent or remote bridge calls. It reads normalized headers such as:

- `X-Agents-Api-Caller-Agent`
- `X-Agents-Api-Caller-User`
- `X-Agents-Api-Caller-Host`
- `X-Agents-Api-Chain-Depth`
- `X-Agents-Api-Chain-Root`

Remote chained contexts cannot claim `self` as caller host, and chain depth is bounded. Malformed chain state fails closed.

## Workspace scope

`WP_Agent_Workspace_Scope` is the generic workspace identity used across auth, memory, transcripts, and stores. It is a normalized pair of workspace type and workspace ID; it is not tied to a specific product concept such as site, blog, repo, or organization.

## Bridge authorization

Remote bridge and external-client authorization builds on these primitives. Bridge clients own external credentials and callback URLs; token and caller context determine which effective user/agent is acting through the bridge.

See [Bridge Authorization And Onboarding](bridge-authorization.md) and [Remote Bridge Protocol](remote-bridge-protocol.md) for the protocol-level companion notes.

## Tests

`tests/authorization-smoke.php` covers token secrecy, token expiry, principal resolution, token touch behavior, capability ceilings, and access grant roles. `tests/caller-context-smoke.php` covers chain defaults and malformed header rejection. `tests/workspace-scope-smoke.php` covers workspace normalization and store-key usage.

## Related pages

- [Channels, external clients, and remote bridges](channels-bridges.md)
- [Memory, context, guidelines, and workspace scope](memory-context-guidelines.md)
- [Identity, transcripts, storage, and persistence boundaries](identity-transcripts-storage.md)
