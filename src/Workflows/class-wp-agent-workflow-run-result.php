<?php
/**
 * Immutable execution outcome of a single workflow run.
 *
 * Captures the per-step record (status, output, error) plus the overall
 * status and the final output map. Run recorders persist this; callers
 * inspect it to act on the result.
 *
 * Statuses:
 *   `pending`   — recorded but not yet executed (used by recorders that
 *                 commit a row before the runner picks the work up).
 *   `running`   — currently executing.
 *   `succeeded` — every step that ran returned without error.
 *   `failed`    — at least one step returned a WP_Error or threw.
 *   `skipped`   — the runner declined to execute (e.g. unknown step
 *                 type that no consumer registered a handler for).
 *
 * Step records have the same statuses minus `pending` (steps either ran
 * or didn't), plus a `started_at` / `ended_at` pair for timing.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Run_Result {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_SUCCEEDED = 'succeeded';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_SKIPPED   = 'skipped';

	/**
	 * @since 0.103.0
	 *
	 * @param string $run_id      Caller-stable id (UUID, post id, custom-table row id).
	 * @param string $workflow_id Spec id this run belongs to.
	 * @param string $status      One of the STATUS_* constants.
	 * @param array  $inputs      Resolved inputs the run was started with.
	 * @param array  $output      Final aggregated output map (or partial if failed).
	 * @param array  $steps       List of step records, each shaped as
	 *                            `[ id, type, status, output, error?, started_at, ended_at ]`.
	 * @param array  $error       Top-level error info (`code`, `message`, `data`) when status === failed.
	 * @param int    $started_at  Unix timestamp.
	 * @param int    $ended_at    Unix timestamp; 0 while running.
	 * @param array  $metadata    Free-form metadata for recorders / tracers (Langfuse trace ids, etc.).
	 */
	public function __construct(
		private string $run_id,
		private string $workflow_id,
		private string $status,
		private array $inputs,
		private array $output,
		private array $steps,
		private array $error,
		private int $started_at,
		private int $ended_at,
		private array $metadata
	) {}

	public static function pending( string $run_id, string $workflow_id, array $inputs, int $started_at ): self {
		return new self( $run_id, $workflow_id, self::STATUS_PENDING, $inputs, array(), array(), array(), $started_at, 0, array() );
	}

	public function get_run_id(): string {
		return $this->run_id;
	}

	public function get_workflow_id(): string {
		return $this->workflow_id;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_inputs(): array {
		return $this->inputs;
	}

	public function get_output(): array {
		return $this->output;
	}

	public function get_steps(): array {
		return $this->steps;
	}

	public function get_error(): array {
		return $this->error;
	}

	public function get_started_at(): int {
		return $this->started_at;
	}

	public function get_ended_at(): int {
		return $this->ended_at;
	}

	public function get_metadata(): array {
		return $this->metadata;
	}

	public function is_succeeded(): bool {
		return self::STATUS_SUCCEEDED === $this->status;
	}

	public function is_failed(): bool {
		return self::STATUS_FAILED === $this->status;
	}

	/**
	 * Return a new result with updated fields. Run results are immutable; the
	 * runner builds a fresh instance per state change rather than mutating.
	 *
	 * @since 0.103.0
	 *
	 * @param array $patch Field => new value. Unknown keys are ignored.
	 * @return self
	 */
	public function with( array $patch ): self {
		return new self(
			(string) ( $patch['run_id'] ?? $this->run_id ),
			(string) ( $patch['workflow_id'] ?? $this->workflow_id ),
			(string) ( $patch['status'] ?? $this->status ),
			(array) ( $patch['inputs'] ?? $this->inputs ),
			(array) ( $patch['output'] ?? $this->output ),
			(array) ( $patch['steps'] ?? $this->steps ),
			(array) ( $patch['error'] ?? $this->error ),
			(int) ( $patch['started_at'] ?? $this->started_at ),
			(int) ( $patch['ended_at'] ?? $this->ended_at ),
			(array) ( $patch['metadata'] ?? $this->metadata ),
		);
	}

	/**
	 * Render to a plain array. Useful for recorders that want to serialise
	 * the run record verbatim (CPT meta, JSON column, REST response).
	 *
	 * @since 0.103.0
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'run_id'      => $this->run_id,
			'workflow_id' => $this->workflow_id,
			'status'      => $this->status,
			'inputs'      => $this->inputs,
			'output'      => $this->output,
			'steps'       => $this->steps,
			'error'       => $this->error,
			'started_at'  => $this->started_at,
			'ended_at'    => $this->ended_at,
			'metadata'    => $this->metadata,
		);
	}
}
