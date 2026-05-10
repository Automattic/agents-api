# Agent registry and packages

This page documents the registry-facing surface for defining agents, packaging reusable agent definitions, and materializing identities. Agents API keeps these concepts declarative: registration does not create posts, tables, directories, access grants, or runtime sessions.

Source evidence: `src/Registry/*`, `src/Packages/*`, `src/Identity/*`, `tests/registry-smoke.php`, `tests/subagents-smoke.php`, `tests/identity-smoke.php`, and `tests/bootstrap-smoke.php`.

## Agent definitions

`WP_Agent` is a declarative value object keyed by a sanitized slug. Constructor validation rejects an empty slug and invalid typed fields.

Core registration fields:

| Field | Purpose |
| --- | --- |
| `slug` | Unique registered agent slug, sanitized with `sanitize_title()`. |
| `label` | Human-readable label; defaults to the slug. |
| `description` | Optional description. |
| `memory_seeds` | Map of scaffold filename to source path. Filenames are sanitized; empty filenames/paths are dropped. |
| `owner_resolver` | Optional callable for a consumer to resolve ownership. |
| `default_config` | Initial materialization/runtime config. The substrate stores the array but does not interpret product-specific keys. |
| `supports_conversation_compaction` | Boolean opt-in for runtime compaction support. |
| `conversation_compaction_policy` | Normalized by `WP_Agent_Conversation_Compaction::normalize_policy()`. |
| `meta` | Optional diagnostics/provenance metadata. Reserved keys: `source_plugin`, `source_type`, `source_package`, `source_version`. |
| `subagents` | Sanitized slugs coordinated by this agent. Non-empty means `is_coordinator()` returns true. |

Example:

```php
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'ops-triage',
			array(
				'label'       => 'Operations Triage',
				'description' => 'Routes incoming operational reports.',
				'default_config' => array(
					'tool_policy' => array(
						'mode'       => 'allow',
						'categories' => array( 'read', 'ticketing' ),
					),
				),
				'meta' => array(
					'source_plugin'  => 'example-plugin/example-plugin.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'ops-pack',
					'source_version' => '1.2.3',
				),
			)
		);
	}
);
```

## Registry lifecycle

`WP_Agents_Registry::init()` is wired to WordPress `init`. It creates the singleton registry and fires `wp_agents_api_init` once.

Public helpers from `src/Registry/register-agents.php`:

| Helper | Responsibility |
| --- | --- |
| `wp_register_agent( string|WP_Agent $agent, array $args = array() ): ?WP_Agent` | Registers during `wp_agents_api_init`; warns and returns `null` outside the registration hook. |
| `wp_get_agent( string $slug ): ?WP_Agent` | Looks up one registered agent after `init`. |
| `wp_get_agents(): array<string, WP_Agent>` | Returns all registered agents. |
| `wp_has_agent( string $slug ): bool` | Checks registration by slug. |
| `wp_unregister_agent( string $slug ): ?WP_Agent` | Removes a registered definition from the in-memory registry. |

Duplicate registration returns `null` and emits a WordPress `_doing_it_wrong()` notice when available. If the existing agent has provenance metadata, duplicate diagnostics include the original source.

## Subagent coordination declarations

`WP_Agent` can declare `subagents`. The substrate only persists the declaration. Consumers decide how to expose subagents as tools such as `delegate-to-<slug>`, how to route calls, and how to apply permissions.

Relevant methods:

- `get_subagents(): array`
- `is_coordinator(): bool`
- `to_array()` includes the `subagents` list.

## Agent packages

`WP_Agent_Package` represents a portable manifest containing one `WP_Agent`, package capabilities, artifacts, version, and metadata. It can be constructed from a manifest array with `WP_Agent_Package::from_array()`.

Canonical package shape:

```php
$package = WP_Agent_Package::from_array(
	array(
		'slug'    => 'ops-pack',
		'version' => '1.0.0',
		'agent'   => array(
			'slug'  => 'ops-triage',
			'label' => 'Operations Triage',
		),
		'capabilities' => array( 'tickets:read', 'tickets:create' ),
		'artifacts'    => array(
			array(
				'type'   => 'example/prompt',
				'slug'   => 'triage-system-prompt',
				'source' => 'prompts/triage.md',
			),
		),
	)
);
```

Package validation rules include:

- package slug is sanitized and cannot be empty;
- version defaults to `1.0.0` and cannot be empty;
- `agent` must be a `WP_Agent` or an array with a non-empty `slug`;
- `capabilities` must be an array of non-empty lowercase strings matching the package capability character set;
- artifacts are sorted by type and slug for stable exports.

## Package artifacts

`WP_Agent_Package_Artifact` is a portable artifact declaration. It records identity and payload location only; product plugins own interpretation.

Fields:

| Field | Purpose |
| --- | --- |
| `type` | Namespaced slug such as `vendor/type`; validated by `prepare_type()`. |
| `slug` | Sanitized artifact slug. |
| `label` / `description` | Human-readable metadata. |
| `source` | Package-relative path. Absolute paths and `..` segments are rejected. |
| `checksum` | Optional caller-owned checksum string. |
| `requires` | Capability/component strings. |
| `meta` | JSON-friendly caller metadata. |

Artifact review and adoption helpers live in `src/Packages/`:

- `WP_Agent_Package_Artifact_Type` — artifact type metadata.
- `WP_Agent_Package_Artifacts_Registry` — in-memory artifact type registry.
- `WP_Agent_Package_Adoption_Diff` — reviewable adoption diff shape.
- `WP_Agent_Package_Adoption_Result` — result shape for install/update/adopt operations.
- `WP_Agent_Package_Adopter` — adopter contract.
- `register-agent-package-artifacts.php` — default artifact type registration helpers.

The package layer deliberately does not create files, run installers, or schedule work. Consumers provide adopters and diff callbacks.

## Materialized identities

`src/Identity/` separates registered definitions from durable agent instances.

- `WP_Agent_Identity_Scope` identifies a logical materialization target, including agent slug, owner user, and instance key.
- `WP_Agent_Materialized_Identity` is the durable identity value returned by a store.
- `WP_Agent_Identity_Store` defines persistence operations:
  - `resolve( WP_Agent_Identity_Scope $scope )`
  - `get( int $identity_id )`
  - `materialize( WP_Agent_Identity_Scope $scope, array $default_config = array(), array $meta = array() )`
  - `update( WP_Agent_Materialized_Identity $identity )`
  - `delete( WP_Agent_Identity_Scope $scope )`

`materialize()` must be idempotent for the same normalized `(agent_slug, owner_user_id, instance_key)` tuple. Access grants, token binding, and runtime behavior stay above the identity store.

## Failure behavior

- Invalid agent constructor arguments throw `InvalidArgumentException`.
- Invalid registry registration catches those exceptions, emits a WordPress-style notice when possible, and returns `null`.
- Registry reads before WordPress `init` return `null`/empty values and emit `_doing_it_wrong()` when available.
- Package and artifact constructors throw `InvalidArgumentException` for malformed manifests.

## Related docs

- [Architecture and boundaries](architecture.md)
- [Authentication, authorization, and consent](auth-authorization-consent.md)
- [Memory, context, guidelines, and transcripts](memory-context-transcripts.md)
- [Source inventory and coverage](source-inventory.md)
