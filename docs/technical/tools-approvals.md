# Tools and Approvals

This page documents the tool declaration, tool visibility, action-policy, execution, and pending-approval contracts loaded from `src/Tools` and `src/Approvals`.

Agents API mediates generic tool shapes and policy decisions. Consumers still own concrete tools, tool sources, user interfaces, product permissions, durable approval queues, and the side effects that apply approved actions.

## Runtime tool declarations

`AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration` validates scoped runtime tool declarations. It only validates the declaration shape; it does not register, expose, or execute tools.

Constants:

- `SOURCE_CLIENT = 'client'`
- `EXECUTOR_CLIENT = 'client'`
- `SCOPE_RUN = 'run'`

A valid runtime declaration includes:

```php
$tool = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalize(
	array(
		'name'        => 'client/lookup-order',
		'source'      => 'client',
		'description' => 'Look up an order by ID.',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'order_id' => array( 'type' => 'integer' ),
			),
		),
		'executor'    => 'client',
		'scope'       => 'run',
	)
);
```

Validation rules from source:

- `name` must match `^[a-z][a-z0-9_-]*/[a-z][a-z0-9_-]*$`.
- The namespace/source prefix must be `client`.
- If `source` is supplied, it must match the prefix extracted from `name`.
- `description` must be a non-empty string.
- `parameters`, when supplied, must be an array.
- `executor` must be `client`.
- `scope` must be `run`.

Invalid declarations throw `InvalidArgumentException` with an `invalid_runtime_tool_declaration` prefix and sanitized field names.

## Tool visibility policy

`WP_Agent_Tool_Policy` resolves which tools are visible for a runtime context. It accepts an already gathered tool map; discovery and concrete registration remain consumer responsibilities.

Runtime modes:

- `chat`
- `pipeline`
- `system`

Named policy modes:

- `allow`
- `deny`

Resolution layers, in order:

1. Tool `mode`/`modes` filtering.
2. Optional caller `tool_access_checker` callback.
3. Registered agent or runtime `tool_policy` fragments.
4. Host `WP_Agent_Tool_Access_Policy` providers from constructor, context, or `agents_api_tool_policy_providers` filter.
5. Runtime `categories` filtering.
6. Runtime and provider `allow_only` lists.
7. Runtime and provider `deny` lists.
8. Final `agents_api_resolved_tools` filter.

Policy providers may also contribute `mandatory_tools` and `mandatory_categories`; those are preserved through allow/category filtering, but explicit deny still removes them.

Example:

```php
$visible = ( new WP_Agent_Tool_Policy() )->resolve(
	$all_tools,
	array(
		'mode' => WP_Agent_Tool_Policy::RUNTIME_CHAT,
		'agent_config' => array(
			'tool_policy' => array(
				'mode'       => WP_Agent_Tool_Policy::MODE_ALLOW,
				'categories' => array( 'read' ),
			),
		),
		'deny' => array( 'client/delete-record' ),
	)
);
```

## Action policy

Action policy answers a different question than visibility: once a visible tool is called, may it execute directly, require preview/approval, or be forbidden?

`WP_Agent_Action_Policy` defines canonical values:

- `direct`
- `preview`
- `forbidden`

`WP_Agent_Action_Policy_Resolver` resolves a tool's action policy from runtime context, agent config, host providers, tool defaults, and the final `agents_api_tool_action_policy` filter. The README documents the source order, and smoke tests cover values and resolver behavior.

Consumers can use `preview` to return an approval proposal rather than applying side effects immediately.

## Tool execution contracts

Tool execution is split into value objects/interfaces so the runtime loop can mediate without owning concrete tools:

- `WP_Agent_Tool_Call` describes one requested call.
- `WP_Agent_Tool_Parameters` normalizes call parameters.
- `WP_Agent_Tool_Executor` is the adapter interface that consumers implement.
- `WP_Agent_Tool_Execution_Core` validates declarations and dispatches to the executor.
- `WP_Agent_Tool_Result` normalizes success/error results.
- `WP_Agent_Tool_Source_Registry` stores generic tool source metadata.

When `WP_Agent_Conversation_Loop` receives `tool_executor` and `tool_declarations`, it mediates the call -> execute -> result-message lifecycle through these contracts.

## Pending action approvals

`AgentsAPI\AI\Approvals\WP_Agent_Pending_Action` represents a proposed side effect that requires explicit approval before a consumer applies it.

Canonical fields:

| Field | Purpose |
| --- | --- |
| `action_id` | Stable pending action identifier. |
| `kind` | Caller-owned action kind. |
| `summary` | Human-readable summary. |
| `preview` | JSON-serializable preview/diff/value for review. |
| `apply_input` | JSON-serializable replay input for the product handler. |
| `workspace` | Optional `WP_Agent_Workspace_Scope` array. |
| `agent` | Optional agent identity. |
| `creator` | Optional creator actor/provenance. |
| `status` | `pending`, `accepted`, `rejected`, `expired`, or `deleted`. |
| `created_at`, `expires_at` | Lifecycle timestamps. |
| `resolved_at`, `resolver` | Terminal audit identity. |
| `resolution_result`, `resolution_error`, `resolution_metadata` | Terminal result audit fields. |
| `metadata` | Caller-owned JSON-serializable context. |

Required fields must be non-empty strings where appropriate, and `preview`, `apply_input`, metadata, and resolution data must be JSON serializable. Terminal statuses require `resolved_at`; all terminal statuses except `expired` also require `resolver`.

Approvals are intentionally generic:

- `WP_Agent_Pending_Action_Status` owns the status vocabulary.
- `WP_Agent_Approval_Decision` models accept/reject decisions.
- `WP_Agent_Pending_Action_Store` defines storage, list, summary, resolution, expiration, and delete operations.
- `WP_Agent_Pending_Action_Resolver` resolves actions with a resolver identity.
- `WP_Agent_Pending_Action_Handler` lets product handlers enforce permissions and apply or reject the proposal.

## Failure modes and safety posture

- Invalid tool declarations fail before execution.
- Explicit deny wins in visibility policy.
- Action policy can forbid or require preview even when a tool is visible.
- Pending action value objects reject missing required fields, invalid statuses, invalid workspace shapes, and non-JSON-serializable payloads.
- Agents API never applies product side effects itself; consumers implement handlers and stores.

## Evidence

Source: `src/Tools/class-wp-agent-tool-declaration.php`, `src/Tools/class-wp-agent-tool-policy.php`, `src/Tools/class-wp-agent-tool-policy-filter.php`, `src/Tools/class-wp-agent-action-policy-resolver.php`, `src/Tools/class-wp-agent-tool-execution-core.php`, `src/Tools/*`, `src/Approvals/class-wp-agent-pending-action.php`, and `src/Approvals/*`.

Tests: `tests/tool-policy-contracts-smoke.php`, `tests/action-policy-values-smoke.php`, `tests/action-policy-resolver-smoke.php`, `tests/tool-runtime-smoke.php`, `tests/pending-action-store-contract-smoke.php`, `tests/approval-resolver-contract-smoke.php`, and `tests/approval-action-value-shape-smoke.php`.
