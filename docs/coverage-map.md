# Agents API Documentation Coverage Map

This coverage map records the source, test, documentation, issue, and pull-request evidence used for the technical documentation bootstrap pass at target ref `1240bdadde1be0231f1da34089e1fac7265d2003` (`docs-agent-flow-selector`). It is intended to help maintainers see which parts of the substrate are now covered by developer-facing docs and where follow-up documentation should be added as code changes.

## Bootstrap outputs

| Output | Purpose |
| --- | --- |
| [`docs/index.md`](index.md) | Navigable developer documentation index, repository map, lifecycle summary, extension hook list, development commands, and ownership principles. |
| [`docs/modules.md`](modules.md) | Broad module-by-module guide for the current public substrate: registry, packages, runtime, tools, auth, consent, approvals, memory/context, channels/bridges, workflows, routines, tests, and open design threads. |
| `docs/coverage-map.md` | This source-derived map of covered modules, docs, tests, issues, and PR evidence. |

Existing focused docs remain part of the documentation surface:

| Existing doc | Covered topic |
| --- | --- |
| [`README.md`](../README.md) | Canonical boundary statement, public surface, examples, ownership model, requirements, and integration overview. |
| [`external-clients.md`](external-clients.md) | External client architecture for direct channels and remote bridges. |
| [`remote-bridge-protocol.md`](remote-bridge-protocol.md) | Queue-first remote bridge flow and storage seam. |
| [`bridge-authorization.md`](bridge-authorization.md) | Bridge authorization/onboarding and Core Connectors boundary. |
| [`default-stores-companion.md`](default-stores-companion.md) | Companion-package proposal for concrete memory/transcript stores. |

## Source tree coverage

| Area | Source paths | Current docs coverage | Test evidence |
| --- | --- | --- | --- |
| Plugin bootstrap and load order | `agents-api.php`, `composer.json` | `docs/index.md` and `docs/modules.md` document direct module loading, constants, `init` hooks, optional Action Scheduler detection, PHP/WP requirements, and `composer test`. | `tests/bootstrap-smoke.php`, `composer.json` scripts. |
| Agent registry | `src/Registry/class-wp-agent.php`, `src/Registry/class-wp-agents-registry.php`, `src/Registry/register-agents.php` | Registration lifecycle, `WP_Agent` fields, `wp_agents_api_init`, public helper functions, source provenance metadata, and subagent declarations are documented in `docs/modules.md`. | `tests/registry-smoke.php`, `tests/subagents-smoke.php`, `tests/bootstrap-smoke.php`. |
| Packages and artifacts | `src/Packages/*` | Package manifest shape, artifact validation, artifact registry lifecycle, and adopter boundary are documented in `docs/modules.md`. | `tests/bootstrap-smoke.php` export/load checks; package surface listed in README. |
| Auth and authorization | `src/Auth/*` | Access grants, tokens, token authenticator, caller context, capability ceilings, WordPress authorization policy, and execution-principal composition are documented in `docs/modules.md` with boundary notes. | `tests/authorization-smoke.php`, `tests/caller-context-smoke.php`, `tests/execution-principal-smoke.php`. |
| Runtime messages and principals | `src/Runtime/class-wp-agent-message.php`, `class-wp-agent-execution-principal.php`, `class-wp-agent-conversation-request.php`, `class-wp-agent-conversation-result.php` | Message envelope schema/types, provider projection, request/result contracts, and principal fields/filters are documented in `docs/modules.md`. | `tests/message-envelope-smoke.php`, `tests/conversation-runner-contracts-smoke.php`, `tests/execution-principal-smoke.php`. |
| Conversation loop | `src/Runtime/class-wp-agent-conversation-loop.php`, completion policy, transcript persister, null persister | Loop ownership boundary, options, tool mediation, completion policy, transcript persistence, locks, budgets, and events are documented in `docs/modules.md`; README remains the full examples page. | `tests/conversation-loop-smoke.php`, `conversation-loop-tool-execution-smoke.php`, `conversation-loop-completion-policy-smoke.php`, `conversation-loop-transcript-persister-smoke.php`, `conversation-loop-events-smoke.php`, `conversation-loop-budgets-smoke.php`. |
| Compaction | `src/Runtime/class-wp-agent-conversation-compaction.php`, `class-wp-agent-compaction-item.php`, `class-wp-agent-compaction-conservation.php`, `class-wp-agent-markdown-section-compaction-adapter.php` | Transcript compaction, deterministic markdown-section planning, and conservation metadata are documented at the module level in `docs/modules.md`; README has detailed conversation compaction examples. | `tests/conversation-compaction-smoke.php`, `tests/compaction-item-smoke.php`, `tests/compaction-conservation-smoke.php`, `tests/markdown-section-compaction-smoke.php`. |
| Iteration budgets | `src/Runtime/class-wp-agent-iteration-budget.php` | Budget object and loop-integrated budget names (`turns`, `tool_calls`, `tool_calls_<name>`) are documented in README and `docs/modules.md`. | `tests/iteration-budget-smoke.php`, `tests/conversation-loop-budgets-smoke.php`. |
| Tools | `src/Tools/*` | Tool declarations/calls/results, `WP_Agent_Tool_Execution_Core`, visibility policy, action policy, providers, hooks, and canonical action policy values are documented in `docs/modules.md`; README has examples. | `tests/tool-runtime-smoke.php`, `tests/tool-policy-contracts-smoke.php`, `tests/action-policy-resolver-smoke.php`, `tests/action-policy-values-smoke.php`. |
| Consent | `src/Consent/*` | Consent operations, default conservative policy, interactive/explicit consent behavior, decisions, and audit metadata are documented in README and `docs/modules.md`. | `tests/consent-policy-smoke.php`. |
| Approvals | `src/Approvals/*` | Pending action value shape, status vocabulary, resolver/store/handler boundaries, approval-required envelopes, and open observer follow-up are documented in README and `docs/modules.md`. | `tests/pending-action-store-contract-smoke.php`, `tests/approval-resolver-contract-smoke.php`, `tests/approval-action-value-shape-smoke.php`. |
| Workspace | `src/Workspace/class-wp-agent-workspace-scope.php` | Generic `(workspace_type, workspace_id)` identity and examples are documented in README and `docs/modules.md`. | `tests/workspace-scope-smoke.php`. |
| Identity | `src/Identity/*` | `WP_Agent_Identity_Scope`, materialized identity, and store contract are documented in `docs/modules.md`. | `tests/identity-smoke.php`. |
| Memory contracts | `src/Memory/*` | Memory scope identity, store contract methods, metadata, capabilities, queries, validators, and unsupported metadata semantics are documented in README and `docs/modules.md`. | `tests/memory-metadata-contract-smoke.php`; store contracts loaded by `tests/bootstrap-smoke.php`. |
| Memory/context registries | `src/Context/class-wp-agent-memory-registry.php`, `class-wp-agent-context-section-registry.php`, `class-wp-agent-composable-context.php`, injection/layer classes | Source registry, context section registry, retrieval policies, composable context, and hooks are documented in README and `docs/modules.md`. | `tests/context-registry-smoke.php`. |
| Retrieved context authority | `src/Context/class-wp-agent-context-authority-tier.php`, conflict kinds/resolution/resolvers/items | Authority tiers, `WP_Agent_Context_Item`, preference vs authoritative-fact conflict behavior, and default resolver are documented in README and `docs/modules.md`. | `tests/context-authority-smoke.php`. |
| Guidelines substrate | `src/Guidelines/*` | `wp_guideline`/`wp_guideline_type` polyfill, constants, capability mapping, scope meta, and opt-out filter are documented in README and `docs/modules.md`. | `tests/guidelines-substrate-smoke.php`. |
| Transcript contracts and locks | `src/Transcripts/*`, `src/Runtime/class-wp-agent-transcript-persister.php` | Conversation store session shape, `agent_slug`, opaque `provider_response_id`, pending dedup, title mutation, advisory lock contract, and null lock are documented in README and `docs/modules.md`. | `tests/conversation-transcript-lock-smoke.php`, `tests/conversation-loop-transcript-persister-smoke.php`, `tests/workspace-scope-smoke.php`. |
| Channels | `src/Channels/class-wp-agent-channel.php`, external message/session/idempotency/signature helpers, `register-agents-chat-ability.php` | Canonical `agents/chat` ability, direct channel base class, normalized external messages, session mapping, webhook safety, idempotency, permissions, hooks, and failure observability are documented in `docs/modules.md` and `docs/external-clients.md`. | `tests/channels-smoke.php`, `tests/agents-chat-ability-smoke.php`, `tests/webhook-safety-smoke.php`. |
| Remote bridges | `src/Channels/class-wp-agent-bridge*.php`, option bridge store | Bridge client registration, queue items, pending/ack semantics, store replacement, and authorization boundary are documented in `docs/modules.md`, `docs/remote-bridge-protocol.md`, and `docs/bridge-authorization.md`. | `tests/remote-bridge-smoke.php`, `tests/webhook-safety-smoke.php`. |
| Workflows | `src/Workflows/*` | Workflow spec, validator, bindings, store/recorder contracts, runner, registry, canonical workflow abilities, permissions, hooks, default handlers, and optional Action Scheduler bridge are documented in `docs/modules.md`. | `tests/workflow-bindings-smoke.php`, `workflow-spec-validator-smoke.php`, `workflow-runner-smoke.php`, `agents-workflow-ability-smoke.php`. |
| Routines | `src/Routines/*` | Routine value object, in-memory registry, persistent session model, Action Scheduler bridge/listener, hooks, validation, and consumer-owned persistence are documented in `docs/modules.md`. | `tests/routine-smoke.php`, `tests/subagents-smoke.php`, `tests/bootstrap-smoke.php`. |
| Boundary enforcement | Entire `src/` tree | Ownership principles are documented in `docs/index.md` and `docs/modules.md`: no product imports, provider/runtime/storage/UI remain consumer-owned. | `tests/no-product-imports-smoke.php`. |
| Docs Agent CI | `.github/workflows/docs-agent.yml`, `tests/playground-ci/*`, `homeboy.json` | Not a runtime module; mentioned only in this coverage map as the source of this docs bootstrap workflow. | PR #126 and #130 workflow/script validation. |

## Public extension-point coverage

| Hook/filter/action | Source | Documented in |
| --- | --- | --- |
| `wp_agents_api_init` | `src/Registry/class-wp-agents-registry.php` | `docs/index.md`, `docs/modules.md`, README |
| `wp_agent_package_artifacts_init` | `src/Packages/class-wp-agent-package-artifacts-registry.php` | `docs/index.md`, `docs/modules.md` |
| `agents_api_execution_principal` | `src/Runtime/class-wp-agent-execution-principal.php` | README, `docs/index.md`, `docs/modules.md` |
| `agents_api_loop_event` | `src/Runtime/class-wp-agent-conversation-loop.php` | README, `docs/index.md`, `docs/modules.md` |
| `agents_api_memory_sources` | `src/Context/class-wp-agent-memory-registry.php` | README, `docs/index.md`, `docs/modules.md` |
| `agents_api_context_sections` | `src/Context/class-wp-agent-context-section-registry.php` | README, `docs/index.md`, `docs/modules.md` |
| `agents_api_resolved_tools` | `src/Tools/class-wp-agent-tool-policy.php` | README, `docs/index.md`, `docs/modules.md` |
| `agents_api_tool_policy_providers` | `src/Tools/class-wp-agent-tool-policy.php` | README, `docs/index.md`, `docs/modules.md` |
| `agents_api_action_policy_providers` | `src/Tools/class-wp-agent-action-policy-resolver.php` | README, `docs/index.md`, `docs/modules.md` |
| `agents_api_tool_action_policy` | `src/Tools/class-wp-agent-action-policy-resolver.php` | README, `docs/index.md`, `docs/modules.md` |
| `wp_agent_chat_handler` | `src/Channels/register-agents-chat-ability.php` | README, `docs/index.md`, `docs/modules.md`, `docs/external-clients.md` |
| `agents_chat_permission` | `src/Channels/register-agents-chat-ability.php` | `docs/index.md`, `docs/modules.md` |
| `agents_chat_dispatch_failed` | `src/Channels/register-agents-chat-ability.php` | `docs/index.md`, `docs/modules.md` |
| `wp_agent_channel_chat_ability` | `src/Channels/class-wp-agent-channel.php` | `docs/index.md`, `docs/modules.md`, `docs/external-clients.md` |
| `wp_agent_workflow_handler` | `src/Workflows/register-agents-workflow-abilities.php` | `docs/index.md`, `docs/modules.md` |
| `agents_run_workflow_permission` | `src/Workflows/register-agents-workflow-abilities.php` | `docs/index.md`, `docs/modules.md` |
| `agents_validate_workflow_permission` | `src/Workflows/register-agents-workflow-abilities.php` | `docs/index.md`, `docs/modules.md` |
| `agents_run_workflow_dispatch_failed` | `src/Workflows/register-agents-workflow-abilities.php`, workflow/routine scheduler listeners | `docs/index.md`, `docs/modules.md` |
| `wp_agent_workflow_known_step_types` | `src/Workflows/class-wp-agent-workflow-spec-validator.php` | `docs/index.md`, `docs/modules.md` |
| `wp_agent_workflow_known_trigger_types` | `src/Workflows/class-wp-agent-workflow-spec-validator.php` | `docs/index.md`, `docs/modules.md` |
| `wp_agent_workflow_step_handlers` | `src/Workflows/class-wp-agent-workflow-runner.php` | `docs/index.md`, `docs/modules.md` |
| `wp_agent_routine_registered` | `src/Routines/class-wp-agent-routine-registry.php` | `docs/index.md`, `docs/modules.md` |
| `wp_agent_routine_unregistered` | `src/Routines/class-wp-agent-routine-registry.php` | `docs/index.md`, `docs/modules.md` |
| `wp_agent_routine_schedule_requested` | `src/Routines/class-wp-agent-routine-action-scheduler-bridge.php` | `docs/index.md`, `docs/modules.md` |
| `wp_guidelines_substrate_enabled` | `src/Guidelines/class-wp-guidelines-substrate.php` | README, `docs/index.md`, `docs/modules.md` |

## Issue and pull-request evidence

The bootstrap pass reviewed open/closed issues and recent PRs to capture current and pending design context.

### Issues reflected in current docs

| Issue | Status at review | Documentation impact |
| --- | --- | --- |
| #128 Pending-action observer contract | Open | Listed as future approvals docs update; current approvals docs avoid claiming observer support. |
| #110 Effective agent resolution | Closed | `WP_Agent_Effective_Agent_Resolver` is included in public-surface/boundary coverage via runtime/auth docs and tests. |
| #104 Bridge authorization/onboarding | Closed | Existing `bridge-authorization.md` is linked and summarized. |
| #103 Webhook safety | Closed | Webhook signature and idempotency helpers documented. |
| #102 External message/session mapping | Closed | `WP_Agent_External_Message` and session map documented. |
| #101 Remote bridge primitives | Closed | `WP_Agent_Bridge` queue/pending/ack semantics documented. |
| #100 Canonical chat contract | Closed | `agents/chat` ability and channel contract documented. |
| #96 Tool mediation continuation default | Closed | Conversation-loop behavior documented as current behavior. |
| #95 Agent slug transcript contract | Closed | `agent_slug` transcript contract documented, not the older metadata workaround. |
| #94 Abilities API lifecycle filters | Open | Listed as future design thread; current docs do not claim support. |
| #93 Vendor mechanism | Open | Listed as future design thread. |
| #87 Personalos learning issue | Open | Listed as future design thread. |
| #86 Provider response ID | Open in issue list, implemented in source | Current transcript contract documents `provider_response_id` as present at target ref. |
| #78 Default stores companion | Open | Existing companion doc linked and source boundary documented. |
| #77 Convergence audit | Open | Open design thread retained; docs avoid asserting unmerged typed lifecycle event objects. |
| #76 Caller context | Closed | Caller-context headers/principal composition documented. |
| #75 Loop event action | Closed | `agents_api_loop_event` documented. |
| #74 Transcript locks | Closed | Lock contract and loop option documented. |
| #64 Context authority | Closed | Authority tiers and resolver documented. |
| #63 Memory/context registry | Closed | Registries and retrieval policy vocabulary documented. |
| #62 Guideline capabilities | Closed | Guideline capability boundary documented. |
| #61 Memory provenance | Closed | Memory metadata fields/defaults documented. |
| #60 Consent policy | Closed | Consent operations/default behavior documented. |
| #59 Approvals | Closed | Pending-action contracts documented. |
| #58 Auth/access/tokens | Closed | Auth model documented. |
| #57 Tool/action policy | Closed | Tool visibility and action policy documented. |
| #56 Workspace scope | Closed | Workspace scope documented. |
| #55 Parent scoping model | Open | Treated as umbrella context for workspace/memory/auth/approval docs. |
| #47 Iteration budgets | Closed | Budget primitive and loop integration documented. |
| #45 Tool mediation | Closed | Loop/tool execution composition documented. |
| #44 Loop events | Closed | Event sink/action documented. |
| #43 Transcript persister | Closed | Loop persister option documented. |
| #42 Completion policy | Closed | Loop completion policy option documented. |
| #38 Minimum consumer path | Closed | README examples remain canonical; index points developers there. |

### Recent PRs reflected in current docs

| PR | Status at review | Documentation impact |
| --- | --- | --- |
| #130 Docs Agent flow selector | Merged | Explains why this bootstrap workflow is available. |
| #129 Routines/subagents docs | Open | This bootstrap includes routines/subagents in `docs/modules.md`; maintainers should reconcile with #129 to avoid duplicate stale docs. |
| #127 Request metadata preservation | Merged | Loop result/persister docs mention request metadata in result/persistence boundary. |
| #126 Reusable Docs Agent workflow | Merged | Explains docs-agent CI context. |
| #125 Ability registration idempotency | Merged | Ability docs state idempotent registration through `wp_has_ability*` behavior at a high level only. |
| #124 Routines + subagents | Merged | Routines and subagents documented. |
| #123 Bridge queue overwrite safety | Merged | Bridge queue ownership safety covered as part of bridge semantics; no low-level method details duplicated. |
| #122 Caller context self-host validation | Merged | Caller context docs stay fail-closed and trust-boundary oriented. |
| #121 Workflow Action Scheduler listener | Merged | Workflow scheduled listener documented. |
| #120 Agent slug transcript contract | Merged | Transcript `agent_slug` documented. |
| #119 Channel terminology cleanup | Merged | Docs avoid stale runtime-specific channel terms. |
| #118 Chat contract completion | Merged | `reply`/assistant `messages` output contract documented. |
| #117 Bridge authorization doc | Merged | Linked and summarized. |
| #116 Remote bridge primitives | Merged | Bridge protocol documented. |
| #115 Webhook safety primitives | Merged | HMAC/idempotency documented. |
| #114 Workflows substrate | Merged | Workflow module documented. |
| #112 External message/session map | Merged | Channel message/session map documented. |
| #111 Effective agent resolver | Merged | Public surface and tests included in docs coverage. |
| #109 External client architecture | Merged | Linked and summarized. |
| #108 Support WP 6.9 activation | Closed unmerged | Target ref README/plugin header says WordPress 7.0; docs preserve source-of-truth at target ref and do not claim 6.9 support. |
| #107 Channels observability/schema cleanup | Merged | Chat dispatch failure hook and schema shape documented. |
| #106 Ability category registration | Merged | Ability category registration included in ability docs at a high level. |
| #105 Canonical chat contract | Merged | Chat dispatcher documented. |
| #99 Channel base class | Merged | `WP_Agent_Channel` documented. |
| #97 Tool mediation default continuation | Merged | Loop continuation semantics documented. |
| #90 WP Agent naming convention | Merged | Docs use current `WP_Agent_*` names and no `_Interface` suffixes. |

## Known intentional gaps

The bootstrap docs are broad but intentionally avoid documenting behavior not present in source at the target ref.

| Gap | Reason |
| --- | --- |
| Concrete default memory/transcript stores | Source defines contracts only; issue #78 proposes a companion package. `default-stores-companion.md` remains the design reference. |
| Provider/model execution adapters | README boundary delegates this to `wp-ai-client` and consumers. |
| Product admin UI, onboarding UI, REST controllers, dashboards | Explicitly outside substrate ownership. |
| Workflow branching/parallelism/nested workflow built-ins | Runner exposes a step-handler extension point; built-ins are only `ability` and `agent`. |
| Approval observer contract | Proposed in #128, not yet in source. |
| Abilities API lifecycle filter integration | Planned in #94, not yet in source. |
| Typed lifecycle event objects from convergence audit | Mentioned in #77 but not present in source at the target ref. |
| Semver/vendor guidance | Tracked by #93; docs do not prescribe a final mechanism. |

## Maintenance checklist for future docs updates

When source changes, update the docs surface if any of these change:

- A public class, interface, helper function, hook, filter, ability, or value-object field is added/renamed/removed.
- A store contract, transcript shape, memory metadata field, workflow spec field, channel payload, or bridge queue shape changes.
- A module gains concrete default behavior where docs currently describe a consumer-owned boundary.
- New tests encode behavior not covered here.
- Open design issues listed above close or land in PRs.

Suggested update path:

1. Update the focused existing doc if one owns the topic (`external-clients.md`, `remote-bridge-protocol.md`, `bridge-authorization.md`, `default-stores-companion.md`).
2. Update `docs/modules.md` when a module contract or public surface changes.
3. Update this coverage map when the documentation evidence or known gaps change.
4. Keep README as the canonical high-level boundary and examples page rather than duplicating every module detail there.
