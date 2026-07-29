# Issue 463 Reviewer Form

Source relationship: promoted candidate repair of the existing request controller,
run awaiter, and scoped-drain workflow family.

Change kind: bounded runtime repair plus deterministic smoke coverage. No new
workflow executor, storage implementation, or Action Scheduler contract was added.

Implementation evidence:

- `WP_Agent_Workflow_Request_Controller` now publicly exposes `start`, `advance`,
  `reconnect`, `get_status`, and `cancel`.
- Reservation atomically writes the idempotency-key mapping and deterministic run
  identity. Advance uses an operation lane with a renewable worker identity.
- Every advance clamps scoped-drain wall-clock work to 5000ms and 25 actions, and
  the lane lease is extended to cover that bounded request.
- Awaiter-derived Action Scheduler scope remains the existing run-derived group;
  terminal cleanup only unschedules branch/resume actions in that group.
- Terminal state records one disposition, clears the lane, preserves the existing
  canonical result envelope, and retries failed terminal callbacks safely.

Verification capability and evidence:

- `php tests/workflow-request-controller-smoke.php`: duplicate starts, interrupted
  reconnect, budget exhaustion, concurrent/expired lane claims, cancellation,
  terminal cleanup, and atomic idempotency identity.
- `php tests/workflow-run-awaiter-smoke.php`: run-scoped await isolation and
  reconnectable budget/refusal behavior.
- `php tests/workflow-scoped-drain-smoke.php`: real scoped-drain over Action
  Scheduler fakes, including a `DISABLE_WP_CRON`-equivalent no-loopback drain.

Why runtime scope is not broader: the repair layers the request contract over the
existing Runner, Run_Awaiter, and Scoped_Drain seams. It does not alter their public
contracts or global Action Scheduler queue behavior; cleanup and draining are scoped
by the authoritative run identity.
