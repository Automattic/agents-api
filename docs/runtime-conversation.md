# Runtime, Messages, Conversation Loop, and Compaction

The runtime module defines the neutral conversation contracts. Consumers still own prompt assembly, model/provider calls, product policy, and concrete storage.

## Message and request contracts

`WP_Agent_Message` is the canonical message envelope. It normalizes message types such as text, tool call, tool result, input required, approval required, final result, error, delta, and multimodal part.

`WP_Agent_Conversation_Request` carries normalized messages, runtime context, metadata, tool declarations, optional principal, workspace scope, and execution options. It validates tool declarations and JSON-friendly context/metadata.

`WP_Agent_Conversation_Result` normalizes returned messages, tool execution results, events, status, and budget data. Invalid result shapes throw validation exceptions.

## Conversation loop

`WP_Agent_Conversation_Loop::run()` sequences a conversation around a caller-owned turn runner. The loop can:

- normalize input messages.
- acquire and release a transcript lock.
- compact history before a turn.
- call the turn runner.
- mediate tool calls through `WP_Agent_Tool_Execution_Core`.
- enforce turn, total tool-call, and per-tool budgets.
- consult a completion policy.
- persist the transcript.
- emit lifecycle events.

The loop has two execution modes. In caller-managed mode, the turn runner owns model dispatch and any tool execution. In mediated mode, the runner returns tool calls and the loop executes them through the configured tool executor before continuing.

## Stop conditions

A run stops when it reaches natural completion, a completion policy returns complete, a budget is exceeded, `max_turns` is reached, `should_continue` returns false, or transcript lock acquisition fails. Explicit turns-budget exhaustion returns `status: budget_exceeded`; the synthetic `max_turns` limit preserves compatibility and stops silently.

## Compaction

`WP_Agent_Conversation_Compaction` is the compaction contract. It is optional and requires both a compaction policy and callable summarizer. `WP_Agent_Compaction_Item` and `WP_Agent_Compaction_Conservation` provide portable item and conservation metadata. Conservation can fail closed when configured; otherwise compaction failures leave the original transcript intact and emit failure events.

`WP_Agent_Markdown_Section_Compaction_Adapter` supports markdown-section-style compaction for bounded transcript summaries.

## Persistence and events

Transcript persistence is optional through `WP_Agent_Transcript_Persister`; `WP_Agent_Null_Transcript_Persister` is the no-op implementation. Persister and lock-release failures are swallowed where the code treats them as observational cleanup, not runtime-fatal behavior.

Runtime events can be observed with an `on_event` callback and the `agents_api_loop_event` action. Observer failures are swallowed. Common events include `turn_started`, `tool_call`, `tool_result`, `budget_exceeded`, `failed`, `completed`, `transcript_lock_contention`, and compaction lifecycle events.

## Failure behavior

Lock contention returns a normalized `transcript_lock_contention` result. A non-array turn-runner result throws `invalid_agent_conversation_loop`. Turn-runner exceptions emit `failed`, attempt persistence with the current transcript, then rethrow the original exception. Tool validation failures become failed tool results instead of crashing the loop.

## Tests

Runtime behavior is covered by the conversation loop, tool execution, budgets, completion policy, events, transcript persister, runner contracts, transcript lock, compaction, conservation, compaction item, and markdown-section compaction smoke tests.

## Related pages

- [Tools, action policy, approvals, and consent](tools-approvals-consent.md)
- [Identity, transcripts, storage, and persistence boundaries](identity-transcripts-storage.md)
- [Extension points and public surface](extension-points.md)
