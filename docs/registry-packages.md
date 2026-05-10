# Registry, Agents, Packages, and Artifacts

The registry and package modules define declarative agent identity and portable package metadata. They do not materialize storage, runtime sessions, files, queues, or product UI.

## Agent registry

Agents are registered during the `wp_agents_api_init` action with:

- `wp_register_agent()`
- `wp_get_agent()`
- `wp_get_agents()`
- `wp_has_agent()`
- `wp_unregister_agent()`

`WP_Agents_Registry::init()` creates the singleton and fires `wp_agents_api_init`. `wp_register_agent()` rejects calls outside that registration window with `_doing_it_wrong()` and returns `null`. Reads are intended after WordPress `init`.

## Agent declarations

`WP_Agent` is a value object for a registered agent definition. It carries:

- slug, label, description, and metadata.
- memory seed declarations.
- optional owner resolver.
- default configuration.
- conversation compaction support and policy.
- subagent slugs for coordinator-style agents.

The constructor normalizes slugs with `sanitize_title()`, defaults labels to the slug, drops invalid memory seed entries, requires callable owner resolvers, normalizes compaction policy through `WP_Agent_Conversation_Compaction::normalize_policy()`, and sanitizes subagent slugs. `is_coordinator()` is true when the agent has at least one subagent.

Duplicate agent registration is rejected. The duplicate notice includes provenance fields from the existing agent metadata such as source plugin, source type, package, and version so conflicts are diagnosable.

## Package manifests

`WP_Agent_Package` is a storage-neutral package declaration. It includes:

- package slug and version.
- a required agent declaration.
- normalized capability strings.
- sorted artifact declarations.
- free-form metadata.

`WP_Agent_Package::from_array()` validates the wire shape and returns `WP_Error` when the package is invalid. Capabilities are lowercased, filtered to portable identifier characters, deduplicated, and sorted.

## Artifacts and artifact types

`WP_Agent_Package_Artifact` declares one package artifact: type, slug, label, description, source path, checksum, requirements, and metadata. Artifact types must be namespaced strings such as `plugin/config` or `docs/page`. Artifact sources are package-relative: no leading slash, drive prefix, or `..` path segment.

Artifact type registration uses:

- `wp_register_agent_package_artifact_type()`
- `wp_get_agent_package_artifact_type()`
- `wp_get_agent_package_artifact_types()`
- `wp_has_agent_package_artifact_type()`
- `wp_unregister_agent_package_artifact_type()`

Types are registered during `wp_agent_package_artifacts_init`. `WP_Agent_Package_Artifact_Type` stores metadata and optional callbacks for validation, diffing, import, and delete. Those callbacks are contracts; consumers decide when and how adoption runs.

## Adoption contracts

`WP_Agent_Package_Adopter` is the runtime adoption seam:

- `diff( WP_Agent_Package $package )`
- `adopt( WP_Agent_Package $package, array $options = array() )`

`WP_Agent_Package_Adoption_Diff` reports `clean`, `needs-adoption`, `needs-update`, or `blocked`. `WP_Agent_Package_Adoption_Result` reports `adopted`, `updated`, `skipped`, or `failed`.

Agents API defines the manifest and adoption result vocabulary. Product plugins own durable stores, file writes, migrations, approvals, and rollback behavior.

## Tests

`tests/registry-smoke.php` covers duplicate registration and provenance notices. `tests/subagents-smoke.php` covers subagent normalization and coordinator detection. `tests/bootstrap-smoke.php` confirms registry and package classes/helpers load from the plugin bootstrap.

## Related pages

- [Bootstrap and lifecycle](bootstrap-lifecycle.md)
- [Runtime, messages, conversation loop, and compaction](runtime-conversation.md)
- [Testing, CI, and operational workflows](testing-operations.md)
