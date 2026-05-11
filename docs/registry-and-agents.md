# Registry And Agent Definitions

Agents API exposes an in-memory registration surface for declarative agent definitions. Registration collects reusable agent metadata; it does not materialize database rows, access records, directories, memory files, tools, or provider configuration.

Source evidence: `src/Registry/class-wp-agent.php`, `src/Registry/class-wp-agents-registry.php`, `src/Registry/register-agents.php`, `tests/registry-smoke.php`, `tests/bootstrap-smoke.php`, and `tests/subagents-smoke.php`.

## Lifecycle

`agents-api.php` registers `WP_Agents_Registry::init()` on WordPress `init` at priority `10`. `WP_Agents_Registry::init()` creates the singleton registry, marks the registration window initialized, and fires:

```php
do_action( 'wp_agents_api_init', $registry );
```

Consumers should register agents from this action:

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

Reads such as `wp_get_agent()` are safe after `init`; the registry intentionally warns and returns `null` before `init` starts.

## Public helpers

The helper functions in `src/Registry/register-agents.php` wrap the singleton registry:

- `wp_register_agent( string|WP_Agent $agent, array $args = array() ): ?WP_Agent`
- `wp_get_agent( string $slug ): ?WP_Agent`
- `wp_get_agents(): array<string, WP_Agent>`
- `wp_has_agent( string $slug ): bool`
- `wp_unregister_agent( string $slug ): ?WP_Agent`

Duplicate registrations and missing lookups emit WordPress-style `_doing_it_wrong()` notices when available.

## `WP_Agent` contract

`WP_Agent` is a declarative value object. Constructor input is normalized, validated, and exported through getters plus `to_array()`.

Core fields:

| Field | Purpose |
| --- | --- |
| `slug` | Sanitized unique agent slug. Empty slugs throw `InvalidArgumentException`. |
| `label` | Human-readable label; defaults to the slug. |
| `description` | Optional description. |
| `memory_seeds` | Map of scaffold filename to source path. Filenames are sanitized. The substrate stores only the declaration. |
| `owner_resolver` | Optional callable for consumer-owned ownership resolution. |
| `default_config` | Initial agent config for first materialization. Consumers decide how to store/apply it. |
| `supports_conversation_compaction` | Whether the agent opts into runtime conversation compaction. |
| `conversation_compaction_policy` | Normalized compaction policy via `WP_Agent_Conversation_Compaction::normalize_policy()`. |
| `meta` | Optional metadata. Source provenance keys are reserved for diagnostics. |
| `subagents` | Sanitized list of coordinated subagent slugs. Non-empty means the agent is a coordinator. |

Invalid property names are ignored with `_doing_it_wrong()` when WordPress is loaded. `WP_Agent` cannot be serialized or unserialized; both magic methods throw `LogicException`.

## Subagents and coordinator agents

`subagents` lets one agent declare that it coordinates other registered agents. `WP_Agent::is_coordinator()` returns true when the list is non-empty. The substrate does not create delegation tools or execute subagents; consumers map subagent slugs to their own ability/tool/runtime layer. This keeps the registry declarative and avoids encoding a product-specific orchestration model.

## Registry behavior

`WP_Agents_Registry` stores definitions in memory keyed by slug. It provides:

- `register()` to accept either a prebuilt `WP_Agent` or a slug plus args.
- `get_all_registered()` to return the map.
- `get_registered()` and `is_registered()` for lookup.
- `unregister()` for tests or dynamic consumers.
- `reset_for_tests()` as an internal smoke-test helper.

Duplicate registration errors include source provenance from the existing agent's `meta` keys when present: `source_plugin`, `source_type`, `source_package`, and `source_version`.

## Consumer responsibility boundary

The registry does not:

- create persistent agent records;
- seed files or memory stores;
- assign users or permissions;
- register tools or abilities;
- choose a provider or model;
- invoke an agent runtime.

Consumers use registry definitions as discovery/configuration input for their own materializers, chat handlers, workflow steps, and UI.