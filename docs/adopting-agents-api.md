# Adopting Agents API in an Existing AI Runtime

> **Status: draft.** This guide is the start of a structured path for teams that
> already run a custom WordPress AI agent runtime and want to converge onto the
> shared substrate — retiring duplicated plumbing while keeping their product
> surface. The capability map below is intentionally incomplete in places; the
> "Gaps and open questions" section tracks what still needs a contract. Feedback
> and additions welcome.

## Who this is for

If you maintain a bespoke AI agent runtime on WordPress — your own multi-turn
loop, your own session storage, your own tool dispatch, memory, and identity
handling — you have almost certainly re-implemented the same plumbing every
other agent author has. Agents API exists to be that shared plumbing.

This guide maps the capabilities a typical custom runtime owns onto the
Agents API primitive that can replace each one, and describes an incremental
adoption strategy that does not require a big-bang rewrite.

The goal is not "rip everything out at once." It is "replace one primitive at a
time, behind a safety net, until your runtime is mostly product code sitting on
a shared substrate."

## What stays yours

Agents API is the substrate, not the application. Adopting it does not ask you
to give up:

- Your product UX — chat panels, admin screens, dashboards, onboarding.
- Your prompt assembly and provider/model selection policy.
- Your concrete tools and their business logic.
- Your storage materialization decisions (you can keep your own tables).
- Your product-specific policy — what to auto-approve, what to escalate, how to
  retain data.

Agents API owns the reusable runtime *contracts* underneath those choices.

## Capability map

Each row is a capability a custom runtime commonly owns, the Agents API
primitive that can replace it, and adoption notes. Primitive names are linked to
their contracts in the source tree.

| Capability in a custom runtime | Agents API primitive | Adoption notes |
|---|---|---|
| Multi-turn orchestration loop | `WP_Agent_Conversation_Loop` (`src/Runtime/`) | You supply a turn runner (your provider call) and adapters; the loop owns turn sequencing, stop conditions, mediation, and event emission. Prompt assembly and model choice stay in your turn runner. |
| Tool-call execution / dispatch | `WP_Agent_Tool_Execution_Core` + `WP_Agent_Tool_Executor` (`src/Tools/`) | The loop mediates tool calls; you implement `WP_Agent_Tool_Executor` to map a prepared call to your concrete tool. Provider tool-call IDs round-trip through mediation. |
| Tool visibility / action gating | `WP_Agent_Tool_Policy` + `WP_Agent_Action_Policy_Resolver` (`src/Tools/`) | Replace ad-hoc allow/deny logic with the generic visibility and `direct`/`preview`/`forbidden` action policy resolution. |
| Abilities API integration | Ability lifecycle bridge (`src/Abilities/`) | When running on WordPress with the Abilities API lifecycle filters, the bridge surfaces observability and an approval gate without each ability author opting in. |
| Session / transcript storage | `WP_Agent_Conversation_Store` (+ `WP_Agent_Principal_Conversation_Store`, `WP_Agent_Conversation_Lock`) (`src/Transcripts/`) | Keep your own backend by registering it on the `wp_agent_conversation_store` filter, or enable the built-in WordPress-native default store for a zero-config start (`add_filter( 'agents_api_enable_default_conversation_store', '__return_true' )`). |
| Long-conversation trimming | `WP_Agent_Conversation_Compaction` + markdown-section adapter (`src/Runtime/`) | Boundary-safe compaction with a caller-supplied summarizer; tool-call/result pairs are preserved. |
| Iteration / runaway limits | `WP_Agent_Iteration_Budget` (`src/Runtime/`) | Bound turns, total tool calls, per-tool calls, and other dimensions uniformly. |
| Agent memory | `WP_Agent_Memory_Store` + memory/context registries (`src/Memory/`, `src/Context/`) | Contracts for provenance/authority-aware memory; you supply the concrete backend. |
| Acting-user / agent identity | `WP_Agent_Execution_Principal` (`src/Runtime/`) | One value object carrying actor, agent, auth source, workspace, capability ceiling, and caller context. |
| Bearer-token / capability auth | `WP_Agent_Token_Authenticator`, `WP_Agent_Capability_Ceiling`, `WP_Agent_Authorization_Policy` (`src/Auth/`) | Generic token resolution and capability-ceiling intersection; your host token store plugs in. |
| Cross-agent (A2A) caller context | `WP_Agent_Caller_Context` (`src/Auth/`) | Parses caller/chain headers; you decide trust. |
| Human-in-the-loop approvals | Pending action primitives (`src/Approvals/`) | Propose-then-apply lifecycle with a typed `approval_required` envelope; your handler applies or rejects. |
| Consent decisions | `WP_Agent_Consent_Policy` (`src/Consent/`) | Separate consent vocabulary for memory, transcripts, sharing, and escalation. |
| Scheduled / event-driven runs | Workflows + routines (`src/Workflows/`, `src/Routines/`) | Spec/validator/runner scaffolding and an optional Action Scheduler bridge. |
| Agent registration / lookup | `WP_Agent` + `WP_Agents_Registry` (`src/Registry/`) | Register agents by slug; carry per-agent runtime overrides. |

For method-level detail on each primitive, see the topic pages linked from
[the docs index](README.md) — especially [Runtime and Tools](runtime-and-tools.md),
[Auth, Consent, Context, and Memory](auth-consent-context-memory.md), and
[Channels, Workflows, and Operations](channels-workflows-operations.md).

## Incremental adoption strategy

Convergence works best one primitive at a time, each swap gated so it cannot
regress observable behavior.

1. **Pin current behavior with a contract test.** Before replacing a primitive,
   capture what your runtime currently produces for a representative set of
   inputs — the transcript shape, tool-call sequence, IDs, and final result.
   A record/replay harness around your provider and tool execution lets you
   re-run the same scenario after each swap and diff the output. This is the
   safety net that makes the rest low-risk.

2. **Start with the lowest-coupling primitive.** Good first swaps are the ones
   with the cleanest seam: iteration budgets, compaction, or the message
   envelope/value objects. They change internal mechanics without touching your
   storage or provider layers.

3. **Adopt the loop, keep your adapters.** Move your orchestration onto
   `WP_Agent_Conversation_Loop`, passing your existing provider call as the turn
   runner and your existing tool execution as a `WP_Agent_Tool_Executor`. The
   loop now owns sequencing, budgets, mediation, compaction, and events; your
   code shrinks to the provider/tool adapters.

4. **Move storage behind the contract.** Implement `WP_Agent_Conversation_Store`
   over your existing backend (or adopt the default store) and register it on the
   `wp_agent_conversation_store` filter. The same pattern applies to memory via
   `wp_agent_memory_store`. Your tables can stay exactly as they are — only the
   access path becomes the contract.

5. **Fold in identity, auth, and approvals last.** These touch security
   boundaries, so adopt them once the loop and storage swaps are proven green by
   the contract harness.

At each step the harness from step 1 is the gate: a swap lands only when it
reproduces the pinned behavior.

## Overriding defaults

Every concrete decision the substrate offers a default for is overridable
through a filter, so adopting Agents API never traps you in its defaults:

- `wp_agent_conversation_store` — your transcript/session backend.
- `wp_agent_memory_store` — your memory backend.
- `wp_agent_pending_action_store` — your approval queue backend.
- `wp_agent_conversation_store` registered at the default priority always wins
  over the opt-in built-in default store (which registers as a low-priority
  fallback only).

## Gaps and open questions

This is the part to fill in collaboratively as real runtimes adopt the
substrate. Known areas that need a contract or a documented recipe before a
custom runtime can fully converge:

- **Capability parity audit.** Each adopting runtime should produce its own
  capability-by-capability checklist against the table above and file issues for
  anything that is a behavioral gap rather than a drop-in.
- **Replay-contract stability.** Replay/regrade consumers need the conversation
  result, message, tool-call, and tool-result shapes to be stable across
  versions. Track field-level guarantees as they firm up.
- **Provider-specific message ordering.** Some providers reject consecutive
  same-role messages; `WP_Agent_Message::coalesce_consecutive_same_role()`
  exists for that, but the full set of provider-shape adapters is still growing.
- **Memory store reference implementation.** The memory contract is defined, but
  a turnkey default memory store (parallel to the default conversation store) is
  not yet shipped.

If you are adopting Agents API in an existing runtime and hit a gap not listed
here, please open an issue — those reports are the most useful signal for what
the substrate still needs.
