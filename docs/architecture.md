# Architecture and boundaries

Agents API is a standalone WordPress plugin and Composer package (`automattic/agents-api`) that provides a provider-neutral backend substrate for agent runtimes. It is intentionally not a product UI, provider client, concrete workflow runtime, or durable storage implementation.

Source evidence: `agents-api.php`, `composer.json`, root `README.md`, and `tests/bootstrap-smoke.php`.

## Layer boundary

```text
wp-ai-client -> provider/model execution and provider capabilities
Agents API   -> agent identity, runtime contracts, orchestration contracts,
                tool mediation, memory/context/transcript/session contracts
Consumers    -> product UX, concrete tools, provider adapters, workflows,
                prompt policy, storage, scheduling, materialization policy
```

The package owns shapes that multiple consumers need to share: value objects, registries, interfaces, canonical abilities, dispatchers, and optional bridges. Consumers provide the business logic around those shapes.

## Bootstrap lifecycle

`agents-api.php` is the plugin entry point. It defines:

- `AGENTS_API_LOADED`
- `AGENTS_API_PATH`
- `AGENTS_API_PLUGIN_FILE`

It then loads source files explicitly with `require_once`. There is no PSR-4 autoloader dependency at runtime; the WordPress plugin bootstrap is the source of truth.

After classes and helper functions are loaded, the bootstrap wires two WordPress lifecycle hooks:

```php
add_action( 'init', array( 'WP_Agents_Registry', 'init' ), 10 );
add_action( 'init', array( 'WP_Guidelines_Substrate', 'register' ), 9 );
```

Implications for consumers:

- Register agents inside `wp_agents_api_init`, which fires during `init` from `WP_Agents_Registry::init()`.
- The guideline substrate polyfill registers just before the agent registry (`init` priority 9 vs. 10).
- Reads such as `wp_get_agent()` are safe after WordPress `init` has fired.

## Module map

| Directory | Responsibility |
| --- | --- |
| `src/Registry/` | `WP_Agent`, in-memory agent registry, public registration helpers. |
| `src/Packages/` | Agent package manifests, artifact declarations, artifact type registry, adoption result/diff contracts. |
| `src/Identity/` | Logical agent identity scope, materialized identity value, identity store interface. |
| `src/Workspace/` | Generic workspace identity shared by memory, transcripts, approvals, and audit adapters. |
| `src/Runtime/` | Message envelope, conversation request/result/runner, execution principal, loop, compaction, budgets, transcript persister. |
| `src/Tools/` | Tool declarations/calls/results, parameter normalization, executor interface, execution core, visibility/action policy. |
| `src/Auth/` | Access grants, token store/token metadata/authenticator, caller context, authorization policy, capability ceiling. |
| `src/Consent/` | Consent operation vocabulary, decisions, policy interface, conservative default policy. |
| `src/Context/` | Memory/context source registry, section registry, composable context, injection policy, authority/conflict resolver. |
| `src/Memory/` | Memory store interface and memory value objects for scope, metadata, query, capabilities, results, validators. |
| `src/Transcripts/` | Conversation transcript store and lock interfaces. |
| `src/Guidelines/` | `wp_guideline`/`wp_guideline_type` compatibility substrate and capability mapping. |
| `src/Channels/` | Direct external channel base class, normalized external messages, webhook/idempotency/session helpers, bridge primitives, `agents/chat` ability. |
| `src/Workflows/` | Workflow spec, validator, bindings, registry, runner, run result, store/recorder interfaces, Action Scheduler bridge, workflow abilities. |
| `src/Routines/` | Persistent scheduled agent routine value object, registry, Action Scheduler bridge, listener wiring. |
| `src/Approvals/` | Pending action, approval decision, status, store/resolver/handler contracts. |

## Public extension surfaces

The substrate exposes WordPress hooks/filters and PHP interfaces rather than product-specific controllers.

Common hooks and filters include:

- `wp_agents_api_init` — register agent definitions.
- `agents_api_loop_event` — observe conversation loop lifecycle events.
- `agents_api_memory_sources` — register memory/context sources.
- `agents_api_context_sections` — register composable context sections.
- `agents_api_resolved_tools` and `agents_api_tool_policy_providers` — adjust visible tools.
- `agents_api_tool_action_policy` — final action-policy override.
- `wp_agent_chat_handler` and `agents_chat_permission` — canonical chat ability runtime and permission gates.
- `wp_agent_workflow_handler`, `wp_agent_workflow_step_handlers`, `wp_agent_workflow_known_step_types`, and `agents_run_workflow_permission` — workflow runtime, step, and permission extension points.
- `wp_agent_workflow_schedule_requested` and `wp_agent_routine_schedule_requested` — scheduler takeover hooks when Action Scheduler is absent or replaced.
- `wp_guidelines_substrate_enabled` — disable the guidelines polyfill when a host supplies one.

## Storage and persistence boundaries

Agents API defines store contracts, not concrete storage policy:

- `WP_Agent_Memory_Store` defines memory persistence semantics.
- `WP_Agent_Conversation_Store` defines transcript session semantics.
- `WP_Agent_Identity_Store` defines materialized agent identity persistence.
- `WP_Agent_Access_Store`, `WP_Agent_Token_Store`, `WP_Agent_Pending_Action_Store`, `WP_Agent_Workflow_Store`, and `WP_Agent_Workflow_Run_Recorder` define persistence seams for their domains.

The repository intentionally keeps concrete guideline-backed memory stores, markdown stores, and CPT transcript stores outside this package. See [Default Stores Companion Proposal](default-stores-companion.md).

## Operational workflows

### Requirements

`composer.json` requires PHP `>=8.1`. The plugin header declares WordPress `Requires at least: 7.0` and PHP `8.1`.

Action Scheduler is suggested, not required. Workflow and routine bridges call Action Scheduler only when `as_schedule_recurring_action`, `as_schedule_cron_action`, and `as_unschedule_all_actions` exist.

### Tests

Run the smoke suite with:

```bash
composer test
```

The Composer script executes focused PHP smoke tests in `tests/`, covering bootstrap, registries, runtime contracts, tools, auth, consent, approvals, channels, workflows, routines, and product-boundary checks.

## Contributor design principles

1. **Keep the substrate provider-neutral.** Provider/model execution belongs to `wp-ai-client` or consumer adapters.
2. **Prefer value objects and interfaces over product services.** Generic shapes live here; UI and storage materialization live in consumers.
3. **Use WordPress lifecycle conventions.** Register on explicit hooks, expose filters for host policy, and avoid hidden global side effects where possible.
4. **Fail closed at request/auth edges.** Token auth, caller context parsing, approval gates, and permission decisions must reject malformed or unauthorized inputs.
5. **Keep observer failures non-fatal.** Conversation loop event observers, transcript persisters, and lock release failures are caught so logging/storage failures do not alter primary runtime results.
6. **Document boundaries when adding a primitive.** New primitives should say what they own, what consumers still own, and which tests prove the contract.
