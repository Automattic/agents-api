# Agents and Packages

Agents API separates declarative agent identity from runtime execution. `WP_Agent` describes an agent; consumers decide whether to materialize it in storage, how to run it, and which product UI exposes it.

Source: `src/Registry/*`, `src/Packages/*`, `tests/registry-smoke.php`, `tests/subagents-smoke.php`, and package-related bootstrap smoke coverage.

## Registration lifecycle

Agents are registered during the `wp_agents_api_init` action, which is fired by `WP_Agents_Registry::init()` on WordPress `init`.

```php
add_action(
	'wp_agents_api_init',
	static function () {
		wp_register_agent(
			'example-agent',
			array(
				'label'       => 'Example Agent',
				'description' => 'Handles an example integration.',
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

Calling `wp_register_agent()` outside `wp_agents_api_init` returns `null` and emits `_doing_it_wrong()`. Reads through `wp_get_agent()`, `wp_get_agents()`, and `wp_has_agent()` are safe after WordPress `init` has fired.

## Public helpers

Defined in `src/Registry/register-agents.php`:

| Helper | Purpose | Return |
| --- | --- | --- |
| `wp_register_agent( string|WP_Agent $agent, array $args = array() )` | Registers an agent definition during `wp_agents_api_init`. | `WP_Agent|null` |
| `wp_get_agent( string $slug )` | Retrieves one registered agent by sanitized slug. | `WP_Agent|null` |
| `wp_get_agents()` | Returns the full map of registered agents. | `array<string, WP_Agent>` |
| `wp_has_agent( string $slug )` | Checks whether a slug is registered. | `bool` |
| `wp_unregister_agent( string $slug )` | Removes a registered definition. | `WP_Agent|null` |

## `WP_Agent` contract

`src/Registry/class-wp-agent.php` defines a thin value object. Construction validates and normalizes registration data only; it does not create rows, files, queues, credentials, or prompt/runtime objects.

Core fields exported by `to_array()`:

| Field | Meaning |
| --- | --- |
| `slug` | Sanitized unique agent slug. Empty slugs throw `InvalidArgumentException`. |
| `label` | Human-readable label; defaults to slug. |
| `description` | Optional description. |
| `memory_seeds` | Map of sanitized scaffold filename to source path. Consumers decide how to materialize seeds. |
| `owner_resolver` | Optional callable for consumer-owned owner resolution. |
| `default_config` | Initial agent config for runtime/materialization adapters. |
| `supports_conversation_compaction` | Boolean opt-in for runtime compaction. |
| `conversation_compaction_policy` | Normalized compaction policy from `WP_Agent_Conversation_Compaction::normalize_policy()`. |
| `meta` | JSON-friendly caller metadata. Reserved provenance keys are `source_plugin`, `source_type`, `source_package`, and `source_version`. |
| `subagents` | Sanitized slugs coordinated by this agent. Non-empty means `is_coordinator()` returns true. |

`WP_Agent` intentionally blocks serialization and unserialization with `LogicException`, matching registry semantics that definitions are code-time declarations rather than durable records.

## Registry behavior

`WP_Agents_Registry` is a singleton initialized after `init`. It:

- stores definitions in memory keyed by sanitized slug;
- rejects duplicate registrations and includes existing source provenance in diagnostics when available;
- rejects reads before `init` with `_doing_it_wrong()` when WordPress helpers exist;
- fires `wp_agents_api_init` exactly once;
- includes `reset_for_tests()` for smoke tests.

Duplicate registration returns `null`; it does not overwrite the earlier definition.

## Subagents and coordinator agents

`WP_Agent` supports a `subagents` list. The substrate only stores the declaration. Consumers are responsible for mapping subagent slugs to registered agents and exposing delegation through their tool or ability layer.

```php
wp_register_agent(
	'coordinator',
	array(
		'label'     => 'Coordinator',
		'subagents' => array( 'researcher', 'writer' ),
	)
);
```

`get_subagents()` returns sanitized slugs and `is_coordinator()` returns whether the list is non-empty.

## Packages

Packages group a `WP_Agent` definition with portable artifact declarations. They are declarative and storage-neutral.

### `WP_Agent_Package`

Defined in `src/Packages/class-wp-agent-package.php`. A package manifest includes:

| Field | Meaning |
| --- | --- |
| `slug` | Required package slug, sanitized with `sanitize_title()`. |
| `version` | Required non-empty package version; defaults to `1.0.0`. |
| `agent` | Required `WP_Agent` or agent manifest array. |
| `capabilities` | Unique sorted capability strings matching `^[a-z0-9:_./-]+$`. |
| `artifacts` | List of `WP_Agent_Package_Artifact` declarations. |
| `meta` | Optional package metadata array. |

Use `WP_Agent_Package::from_array( $manifest )` to parse a manifest and `to_array()` to export the normalized shape.

### `WP_Agent_Package_Artifact`

Defined in `src/Packages/class-wp-agent-package-artifact.php`. Artifacts carry identity and payload-location metadata only. Consumers own artifact interpretation.

Key validation rules:

- `type` must be a namespaced slug such as `example/instructions`.
- `slug` must sanitize to a non-empty value.
- `source` must be package-relative, cannot be absolute, and cannot contain `..` path segments.
- `requires` is a unique sorted list of capability/component strings.

Representative artifact:

```php
$artifact = new WP_Agent_Package_Artifact(
	array(
		'type'        => 'example/instructions',
		'slug'        => 'default-guidance',
		'label'       => 'Default Guidance',
		'source'      => 'artifacts/guidance.md',
		'checksum'    => 'sha256:...',
		'requires'    => array( 'agents-api:memory' ),
		'meta'        => array( 'format' => 'markdown' ),
	)
);
```

## Artifact registry and adopters

The package module also includes artifact type and adoption contracts in `src/Packages/*`:

- `WP_Agent_Package_Artifact_Type` describes supported artifact kinds.
- `WP_Agent_Package_Artifacts_Registry` records known artifact types.
- `WP_Agent_Package_Adopter`, `WP_Agent_Package_Adoption_Diff`, and `WP_Agent_Package_Adoption_Result` define review/adoption shapes.
- `register-agent-package-artifacts.php` wires built-in artifact registration.

The substrate stays generic: package adoption can generate reviewable diffs, but consumers still decide where artifacts are stored and how users approve installation.

## Failure behavior

- Invalid agent argument types throw inside `WP_Agent`, are caught by the registry, and return `null` from registration helpers.
- Invalid package manifests throw `InvalidArgumentException` immediately.
- Duplicate agent registration returns `null` and preserves the original definition.
- Package artifact `source` rejects parent directory traversal and absolute paths.

## Design guidance for consumers

- Keep registered agent definitions minimal and portable.
- Put product-specific prompts, concrete tools, storage IDs, and UI state in consumer adapters or `default_config`, not in new substrate fields.
- Use `meta` provenance keys so diagnostics can identify which plugin/package registered a slug.
- Treat packages as manifests, not installers; adoption is a separate reviewed operation.
