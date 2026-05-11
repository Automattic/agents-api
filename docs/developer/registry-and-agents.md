# Agent Registry And Definitions

Agents API exposes a WordPress-style registration surface for product plugins to declare agent definitions without forcing a storage, UI, or runtime implementation.

## Lifecycle

The registry is initialized from `agents-api.php` on WordPress `init` at priority 10 by calling `WP_Agents_Registry::init()`. Initialization creates the singleton and fires `wp_agents_api_init` exactly once.

Consumers should register agents inside `wp_agents_api_init`:

```php
add_action(
	'wp_agents_api_init',
	static function () {
		wp_register_agent(
			'example-agent',
			array(
				'label'       => 'Example Agent',
				'description' => 'Answers questions for the example product.',
				'meta'        => array(
					'source_plugin'  => 'example-plugin/example-plugin.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'example-package',
					'source_version' => '1.2.3',
				),
			)
		);
	}
);
```

`wp_register_agent()` deliberately returns `null` and emits `_doing_it_wrong()` when called outside `wp_agents_api_init`. Reads such as `wp_get_agent()` and `wp_has_agent()` are safe after `init` has fired.

## Public helpers

Defined in `src/Registry/register-agents.php`:

| Helper | Purpose | Failure behavior |
| --- | --- | --- |
| `wp_register_agent( string|WP_Agent $agent, array $args = array() ): ?WP_Agent` | Register one agent definition during `wp_agents_api_init`. | Returns `null` for invalid args, duplicate slugs, no registry, or wrong lifecycle. |
| `wp_get_agent( string $slug ): ?WP_Agent` | Return one registered definition. | Returns `null` when the registry is unavailable or the slug is missing. |
| `wp_get_agents(): array` | Return all registered definitions keyed by slug. | Returns an empty array when the registry is unavailable. |
| `wp_has_agent( string $slug ): bool` | Check whether a slug is registered. | Returns `false` before registry availability. |
| `wp_unregister_agent( string $slug ): ?WP_Agent` | Remove a registered definition. | Returns `null` when unavailable or missing. |

## `WP_Agent` value object

`src/Registry/class-wp-agent.php` models a declarative agent definition. Constructing it has no side effects: it does not create posts, database rows, files, access records, or runtime state.

Core fields and accessors:

| Field | Accessor | Notes |
| --- | --- | --- |
| `slug` | `get_slug()` | Sanitized with `sanitize_title()`; empty slugs throw `InvalidArgumentException`. |
| `label` | `get_label()` | Defaults to slug. |
| `description` | `get_description()` | Optional text. |
| `memory_seeds` | `get_memory_seeds()` | Sanitized map of scaffold filename to source path. |
| `owner_resolver` | `get_owner_resolver()` | Optional callable; invalid non-callables throw. |
| `default_config` | `get_default_config()` | Caller-owned initial materialization config. |
| `supports_conversation_compaction` | `supports_conversation_compaction()` | Opt-in flag for runtime compaction support. |
| `conversation_compaction_policy` | `get_conversation_compaction_policy()` | Normalized through `WP_Agent_Conversation_Compaction::normalize_policy()`. |
| `meta` | `get_meta()` | Optional metadata. Source provenance keys are reserved for diagnostics. |
| `subagents` | `get_subagents()`, `is_coordinator()` | Sanitized coordinator subagent slugs. Consumers map these to tools/abilities. |

`to_array()` exports the definition as a JSON-friendly array for registry and diagnostics. `__sleep()` and `__wakeup()` throw `LogicException`; the value is not intended to be serialized.

## Registry behavior

`WP_Agents_Registry` stores definitions in memory for the current request. It sanitizes lookup slugs, prevents duplicates, and formats duplicate-source diagnostics from `meta.source_plugin`, `meta.source_type`, `meta.source_package`, and `meta.source_version`.

`get_instance()` returns `null` before WordPress `init` has started and emits `_doing_it_wrong()` when available. This protects consumers from creating a registration window before WordPress is ready.

## Evidence

- Implementation: `src/Registry/register-agents.php`, `src/Registry/class-wp-agent.php`, `src/Registry/class-wp-agents-registry.php`.
- Bootstrap: `agents-api.php`.
- Tests: `tests/registry-smoke.php`, `tests/bootstrap-smoke.php`, `tests/subagents-smoke.php`.
