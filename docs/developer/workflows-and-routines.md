# Workflows And Routines

Agents API includes workflow and routine substrate primitives for consumers that need reusable orchestration contracts. The package does **not** ship a concrete durable workflow runtime, workflow editor, product step library, or product scheduling policy.

## Workflow module inventory

Workflow classes live in `src/Workflows/**` under the `AgentsAPI\AI\Workflows` namespace.

| Surface | Purpose |
| --- | --- |
| `WP_Agent_Workflow_Spec` | Immutable workflow definition value object with ID, inputs, steps, triggers, and metadata. |
| `WP_Agent_Workflow_Spec_Validator` | Structural validator for raw specs, including IDs, steps, triggers, and step binding references. |
| `WP_Agent_Workflow_Bindings` | Expands `${...}` bindings against workflow inputs and previous step outputs. |
| `WP_Agent_Workflow_Run_Result` | Run result value object with run ID, workflow ID, status, inputs, output, steps, errors, timestamps, and metadata. |
| `WP_Agent_Workflow_Store` | Consumer storage contract for persisted workflow specs. |
| `WP_Agent_Workflow_Run_Recorder` | Consumer storage/observability contract for run lifecycle records. |
| `WP_Agent_Workflow_Runner` | Narrow runner skeleton for sequential step execution. |
| `WP_Agent_Workflow_Registry` | In-memory registry for specs registered by code. |
| `WP_Agent_Workflow_Action_Scheduler_Bridge` | Optional bridge for scheduled workflow runs when Action Scheduler is present. |
| `register_workflow_handler()` | Helper that registers the first callable runtime handler through `wp_agent_workflow_handler`. |

## Spec validation

`WP_Agent_Workflow_Spec_Validator::validate( array $spec ): array` returns structured errors. An empty array means the spec is structurally valid enough to construct a spec object.

The validator checks:

- `id` is a non-empty string.
- `inputs`, when present, is a map.
- `steps` is a non-empty list.
- Each step has a non-empty unique `id` and non-empty `type`.
- Built-in step types are `ability` and `agent`; consumers can extend known types with `wp_agent_workflow_known_step_types`.
- `ability` steps have `ability`.
- `agent` steps have `agent` and `message`.
- Optional triggers are a list; built-in trigger types are `on_demand`, `wp_action`, and `cron`; consumers can extend known triggers with `wp_agent_workflow_known_trigger_types`.
- `wp_action` triggers have `hook`.
- `cron` triggers have either `expression` or `interval`.
- `${steps.<id>.output.*}` references only point to prior steps.

The validator does not check whether referenced abilities or agents exist. That is a runtime responsibility.

## Runner lifecycle

`WP_Agent_Workflow_Runner::run( WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() ): WP_Agent_Workflow_Run_Result` executes a workflow sequentially:

1. Create a running result with a generated or caller-supplied `run_id`.
2. Call `WP_Agent_Workflow_Run_Recorder::start()` when a recorder is present.
3. Validate required inputs declared by the spec.
4. Walk steps in order.
5. Expand bindings against `inputs` and earlier `steps.<id>.output` values.
6. Dispatch each step to a handler.
7. Record per-step status, output/error, and timestamps.
8. Call recorder `update()` after input failure, each step update, and terminal completion.
9. Return a terminal result with status `succeeded` or `failed`.

Supported runtime options:

- `run_id`: caller-supplied run identifier.
- `continue_on_error`: keep executing after failed steps; default is `false`.
- `metadata`: JSON-friendly metadata copied to the run result.

Default step handlers:

- `ability`: calls `wp_get_ability( $step['ability'] )->execute( $step['args'] ?? array() )`.
- `agent`: calls the canonical `agents/chat` ability with `agent`, `message`, and optional `session_id`.

Consumers can add or replace step handlers by passing handlers to the constructor or filtering `wp_agent_workflow_step_handlers`. This is the extension point for product step types such as `branch`, `parallel`, and nested `workflow`.

## Canonical workflow abilities

`src/Workflows/register-agents-workflow-abilities.php` registers three Abilities API dispatchers under the `agents-api` ability category:

| Ability | Purpose | Default permission |
| --- | --- | --- |
| `agents/run-workflow` | Execute a registered workflow ID or inline spec by dispatching to a consumer runtime from `wp_agent_workflow_handler`. | `current_user_can( 'manage_options' )`, filterable through `agents_run_workflow_permission`. |
| `agents/validate-workflow` | Structurally validate a raw spec without touching storage or runtime. | `is_user_logged_in()`, filterable through `agents_validate_workflow_permission`. |
| `agents/describe-workflow` | Return an in-memory registered spec and its input declarations. | Same callback as `agents/run-workflow`. |

`agents/run-workflow` returns `agents_run_workflow_no_handler` when no runtime is registered, `agents_run_workflow_invalid_result` when the handler returns a non-array value, and propagates handler `WP_Error` values. Dispatch failures fire `agents_run_workflow_dispatch_failed`.

### `agents/run-workflow` input example

```json
{
  "workflow_id": "publish-summary",
  "spec": null,
  "inputs": {
    "post_id": 123
  },
  "options": {
    "run_id": "run-123",
    "continue_on_error": false,
    "metadata": { "source": "admin-ui" }
  }
}
```

The output schema requires `run_id`, `workflow_id`, and `status`; it may include `output`, `steps`, `error`, `started_at`, `ended_at`, and `metadata`.

## Optional Action Scheduler bridge

`composer.json` suggests `woocommerce/action-scheduler` for scheduled workflow execution. Agents API detects Action Scheduler at runtime and no-ops cleanly when absent. The substrate owns the optional bridge shape; consumers still own durable workflow storage, activation policy, and operational controls.

## Routines

Routine classes live in `src/Routines/**` and provide a lighter declaration/registry bridge for recurring or scheduled agent behavior:

- `WP_Agent_Routine`: routine declaration value object.
- `WP_Agent_Routine_Registry`: in-memory registry.
- `WP_Agent_Routine_Action_Scheduler_Bridge`: Action Scheduler bridge.
- `register-routines.php`, `register-routine-bridge-sync.php`, and `register-action-scheduler-listener.php`: bootstrap/listener helpers.

Routines share the same boundary as workflows: Agents API declares generic scheduling/run contracts; consumers decide concrete authoring UX, storage, permission model, and operational policy.

## Evidence

- Implementation: `src/Workflows/**`, `src/Routines/**`, `composer.json`.
- Tests: `tests/workflow-bindings-smoke.php`, `tests/workflow-spec-validator-smoke.php`, `tests/workflow-runner-smoke.php`, `tests/agents-workflow-ability-smoke.php`, `tests/routine-smoke.php`.
