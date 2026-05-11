# Architecture and bootstrap boundaries

This page describes the source-derived architecture for Agents API. It is part of the [technical documentation index](index.md).

## Package role

Agents API is a WordPress plugin substrate for agent runtime primitives. It is intentionally not a product application, provider client, workflow product, or storage implementation. The package boundary in `README.md`, `agents-api.php`, and `composer.json` is:

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts, tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy, storage/materialization policy
```

Source evidence:

- `agents-api.php` declares the plugin, requires PHP `>=8.1`, requires WordPress `7.0`, defines `AGENTS_API_LOADED`, `AGENTS_API_PATH`, and `AGENTS_API_PLUGIN_FILE`, then loads every substrate class with `require_once`.
- `composer.json` marks the package as a `wordpress-plugin`, suggests Action Scheduler only for scheduled workflow/routine execution, and runs the smoke-test suite through `composer test`.
- `tests/no-product-imports-smoke.php` guards the package boundary by preventing product-specific imports.

## Bootstrap lifecycle

The bootstrap file is the authoritative load order. It loads value objects and interfaces before registries and integration functions, then attaches WordPress lifecycle hooks:

```php
add_action( 'init', array( 'WP_Agents_Registry', 'init' ), 10 );
add_action( 'init', array( 'WP_Guidelines_Substrate', 'register' ), 9 );
```

Implications for consumers:

1. Register agents inside the `wp_agents_api_init` action.
2. Read registry state after WordPress `init` has fired.
3. Treat concrete persistence, UI, prompt assembly, and provider execution as consumer-owned adapters.

`WP_Agents_Registry::get_instance()` intentionally returns `null` and emits `_doing_it_wrong()` before `init`, which keeps registration lifecycle deterministic.

## Module inventory

The current `src/` tree is organized by substrate concern:

| Directory | Responsibility | Primary public surfaces |
| --- | --- | --- |
| `Registry/` | Declarative agent definitions and in-memory registration lifecycle. | `WP_Agent`, `WP_Agents_Registry`, `wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, `wp_unregister_agent()` |
| `Runtime/` | Conversation messages, requests/results, execution principal, compaction, iteration budgets, transcript persistence, and the generic multi-turn loop. | `AgentsAPI\AI\WP_Agent_Message`, `WP_Agent_Conversation_Loop`, `WP_Agent_Conversation_Result`, `WP_Agent_Iteration_Budget`, `WP_Agent_Execution_Principal` |
| `Tools/` | Runtime tool declarations, calls, parameters, execution mediation, visibility policy, and action policy. | `AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration`, `WP_Agent_Tool_Execution_Core`, `WP_Agent_Tool_Result`, `WP_Agent_Tool_Policy`, `WP_Agent_Action_Policy_Resolver` |
| `Auth/` | Access grants, bearer-token metadata/authentication, caller-chain context, capability ceilings, and WordPress authorization. | `WP_Agent_Token`, `WP_Agent_Token_Authenticator`, `WP_Agent_Access_Grant`, `WP_Agent_WordPress_Authorization_Policy`, `WP_Agent_Caller_Context` |
| `Channels/` | External messaging channels, bridge queue/store contracts, webhook signatures, idempotency, session maps, and `agents/chat`. | `AgentsAPI\AI\Channels\WP_Agent_Channel`, `WP_Agent_External_Message`, `AGENTS_CHAT_ABILITY`, `register_chat_handler()` |
| `Workflows/` | Workflow specs, validation, bindings, runner, registry/store/recorder contracts, abilities, and optional Action Scheduler bridge. | `WP_Agent_Workflow_Spec`, `WP_Agent_Workflow_Runner`, `agents/run-workflow`, `agents/validate-workflow`, `agents/describe-workflow` |
| `Routines/` | Scheduled persistent agent invocations that reuse a conversation session. | `WP_Agent_Routine`, `WP_Agent_Routine_Registry`, `WP_Agent_Routine_Action_Scheduler_Bridge` |
| `Memory/` and `Context/` | Store-neutral memory contracts, metadata, query/results, context source registries, composable context, authority/conflict vocabulary. | `WP_Agent_Memory_Store`, `WP_Agent_Memory_Metadata`, `WP_Agent_Memory_Registry`, `WP_Agent_Context_Section_Registry`, context conflict resolver classes |
| `Guidelines/` | Shared `wp_guideline`/`wp_guideline_type` substrate polyfill and capabilities when Core/Gutenberg do not provide it. | `wp_guideline_types()`, `WP_Guidelines_Substrate` |
| `Approvals/` and `Consent/` | Pending action approval contracts and generic consent operation decisions. | `WP_Agent_Pending_Action`, `WP_Agent_Pending_Action_Store`, `WP_Agent_Consent_Policy`, `WP_Agent_Default_Consent_Policy` |
| `Packages/` | Agent package and package artifact value objects and registries. | `WP_Agent_Package`, `WP_Agent_Package_Artifact`, `WP_Agent_Package_Artifacts_Registry` |
| `Identity/`, `Workspace/`, `Transcripts/` | Generic identity, workspace, transcript store and lock contracts. | `WP_Agent_Workspace_Scope`, `WP_Agent_Identity_Store`, `WP_Agent_Conversation_Store`, `WP_Agent_Conversation_Lock` |

## Design principles for contributors

The tests and README enforce several contributor-facing constraints:

- Keep the substrate provider-neutral. Provider/model execution belongs in `wp-ai-client` or a consumer adapter.
- Prefer JSON-friendly value objects and arrays at runtime boundaries so hosts can materialize them in their own stores.
- Define contracts and generic policy vocabulary in Agents API; put UI, durable product tables, workflow editors, concrete tools, and product-specific semantics in consumers.
- Fail closed at request/auth edges and degrade safely at optional integration edges. Examples include malformed caller-context headers returning `null` from `WP_Agent_Token_Authenticator`, missing Action Scheduler no-oping in bridges, and observer/persister failures not changing loop results.
- Keep hooks/filters as extension seams, not hidden product policy.

## Tests that prove the architecture

The `composer test` script runs smoke tests across registry, auth, runtime, tools, channels, workflows, routines, memory/context, and boundary checks. Representative files include:

- `tests/bootstrap-smoke.php` for plugin bootstrap/load behavior.
- `tests/registry-smoke.php` and `tests/subagents-smoke.php` for agent definition registration and coordinator metadata.
- `tests/conversation-loop-*.php` for loop sequencing, tool mediation, completion policy, transcript persistence, events, and budgets.
- `tests/channels-smoke.php`, `tests/agents-chat-ability-smoke.php`, and `tests/remote-bridge-smoke.php` for channel/bridge contracts.
- `tests/workflow-*.php`, `tests/agents-workflow-ability-smoke.php`, and `tests/routine-smoke.php` for workflow and routine contracts.
- `tests/no-product-imports-smoke.php` for package-boundary enforcement.

## Future coverage

The committed bootstrap docs cover the main architecture and public contracts, but these inventory items should receive deeper follow-up pages because they each have enough surface area for focused references:

- Package/artifact adoption flows in `src/Packages/`: documented here only as a module boundary; needs installer/update examples and diff callback details.
- Guidelines substrate in `src/Guidelines/`: summarized here and in existing README; needs a focused capability and metadata page.
- Identity and transcript store contracts in `src/Identity/` and `src/Transcripts/`: covered as boundaries; needs store implementer reference with expected arrays and locking examples.
- Bridge authorization proposal docs already exist in `docs/bridge-authorization.md`; future pass should reconcile them into this technical tree once the protocol stabilizes.
