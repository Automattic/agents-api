# Capability Map

A reference mapping the capabilities an AI agent runtime needs to the Agents API
primitive that provides each one. Use it to see at a glance what the substrate
already provides and where each contract lives.

Each row names a capability, the primitive(s) that provide it, the contract in
the source tree, and the topic page with method-level detail. "Capability" is
phrased the way a custom runtime usually thinks about it, so a team evaluating
the substrate can match their own components against it.

## Runtime and loop

| Capability | Agents API primitive | Contract | Detail |
|---|---|---|---|
| Multi-turn orchestration loop | `WP_Agent_Conversation_Loop` | [`src/Runtime/class-wp-agent-conversation-loop.php`](../src/Runtime/class-wp-agent-conversation-loop.php) | [Runtime and Tools](runtime-and-tools.md) |
| Normalized message envelope | `WP_Agent_Message` | [`src/Runtime/class-wp-agent-message.php`](../src/Runtime/class-wp-agent-message.php) | [Runtime and Tools](runtime-and-tools.md) |
| Request / result value objects | `WP_Agent_Conversation_Request`, `WP_Agent_Conversation_Result` | [`src/Runtime/`](../src/Runtime/) | [Runtime and Tools](runtime-and-tools.md) |
| Completion / continuation policy | `WP_Agent_Conversation_Completion_Policy` | [`src/Runtime/class-wp-agent-conversation-completion-policy.php`](../src/Runtime/class-wp-agent-conversation-completion-policy.php) | [Runtime and Tools](runtime-and-tools.md) |
| Long-conversation trimming | `WP_Agent_Conversation_Compaction`, markdown-section adapter | [`src/Runtime/class-wp-agent-conversation-compaction.php`](../src/Runtime/class-wp-agent-conversation-compaction.php) | [Runtime and Tools](runtime-and-tools.md) |
| Iteration / runaway limits | `WP_Agent_Iteration_Budget` | [`src/Runtime/class-wp-agent-iteration-budget.php`](../src/Runtime/class-wp-agent-iteration-budget.php) | [Runtime and Tools](runtime-and-tools.md) |
| Transcript persistence hook | `WP_Agent_Transcript_Persister` | [`src/Runtime/class-wp-agent-transcript-persister.php`](../src/Runtime/class-wp-agent-transcript-persister.php) | [Runtime and Tools](runtime-and-tools.md) |
| Lifecycle events / observability | `agents_api_loop_event` action + `on_event` sink | [`src/Runtime/class-wp-agent-conversation-loop.php`](../src/Runtime/class-wp-agent-conversation-loop.php) | [Runtime and Tools](runtime-and-tools.md) |
| Task execution target plumbing | `agents/run-task`, task run-control helpers, executor target filters | [`src/Tasks/`](../src/Tasks/) | [Runtime and Tools](runtime-and-tools.md#task-execution-targets) |

## Tools

| Capability | Agents API primitive | Contract | Detail |
|---|---|---|---|
| Tool-call execution / dispatch | `WP_Agent_Tool_Execution_Core` + `WP_Agent_Tool_Executor` | [`src/Tools/class-wp-agent-tool-execution-core.php`](../src/Tools/class-wp-agent-tool-execution-core.php), [`class-wp-agent-tool-executor.php`](../src/Tools/class-wp-agent-tool-executor.php) | [Runtime and Tools](runtime-and-tools.md) |
| Tool declarations / parameters | `WP_Agent_Tool_Declaration`, `WP_Agent_Tool_Parameters` | [`src/Tools/`](../src/Tools/) | [Runtime and Tools](runtime-and-tools.md) |
| Tool visibility policy | `WP_Agent_Tool_Policy` | [`src/Tools/class-wp-agent-tool-policy.php`](../src/Tools/class-wp-agent-tool-policy.php) | [Runtime and Tools](runtime-and-tools.md) |
| Action gating (`direct`/`preview`/`forbidden`) | `WP_Agent_Action_Policy_Resolver` | [`src/Tools/class-wp-agent-action-policy-resolver.php`](../src/Tools/class-wp-agent-action-policy-resolver.php) | [Runtime and Tools](runtime-and-tools.md) |
| Tool-call/result pairing repair | `WP_Agent_Tool_Pair_Validator` | [`src/Runtime/class-wp-agent-tool-pair-validator.php`](../src/Runtime/class-wp-agent-tool-pair-validator.php) | [Runtime and Tools](runtime-and-tools.md) |
| Abilities API lifecycle integration | Ability lifecycle bridge | [`src/Abilities/class-wp-agent-ability-lifecycle-bridge.php`](../src/Abilities/class-wp-agent-ability-lifecycle-bridge.php) | [Runtime and Tools](runtime-and-tools.md) |

## Transcripts and sessions

| Capability | Agents API primitive | Contract | Detail |
|---|---|---|---|
| Session / transcript storage | `WP_Agent_Conversation_Store` | [`src/Transcripts/class-wp-agent-conversation-store.php`](../src/Transcripts/class-wp-agent-conversation-store.php) | [Channels, Workflows, and Operations](channels-workflows-operations.md) |
| Non-user (principal) owned sessions | `WP_Agent_Principal_Conversation_Store` | [`src/Transcripts/class-wp-agent-principal-conversation-store.php`](../src/Transcripts/class-wp-agent-principal-conversation-store.php) | [Channels, Workflows, and Operations](channels-workflows-operations.md) |
| Single-writer transcript lock | `WP_Agent_Conversation_Lock` | [`src/Transcripts/class-wp-agent-conversation-lock.php`](../src/Transcripts/class-wp-agent-conversation-lock.php) | [Channels, Workflows, and Operations](channels-workflows-operations.md) |
| Built-in WordPress-native store | `WP_Agent_Cpt_Conversation_Store` (opt-in) | [`src/Transcripts/class-wp-agent-cpt-conversation-store.php`](../src/Transcripts/class-wp-agent-cpt-conversation-store.php) | [Default Stores Companion Proposal](default-stores-companion.md) |

## Memory and context

| Capability | Agents API primitive | Contract | Detail |
|---|---|---|---|
| Agent memory store | `WP_Agent_Memory_Store` + value objects | [`src/Memory/`](../src/Memory/) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |
| Memory / context source registry | `WP_Agent_Memory_Registry` | [`src/Context/class-wp-agent-memory-registry.php`](../src/Context/class-wp-agent-memory-registry.php) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |
| Composable context assembly | `WP_Agent_Context_Section_Registry`, `WP_Agent_Composable_Context` | [`src/Context/`](../src/Context/) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |
| Retrieved-context authority / conflict | `WP_Agent_Context_Item`, conflict resolver | [`src/Context/`](../src/Context/) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |

## Identity and auth

| Capability | Agents API primitive | Contract | Detail |
|---|---|---|---|
| Acting-user / agent identity | `WP_Agent_Execution_Principal` | [`src/Runtime/class-wp-agent-execution-principal.php`](../src/Runtime/class-wp-agent-execution-principal.php) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |
| Bearer-token authentication | `WP_Agent_Token_Authenticator`, `WP_Agent_Token_Store` | [`src/Auth/class-wp-agent-token-authenticator.php`](../src/Auth/class-wp-agent-token-authenticator.php) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |
| Capability ceiling / authorization | `WP_Agent_Capability_Ceiling`, `WP_Agent_Authorization_Policy` | [`src/Auth/class-wp-agent-capability-ceiling.php`](../src/Auth/class-wp-agent-capability-ceiling.php), [`class-wp-agent-authorization-policy.php`](../src/Auth/class-wp-agent-authorization-policy.php) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |
| Access grants | `WP_Agent_Access_Grant`, `WP_Agent_Access_Store` | [`src/Auth/`](../src/Auth/) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |
| Cross-agent (A2A) caller context | `WP_Agent_Caller_Context` | [`src/Auth/class-wp-agent-caller-context.php`](../src/Auth/class-wp-agent-caller-context.php) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |

## Approvals and consent

| Capability | Agents API primitive | Contract | Detail |
|---|---|---|---|
| Human-in-the-loop approvals | Pending action primitives (`WP_Agent_Pending_Action`, store, resolver, handler) | [`src/Approvals/`](../src/Approvals/) | [Channels, Workflows, and Operations](channels-workflows-operations.md) |
| Consent decisions | `WP_Agent_Consent_Policy` | [`src/Consent/class-wp-agent-consent-policy.php`](../src/Consent/class-wp-agent-consent-policy.php) | [Auth, Consent, Context, and Memory](auth-consent-context-memory.md) |

## Orchestration and registration

| Capability | Agents API primitive | Contract | Detail |
|---|---|---|---|
| Workflows (multi-step) | Workflow spec / validator / runner | [`src/Workflows/`](../src/Workflows/) | [Channels, Workflows, and Operations](channels-workflows-operations.md) |
| Scheduled / recurring runs | `WP_Agent_Routine` + Action Scheduler bridge | [`src/Routines/class-wp-agent-routine.php`](../src/Routines/class-wp-agent-routine.php) | [Channels, Workflows, and Operations](channels-workflows-operations.md) |
| External channels / bridges | `WP_Agent_Channel`, bridge primitives | [`src/Channels/`](../src/Channels/) | [Channels, Workflows, and Operations](channels-workflows-operations.md) |
| Agent registration / lookup | `WP_Agent`, `WP_Agents_Registry` | [`src/Registry/class-wp-agent.php`](../src/Registry/class-wp-agent.php), [`class-wp-agents-registry.php`](../src/Registry/class-wp-agents-registry.php) | [Registry and Packages](registry-and-packages.md) |
| Packaging / artifacts | `WP_Agent_Package`, artifact registry | [`src/Packages/`](../src/Packages/) | [Registry and Packages](registry-and-packages.md) |

## Extension points

Each store/policy the substrate offers a default for is reachable through a
filter, so a consumer plugs in its own backend without forking:

| Filter | Replaces |
|---|---|
| `wp_agent_conversation_store` | Transcript / session backend |
| `wp_agent_memory_store` | Memory backend |
| `wp_agent_pending_action_store` | Approval queue backend |
| `wp_agent_execution_targets` | Task executor target declarations |
| `wp_agent_task_handler` | Task executor dispatch handler |
| `wp_agent_task_run_status_handler` | Task run status backend |
| `wp_agent_task_run_cancel_handler` | Task cancellation backend |
| `agents_api_execution_principal` | How the current principal is resolved |
| `agents_api_enable_default_conversation_store` | Opt in to the built-in WordPress-native conversation store |
