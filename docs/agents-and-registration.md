# Agents And Registration

This page documents the developer-facing agent definition and registry surface. It is grounded in `src/Registry/*`, the bootstrap wiring in `agents-api.php`, and `tests/registry-smoke.php` / `tests/bootstrap-smoke.php`.

## Purpose

Agents API treats an agent as a declarative definition that consumers can register and later materialize through their own stores and runtime adapters. Registration does not create database rows, grant access, write scaffold files, or dispatch model calls.

```text
consumer plugin
  -> wp_agents_api_init
  -> wp_register_agent( slug, args )
  -> in-memory WP_Agents_Registry
  -> consumer-owned materialization/runtime later
```

## Lifecycle

`agents-api.php` wires `WP_Agents_Registry::init()` to WordPress `init` at priority `10`. `WP_Agents_Registry::init()` creates the singleton registry, marks registration initialized, and fires `wp_agents_api_init`.

Register agents only inside `wp_agents_api_init`:

```php
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			'editor-assistant',
			array(
				'label'       => 'Editor Assistant',
				'description' => 'Helps editorial users draft and review content.',
				'meta'        => array(
					'source_plugin'  => 'example-plugin/example-plugin.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'editor-tools',
					'source_version' => '1.2.3',
				),
			)
		);
	}
);
```

Calling `wp_register_agent()` outside `wp_agents_api_init` emits a WordPress `_doing_it_wrong()` notice and returns `null`. Reads such as `wp_get_agent()` rely on `WP_Agents_Registry::get_instance()`, which returns `null` before WordPress `init` has started.

## Public helpers

Defined in `src/Registry/register-agents.php`:

| Helper | Responsibility | Failure behavior |
| --- | --- | --- |
| `wp_register_agent( string|WP_Agent $agent, array $args = array() ): ?WP_Agent` | Register one definition during `wp_agents_api_init`. | Returns `null` when called too early/late, registry is unavailable, arguments are invalid, or slug duplicates an existing agent. |
| `wp_get_agent( string $slug ): ?WP_Agent` | Return a registered agent by slug. | Returns `null` when registry is unavailable or slug is not registered. |
| `wp_get_agents(): array` | Return all registered agents keyed by slug. | Returns an empty array when registry is unavailable. |
| `wp_has_agent( string $slug ): bool` | Check registration by slug. | Returns `false` when registry is unavailable. |
| `wp_unregister_agent( string $slug ): ?WP_Agent` | Remove a registered definition. | Returns `null` when registry is unavailable or slug is unknown. |

`WP_Agents_Registry` also exposes `register()`, `get_all_registered()`, `get_registered()`, `is_registered()`, and `unregister()` methods for lower-level integration and tests.

## `WP_Agent` definition object

Defined in `src/Registry/class-wp-agent.php`.

### Constructor

```php
new WP_Agent( string $slug, array $args = array() )
```

The constructor sanitizes the slug with `sanitize_title()` and throws `InvalidArgumentException` when the slug becomes empty or typed arguments are invalid.

### Supported fields

| Field | Type | Meaning |
| --- | --- | --- |
| `label` | `string` | Human-readable label. Defaults to slug. |
| `description` | `string` | Optional description. |
| `memory_seeds` | `array<string,string>` | Map of scaffold filename to source path. Filenames are sanitized; empty filenames/paths are dropped. |
| `owner_resolver` | `callable` | Optional callback for consumer-owned owner resolution. |
| `default_config` | `array<string,mixed>` | Initial config used by consumer materialization/runtime adapters. |
| `supports_conversation_compaction` | `bool` | Whether this agent opts into runtime transcript compaction. |
| `conversation_compaction_policy` | `array<string,mixed>` | Normalized by `WP_Agent_Conversation_Compaction::normalize_policy()`. |
| `meta` | `array<string,mixed>` | Optional metadata. Source provenance keys are reserved for diagnostics. |
| `subagents` | `string[]` | Slugs coordinated by this agent. Non-empty means `is_coordinator()` returns `true`. |

### Getters and export

`WP_Agent` exposes `get_slug()`, `get_label()`, `get_description()`, `get_memory_seeds()`, `get_owner_resolver()`, `get_default_config()`, `supports_conversation_compaction()`, `get_conversation_compaction_policy()`, `get_meta()`, `get_subagents()`, `is_coordinator()`, and `to_array()`.

`to_array()` returns a JSON-friendly registration shape with the fields above. The object rejects serialization and unserialization with `LogicException` through `__sleep()` and `__wakeup()`.

## Duplicate registration diagnostics

`WP_Agents_Registry::register()` rejects duplicate slugs. When the existing agent contains source provenance in `meta.source_plugin`, `meta.source_type`, `meta.source_package`, or `meta.source_version`, the `_doing_it_wrong()` message includes a formatted source summary such as `plugin=example-plugin/example-plugin.php, type=bundled-agent`.

## Subagent declarations

`WP_Agent::get_subagents()` returns sanitized subagent slugs. The substrate only stores the declaration. Consumers remain responsible for mapping those slugs to registered agents and surfacing delegation through their tool or ability layer.

## Relationship to identity materialization

`WP_Agent` is a definition. Durable instances are modeled separately by `src/Identity/*`:

- `WP_Agent_Identity_Scope` identifies a logical `(agent_slug, owner_user_id, instance_key)` style scope.
- `WP_Agent_Materialized_Identity` represents a durable identity record.
- `WP_Agent_Identity_Store` defines `resolve()`, `get()`, `materialize()`, `update()`, and `delete()`.

This split lets consumers choose whether agents are materialized as posts, rows, external records, or not at all.

## Related tests

- `tests/registry-smoke.php` verifies registration helper behavior.
- `tests/bootstrap-smoke.php` verifies bootstrap load order and public surface availability.
- `tests/subagents-smoke.php` covers subagent declaration behavior.
- `tests/identity-smoke.php` covers identity value objects/contracts.
