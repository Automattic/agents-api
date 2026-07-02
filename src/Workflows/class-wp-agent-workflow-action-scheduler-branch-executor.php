<?php
/**
 * The Action Scheduler branch executor — the one executor core ships, and the
 * ONLY table-free path to asynchronous, concurrent parallel-branch execution.
 *
 * Under the substrate's hard constraint (agents-api may add NO new database
 * tables, because it is headed for wpcom / WordPress core), Action Scheduler is
 * the only substrate that supplies — without a new table — the two things async
 * needs:
 *
 *   1. Durable branch persistence. Each branch's self-contained descriptor
 *      rides in the AS action payload, stored in AS's OWN tables. No agents-api
 *      table, and the descriptor survives restart because AS makes it so.
 *   2. A cross-process atomic claim. AS's queue runner claims each action
 *      exactly once — precisely the compare-and-set the "last branch resumes
 *      exactly once" transition requires. The resume is itself enqueued as one
 *      more claimed action, so even under a simultaneous multi-branch finish
 *      exactly one resume runs (the rest are claimed no-ops that re-check the
 *      run is still SUSPENDED and bail). No hand-rolled lock, no lock table.
 *
 * This is a DIFFERENT Action Scheduler use-case from the cron bridge
 * ({@see WP_Agent_Workflow_Action_Scheduler_Bridge}), which schedules ONE
 * recurring action per workflow trigger under `wp_agent_workflow_run_scheduled`.
 * This executor enqueues ONE async action PER BRANCH under a distinct hook,
 * plus one RESUME action per suspended run. Both reuse GROUP `agents-api` so an
 * operator has a single group to reason about; the hooks keep them separate.
 *
 * The runner owns the suspend/resume state machine and the reconcile entry
 * point ({@see agents_reconcile_workflow_branch()}); this executor owns only the
 * mechanism that runs branches out-of-band and drives that reconcile back in.
 *
 * @package AgentsAPI
 * @since   0.5.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Action_Scheduler_Branch_Executor implements WP_Agent_Workflow_Branch_Executor {

	/**
	 * Stable executor id stamped on every frame + handle so reconcile and the
	 * resume-dispatch seam can attribute a suspended run to this executor.
	 *
	 * @since 0.5.0
	 */
	public const ID = 'action_scheduler';

	/**
	 * The per-branch async action hook. One action per parallel branch is
	 * enqueued under this hook; its callback rehydrates the branch from its
	 * payload, runs it, and reconciles. Distinct from the cron bridge's
	 * `wp_agent_workflow_run_scheduled` so the two AS integrations never collide.
	 *
	 * @since 0.5.0
	 */
	public const BRANCH_HOOK = 'wp_agent_workflow_branch_run';

	/**
	 * The resume action hook. When a reconcile observes all branches terminal it
	 * enqueues ONE action under this hook rather than resuming inline; AS claims
	 * it exactly once, and the callback re-checks the run is still SUSPENDED
	 * before resuming — the exactly-once guarantee.
	 *
	 * @since 0.5.0
	 */
	public const RESUME_HOOK = 'wp_agent_workflow_run_resume';

	/**
	 * Shared AS group with the cron bridge so operators reason about one group.
	 * The hook, not the group, distinguishes the two integrations.
	 *
	 * @since 0.5.0
	 */
	public const GROUP = WP_Agent_Workflow_Action_Scheduler_Bridge::GROUP;

	/**
	 * Whether Action Scheduler's async enqueue is available. This — not mere
	 * presence of the plugin — is the async gate: `as_enqueue_async_action` is
	 * what supplies both the durable payload store and the atomic claim.
	 *
	 * @since 0.5.0
	 */
	public static function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * @inheritDoc
	 * @since 0.5.0
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Dispatch one Action Scheduler async action per branch.
	 *
	 * PAYLOAD OFFLOAD (§Bug 1). The branch's self-contained descriptor —
	 * including the shared immutable `context` snapshot — is NOT packed inline
	 * into the AS action `args`. Action Scheduler enforces a hard 8,000-character
	 * limit on its `args` column, and a rich context (a multi-page brief) blows
	 * past it. Instead the descriptor is persisted to the table-free branch store
	 * ({@see WP_Agent_Workflow_Branch_Store}) and the AS args carry only a small,
	 * stable reference — `{ run_id, handle_id, store_ref, context_ref }` — whose
	 * size does not scale with context richness. The shared context is stored
	 * ONCE per run (run-scoped) rather than duplicated into every branch payload.
	 *
	 * FAIL-LOUD (§Bug 2). Every `as_enqueue_async_action()` call is checked: an
	 * enqueue that returns a non-positive id (or throws) is a hard failure, not a
	 * phantom `ref => 0` handle. On ANY branch's enqueue failure this returns a
	 * WP_Error and the run fails fast with a descriptive message rather than
	 * suspending against a branch that was never enqueued and hanging until its
	 * budget expires. A partial dispatch (some branches enqueued, one failed) is
	 * also a hard failure — the run must not suspend against a partial branch set.
	 *
	 * The `run_id` / `step_id` a branch reconciles against come from the
	 * descriptor. The runner stamps them onto each descriptor before dispatch
	 * (see {@see WP_Agent_Workflow_Runner::role_branch_descriptor()} /
	 * {@see WP_Agent_Workflow_Runner::build_map_dispatch_plan()}), but as a
	 * belt-and-suspenders we also read them from the shared context.
	 *
	 * @param array<int,array<string,mixed>> $branches Branch descriptors.
	 * @param array<string,mixed>            $context  Shared context snapshot.
	 * @return array<int,array<string,mixed>>|\WP_Error BranchHandle[] or a hard failure.
	 * @since 0.5.0
	 */
	public function dispatch( array $branches, array $context ) {
		$context_run_id  = self::string_value( $context['_workflow_run_id'] ?? '' );
		$context_step_id = self::string_value( $context['_workflow_step_id'] ?? '' );

		// The shared immutable context is identical for every branch, so store it
		// ONCE per run and reference it from each branch — never duplicate a
		// multi-KB context into N branch payloads. Its ref rides in every branch's
		// AS args (a short option name), and the branch action re-seats it into the
		// descriptor's branch_vars.context on rehydrate.
		$run_id_for_ctx = '' !== $context_run_id ? $context_run_id : self::first_branch_run_id( $branches );
		$shared_context = is_array( $context['shared_context'] ?? null ) ? self::string_keyed_array( $context['shared_context'] ) : array();
		$context_ref    = '' !== $run_id_for_ctx
			? WP_Agent_Workflow_Branch_Store::put_shared_context( $run_id_for_ctx, $shared_context )
			: '';

		$handles = array();
		foreach ( $branches as $index => $branch ) {
			$key = self::string_value( $branch['key'] ?? (string) $index );

			// Prefer the self-contained descriptor's identity; fall back to the
			// dispatch context. The descriptor IS the durable payload, so its
			// run_id / step_id must be authoritative once it rides in the store.
			$run_id  = self::string_value( $branch['run_id'] ?? '' );
			$step_id = self::string_value( $branch['step_id'] ?? '' );
			$run_id  = '' !== $run_id ? $run_id : $context_run_id;
			$step_id = '' !== $step_id ? $step_id : $context_step_id;

			// Handle id is unique within the run so reconcile can address it and
			// a duplicate reconcile is a no-op.
			$handle_id = self::build_handle_id( $run_id, $step_id, $key, $index );

			// The descriptor is self-contained: it carries everything the branch
			// action needs to run and reconcile without re-reading the spec. Strip
			// the shared context out of the stored descriptor — it lives ONCE in
			// the run-scoped context row and is re-seated on rehydrate.
			$descriptor = array_merge(
				$branch,
				array(
					'run_id'    => $run_id,
					'step_id'   => $step_id,
					'handle_id' => $handle_id,
					'key'       => $key,
				)
			);
			$descriptor = self::strip_shared_context( $descriptor );

			// Offload the descriptor to the store; the AS args carry only the ref.
			$store_ref = WP_Agent_Workflow_Branch_Store::put_branch( $run_id, $handle_id, $descriptor );

			$payload = array(
				'run_id'      => $run_id,
				'handle_id'   => $handle_id,
				'store_ref'   => $store_ref,
				'context_ref' => $context_ref,
			);

			$action_id = self::enqueue_async_action( self::BRANCH_HOOK, array( $payload ), self::GROUP );

			// FAIL LOUD: a non-positive action id means the enqueue did not durably
			// persist the branch (AS's args-size guard threw, AS is down, etc.).
			// Returning a phantom ref=0 handle here is what previously made the run
			// suspend against a branch that never existed and hang. Fail the whole
			// dispatch instead — clean up what we already stored so no orphan rows
			// linger, and surface a descriptive WP_Error.
			if ( $action_id <= 0 ) {
				if ( '' !== $run_id ) {
					WP_Agent_Workflow_Branch_Store::forget_run( $run_id );
				}
				return new \WP_Error(
					'workflow_branch_dispatch_enqueue_failed',
					sprintf(
						'Failed to enqueue async branch action for branch `%s` (run `%s`): Action Scheduler returned no action id. The run is failing fast rather than suspending against a branch that was never enqueued.',
						$key,
						$run_id
					)
				);
			}

			$handles[] = array(
				'id'       => $handle_id,
				'key'      => $key,
				'executor' => self::ID,
				'status'   => 'dispatched',
				'required' => ! empty( $branch['required'] ),
				'ref'      => $action_id,
			);
		}

		return $handles;
	}

	/**
	 * Whether every handle is terminal. Authoritative source is the
	 * reconcile-tracked frame status stamped on each handle (the runner keeps it
	 * current via {@see agents_reconcile_workflow_branch()}); this executor does
	 * not poll AS. When a `ref` (AS action id) is present it is cross-checked so
	 * an action that finished without reconciling (a crashed callback) is not
	 * mistaken for still-in-flight forever — but the frame status wins.
	 *
	 * @inheritDoc
	 * @since 0.5.0
	 */
	public function are_all_complete( array $handles ): bool {
		foreach ( $handles as $handle ) {
			$status = self::string_value( $handle['status'] ?? '' );
			if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED === $status
				|| WP_Agent_Workflow_Run_Result::STATUS_FAILED === $status ) {
				continue;
			}
			return false;
		}
		return true;
	}

	/**
	 * Collect terminal branch outputs keyed by the branch key (role or index).
	 * The authoritative outputs live in the reconcile-tracked frame; a handle
	 * only carries its key + status here, so `collect()` returns an empty result
	 * per key. The runner reads reconciled outputs from the frame's `completed`
	 * map (via {@see agents_workflow_branch_results_by_key()}), not from here —
	 * this method exists to satisfy the interface for the already-complete
	 * (synchronous) inline path, which the AS executor never takes.
	 *
	 * @inheritDoc
	 * @since 0.5.0
	 */
	public function collect( array $handles ): array {
		$out = array();
		foreach ( $handles as $handle ) {
			$key         = self::string_value( $handle['key'] ?? '' );
			$out[ $key ] = array(
				'key'    => $key,
				'status' => self::string_value( $handle['status'] ?? 'dispatched' ),
				'output' => null,
			);
		}
		return $out;
	}

	// ── Action callbacks ─────────────────────────────────────────────────────

	/**
	 * The BRANCH_HOOK callback. Rehydrates one branch from its self-contained
	 * descriptor, runs it through the SHARED branch runner
	 * ({@see WP_Agent_Workflow_Runner::run_branch_steps()} — the exact code the
	 * synchronous path uses), builds a BranchResult, and drives the REAL
	 * reconcile entry point. Nothing about branch execution diverges from sync;
	 * only WHERE (a claimed AS action) and WHEN (its result lands via reconcile).
	 *
	 * @since 0.5.0
	 *
	 * @param array<mixed> $payload Action payload: { run_id, handle_id, store_ref, context_ref }.
	 * @return void
	 */
	public static function run_branch_action( array $payload ): void {
		$run_id      = self::string_value( $payload['run_id'] ?? '' );
		$handle_id   = self::string_value( $payload['handle_id'] ?? '' );
		$store_ref   = self::string_value( $payload['store_ref'] ?? '' );
		$context_ref = self::string_value( $payload['context_ref'] ?? '' );

		if ( '' === $run_id || '' === $handle_id ) {
			return;
		}

		// Rehydrate the full self-contained descriptor from the branch store using
		// the lightweight ref the AS args carried. The store re-seats the run-scoped
		// shared context into branch_vars.context, so the branch runs against the
		// same descriptor shape the runner built at dispatch. A backward-compatible
		// inline descriptor (a payload enqueued before the offload) is still honored.
		$descriptor = '' !== $store_ref
			? WP_Agent_Workflow_Branch_Store::get_branch( $store_ref, $context_ref )
			: null;
		if ( null === $descriptor && is_array( $payload['branch'] ?? null ) ) {
			$descriptor = self::string_keyed_array( $payload['branch'] );
		}

		if ( ! is_array( $descriptor ) ) {
			// The stored descriptor is gone (expired / evicted) and no inline
			// fallback exists. Reconcile a clean failure so the run does not hang
			// SUSPENDED against a branch that can no longer run.
			$branch_result = array(
				'key'    => '',
				'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
				'output' => null,
				'steps'  => array(),
				'error'  => array(
					'code'    => 'workflow_branch_descriptor_missing',
					'message' => sprintf( 'Branch descriptor for handle `%s` (run `%s`) could not be rehydrated from the branch store.', $handle_id, $run_id ),
				),
				'item'   => null,
			);
			agents_reconcile_workflow_branch( $run_id, $handle_id, $branch_result );
			return;
		}

		$key           = self::string_value( $descriptor['key'] ?? '' );
		$branch_result = self::execute_branch( $descriptor, $key );

		agents_reconcile_workflow_branch( $run_id, $handle_id, $branch_result );
	}

	/**
	 * Run one branch's nested steps through the shared runner and normalize the
	 * outcome into a BranchResult ({ key, status, output, steps, error, item }).
	 * A required-branch failure is reported as a `failed` BranchResult so the
	 * runner's reconcile applies the same required-branch rule the sync path
	 * does; a non-required failure surfaces the error as the branch output but
	 * reports `succeeded`, matching {@see WP_Agent_Workflow_Runner::run_role_branch()}.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string,mixed> $descriptor The self-contained branch descriptor.
	 * @param string              $key        Branch key (role or index).
	 * @return array<string,mixed> BranchResult.
	 */
	private static function execute_branch( array $descriptor, string $key ): array {
		$steps = is_array( $descriptor['steps'] ?? null ) ? $descriptor['steps'] : array();
		if ( empty( $steps ) ) {
			return array(
				'key'    => $key,
				'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
				'output' => null,
				'steps'  => array(),
				'error'  => array(
					'code'    => 'workflow_parallel_branch_steps_invalid',
					'message' => sprintf( 'parallel branch `%s` must include a non-empty nested `steps` list.', $key ),
				),
				'item'   => $descriptor['item'] ?? null,
			);
		}

		$continue_on_error = ! empty( $descriptor['continue_on_error'] );
		$required          = ! empty( $descriptor['required'] );
		$branch_vars       = is_array( $descriptor['branch_vars'] ?? null ) ? $descriptor['branch_vars'] : array();

		$handlers = self::resolve_handlers();
		$executor = new WP_Agent_Workflow_Step_Executor( $handlers );

		// Rebuild the branch context: a fresh, isolated per-branch context with
		// the branch's scoped vars (shared context + role contract, or the map
		// item/index), exactly like the sync branch context.
		$branch_context = ( new WP_Agent_Workflow_Run_Context(
			array(
				'inputs' => array(),
				'steps'  => array(),
				'vars'   => array(),
			)
		) )->with_vars( $branch_vars );

		$run = WP_Agent_Workflow_Runner::run_branch_steps(
			$steps,
			$branch_context,
			$executor,
			$handlers,
			$continue_on_error,
			$key
		);

		if ( is_wp_error( $run ) ) {
			if ( $required ) {
				return array(
					'key'    => $key,
					'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
					'output' => null,
					'steps'  => array(),
					'error'  => array(
						'code'    => $run->get_error_code(),
						'message' => $run->get_error_message(),
					),
					'item'   => $descriptor['item'] ?? null,
				);
			}

			// A non-required branch surfaces its error as the branch output but
			// still reports succeeded so the run is not failed by it.
			return array(
				'key'    => $key,
				'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
				'output' => array(
					'error' => array(
						'code'    => $run->get_error_code(),
						'message' => $run->get_error_message(),
					),
				),
				'steps'  => array(),
				'error'  => null,
				'item'   => $descriptor['item'] ?? null,
			);
		}

		return array(
			'key'    => $key,
			'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
			'output' => $run['last'],
			'steps'  => $run['steps'],
			'error'  => null,
			'item'   => $descriptor['item'] ?? null,
		);
	}

	/**
	 * Deferred-resume seam handler. Hooked on `wp_agent_workflow_resume_dispatch`
	 * (fired by the reconcile entry point once every branch is terminal). When
	 * the suspended run is owned by THIS executor, enqueue a single claimed
	 * RESUME action and return `true` so reconcile does NOT resume inline. AS's
	 * atomic claim guarantees exactly one of these actions is ever claimed-and-
	 * run, so a simultaneous multi-branch finish enqueues at most one effective
	 * resume; the handler re-checks SUSPENDED and no-ops otherwise.
	 *
	 * @since 0.5.0
	 *
	 * @param bool   $deferred    Whether resume is already deferred.
	 * @param string $run_id      The suspended run id.
	 * @param string $executor_id The frame's owning executor id.
	 * @return bool True when this executor claimed the resume.
	 */
	public static function maybe_defer_resume( bool $deferred, string $run_id, string $executor_id ): bool {
		if ( $deferred ) {
			return true;
		}
		if ( self::ID !== $executor_id || '' === $run_id ) {
			return false;
		}
		if ( ! self::is_available() ) {
			// AS vanished mid-flight — let reconcile resume inline rather than
			// strand the run.
			return false;
		}

		self::enqueue_async_action(
			self::RESUME_HOOK,
			array( array( 'run_id' => $run_id ) ),
			self::GROUP
		);

		return true;
	}

	/**
	 * The RESUME_HOOK callback. AS claimed this action exactly once. Re-check the
	 * run is still SUSPENDED (a concurrent claim may already have resumed it) and
	 * resume exactly once; a run that already resumed is a harmless no-op. This
	 * re-check-then-resume is the whole exactly-once correctness point — the
	 * guard is AS's claim, and the re-read of the frame's SUSPENDED status.
	 *
	 * @since 0.5.0
	 *
	 * @param array<mixed> $payload Action payload: { run_id }.
	 * @return void
	 */
	public static function run_resume_action( array $payload ): void {
		$run_id = self::string_value( $payload['run_id'] ?? '' );
		if ( '' === $run_id ) {
			return;
		}

		$recorder = agents_workflow_resolve_recorder();
		if ( null === $recorder ) {
			return;
		}

		$result = $recorder->find( $run_id );
		if ( null === $result || ! $result->is_suspended() ) {
			// Already resumed (or gone). The claimed action is a no-op. This is
			// the second-of-two simultaneous finishers being deduped by AS's
			// claim + this SUSPENDED re-check.
			return;
		}

		$runner = agents_workflow_resolve_runner( $recorder );
		$runner->resume( $run_id );

		// The run has resolved (the suspension frame was cleared by resume). Release
		// this run's stored branch payloads so no orphan option rows linger — same
		// cleanup discipline the suspension frame follows.
		WP_Agent_Workflow_Branch_Store::forget_run( $run_id );
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/**
	 * Enqueue an async action, tolerating environments where the AS shim only
	 * defines the bare function. Returns the action id (int), or 0 on failure.
	 *
	 * FAIL-LOUD SEAM. Action Scheduler THROWS from `as_enqueue_async_action()`
	 * when the enqueue is rejected (most relevantly its 8,000-char args-size
	 * guard: `ActionScheduler_Action::$args too long`). When AS's queue runner
	 * calls this it catches+logs+swallows that throw, so the throw never reaches
	 * the caller — the enqueue silently returns nothing and the branch is never
	 * durably scheduled. We normalize BOTH failure modes to a `0` return so the
	 * caller ({@see self::dispatch()}) can detect the failure and fail the run
	 * loudly instead of returning a phantom `ref=0` handle that suspends against
	 * a branch that does not exist.
	 *
	 * @since 0.5.0
	 *
	 * @param string       $hook  Action hook.
	 * @param array<mixed> $args  Action args (a single-element list holding the payload array).
	 * @param string       $group Action group.
	 * @return int Action id, or 0 when the enqueue failed (threw or returned no id).
	 */
	private static function enqueue_async_action( string $hook, array $args, string $group ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}
		try {
			// AS returns the new action id (a positive int) on success. dispatch()
			// treats a non-positive return as a hard failure.
			return (int) as_enqueue_async_action( $hook, $args, $group );
		} catch ( \Throwable $error ) {
			// AS rejected the enqueue (e.g. args too long / queue unavailable).
			// Normalize to 0 so dispatch() surfaces a clean WP_Error rather than
			// letting the throw be swallowed by AS's queue runner into a hang.
			unset( $error );
			return 0;
		}
	}

	/**
	 * Resolve the step-type handler map used to run a rehydrated branch's steps.
	 * Reuses the same reconcile-side resolver so branch execution and aggregate
	 * execution share one handler map.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string,mixed>
	 */
	private static function resolve_handlers(): array {
		return agents_workflow_resolve_step_handlers();
	}

	/**
	 * Strip the shared immutable context out of a branch descriptor before it is
	 * stored. The shared context lives ONCE in the run-scoped context row; keeping
	 * a copy inside every branch descriptor would re-introduce the N-copies
	 * duplication the store exists to remove. The per-branch role/item scoping in
	 * `branch_vars` is left intact.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string,mixed> $descriptor Branch descriptor.
	 * @return array<string,mixed>
	 */
	private static function strip_shared_context( array $descriptor ): array {
		if ( is_array( $descriptor['branch_vars'] ?? null ) && array_key_exists( 'context', $descriptor['branch_vars'] ) ) {
			unset( $descriptor['branch_vars']['context'] );
		}
		return $descriptor;
	}

	/**
	 * Recover a run id from the first branch descriptor as a belt-and-suspenders
	 * source for the run-scoped shared-context key when the dispatch context did
	 * not carry `_workflow_run_id`.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int,array<string,mixed>> $branches Branch descriptors.
	 * @return string
	 */
	private static function first_branch_run_id( array $branches ): string {
		foreach ( $branches as $branch ) {
			$run_id = self::string_value( $branch['run_id'] ?? '' );
			if ( '' !== $run_id ) {
				return $run_id;
			}
		}
		return '';
	}

	/**
	 * Build a handle id unique within the run: `<run_id>:<step_id>:<key>:<index>`.
	 *
	 * @since 0.5.0
	 */
	private static function build_handle_id( string $run_id, string $step_id, string $key, int $index ): string {
		$parts = array_filter(
			array( $run_id, $step_id, $key, (string) $index ),
			static function ( string $part ): bool {
				return '' !== $part;
			}
		);
		return implode( ':', $parts );
	}

	/**
	 * @param mixed $value Value to normalize.
	 */
	private static function string_value( $value ): string {
		if ( is_scalar( $value ) || $value instanceof \Stringable ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Keep only string keys, giving PHPStan a precise `array<string,mixed>` for
	 * a descriptor rehydrated from an opaque action payload.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}
		return $result;
	}
}
