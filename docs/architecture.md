# Architecture and bootstrap lifecycle

Agents API is a standalone WordPress plugin that loads a provider-neutral agent runtime substrate. The implementation is intentionally organized as contracts, value objects, registries, and narrow orchestration helpers rather than a product application.

## Requirements and package shape

- Plugin entry point: `agents-api.php`.
- Composer package: `automattic/agents-api` with PHP `>=8.1`.
- Plugin metadata declares version `0.1.0`, GPL-2.0-or-later licensing, and WordPress `Requires at least: 7.0` in the inspected ref.
- `woocommerce/action-scheduler` is suggested, not required. Workflow and routine scheduling bridges detect Action Scheduler at runtime and no-op when it is unavailable.
- Tests run through `composer test`, which executes the smoke-test suite listed in `composer.json`.

## Layer boundary

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts, tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy, storage/materialization policy
```

Agents API does not own provider request code, admin screens, product REST controllers, workflow editors, concrete tool execution adapters, concrete persistence schemas, prompt assembly policy, support routing, or product-specific runtime semantics.

## Bootstrap flow

`agents-api.php` guards against double-loading with `AGENTS_API_LOADED`, defines `AGENTS_API_PATH` and `AGENTS_API_PLUGIN_FILE`, then requires the source modules explicitly. The load order matters because the repository uses WordPress-shaped class files rather than Composer autoloading.

At WordPress `init`:

- `WP_Guidelines_Substrate::register()` runs at priority `9`, registering the guideline CPT/taxonomy polyfill when enabled and when no upstream provider already registered those objects.
- `WP_Agents_Registry::init()` runs at priority `10`, opens the `wp_agents_api_init` registration window, and fires `do_action( 'wp_agents_api_init', $registry )`.

Agent registrations are expected inside `wp_agents_api_init`; reads such as `wp_get_agent()` are safe after `init` has fired.

## Source module map

| Module | Responsibility |
| --- | --- |
| `src/Registry` | Declarative `WP_Agent` value object, in-memory `WP_Agents_Registry`, and public agent helper functions. |
| `src/Runtime` | Message envelopes, execution principals, effective-agent resolution, conversation request/result/runner contracts, loop sequencing, compaction, iteration budgets, transcript persister interfaces. |
| `src/Tools` | Tool declarations, calls, parameters, execution core, executor contracts, tool visibility policy, action policy, and policy provider contracts. |
| `src/Auth` | Access grants, token metadata/store/authenticator, authorization policies, capability ceilings, and cross-site caller context. |
| `src/Approvals` | Pending action, approval decision, status, store, resolver, and handler contracts. |
| `src/Consent` | Generic consent operations, decisions, policy interface, and conservative default policy. |
| `src/Context` | Memory/context source registry, context section registry, composable context, retrieved-context authority tiers, conflict kinds, conflict resolver contracts. |
| `src/Memory` | Store-neutral memory scope, metadata, query, validation, read/write/list results, store capabilities, validators, and store interface. |
| `src/Guidelines` | `wp_guideline` / `wp_guideline_type` substrate polyfill and explicit guideline capability mapping. |
| `src/Channels` | Direct channel base class, canonical chat ability, external message normalization, session map/store, webhook signature/idempotency, remote bridge services. |
| `src/Workflows` | Workflow spec, validator, binding expansion, runner, registry, store/recorder contracts, canonical workflow abilities, Action Scheduler bridge/listener. |
| `src/Routines` | Persistent scheduled routine value object, registry, public helpers, Action Scheduler bridge/listener. |
| `src/Transcripts` | Conversation store and lock contracts plus null lock implementation. |
| `src/Identity` | Agent identity scope, materialized identity, and identity store contract. |
| `src/Workspace` | Generic workspace identity value object. |
| `src/Packages` | Agent package, package artifact, artifact type, artifact registry, adoption diff/result/adopter contracts. |

## Design principles

1. **Provider neutrality.** Consumers provide the model/provider dispatch adapter; Agents API only normalizes runtime contracts around it.
2. **WordPress-shaped integration.** Public registration helpers, `init` lifecycle, `do_action`/`apply_filters`, capability checks, and optional CPT/taxonomy substrate follow WordPress conventions.
3. **Contracts before implementations.** Memory, transcript, token, access, workflow store, run recorder, pending-action store, and default stores are interfaces or proposals; concrete storage is consumer-owned or belongs in a companion package.
4. **Durability boundaries are explicit.** Locks, compare-and-swap hashes, queue acknowledgements, approval resolution fields, and metadata capability declarations make failure modes visible to consumers.
5. **Fail closed where trust is involved.** Token authentication rejects expired tokens and malformed caller context before touching tokens; caller headers are claims only; guideline private memory uses explicit owner/workspace metadata and capabilities rather than ordinary private-post semantics.
6. **No product imports.** `tests/no-product-imports-smoke.php` guards the boundary that Agents API must not import Data Machine or other consumer product classes.

## Operational workflows

- Run all smoke tests with `composer test`.
- Validate individual PHP files with `php -l <file>` for focused changes.
- Use `homeboy`/Playground CI workflows only as consumer infrastructure; the substrate itself remains ordinary PHP/WordPress plugin code.
- Documentation maintenance should keep `docs/coverage-map.md` aligned when source modules, public hooks, workflow/routine abilities, or contract interfaces change.
