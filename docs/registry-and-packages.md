# Registry and Packages

This page documents the developer-facing contracts for declaring agents and portable agent packages.

Source evidence: `src/Registry/*`, `src/Packages/*`, `agents-api.php`, `tests/registry-smoke.php`, `tests/subagents-smoke.php`, and package-related smoke coverage in `composer.json`.

## Agent registration lifecycle

Agents are declarative definitions collected by `WP_Agents_Registry`. Constructing or registering an agent does not create database rows, scaffold files, queues, scheduled work, or access records.

Registration must happen inside the `wp_agents_api_init` action:

```php
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'example-agent',
			array(
				'label'       => 'Example Agent',
				'description' => 'Handles example tasks.',
				'meta'        => array(
					'source_plugin'  => 'example/example.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'example-package',
					'source_version' => '1.2.3',
				),
			)
		);
	}
);
```

`WP_Agents_Registry::init()` is wired to WordPress `init`. Reads such as `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, and `wp_unregister_agent()` require the registry singleton, so callers should treat them as post-`init` APIs.

## `WP_Agent` contract

`WP_Agent` is the normalized definition object for one agent slug. Public responsibilities:

- sanitize and validate the slug;
- normalize registration arguments;
- reject invalid types with `InvalidArgumentException` or WordPress `_doing_it_wrong()` notices;
- expose getters and `to_array()` for registry consumers;
- prevent serialization and unserialization.

Core fields exported by `to_array()`:

| Field | Purpose |
| --- | --- |
| `slug` | Sanitized unique agent identifier. |
| `label` | Human-readable label, defaulting to the slug. |
| `description` | Optional description. |
| `memory_seeds` | Map of scaffold filename to source path. The substrate stores the declaration only. |
| `owner_resolver` | Optional callable for consumer-owned owner resolution. |
| `default_config` | Initial materialization config for consumers. |
| `supports_conversation_compaction` | Whether the agent opts into runtime compaction support. |
| `conversation_compaction_policy` | Normalized compaction policy. |
| `meta` | Optional JSON-friendly metadata and reserved source provenance keys. |
| `subagents` | Sanitized slugs of coordinated subagents. |

Coordinator agents are detected with `is_coordinator()` when `subagents` is non-empty. The substrate records the declaration only; consumers expose subagents as delegate tools or abilities.

## Registry helper functions

`src/Registry/register-agents.php` exports:

- `wp_register_agent( string|WP_Agent $agent, array $args = array() ): ?WP_Agent`
- `wp_get_agent( string $slug ): ?WP_Agent`
- `wp_get_agents(): array<string, WP_Agent>`
- `wp_has_agent( string $slug ): bool`
- `wp_unregister_agent( string $slug ): ?WP_Agent`

Duplicate registration returns `null` and emits an invalid-usage notice that includes source provenance when available.

## Package manifests

`WP_Agent_Package` models a portable package containing one agent definition plus generic artifacts. It is storage-neutral and runtime-neutral.

Normalized package fields:

| Field | Purpose |
| --- | --- |
| `slug` | Sanitized package slug. |
| `version` | Non-empty version string, default `1.0.0`. |
| `agent` | `WP_Agent` definition. |
| `capabilities` | Sorted, unique capability/component strings. |
| `artifacts` | Sorted `WP_Agent_Package_Artifact` declarations. |
| `meta` | Package-owned JSON-friendly metadata. |

Construct from a manifest with `WP_Agent_Package::from_array( $manifest )`.

## Package artifacts

`WP_Agent_Package_Artifact` records identity and payload location only. Consumers own interpretation, install/update behavior, and review UI.

Core fields:

| Field | Validation and meaning |
| --- | --- |
| `type` | Namespaced slug such as `vendor/type`, validated by `prepare_type()`. |
| `slug` | Sanitized artifact slug. |
| `label` | Human-readable label, defaulting to slug. |
| `description` | Optional description. |
| `source` | Package-relative source path. Absolute paths and `..` segments are rejected. |
| `checksum` | Optional checksum string. |
| `requires` | Sorted, unique capability/component strings. |
| `meta` | Optional metadata. |

Package artifacts can describe diff callbacks through registered artifact types, which supports human-reviewable adoption/update flows without tying package review to live runtime pending-action approval.

## Artifact type registry

`WP_Agent_Package_Artifacts_Registry` manages artifact type metadata. Registration fires through `wp_agent_package_artifacts_init` when the singleton is first resolved after `init`.

Helper functions:

- `wp_register_agent_package_artifact_type()`
- `wp_get_agent_package_artifact_type()`
- `wp_get_agent_package_artifact_types()`
- `wp_has_agent_package_artifact_type()`
- `wp_unregister_agent_package_artifact_type()`

Like agent registration, artifact type registration is a collection step. Consumers decide if and when lifecycle callbacks run.

## Failure modes and boundaries

- Agent and package constructors throw on invalid slugs, invalid field types, missing agent definitions, invalid package artifact type slugs, absolute artifact paths, and non-JSON-serializable metadata.
- Registration helpers return `null` instead of throwing for invalid registry-time input.
- Registries are in-memory facades, not durable stores.
- Product plugins own materialization, storage, package installation, authorization, and UX.
