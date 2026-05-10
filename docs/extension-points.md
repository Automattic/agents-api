# Extension Points and Public Surface

Agents API exposes a WordPress-shaped public surface: global helper functions for registration/lookup, Abilities API dispatchers for runtime entry points, value objects and interfaces for adapters, and WordPress hooks/filters for host policy.

## Agent helpers

Register agents during `wp_agents_api_init`:

- `wp_register_agent( string|WP_Agent $agent, array $args = array() ): ?WP_Agent`
- `wp_get_agent( string $slug ): ?WP_Agent`
- `wp_get_agents(): array`
- `wp_has_agent( string $slug ): bool`
- `wp_unregister_agent( string $slug ): ?WP_Agent`

`wp_register_agent()` refuses calls outside `wp_agents_api_init`. Reads are safe after WordPress `init`.

## Workflow and routine helpers

Workflow registration is backed by the in-memory workflow registry:

- `wp_register_workflow()` / `wp_get_workflow()` are loaded from `src/Workflows/register-workflows.php`.
- `AgentsAPI\AI\Workflows\register_workflow_handler()` adds the first runtime handler to `wp_agent_workflow_handler`.

Routine registration is backed by the in-memory routine registry:

- `wp_register_routine()` / lookup helpers are loaded from `src/Routines/register-routines.php`.
- Routine bridge sync files attach registry events to optional scheduling.

## Guideline helpers

`wp_guideline_types()` returns guideline type definitions and is filterable through `wp_guideline_types`. Internal helpers in `src/Guidelines/guidelines.php` map capabilities for private memory and workspace-shared guidance.

## Canonical abilities

Agents API registers these abilities when the WordPress Abilities API is available:

| Ability | Purpose | Runtime owner |
| --- | --- | --- |
| `agents/chat` | Send one message to an agent and receive a reply. | Consumer via `wp_agent_chat_handler`. |
| `agents/run-workflow` | Run a workflow by ID or inline spec. | Consumer via `wp_agent_workflow_handler`. |
| `agents/validate-workflow` | Structurally validate a workflow spec. | Agents API. |
| `agents/describe-workflow` | Return an in-memory registered workflow spec and inputs. | Agents API. |

The chat and run-workflow abilities are dispatchers. They validate shape, locate a registered handler, call it, and fire failure hooks on missing/invalid handlers.

## WordPress hooks and filters

### Registration and bootstrap

- `wp_agents_api_init` — registration window for agents.
- `wp_abilities_api_categories_init` — used to register the `agents-api` ability category.
- `wp_abilities_api_init` — used to register canonical abilities.
- `wp_guidelines_substrate_enabled` — disables the guideline polyfill when false.

### Runtime dispatch and permissions

- `wp_agent_chat_handler` — returns the callable for `agents/chat`.
- `agents_chat_dispatch_failed` — action fired for chat dispatcher failures.
- `agents_chat_permission` — filters `agents/chat` permission. Default is `current_user_can( 'manage_options' )`.
- `wp_agent_channel_chat_ability` — changes the ability slug used by `WP_Agent_Channel`.
- `wp_agent_workflow_handler` — returns the callable for `agents/run-workflow`.
- `agents_run_workflow_dispatch_failed` — action fired for workflow dispatcher failures.
- `agents_run_workflow_permission` — filters run/describe workflow permission. Default is `manage_options`.
- `agents_validate_workflow_permission` — filters validate-workflow permission. Default is any logged-in user.
- `agents_api_execution_principal` — lets hosts resolve an `WP_Agent_Execution_Principal` from request context.

### Conversation loop and tools

- `agents_api_loop_event` — observational action for loop lifecycle events. Observer exceptions are swallowed.
- `agents_api_resolved_tools` — final filter over visible tools from `WP_Agent_Tool_Policy`.
- `agents_api_tool_policy_providers` — supplies `WP_Agent_Tool_Access_Policy` providers.
- `agents_api_action_policy_providers` — supplies `WP_Agent_Action_Policy_Provider` providers.
- `agents_api_tool_action_policy` — final filter over direct/preview/forbidden action policy.

### Context and memory

- `agents_api_memory_sources` — one-time hook to register memory/context sources.
- `agents_api_context_sections` — one-time hook to register composable context sections.
- `agents_api_composable_context_content` — filters composed context content.

### Workflows and routines

- `wp_agent_workflow_known_step_types` — extends validator-known step types beyond `ability` and `agent`.
- `wp_agent_workflow_known_trigger_types` — extends validator-known trigger types beyond `on_demand`, `wp_action`, and `cron`.
- `wp_agent_workflow_step_handlers` — extends/replaces runner step handlers.
- `wp_agent_workflow_registered` — fires after in-memory workflow registration.
- `wp_agent_workflow_schedule_requested` — fires for every cron trigger the workflow bridge would schedule.
- `wp_agent_routine_registered` / `wp_agent_routine_unregistered` — fire when routines are added/removed.
- `wp_agent_routine_schedule_requested` — fires when the routine bridge would schedule a routine.

## Adapter interfaces

Consumers implement interfaces for durable behavior:

- Auth: `WP_Agent_Access_Store`, `WP_Agent_Token_Store`, `WP_Agent_Authorization_Policy`.
- Runtime: `WP_Agent_Conversation_Runner`, `WP_Agent_Conversation_Completion_Policy`, `WP_Agent_Transcript_Persister`.
- Tools: `WP_Agent_Tool_Executor`, `WP_Agent_Tool_Access_Policy`, `WP_Agent_Action_Policy_Provider`.
- Approvals: `WP_Agent_Pending_Action_Store`, `WP_Agent_Pending_Action_Resolver`, `WP_Agent_Pending_Action_Handler`.
- Consent: `WP_Agent_Consent_Policy`.
- Memory: `WP_Agent_Memory_Store`, `WP_Agent_Memory_Validator`.
- Transcripts: `WP_Agent_Conversation_Store`, `WP_Agent_Conversation_Lock`.
- Identity: `WP_Agent_Identity_Store`.
- Workflows: `WP_Agent_Workflow_Store`, `WP_Agent_Workflow_Run_Recorder`.
- Channels/bridges: `WP_Agent_Channel_Session_Store`, `WP_Agent_Message_Idempotency_Store`, `WP_Agent_Bridge_Store`.

## Related pages

- [Bootstrap and lifecycle](bootstrap-lifecycle.md)
- [Runtime, messages, conversation loop, and compaction](runtime-conversation.md)
- [Workflows and routines](workflows-routines.md)
