# Tools, Action Policy, Approvals, and Consent

These modules define tool visibility, tool execution mediation, staged action approvals, and consent decisions. They provide contracts and conservative defaults, while products own concrete tools, UI, stores, and resolution flows.

## Tool declarations and sources

`WP_Agent_Tool_Declaration` validates runtime tool declarations. `WP_Agent_Tool_Source_Registry` gathers tool declarations from registered sources; earlier sources win on duplicate names.

Tool source and visibility extension points include:

- `agents_api_tool_sources`
- `agents_api_tool_source_order`
- `agents_api_tool_policy_providers`
- `agents_api_resolved_tools`

## Visibility policy

`WP_Agent_Tool_Policy` resolves the visible tool set for a runtime mode. It combines mode, category, allow-only, deny, mandatory tools/categories, access checks, providers, and final filters. `WP_Agent_Tool_Policy_Filter` provides reusable filtering mechanics.

Supported modes include chat, pipeline, and system. Mandatory tools survive optional filtering so a runtime can keep required infrastructure tools available.

## Tool execution

`WP_Agent_Tool_Call` normalizes model/tool-call payloads. `WP_Agent_Tool_Parameters` validates required parameters and merges runtime context with explicit call parameters, with tool parameters winning. `WP_Agent_Tool_Execution_Core` checks that the tool exists, validates parameters, calls `WP_Agent_Tool_Executor`, and normalizes the result through `WP_Agent_Tool_Result`.

Missing tools, missing required parameters, and executor exceptions return normalized error results. Executor payloads without a `success` flag are wrapped as successful payloads.

## Action policy

`WP_Agent_Action_Policy` defines the action vocabulary:

- `direct` — execute immediately.
- `preview` — stage for review before applying.
- `forbidden` — deny execution.

`WP_Agent_Action_Policy_Resolver` resolves policy in this order: explicit deny list, agent per-tool policy, agent per-category policy, providers, tool default policy, mode-specific policy, then default direct execution. Hosts extend resolution with `agents_api_action_policy_providers` and `agents_api_tool_action_policy`.

## Pending approvals

`WP_Agent_Pending_Action` is the durable value object for a staged action. It contains the action ID, kind, summary, preview, apply input, creation timestamp, optional workspace/agent/creator/expiry/resolution audit, and metadata.

Statuses live in `WP_Agent_Pending_Action_Status`: pending, accepted, rejected, expired, and deleted. Terminal actions require resolution metadata where applicable. Stores implement `WP_Agent_Pending_Action_Store`; products implement `WP_Agent_Pending_Action_Handler` and resolver behavior through `WP_Agent_Pending_Action_Resolver`.

Agents API intentionally does not prescribe approval UI, notification routing, storage tables, or apply/reject side effects.

## Consent

`WP_Agent_Consent_Operation` covers store memory, use memory, store transcript, share transcript, and escalate to human. `WP_Agent_Consent_Decision` records allowed/denied state, reason, and JSON-friendly audit metadata. `WP_Agent_Consent_Policy` is the host policy interface.

`WP_Agent_Default_Consent_Policy` is conservative: non-interactive contexts deny, and interactive contexts require explicit consent for the requested operation. Interactive mode is inferred from flags or context/mode values such as chat, interactive, or REST.

## Tests

Tool runtime, tool policy contracts, action policy values, action policy resolver, pending action shape, approval resolver contracts, and consent policy smoke tests cover these modules.

## Related pages

- [Runtime, messages, conversation loop, and compaction](runtime-conversation.md)
- [Auth, permissions, caller context, and bridge authorization](auth-permissions.md)
- [Workflows and routines](workflows-routines.md)
