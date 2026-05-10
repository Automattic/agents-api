# Routines And Subagent Coordination

Agents API includes two related substrate primitives for long-running and coordinated agent behavior:

- **Routines** are code-defined scheduled invocations of an agent that reuse a stable conversation session across every wake.
- **Subagent declarations** let a registered agent state which other agent slugs it coordinates, without Agents API owning the delegation runtime.

Both features are contracts and value objects only. Consumers still own concrete chat runtimes, durable storage, UI, authorization policy, and product-specific scheduling or delegation behavior.

## Ownership Boundary

Agents API owns:

- The `WP_Agent_Routine` value object and validation rules.
- The in-memory `WP_Agent_Routine_Registry`.
- Global helper functions for registering, reading, listing, and unregistering routines.
- The optional Action Scheduler bridge that mirrors routine registration into scheduled actions when Action Scheduler is loaded.
- The scheduled wake listener that dispatches a routine through the canonical `agents/chat` ability.
- The `WP_Agent::subagents` declaration field, plus `get_subagents()` and `is_coordinator()` accessors.

Consumers own:

- The chat runtime registered through the `wp_agent_chat_handler` filter.
- Persistence of routine sessions, transcripts, run history, logs, and analytics.
- Any routine editor UI, database-backed routine store, enable/disable state, or per-user scheduling policy.
- Authorization for who may create, expose, or run a routine.
- Concrete delegation tools such as `delegate-to-<slug>` and any orchestration policy for coordinator agents.
- Product-specific prompts, escalation behavior, storage retention, and observability sinks.

## Routine Model

A routine is a persistent, scheduled wake-up for one registered agent. Unlike a workflow, which is a deterministic recipe run with fresh inputs, a routine reuses the same `session_id` for every wake so conversation context can accumulate over time.

`AgentsAPI\AI\Routines\WP_Agent_Routine` accepts these arguments:

| Argument | Type | Required | Description |
| --- | --- | --- | --- |
| `label` | string | No | Human-readable label. Defaults to the routine id. |
| `agent` | string | Yes | Registered agent slug to invoke. |
| `interval` | int | One of `interval` or `expression` | Recurring interval in seconds. Must be greater than zero. |
| `expression` | string | One of `interval` or `expression` | Cron expression for Action Scheduler cron-style schedules. |
| `prompt` | string | No | User-side message sent on each wake. Defaults to an empty string. |
| `session_id` | string | No | Persistent session id. Defaults to `routine:<routine-id>`. |
| `meta` | array | No | JSON-friendly caller-owned metadata. |

Validation is intentionally strict:

- Routine ids are sanitized with `sanitize_title()` and cannot be empty.
- `agent` is required.
- Exactly one trigger is required: either `interval` or `expression`, not both.
- `interval` must be a positive integer when present.
- `expression` must be a non-empty string when present.

```php
use AgentsAPI\AI\Routines\WP_Agent_Routine;

$routine = new WP_Agent_Routine(
	'lunar-monitor',
	array(
		'label'    => 'Lunar Monitor',
		'agent'    => 'commander',
		'interval' => HOUR_IN_SECONDS,
		'prompt'   => 'Status check. Anything new?',
	)
);

$routine->get_id();               // 'lunar-monitor'
$routine->get_agent_slug();       // 'commander'
$routine->get_trigger_type();     // WP_Agent_Routine::TRIGGER_INTERVAL
$routine->get_interval_seconds(); // 3600
$routine->get_session_id();       // 'routine:lunar-monitor'
```

Cron-expression routines use `expression` instead of `interval`:

```php
$routine = new WP_Agent_Routine(
	'nightly-digest',
	array(
		'agent'      => 'commander',
		'expression' => '0 9 * * *',
		'prompt'     => 'Prepare the daily digest.',
		'session_id' => 'daily-digest-session',
	)
);
```

## Registering Routines

Code-defined routines are stored in an in-memory registry for the current request. This mirrors the workflow registry: registration is declarative substrate state, not durable product storage.

```php
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_routine(
			'lunar-monitor',
			array(
				'label'    => 'Lunar Monitor',
				'agent'    => 'commander',
				'interval' => HOUR_IN_SECONDS,
				'prompt'   => 'Status check. Anything new?',
				'meta'     => array(
					'source_plugin' => 'example-plugin/example-plugin.php',
				),
			)
		);
	}
);
```

Public helpers:

- `wp_register_routine( string $id, array $args ): WP_Agent_Routine|WP_Error`
- `wp_get_routine( string $routine_id ): ?WP_Agent_Routine`
- `wp_get_routines(): WP_Agent_Routine[]`
- `wp_unregister_routine( string $routine_id ): true|WP_Error`

The registry class also exposes `WP_Agent_Routine_Registry::register()`, `::find()`, `::all()`, and `::unregister()` for callers that need the class-level API.

## Scheduling Lifecycle

Routine scheduling is optional. Agents API does not require Action Scheduler; it only integrates when Action Scheduler functions are available.

When a routine is registered:

1. `WP_Agent_Routine_Registry::register()` stores the routine in memory.
2. The registry fires `wp_agent_routine_registered` with the routine object.
3. `register-routine-bridge-sync.php` asks `WP_Agent_Routine_Action_Scheduler_Bridge::register()` to sync the schedule.
4. The bridge fires `wp_agent_routine_schedule_requested` for custom schedulers, even when Action Scheduler is not loaded.
5. If Action Scheduler is available, the bridge unschedules prior actions for the same routine id, then schedules a recurring or cron action under hook `wp_agent_routine_run_scheduled` and group `agents-api`.

When a routine is unregistered:

1. The registry removes the routine from memory.
2. It fires `wp_agent_routine_unregistered`.
3. The bridge cancels matching scheduled actions when Action Scheduler is available.

Bridge constants:

```php
AgentsAPI\AI\Routines\WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK;
// 'wp_agent_routine_run_scheduled'

AgentsAPI\AI\Routines\WP_Agent_Routine_Action_Scheduler_Bridge::GROUP;
// 'agents-api'
```

Hosts that do not use Action Scheduler can listen to `wp_agent_routine_schedule_requested` and schedule the routine through their own scheduler.

## Scheduled Wake Dispatch

The scheduled listener is registered on `wp_agent_routine_run_scheduled`. It accepts either a bare routine id string or an args array containing `routine_id`.

On wake, the listener:

1. Extracts the routine id.
2. Looks up the routine in `WP_Agent_Routine_Registry`.
3. Requires the Abilities API helper `wp_get_ability()`.
4. Loads the canonical `agents/chat` ability.
5. Temporarily grants chat permission for the scheduled invocation through the `agents_chat_permission` and `openclawp_chat_ability_permission` filters.
6. Executes `agents/chat` with the routine's agent slug, prompt, and persistent session id.
7. Emits success or failure hooks for consumers to observe.

The dispatched chat input is:

```php
array(
	'agent'      => $routine->get_agent_slug(),
	'message'    => $routine->get_prompt(),
	'session_id' => $routine->get_session_id(),
)
```

Dispatch failures do not throw from the Action Scheduler callback. Instead, the listener fires:

```php
do_action( 'agents_run_routine_dispatch_failed', $reason, $context );
```

Known failure reasons include:

- `no_routine_id`
- `routine_not_registered`
- `abilities_api_missing`
- `agents_chat_missing`
- Any `WP_Error::get_error_code()` returned by the chat ability execution.

Successful dispatches fire:

```php
do_action( 'wp_agent_routine_run_completed', $routine, $result );
```

Use that hook for run recording, metrics, assistant reply capture, token usage, and product-specific audit trails.

## Session And Persistence Expectations

The routine's `session_id` is the continuity boundary. Agents API passes it to `agents/chat`, but it does not persist the transcript or materialize the session. The chat runtime and conversation store decide how the id maps to durable records.

A typical consumer stack is:

```text
wp_register_routine()
-> optional Action Scheduler bridge
-> wp_agent_routine_run_scheduled
-> agents/chat ability
-> consumer chat runtime
-> consumer transcript/session store keyed by session_id
-> wp_agent_routine_run_completed observer records metrics
```

If a consumer needs database-backed routines, enable/disable state, per-user ownership, retries, retention, or historical run records, those belong in the consumer or a companion package rather than this substrate.

## Subagent Declarations

`WP_Agent` accepts a `subagents` array. Each entry is sanitized with `sanitize_title()` and empty values are removed.

```php
wp_register_agent(
	'commander',
	array(
		'label'     => 'Commander',
		'subagents' => array( 'detector', 'navigator' ),
	)
);

$agent = wp_get_agent( 'commander' );

$agent->get_subagents();  // array( 'detector', 'navigator' )
$agent->is_coordinator(); // true
```

The field is also included in `WP_Agent::to_array()` as `subagents`.

The declaration is intentionally passive. Agents API does not automatically look up the child agents, create delegation tools, enforce recursion limits, or run child agents. Consumers can interpret the declaration as a coordinator contract and expose product-owned tools such as `delegate-to-detector` or `delegate-to-navigator` through the normal tool and ability layers.

## Hooks Reference

| Hook | Type | Purpose |
| --- | --- | --- |
| `wp_agent_routine_registered` | action | Fires after a routine is added to the in-memory registry. |
| `wp_agent_routine_unregistered` | action | Fires after a routine is removed from the in-memory registry. |
| `wp_agent_routine_schedule_requested` | action | Fires whenever the Action Scheduler bridge is asked to schedule a routine, even if Action Scheduler is unavailable. |
| `wp_agent_routine_run_scheduled` | action | Scheduled wake hook consumed by the routine listener. |
| `agents_run_routine_dispatch_failed` | action | Observability hook for failed scheduled routine dispatch. |
| `wp_agent_routine_run_completed` | action | Observability hook for successful scheduled routine dispatch. |

## Testing

The routine and subagent contracts are covered by pure-PHP smoke tests:

```bash
php tests/routine-smoke.php
php tests/subagents-smoke.php
```

They also run as part of the full Composer test script:

```bash
composer test
```
