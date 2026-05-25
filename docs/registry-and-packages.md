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

## Package lifecycle primitives

Agents API owns storage-neutral primitives for package update planning. They do not create database rows, read package files from disk, approve changes, or apply artifacts. Consumers provide installed/current/target artifact arrays and decide how to store or display the resulting plan.

Core classes:

| Class | Purpose |
| --- | --- |
| `WP_Agent_Package_Artifact_Status` | Drift vocabulary: `clean`, `modified`, `missing`, `orphaned`. |
| `WP_Agent_Package_Artifact_Hasher` | Deterministic SHA-256 hashes for strings and JSON-friendly payloads. Associative key order is normalized; list order remains significant. |
| `WP_Agent_Package_Installed_Artifact` | Immutable install-time snapshot with package version, lossless artifact identity, hashes, status, timestamps, and optional installed payload. |
| `WP_Agent_Package_Update_Planner` | Pure planner that compares installed, current, and target artifact state. |
| `WP_Agent_Package_Update_Plan` | Bucketed plan value object. |
| `WP_Agent_Package_Artifact_Callbacks` | Helper for invoking registered artifact type lifecycle callbacks. |
| `WP_Agent_Package_Artifact_State_Store` | Storage-neutral contract for installed, current, target, and recorded artifact snapshots. |
| `WP_Agent_Package_Adoption_Request` | Value object describing install, upgrade, reconcile, uninstall, or dry-run adoption. |
| `WP_Agent_Package_Adoption_Result` | Value object describing plans, applied/skipped/failed entries, and recorded snapshots. |
| `WP_Agent_Package_Adoption_Orchestrator` | Storage-neutral coordinator that composes the planner, artifact callbacks, and state store. |

`WP_Agent_Package_Update_Planner::plan()` returns buckets:

| Bucket | Meaning |
| --- | --- |
| `auto_apply` | New artifacts without local state, or artifacts whose current hash still matches the installed hash. |
| `needs_approval` | Untracked local artifacts or locally modified artifacts that differ from the target. |
| `warnings` | Missing local artifacts or installed artifacts absent from the target package. |
| `no_op` | Current artifact already matches the target. |

Planner entries include artifact identity, hashes, a reason, a summary, and a redacted before/after diff payload. Secret-like keys such as `token`, `password`, `api_key`, `authorization`, and `credential` are redacted recursively.

`WP_Agent_Package_Adoption_Diff` accepts an optional `WP_Agent_Package_Update_Plan` so adopters can return bucketed artifact plans alongside the existing flat `changes` and `warnings` fields.

## Package adoption orchestration

Plugin authors can ship package definitions with their plugin releases while still preserving user-customized runtime state. The plugin release answers which package definition is available; package adoption answers how that definition reconciles with installed and current artifacts.

The generic adoption flow is:

```text
plugin package definition
  -> state store reads installed/current/target artifacts
  -> update planner creates buckets
  -> orchestrator applies auto-apply plus approved artifacts
  -> artifact callbacks materialize product-specific runtime state
  -> state store records installed snapshots for applied artifacts
```

`WP_Agent_Package_Adoption_Orchestrator` owns only that neutral sequencing. It does not store rows, expose UI, approve user decisions, fetch remote package sources, or understand product artifacts. Consumers provide a `WP_Agent_Package_Artifact_State_Store`, registered artifact type callbacks, and any approval surface.

`WP_Agent_Package_Adoption_Request` controls the operation and policy knobs:

| Field | Purpose |
| --- | --- |
| `operation` | `install`, `upgrade`, `reconcile`, `uninstall`, or `dry-run`. |
| `dry_run` | Returns a plan without invoking import callbacks or recording snapshots. |
| `auto_apply` | Allows or blocks the `auto_apply` bucket from materializing. |
| `approved_artifact_keys` | Explicit artifact keys that may be applied from non-auto buckets. |
| `context` | Consumer metadata forwarded to state store and artifact callbacks. |

`WP_Agent_Package_Adoption_Result` reports the plan, applied entries, skipped entries, failed entries, and installed snapshots recorded for applied artifacts. This lets plugin updates ship improved bundled agents while customized prompts, flows, memory, or settings remain reviewable instead of being blindly overwritten.

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
