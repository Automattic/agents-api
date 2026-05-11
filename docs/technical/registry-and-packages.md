# Agent Registry And Packages

This page documents the developer-facing registration and package surfaces in Agents API. These contracts are source-derived from `src/Registry/*`, `src/Packages/*`, `agents-api.php`, and the bootstrap assertions in `tests/bootstrap-smoke.php` and `tests/registry-smoke.php`.

## Agent registration lifecycle

Agents are declarative definitions collected in memory. Registering an agent does not create database rows, access records, directories, memory files, or provider runtime state.

The lifecycle is:

1. `agents-api.php` loads registry classes and helpers.
2. WordPress fires `init`.
3. `WP_Agents_Registry::init()` creates the singleton registry if needed, marks the registration window initialized, and fires `wp_agents_api_init`.
4. Consumers call `wp_register_agent()` from a `wp_agents_api_init` callback.
5. Consumers read definitions later with `wp_get_agent()`, `wp_get_agents()`, or `wp_has_agent()`.

`wp_register_agent()` explicitly checks `doing_action( 'wp_agents_api_init' )`. Calls outside that hook emit `_doing_it_wrong()` and return `null`.

```php
add_action(
	'wp_agents_api_init',
	static function () {
		wp_register_agent(
			'example-agent',
			array(
				'label'       => 'Example Agent',
				'description' => 'Handles example requests.',
				'meta'        => array(
					'source_plugin'  => 'example/example.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'example-package',
					'source_version' => '1.0.0',
				),
			)
		);
	}
);
```

## Public helper functions

Defined in `src/Registry/register-agents.php`:

| Function | Purpose | Failure behavior |
| --- | --- | --- |
| `wp_register_agent( string|WP_Agent $agent, array $args = array() ): ?WP_Agent` | Registers a definition during `wp_agents_api_init`. | Returns `null` for invalid args, unavailable registry, duplicate slug, or wrong lifecycle. |
| `wp_get_agent( string $slug ): ?WP_Agent` | Returns one registered definition. | Returns `null` before registry availability or when slug is missing. |
| `wp_get_agents(): array` | Returns all registered definitions keyed by slug. | Returns an empty array when the registry is unavailable. |
| `wp_has_agent( string $slug ): bool` | Checks registration by sanitized slug. | Returns `false` when unavailable. |
| `wp_unregister_agent( string $slug ): ?WP_Agent` | Removes a definition from the in-memory registry. | Returns `null` when unavailable or missing. |

## `WP_Agent` value object

`WP_Agent` lives in `src/Registry/class-wp-agent.php`. It sanitizes the slug with `sanitize_title()` and throws `InvalidArgumentException` when the sanitized slug is empty.

Core fields exported by `to_array()`:

| Field | Getter | Responsibility |
| --- | --- | --- |
| `slug` | `get_slug()` | Stable sanitized agent identifier. |
| `label` | `get_label()` | Human-readable label, defaulting to the slug. |
| `description` | `get_description()` | Optional human-readable description. |
| `memory_seeds` | `get_memory_seeds()` | Map of scaffold filename to source path. Filenames are sanitized; empty names and paths are dropped. |
| `owner_resolver` | `get_owner_resolver()` | Optional callable used by consumers to resolve ownership. |
| `default_config` | `get_default_config()` | Initial materialization config for consumer-owned stores. |
| `supports_conversation_compaction` | `supports_conversation_compaction()` | Boolean opt-in for runtime compaction. |
| `conversation_compaction_policy` | `get_conversation_compaction_policy()` | Normalized by `WP_Agent_Conversation_Compaction::normalize_policy()`. |
| `meta` | `get_meta()` | Optional metadata; source provenance keys are reserved. |
| `subagents` | `get_subagents()` / `is_coordinator()` | Coordinator declarations. Consumers expose subagents as tools or abilities. |

`WP_Agent` rejects serialization and unserialization through `__sleep()` and `__wakeup()`, preserving it as an in-process definition object rather than a persistence format.

## Registry behavior

`WP_Agents_Registry` in `src/Registry/class-wp-agents-registry.php` is a singleton facade with an in-memory `registered_agents` map.

Important behavior:

- `get_instance()` returns `null` before WordPress `init` has started or fired, and emits `_doing_it_wrong()` when available.
- `init()` fires `wp_agents_api_init` only once.
- Duplicate registration returns `null` and includes existing source provenance in the diagnostic message when `meta.source_*` fields are present.
- Reads sanitize slugs before lookup.
- `reset_for_tests()` exists for tests only.

## Package and artifact contracts

The package module in `src/Packages` defines substrate-level package shapes without implementing a product installer or updater. It includes:

- `WP_Agent_Package`
- `WP_Agent_Package_Artifact`
- `WP_Agent_Package_Artifact_Type`
- `WP_Agent_Package_Artifacts_Registry`
- `WP_Agent_Package_Adoption_Diff`
- `WP_Agent_Package_Adoption_Result`
- `WP_Agent_Package_Adopter`
- helper registration functions in `register-agent-package-artifacts.php`

Package artifacts can describe reviewable diff behavior through `diff_callback`. That callback belongs to package artifact review; it is separate from runtime pending-action approval, which is documented in [Auth, consent, context, and memory](auth-consent-context-memory.md).

## Provenance and diagnostics

Agents API reserves these `meta` keys for source diagnostics:

- `source_plugin`
- `source_type`
- `source_package`
- `source_version`

`WP_Agents_Registry::format_agent_source()` uses those scalar fields to make duplicate-registration notices actionable.

## Tests that prove this behavior

- `tests/bootstrap-smoke.php` proves the registration facade, registry classes, package helpers, and package artifact classes load from the standalone bootstrap.
- `tests/registry-smoke.php` covers agent registration and lookup behavior.
- `tests/subagents-smoke.php` covers coordinator/subagent declarations.
- `tests/no-product-imports-smoke.php` protects the substrate boundary.
