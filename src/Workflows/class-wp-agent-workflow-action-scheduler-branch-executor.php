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
	 * Dispatch one Action Scheduler async action per branch. The branch's
	 * self-contained descriptor rides in the action payload — that payload,
	 * stored in AS's own tables, IS the durable branch store (no agents-api
	 * table). Returns a BranchHandle per branch carrying the AS action id as its
	 * opaque `ref`.
	 *
	 * The `run_id` / `step_id` a branch reconciles against come from the
	 * descriptor. The runner stamps them onto each descriptor before dispatch
	 * (see {@see WP_Agent_Workflow_Runner::role_branch_descriptor()} /
	 * {@see WP_Agent_Workflow_Runner::build_map_dispatch_plan()}), but as a
	 * belt-and-suspenders we also read them from the shared context.
	 *
	 * @inheritDoc
	 * @since 0.5.0
	 */
	public function dispatch( array $branches, array $context ): array {
		$context_run_id  = self::string_value( $context['_workflow_run_id'] ?? '' );
		$context_step_id = self::string_value( $context['_workflow_step_id'] ?? '' );

		$handles = array();
		foreach ( $branches as $index => $branch ) {
			$key = self::string_value( $branch['key'] ?? (string) $index );

			// Prefer the self-contained descriptor's identity; fall back to the
			// dispatch context. The descriptor IS the durable payload, so its
			// run_id / step_id must be authoritative once it rides in AS.
			$run_id  = self::string_value( $branch['run_id'] ?? '' );
			$step_id = self::string_value( $branch['step_id'] ?? '' );
			$run_id  = '' !== $run_id ? $run_id : $context_run_id;
			$step_id = '' !== $step_id ? $step_id : $context_step_id;

			// Handle id is unique within the run so reconcile can address it and
			// a duplicate reconcile is a no-op.
			$handle_id = self::build_handle_id( $run_id, $step_id, $key, $index );

			// The descriptor is self-contained: it carries everything the branch
			// action needs to run and reconcile without re-reading the spec.
			$descriptor = array_merge(
				$branch,
				array(
					'run_id'    => $run_id,
					'step_id'   => $step_id,
					'handle_id' => $handle_id,
					'key'       => $key,
				)
			);

			$payload = array(
				'run_id'    => $run_id,
				'handle_id' => $handle_id,
				'branch'    => $descriptor,
			);

			$action_id = self::enqueue_async_action( self::BRANCH_HOOK, array( $payload ), self::GROUP );

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
	 * @param array<mixed> $payload Action payload: { run_id, handle_id, branch }.
	 * @return void
	 */
	public static function run_branch_action( array $payload ): void {
		$run_id     = self::string_value( $payload['run_id'] ?? '' );
		$handle_id  = self::string_value( $payload['handle_id'] ?? '' );
		$descriptor = is_array( $payload['branch'] ?? null ) ? self::string_keyed_array( $payload['branch'] ) : array();

		if ( '' === $run_id || '' === $handle_id ) {
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
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/**
	 * Enqueue an async action, tolerating environments where the AS shim only
	 * defines the bare function. Returns the action id (int) or 0.
	 *
	 * @since 0.5.0
	 *
	 * @param string       $hook  Action hook.
	 * @param array<mixed> $args  Action args (a single-element list holding the payload array).
	 * @param string       $group Action group.
	 * @return int
	 */
	private static function enqueue_async_action( string $hook, array $args, string $group ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}
		return as_enqueue_async_action( $hook, $args, $group );
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
