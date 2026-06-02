# Introduction to Agents API

New to Agents API? Start here. This page explains the core idea, the vocabulary
the rest of the docs assume, and how a single request flows through the runtime.
It assumes you know WordPress and the general shape of LLM apps, but not any of
this project's terms.

## The one-sentence version

Agents API is the **runtime layer that turns a single model call into a
multi-turn, tool-using agent** — it owns the loop, the transcript, tool
mediation, memory, and identity, so your plugin only has to supply the parts
that are actually yours.

## Where it sits

Three layers cooperate to build an agent on WordPress:

| Layer | Answers | Provided by |
|---|---|---|
| **AI Client** | "How do I call a model?" | `wp-ai-client` (talk to OpenAI / Anthropic / etc.) |
| **Abilities API** | "What is the AI allowed to do?" | WordPress core — a registry of callable abilities |
| **Agents API** | "How do I run an agent that uses those?" | This project — the runtime that orchestrates turns, tools, memory, and identity |

A model call on its own is one question and one answer. An *agent* keeps going:
it reads the question, decides to call a tool, reads the tool's result, maybe
calls another, and stops when it's done — remembering the conversation and
respecting who's asking. Agents API is the machinery that runs that cycle.

## The vocabulary

These are the terms the rest of the documentation uses. Each is one idea.

**Agent** — a registered identity with a slug, configuration, and capabilities.
You register one with `wp_register_agent()` and look it up by slug.

**Conversation loop** — the engine that runs an agent turn by turn until it's
done. It owns sequencing, stop conditions, tool mediation, compaction, and
event emission. The central primitive: `WP_Agent_Conversation_Loop`.

**Turn** — one pass through the loop: send the current messages to the model,
get back text and/or tool calls, act on them.

**Turn runner** — *your* adapter that actually calls the model. The loop hands
it the messages; it returns the model's response. Prompt assembly and model
choice live here, so the substrate stays provider-neutral.

**Tool** — an action the agent can take, declared through the Abilities API.

**Tool executor** — *your* adapter that runs a concrete tool when the loop asks.
You implement `WP_Agent_Tool_Executor`; the substrate prepares and validates the
call first.

**Mediation** — the loop's tool cycle: take a tool call from the model,
validate it, run it through your executor, append the result to the transcript,
and decide whether to continue. You don't write this; the loop does.

**Transcript / Session** — the stored record of a conversation. A *session* is
the row; the *transcript* is its messages. Stored through the
`WP_Agent_Conversation_Store` contract — bring your own backend, or enable the
built-in WordPress-native one.

**Message envelope** — the normalized shape every message takes inside the
runtime (`WP_Agent_Message`), so text, tool calls, tool results, and approvals
all travel through the same structure.

**Principal** — *who* a request is acting as: the user, the agent, the auth
source, the workspace, and the capability ceiling. One value object,
`WP_Agent_Execution_Principal`, carries it through the run.

**Workspace** — the generic "where does this belong" scope (a site, a network,
a code workspace, an ephemeral environment). Memory, sessions, and audit all
hang off a workspace rather than assuming a single site ID.

**Memory** — facts an agent keeps across sessions, with provenance and an
authority tier, behind the `WP_Agent_Memory_Store` contract.

**Compaction** — trimming a long transcript before it overflows the context
window, preserving tool-call/result pairs, via a summarizer you supply.

**Iteration budget** — a bound on a run (max turns, max tool calls, per-tool
calls) so an agent can't loop forever.

**Approval / pending action** — when an action needs a human yes/no, the agent
proposes it instead of running it; the proposal waits as a *pending action*
until accepted or rejected.

**Channel** — an adapter that maps an external transport (Slack, Telegram,
email, …) onto the agent chat surface.

**Workflow / Routine** — multi-step orchestration (*workflow*) and scheduled or
recurring runs (*routine*), with an optional Action Scheduler bridge.

## How a turn flows

Putting the vocabulary together, one round looks like this:

1. A message arrives (from a REST call, a channel, a scheduled routine, …).
2. The **loop** normalizes it into the **transcript** and starts a **turn**.
3. The loop calls your **turn runner**, which calls the model and returns its
   response — possibly with **tool calls**.
4. For each tool call, the loop **mediates**: validates it, runs your **tool
   executor**, and appends the result to the transcript.
5. The loop checks its stop conditions — natural completion, **iteration
   budget**, completion policy — and either runs another turn or finishes.
6. Along the way it can **compact** a long transcript, emit lifecycle **events**
   for observability, and **persist** the transcript through your store.
7. The whole run acts as a **principal** within a **workspace**, so identity and
   scope are consistent end to end.

You supply steps 3 and 4's concrete logic (model call, tool execution). The loop
owns everything else.

## What's yours vs. what the substrate owns

Agents API is the substrate, not the application. It owns the reusable
contracts; you keep your product. You bring: the model call (turn runner),
concrete tools (executor), prompt and model policy, storage materialization, and
product UX. The substrate brings: the loop, mediation, transcript/session and
memory contracts, identity and auth, compaction, budgets, approvals, channels,
and workflow scaffolding.

## Where to go next

- **[Capability Map](capability-map.md)** — now that you know the terms, the
  full table of each capability and the primitive that provides it.
- **[Runtime and Tools](runtime-and-tools.md)** — the loop, tool mediation,
  policies, budgets, and events in depth.
- **[Auth, Consent, Context, and Memory](auth-consent-context-memory.md)** —
  principals, tokens, capability ceilings, memory, and context.
- **[Channels, Workflows, and Operations](channels-workflows-operations.md)** —
  channels, workflows, routines, transcripts, and approvals.
- **[Architecture](architecture.md)** — module inventory and the substrate
  boundary.
