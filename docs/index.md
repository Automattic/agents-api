# Agents API developer documentation

Agents API is a WordPress-shaped, provider-neutral substrate for agent runtime contracts. It provides value objects, registries, orchestration seams, and policy contracts that product plugins can compose without copying the same agent primitives. Product plugins still own concrete provider dispatch, product UI, durable storage implementations, prompt policy, runtime adapters, and product-specific workflows.

This documentation surface was bootstrapped from `README.md`, `docs/**`, `agents-api.php`, the `src/**` modules, `composer.json`, smoke tests, open and closed issues, and recent pull requests. The source-derived coverage map is maintained in [Coverage map](coverage-map.md).

## Start here

| Topic | Scope |
| --- | --- |
| [Architecture and bootstrap lifecycle](architecture.md) | Plugin loading, module boundaries, initialization hooks, requirements, testing, and design principles. |
| [Agents, runtime, and tools](agents-runtime-tools.md) | Agent registration, conversation loop, messages, compaction, iteration budgets, tool declarations, execution mediation, tool visibility, and action policy. |
| [Authorization, identity, and permissions](auth-identity-permissions.md) | Execution principals, bearer tokens, access grants, caller context, capability ceilings, effective-agent resolution, identity, and workspace scope. |
| [Memory, context, and guidelines](memory-context-guidelines.md) | Memory store contracts, provenance metadata, context registries, retrieved-context authority, composable sections, and `wp_guideline` substrate. |
| [Channels and bridges](channels-bridges.md) | `agents/chat`, direct channels, external message normalization, session mapping, webhook safety, and remote bridge queue/pending/ack semantics. |
| [Workflows and routines](workflows-routines.md) | Workflow specs, validation, bindings, runner, canonical workflow abilities, optional Action Scheduler bridge, routines, and subagent declarations. |
| [Persistence, approvals, consent, and packages](persistence-approvals-consent-packages.md) | Transcript stores and locks, pending action approval contracts, consent decisions, package artifact contracts, and default-store boundary. |
| [Coverage map](coverage-map.md) | Source/test/docs/issues/PR evidence, coverage status by source area, known out-of-scope areas, and maintenance checklist. |

## Public extension points at a glance

- **Registration hooks:** `wp_agents_api_init`, `wp_abilities_api_categories_init`, `wp_abilities_api_init`.
- **Agent registry helpers:** `wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, `wp_unregister_agent()`.
- **Workflow/routine helpers:** `wp_register_workflow()`, `wp_get_workflow()`, `wp_register_routine()`, `wp_get_routine()` and the workflow handler helper in `AgentsAPI\AI\Workflows\register_workflow_handler()`.
- **Canonical abilities:** `agents/chat`, `agents/run-workflow`, `agents/validate-workflow`, and `agents/describe-workflow`.
- **Runtime hooks and filters:** `agents_api_loop_event`, `agents_api_execution_principal`, `agents_api_resolved_tools`, `agents_api_tool_action_policy`, `agents_api_tool_policy_providers`, `agents_api_action_policy_providers`, `wp_agent_chat_handler`, `wp_agent_workflow_handler`, `wp_agent_workflow_step_handlers`, `wp_agent_workflow_known_step_types`, `wp_agent_workflow_known_trigger_types`, `agents_run_workflow_permission`, `agents_validate_workflow_permission`, `wp_guidelines_substrate_enabled`, `agents_api_memory_sources`, and `agents_api_context_sections`.
- **Scheduling hooks:** `wp_agent_workflow_schedule_requested`, `wp_agent_workflow_run_scheduled`, `wp_agent_routine_schedule_requested`, `wp_agent_routine_run_scheduled`, and routine registry lifecycle hooks.

## Ownership boundary

Agents API owns reusable contracts and provider-neutral runtime mechanics: agent definitions, message envelopes, conversation loop sequencing, tool mediation, authorization shapes, context/memory contracts, channel/bridge substrate, workflow/routine plumbing, transcript and approval interfaces, consent value objects, package artifact contracts, and guideline substrate vocabulary.

Consumers own concrete provider calls, product UI, REST controllers, durable storage, store schemas, workflow editors, concrete tools, prompt assembly, support/escalation routing, approval UX, connector onboarding screens, and product-specific policy decisions.
