# Agent Registry And Packages

This page documents the declarative agent and package surface in `src/Registry/`, `src/Packages/`, and `src/Identity/`.

## Scope

Agents API stores definitions and contracts only. Registering an agent or package does not create database rows, files, access grants, schedules, or runtime sessions. Consumers decide when definitions are materialized and how they are connected to UX, storage, tools, and provider adapters.

## Agent registration lifecycle

Public surface:

- `wp_register_agent()`
- `wp_get_agent()`
- `wp_get_agents()`
- `wp_has_agent()`
- `wp_unregister_agent()`
- `WP_Agent`
- `WP_Agents_Registry`
- `wp_agents_api_init`

`agents-api.php` wires `WP_Agents_Registry::init()` to WordPress `init` priority 10. That method fires `wp_agents_api_init`; consumers must call `wp_register_agent()` inside that action. Reads are safe after `init` has fired.

```php
add_action(
	'wp_agents_api_init',
	static function (): void {
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

`wp_register_agent()` emits `_doing_it_wrong()` and returns `null` when called outside `wp_agents_api_init`. `WP_Agents_Registry::get_instance()` also refuses early initialization before `init` has started.

## `WP_Agent` definition fields

`WP_Agent` is a thin declarative value object. It normalizes and validates:

- `slug` — sanitized unique slug.
- `label` and `description` — human-facing definition metadata.
- `memory_seeds` — map of scaffold filename to source path; filenames are sanitized.
- `owner_resolver` — optional callable used by consumers and by effective-agent owner fallback.
- `default_config` — caller-owned runtime config array.
- `supports_conversation_compaction` and `conversation_compaction_policy` — declarative compaction support.
- `meta` — source provenance and caller-owned metadata.
- `subagents` — sanitized slugs this agent can coordinate.

Unknown constructor keys that do not map to object properties are rejected with `_doing_it_wrong()` rather than silently becoming public API.

## Subagents

`WP_Agent::get_subagents()` returns sanitized subagent slugs. `WP_Agent::is_coordinator()` returns true when at least one subagent is declared.

Subagents are declarations only. Agents API does not create delegation tools or choose routing policy. Consumers map the slugs to other registered agents and may expose delegation as tools such as `delegate-to-<slug>`.

## Effective agent resolution

`AgentsAPI\AI\WP_Agent_Effective_Agent_Resolver` resolves a per-invocation agent slug without introducing global mutable "active agent" state.

Resolution order:

1. Explicit operation input: `agent_slug` or `effective_agent_id`.
2. `WP_Agent_Execution_Principal::effective_agent_id`.
3. Persisted invocation context: `persisted_agent_slug` or `persisted_effective_agent_id`.
4. Owner fallback only when the owner resolves to exactly one candidate.

If owner fallback finds multiple candidates, the resolver throws an ambiguity error. Consumers should surface that as "provide an explicit agent" rather than selecting the first owned agent.

## Materialized identity contracts

`src/Identity/` separates declarative registration from durable consumer-owned instances:

- `WP_Agent_Identity_Scope` — `(agent_slug, owner_user_id, instance_key)`.
- `WP_Agent_Materialized_Identity` — durable ID plus scope, config, metadata, and timestamps.
- `WP_Agent_Identity_Store` — resolve, get, materialize, update, and delete contract.

The identity store does not own access grants, token binding, runtime execution, or UI. Those compose through auth and runtime adapters.

## Packages and artifacts

Package surface:

- `WP_Agent_Package`
- `WP_Agent_Package_Artifact`
- `WP_Agent_Package_Artifact_Type`
- `WP_Agent_Package_Artifacts_Registry`
- `WP_Agent_Package_Adopter`
- `WP_Agent_Package_Adoption_Diff`
- `WP_Agent_Package_Adoption_Result`

A package describes an agent definition, version, capabilities, artifacts, and metadata. Artifact source paths are package-relative and reject parent-directory traversal. Artifacts may include diff callbacks so consumers can show human-reviewable install/update previews.

Register artifact types through `wp_agent_package_artifacts_init`. Consumers own when callbacks run, where artifacts are stored, and how review/approval is presented.

## Tests

Relevant smoke tests:

- `tests/registry-smoke.php`
- `tests/subagents-smoke.php`
- `tests/effective-agent-resolver-smoke.php`
- `tests/identity-smoke.php`
- `tests/bootstrap-smoke.php`
