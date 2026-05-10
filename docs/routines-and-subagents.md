# Routines And Subagents

Agents API includes two small orchestration primitives for long-running and delegated agent behavior:

- **Routines**: code-defined, scheduled invocations of an agent that reuse a persistent conversation session across wakes.
- **Subagents**: declarative relationships where one registered agent identifies other agents it may coordinate or delegate to.

Both are substrate-level contracts. Agents API defines value objects, registries, scheduler wiring, hooks, and dispatch shape; consumers still own durable storage, concrete runtimes, product UI, prompt policy, tool exposure, and audit/reporting.

## Boundary

| Agents API owns | Consumers own |
| --- | --- |
| `WP_Agent_Routine` value object | Routine creation UI, durable routine records, enable/disable state, ownership, and retention |
| In-memory routine registry and global helper functions | Mapping stored routines into code registrations on boot |
| Optional Action Scheduler bridge | Installing/loading Action Scheduler and choosing operational retry policy |
| Scheduled wake listener that dispatches the canonical `agents/chat` ability | The actual `agents/chat` handler, provider/model execution, transcript persistence, and run metrics |
| Routine and scheduler hooks for observability | Product-specific dashboards, logs, alerts, and run history |
| `WP_Agent::subagents` declaration and getters | Turning subagent declarations into tools, permissions, prompts, and runtime delegation behavior |

Agents API does not ship a concrete routine runtime, workflow engine, durable routine table, routine editor, or subagent delegation tool executor.

## Routines

A routine is a persistent, scheduled invocation of an agent. Unlike a workflow, which is a deterministic recipe with fresh inputs per run, a routine wakes the same agent against the same session ID repeatedly so context can accumulate over time.

Use routines for substrate-level patterns such as:

- periodic status checks;
- recurring digests;
- long-running monitor loops;
- agent "main loop" behavior where active time and wall-clock time differ.

### Public API

Routine classes and helpers live under `src/Routines/`:

- `AgentsAPI\AI\Routines\WP_Agent_Routine`
- `AgentsAPI\AI\Routines\WP_Agent_Routine_Registry`
- `AgentsAPI\AI\Routines\WP_Agent_Routine_Action_Scheduler_Bridge`
- `wp_register_routine()`
- `wp_get_routine()`
- `wp_get_routines()`
- `wp_unregister_routine()`

A routine definition accepts:

- `label` — human-readable label; defaults to the routine ID.
- `agent` — required registered agent slug.
- `interval` — interval trigger in seconds.
- `expression` — cron expression trigger.
- `prompt` — message sent when the routine wakes.
- `session_id` — persistent conversation session; defaults to `routine:<routine-id>`.
- `meta` — caller-owned JSON-friendly metadata.

Exactly one of `interval` or `expression` is required.

```php
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_routine(
			'lunar-monitor',
			array(
				'label'    => 'Lunar Monitor',
				'agent'    => 'commander',
				'interval' => 3600,
				'prompt'   => 'Status check. Anything new?',
				'meta'     => array(
					'source_plugin' => 'example/example.php',
				),
			)
		);
	}
);
```

Cron-expression routines use `expression` instead of `interval`:

```php
wp_register_routine(
	'nightly-digest',
	array(
		'agent'      => 'commander',
		'expression' => '0 9 * * *',
		'prompt'     => 'Prepare the daily digest.',
		'session_id' => 'routine:nightly-digest:v1',
	)
);
```

The registry is in-memory for the current request, mirroring the workflow registry. It is not a persistence layer or cache. If a product stores routines in a database, it should load and register the active definitions during boot.

### Validation

`WP_Agent_Routine` validates the structural shape when constructed:

- the sanitized routine ID must not be empty;
- `agent` must be present;
- one trigger must be present;
- `interval` and `expression` are mutually exclusive;
- `interval` must be a positive integer when used;
- `expression` must be a non-empty string when used.

`WP_Agent_Routine_Registry::register()` returns a `WP_Error` with code `invalid_routine` when construction fails. `wp_unregister_routine()` returns `true` for a registered ID or a `WP_Error` with code `not_registered` for an unknown ID.

### Scheduling Lifecycle

When `wp_register_routine()` succeeds, the registry fires:

```text
wp_agent_routine_registered
```

Agents API listens to that hook and asks `WP_Agent_Routine_Action_Scheduler_Bridge` to register a schedule. The bridge:

1. fires `wp_agent_routine_schedule_requested` for custom schedulers and observability;
2. no-ops cleanly when Action Scheduler functions are unavailable;
3. unschedules existing actions for the routine ID to keep registration idempotent;
4. schedules either a recurring action for `interval` triggers or a cron action for `expression` triggers.

The Action Scheduler hook is:

```text
wp_agent_routine_run_scheduled
```

The Action Scheduler group is:

```text
agents-api
```

When a routine is unregistered, the registry fires:

```text
wp_agent_routine_unregistered
```

Agents API listens to that hook and unschedules the matching Action Scheduler actions when Action Scheduler is available.

### Dispatch Lifecycle

The scheduled listener resolves the routine and dispatches the canonical `agents/chat` ability with this input shape:

```php
array(
	'agent'      => $routine->get_agent_slug(),
	'message'    => $routine->get_prompt(),
	'session_id' => $routine->get_session_id(),
)
```

The persistent `session_id` is the continuity boundary. Agents API does not decide how transcript state is stored; the consumer's chat runtime and transcript adapters own that behavior.

Scheduled dispatch handles failures through hooks instead of throwing from the Action Scheduler callback. This avoids marking scheduled actions as failed for conditions such as a temporarily missing consumer runtime.

Failure hook:

```text
agents_run_routine_dispatch_failed
```

Known failure reasons include:

- `no_routine_id`
- `routine_not_registered`
- `abilities_api_missing`
- `agents_chat_missing`
- a `WP_Error` code returned by the chat ability

Successful dispatch fires:

```text
wp_agent_routine_run_completed
```

The completion hook receives the `WP_Agent_Routine` instance and the canonical chat output array. Consumers can attach run recording, timing, assistant reply capture, token usage, or dashboard updates there.

```php
add_action(
	'wp_agent_routine_run_completed',
	static function ( AgentsAPI\AI\Routines\WP_Agent_Routine $routine, array $result ): void {
		my_routine_runs()->record(
			$routine->get_id(),
			array(
				'session_id' => $routine->get_session_id(),
				'result'     => $result,
				'ended_at'   => time(),
			)
		);
	},
	10,
	2
);
```

### Permissions

Scheduled wakes run in a cron/loopback context, not an interactive user session. The routine listener temporarily grants the canonical chat permission filters for that invocation only:

- `agents_chat_permission`
- `openclawp_chat_ability_permission`

This is intentionally narrow. Products should still enforce their own routine ownership, capability ceilings, and agent access policy when they create, store, enable, or materialize routine definitions.

### Custom Schedulers

Hosts that do not use Action Scheduler can listen to `wp_agent_routine_schedule_requested` and schedule routine wakes in their own system. They should invoke the same routine dispatch boundary or call `agents/chat` with the same shape so routine behavior remains portable.

```php
add_action(
	'wp_agent_routine_schedule_requested',
	static function ( AgentsAPI\AI\Routines\WP_Agent_Routine $routine ): void {
		my_scheduler()->upsert_recurring_agent_job(
			$routine->get_id(),
			$routine->to_array()
		);
	}
);
```

## Subagents

Subagents are a declaration on `WP_Agent` that identifies other agent slugs a parent agent may coordinate.

```php
wp_register_agent(
	'commander',
	array(
		'label'     => 'Commander',
		'subagents' => array( 'detector', 'navigator' ),
	)
);
```

The `subagents` array is sanitized as agent slugs and empty values are removed. A non-array `subagents` value is rejected during `WP_Agent` construction.

Read the declaration through:

```php
$agent = wp_get_agent( 'commander' );

$agent->get_subagents();   // array( 'detector', 'navigator' )
$agent->is_coordinator();  // true when at least one subagent is declared
$agent->to_array();        // includes the `subagents` key
```

Subagents are declarative only. Agents API does not automatically register delegate tools, call child agents, route permissions, merge transcripts, or define prompt semantics. Consumers that want delegation can map the declaration into their runtime in a product-specific way, for example:

```text
WP_Agent::get_subagents()
-> verify each child agent exists and is allowed for this principal/workspace
-> expose delegate-to-<slug> tools to the parent agent
-> route each delegated call through the consumer's chat or workflow runtime
-> persist child run transcripts/audit records according to product policy
```

This keeps the generic substrate small while giving products a common registration field for coordinator agents.

## Relationship To Workflows

Routines and workflows solve different orchestration problems:

| Primitive | Shape | State model | Runtime ownership |
| --- | --- | --- | --- |
| Routine | Scheduled wake of one agent | Reuses a persistent conversation session across wakes | Dispatches `agents/chat`; consumer owns chat runtime and storage |
| Workflow | Deterministic multi-step recipe | Fresh run inputs and explicit run result | Dispatches `agents/run-workflow`; consumer owns concrete workflow runtime |
| Subagents | Agent-to-agent coordination declaration | No runtime state by itself | Consumer turns declarations into delegation tools/policy |

Use a routine when the key requirement is a recurring agent session. Use a workflow when the key requirement is an explicit, structured recipe with step-level results. Use subagents when a registered agent needs to advertise which other agents it can coordinate.

## Tests

The source includes pure-PHP smoke coverage for these contracts:

```bash
php tests/routine-smoke.php
php tests/subagents-smoke.php
```

The full repository test command also runs them:

```bash
composer test
```
