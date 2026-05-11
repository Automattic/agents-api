# Workflows And Routines

Agents API ships workflow and routine contracts for reusable orchestration, but it does not ship a concrete product workflow runtime, durable workflow UI, product step types, or scheduler stack beyond optional Action Scheduler bridges.

Source evidence: `src/Workflows/**`, `src/Routines/**`, `tests/workflow-*.php`, `tests/agents-workflow-ability-smoke.php`, `tests/routine-smoke.php`, and the Action Scheduler suggestion in `composer.json`.

## Workflow model

A workflow is a storage-agnostic spec plus a runner contract. The canonical value object is `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec`.

A spec contains:

| Field | Purpose |
| --- | --- |
| `id` | Stable workflow identifier, usually namespaced by the consumer. |
| `version` | Caller-defined version string; defaults to `0.0.0` when omitted. |
| `inputs` | Map of input name to JSON-Schema-like fragments with `type`, optional `required`, and optional `default`. |
| `steps` | Ordered list of step definitions. The substrate supports `ability` and `agent` by default. |
| `triggers` | Optional trigger definitions. Known defaults are `on_demand`, `wp_action`, and `cron`. |
| `meta` | Free-form consumer metadata, opaque to the runner. |

Representative spec:

```php
$spec = AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec::from_array(
	array(
		'id'      => 'example/triage-comment',
		'version' => '1.0.0',
		'inputs'  => array(
			'comment_id' => array( 'type' => 'integer', 'required' => true ),
		),
		'steps'   => array(
			array(
				'id'      => 'classify',
				'type'    => 'agent',
				'agent'   => 'triage-agent',
				'message' => 'Classify comment ${inputs.comment_id}',
			),
			array(
				'id'      => 'record',
				'type'    => 'ability',
				'ability' => 'example/record-classification',
				'args'    => array(
					'classification' => '${steps.classify.output.reply}',
				),
			),
		),
	)
);
```

`WP_Agent_Workflow_Spec::from_array()` returns a `WP_Error` with structured validation details when the spec is invalid.

## Structural validation

`WP_Agent_Workflow_Spec_Validator::validate( array $spec ): array` performs structural linting without touching storage or runtime dependencies. It checks:

- non-empty string `id`;
- `inputs` is a map when present;
- at least one step exists;
- each step has a non-empty `id` and `type`;
- duplicate step IDs;
- known step types, extended by `wp_agent_workflow_known_step_types`;
- required fields for `ability` and `agent` steps;
- trigger list shape and known trigger types, extended by `wp_agent_workflow_known_trigger_types`;
- `wp_action` triggers include `hook`;
- `cron` triggers include `expression` or `interval`;
- `${steps.<id>.output.*}` references point only to earlier steps.

The validator intentionally does not verify that referenced agents or abilities exist. That is a runtime concern.

## Runner lifecycle

`WP_Agent_Workflow_Runner::run( WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() ): WP_Agent_Workflow_Run_Result` executes a spec step-by-step.

Lifecycle:

1. Generate or accept a `run_id`.
2. Create an initial `running` result.
3. Call `WP_Agent_Workflow_Run_Recorder::start()` when a recorder is configured.
4. Validate required inputs.
5. For each step:
   - expand `${...}` bindings with `WP_Agent_Workflow_Bindings` against inputs and previous step outputs;
   - dispatch to the step-type handler;
   - record status, output, error, and timestamps;
   - call recorder `update()`;
   - stop on failure unless `continue_on_error` is set.
6. Return a terminal `succeeded` or `failed` `WP_Agent_Workflow_Run_Result` with aggregated step output.

If `recorder->start()` returns `WP_Error`, the runner returns a failed result and does not run steps. Missing step handlers produce a skipped step record and mark the run failed. Handler `WP_Error` values become structured step errors.

## Built-in step types

The default handler map contains:

- `ability` — requires `wp_get_ability()`, looks up the named ability, and calls `execute()` with resolved `args`.
- `agent` — calls the canonical `agents/chat` ability with `agent`, `message`, and optional `session_id`.

Consumers extend or replace handlers through the constructor or the `wp_agent_workflow_step_handlers` filter. This is where product-specific `branch`, `parallel`, or nested `workflow` behavior belongs.

## Workflow abilities

`src/Workflows/register-agents-workflow-abilities.php` registers three canonical abilities:

| Ability | Purpose | Default permission |
| --- | --- | --- |
| `agents/run-workflow` | Dispatch a registered or inline workflow to a runtime handler. | `current_user_can( 'manage_options' )`, filtered by `agents_run_workflow_permission`. |
| `agents/validate-workflow` | Structurally validate a supplied spec and return `valid` plus `errors`. | `is_user_logged_in()`, filtered by `agents_validate_workflow_permission`. |
| `agents/describe-workflow` | Return a registered in-memory spec and its inputs. | Same gate as run-workflow. |

`agents/run-workflow` dispatches through the `wp_agent_workflow_handler` filter. `AgentsAPI\AI\Workflows\register_workflow_handler()` is a convenience helper. If no handler is registered, or the handler returns an invalid type, the dispatcher emits `agents_run_workflow_dispatch_failed` and returns `WP_Error`.

Canonical run-workflow input accepts `workflow_id`, `spec`, `inputs`, and `options`. Canonical output requires `run_id`, `workflow_id`, and `status`, with optional `output`, `steps`, `error`, `started_at`, `ended_at`, and `metadata`.

## Workflow storage and scheduling boundaries

Agents API defines these seams:

- `WP_Agent_Workflow_Registry` for in-memory registration.
- `WP_Agent_Workflow_Store` for consumer-owned persistence.
- `WP_Agent_Workflow_Run_Recorder` for durable run history.
- `WP_Agent_Workflow_Action_Scheduler_Bridge` for optional Action Scheduler integration.

The package does not provide durable workflow tables, a workflow editor, product step types, trigger listeners beyond generic bridges, or a full scheduling stack.

## Routines

A routine is a persistent scheduled invocation of an agent that reuses the same conversation session across wakes. It differs from a workflow because it is not a deterministic recipe with fresh inputs per run; it is a long-running agent loop with a stable `session_id`.

`AgentsAPI\AI\Routines\WP_Agent_Routine` fields:

| Field | Purpose |
| --- | --- |
| `id` | Sanitized unique routine slug. |
| `label` | Human-readable label; defaults to the ID. |
| `agent` | Required registered agent slug. |
| `interval` or `expression` | Exactly one trigger: interval seconds or cron expression. |
| `prompt` | Prompt sent on each wake. |
| `session_id` | Persistent session reused across wakes; defaults to `routine:<id>`. |
| `meta` | Consumer-owned metadata. |

`WP_Agent_Routine_Action_Scheduler_Bridge` optionally registers Action Scheduler actions using hook `wp_agent_routine_run_scheduled` and group `agents-api`. `register()` always fires `wp_agent_routine_schedule_requested` so custom schedulers can take over even when Action Scheduler is unavailable. Registration is idempotent: existing actions for the routine are unscheduled before a new recurring or cron action is scheduled.

Routine dispatch on wake is listener-owned. Agents API provides the value object, registry, bridge, and listener wiring; consumers own persistence, concrete wake handling, policy, and UI.
