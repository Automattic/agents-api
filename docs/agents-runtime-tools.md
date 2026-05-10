# Agents, runtime, and tools

This page documents the core runtime path: registered agent definitions, normalized messages, the multi-turn conversation loop, compaction and budget controls, and generic tool mediation.

## Agent definitions and registry

`WP_Agent` is a declarative value object, not a database row or runtime base class. Constructing or registering one does not create storage, token records, scaffold files, or directories.

Recognized registration fields include:

- `label` and `description` for diagnostics and UI surfaces owned by consumers.
- `memory_seeds`, a map of scaffold filename to source path.
- `owner_resolver`, an optional callable used by owner-based effective-agent resolution.
- `default_config`, caller-owned runtime config such as provider/model/tool policy defaults.
- `supports_conversation_compaction` and `conversation_compaction_policy`.
- `meta`, including reserved provenance keys `source_plugin`, `source_type`, `source_package`, and `source_version`.
- `subagents`, a sanitized list of agent slugs coordinated by this agent.

Register agents inside `wp_agents_api_init`:

```php
add_action(
    'wp_agents_api_init',
    static function () {
        wp_register_agent(
            'example-agent',
            array(
                'label'          => 'Example Agent',
                'default_config' => array(
                    'tool_policy' => array(
                        'mode'       => 'allow',
                        'categories' => array( 'read' ),
                    ),
                ),
                'meta'           => array(
                    'source_plugin' => 'example-plugin/example-plugin.php',
                ),
            )
        );
    }
);
```

`WP_Agents_Registry` stores definitions in memory for the request. Public helpers are `wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, and `wp_unregister_agent()`.

## Message envelopes

`AgentsAPI\AI\WP_Agent_Message` normalizes both plain `role`/`content` messages and typed envelopes into a JSON-friendly shape:

```php
array(
    'schema'   => 'agents-api.message',
    'version'  => 1,
    'type'     => 'text',
    'role'     => 'assistant',
    'content'  => 'Hello',
    'payload'  => array(),
    'metadata' => array(),
)
```

Supported message types include `text`, `tool_call`, `tool_result`, `input_required`, `approval_required`, `final_result`, `error`, `delta`, and `multimodal_part`. Helpers such as `text()`, `toolCall()`, `toolResult()`, and `approvalRequired()` build canonical envelopes. `to_provider_message()` projects an envelope back to a provider-facing `role`/`content` shape while preserving metadata needed by adapters.

## Conversation loop boundary

`AgentsAPI\AI\WP_Agent_Conversation_Loop::run()` sequences a conversation around caller-owned adapters. The loop owns:

- message normalization;
- optional compaction before each turn;
- invoking a caller-supplied turn runner;
- validating/normalizing turn results;
- optional tool-call mediation;
- typed completion policy checks;
- transcript persistence through `WP_Agent_Transcript_Persister`;
- optional transcript locking through `WP_Agent_Conversation_Lock`;
- iteration budget enforcement;
- lifecycle event emission through `on_event` and `agents_api_loop_event`.

The loop does **not** assemble prompts, select providers/models, implement concrete tools, choose storage, expose UI, or define product-specific workflow semantics.

Minimal caller-managed use:

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
    $messages,
    static function ( array $messages, array $context ): array {
        return $provider_adapter->run_turn( $messages, $context );
    },
    array(
        'max_turns' => 4,
        'context'   => array( 'agent_id' => 'example-agent' ),
    )
);
```

When `tool_executor` and `tool_declarations` are provided, the turn runner can return `tool_calls`; the loop validates, executes, and appends tool-call/tool-result messages. In mediation mode, `should_continue` defaults to continue so natural completion, budgets, completion policy, and `max_turns` become the stop signals.

## Loop options and failure modes

Important options:

- `max_turns`: defaults to `1`; synthesized into a `turns` budget unless an explicit budget is provided.
- `budgets`: `WP_Agent_Iteration_Budget` instances keyed by budget name.
- `completion_policy`: `WP_Agent_Conversation_Completion_Policy` implementation.
- `transcript_persister`: called on success and failure; persister exceptions are swallowed so persistence cannot change the runtime result.
- `transcript_lock` / `transcript_lock_store`: advisory session lock; contention returns status `transcript_lock_contention`.
- `on_event`: caller-owned observer. Exceptions are swallowed.

The loop emits `turn_started`, `tool_call`, `tool_result`, `budget_exceeded`, `completed`, `failed`, and lock-contention events. The same event snapshots are also published through `do_action( 'agents_api_loop_event', $event, $payload )`.

## Compaction and conservation

`WP_Agent_Conversation_Compaction` is provider-neutral. Consumers pass a policy and summarizer callable. The policy controls whether compaction is enabled, message thresholds, recent message retention, summary role/prefix/provider/model metadata, tool-boundary preservation, conservation checks, and deterministic overflow archiving.

If summarization fails or conservation fails with fail-closed enabled, the original transcript is returned with a failure event rather than silently dropping history. Overflow archiving can deterministically split old messages into archive items and insert an archive stub without a model call.

Related generic primitives include `WP_Agent_Compaction_Item`, `WP_Agent_Compaction_Conservation`, and `WP_Agent_Markdown_Section_Compaction_Adapter`.

## Iteration budgets

`WP_Agent_Iteration_Budget` is a stateful per-run counter with a name and ceiling. The loop natively understands:

- `turns`;
- `tool_calls`;
- `tool_calls_<tool_name>`.

When an explicit budget is exceeded, the loop returns `status: budget_exceeded` and `budget: <name>` and emits a `budget_exceeded` event.

## Tool declarations and execution

`AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration` describes a tool in a normalized runtime shape. `WP_Agent_Tool_Call`, `WP_Agent_Tool_Parameters`, and `WP_Agent_Tool_Result` normalize invocation and result data.

`WP_Agent_Tool_Execution_Core` performs product-neutral mediation:

1. Find the requested tool declaration.
2. Validate required parameters.
3. Build normalized parameters with runtime context.
4. Call a consumer-supplied `WP_Agent_Tool_Executor`.
5. Catch executor exceptions and normalize success/error results.

Concrete execution remains a consumer adapter.

## Tool visibility policy

`WP_Agent_Tool_Policy` resolves the visible tool map. Inputs include:

- runtime mode (`chat`, `pipeline`, or `system`);
- tool-declared `mode` / `modes`;
- `tool_access_checker` callable;
- registered agent or runtime `tool_policy`;
- `WP_Agent_Tool_Access_Policy` providers from constructor, context, or `agents_api_tool_policy_providers`;
- runtime `categories`, `allow_only`, and `deny` lists.

The final map can be filtered with `agents_api_resolved_tools`.

## Action policy

`WP_Agent_Action_Policy_Resolver` resolves whether a visible tool executes as `direct`, `preview`, or `forbidden`.

Resolution order:

1. Explicit runtime `deny` list.
2. Agent/runtime per-tool action policy.
3. Agent/runtime per-category action policy.
4. Host `WP_Agent_Action_Policy_Provider` providers from constructor, context, or `agents_api_action_policy_providers`.
5. Tool-declared `action_policy`.
6. Tool-declared mode-specific `action_policy_<mode>`.
7. Default `direct`.
8. Final `agents_api_tool_action_policy` filter.

The resolver returns only canonical values from `AgentsAPI\AI\Tools\WP_Agent_Action_Policy`.
