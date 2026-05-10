# Workflows and Routines

Workflows are deterministic step recipes. Routines are persistent scheduled wakes for an agent session. Agents API defines their contracts, registries, ability dispatchers, and optional Action Scheduler bridges; products own concrete stores, handlers, and UX.

## Workflow specs

`WP_Agent_Workflow_Spec` is the immutable wire shape for a workflow: ID, version, input schemas, ordered steps, triggers, metadata, and raw array for round-tripping. `WP_Agent_Workflow_Spec::from_array()` validates through `WP_Agent_Workflow_Spec_Validator` and returns `WP_Error` on invalid specs.

The initial step vocabulary includes `ability` and `agent`. Trigger vocabulary includes `on_demand`, `wp_action`, and `cron`. Consumers can extend known step and trigger types through validator filters.

## Registration and abilities

`wp_register_workflow()` and lookup helpers register workflows in the in-memory registry. Canonical abilities include:

- `agents/run-workflow`
- `agents/validate-workflow`
- `agents/describe-workflow`

`agents/run-workflow` dispatches to a handler supplied by `wp_agent_workflow_handler`. Validation and describe are handled by Agents API. Permissions are filtered with `agents_run_workflow_permission` and `agents_validate_workflow_permission`.

## Runner

`WP_Agent_Workflow_Runner` validates required inputs, expands `${...}` bindings with `WP_Agent_Workflow_Bindings`, executes steps in order, records start/step/end lifecycle through `WP_Agent_Workflow_Run_Recorder`, and returns `WP_Agent_Workflow_Run_Result`.

Default step handlers cover abilities and agents. Consumers extend or replace handlers with `wp_agent_workflow_step_handlers`. Missing handlers and `WP_Error` step results produce failed step records; `continue_on_error` keeps later steps running when requested. The final output contains per-step outputs and a `last` shortcut only when the last step succeeded.

## Storage and scheduling

`WP_Agent_Workflow_Store` and `WP_Agent_Workflow_Run_Recorder` are persistence contracts. Specs may come from PHP, posts, custom tables, REST uploads, files, or remote systems.

`WP_Agent_Workflow_Action_Scheduler_Bridge` maps cron triggers to Action Scheduler when the `as_*` functions exist. Without Action Scheduler, the bridge no-ops cleanly. Schedule requests also fire `wp_agent_workflow_schedule_requested` so custom schedulers can observe or replace the bridge.

## Routines

`WP_Agent_Routine` represents a persistent scheduled invocation of an agent. It requires an agent slug and either an interval in seconds or a cron expression. Routines reuse a stable session ID across wakes, defaulting to `routine:{id}`, so the agent can accumulate context over time.

Routine registration uses `wp_register_routine()` and the in-memory routine registry. `WP_Agent_Routine_Action_Scheduler_Bridge` handles optional Action Scheduler scheduling and emits `wp_agent_routine_schedule_requested`. The actual wake dispatch is through the named ability, commonly `agents/chat`; session persistence belongs to the consumer.

## Tests

Workflow bindings, spec validator, workflow runner, workflow ability, and routine smoke tests cover spec shape, binding expansion, handler dispatch, recorder behavior, ability contracts, optional scheduling, and routine validation.

## Related pages

- [Extension points and public surface](extension-points.md)
- [Runtime, messages, conversation loop, and compaction](runtime-conversation.md)
- [Testing, CI, and operational workflows](testing-operations.md)
