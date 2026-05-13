<?php
/**
 * Workflow runner — executes a {@see WP_Agent_Workflow_Spec} step-by-step.
 *
 * The runner is intentionally narrow:
 *
 *   1. Validate inputs against the spec's input schema (presence + type).
 *   2. Walk steps in order. For each step:
 *        a. Resolve `${...}` bindings against `inputs` + earlier step outputs.
 *        b. Dispatch to a step-type handler (`ability` / `agent` ship by
 *           default; consumers register more via the handler map).
 *        c. Record the per-step outcome (status, output, error, timing).
 *        d. If the step failed and the spec didn't opt into `continue_on_error`,
 *           short-circuit the run.
 *   3. Update the recorder once at start, once per step, and once at end.
 *   4. Return a final {@see WP_Agent_Workflow_Run_Result}.
 *
 * What the runner does NOT do:
 *   - Branching, parallelism, nested workflows. Step-handler map is the
 *     extension point for those — a consumer can register a `branch`
 *     handler that runs a sub-list, or a `workflow` handler that calls
 *     this runner recursively.
 *   - Triggering. Triggers are wired separately
 *     ({@see WP_Agent_Workflow_Action_Scheduler_Bridge} for cron, and a
 *     consumer-registered listener for `wp_action`). The runner only
 *     executes; it doesn't schedule or hook.
 *   - Storage. Specs come in as Spec instances (often pulled from a
 *     {@see WP_Agent_Workflow_Store} or registry); recorders persist
 *     run history.
 *
 * Usage:
 *
 *     $runner = new WP_Agent_Workflow_Runner( $recorder, $step_handlers );
 *     $result = $runner->run( $spec, [ 'comment_id' => 42 ] );
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class WP_Agent_Workflow_Runner {

	/**
	 * @var array<string,callable> Step type → handler. Each handler receives
	 *                             ( array $resolved_step, array $context ) and
	 *                             returns array|WP_Error. Consumers extend
	 *                             via the constructor or the
	 *                             `wp_agent_workflow_step_handlers` filter.
	 */
	protected array $step_handlers;

	public function __construct(
		protected ?WP_Agent_Workflow_Run_Recorder $recorder = null,
		array $step_handlers = array()
	) {
		$defaults = array(
			'ability' => array( __CLASS__, 'default_ability_handler' ),
			'agent'   => array( __CLASS__, 'default_agent_handler' ),
			'foreach' => array( __CLASS__, 'default_foreach_handler' ),
		);

		/**
		 * Filter the step-type handler map. Consumers add new step types
		 * (`branch`, `parallel`, `workflow`, …) by registering a callable
		 * here. Default `ability` and `agent` handlers can also be replaced
		 * if a consumer wants to substitute a different ability / agent
		 * runtime.
		 *
		 * @since 0.103.0
		 *
		 * @param array<string,callable> $handlers Default + caller-supplied handlers.
		 */
		$this->step_handlers = (array) apply_filters(
			'wp_agent_workflow_step_handlers',
			array_merge( $defaults, $step_handlers )
		);
	}

	/**
	 * Execute a workflow.
	 *
	 * @since 0.103.0
	 *
	 * @param WP_Agent_Workflow_Spec $spec
	 * @param array                  $inputs Caller-supplied inputs. Required
	 *                                       inputs missing here cause an early
	 *                                       failure with a structured error.
	 * @param array                  $options Runtime options:
	 *                                        - `run_id` (string, optional): caller-suggested run id.
	 *                                        - `continue_on_error` (bool): keep running after a failed step. Default false.
	 *                                        - `metadata` (array): forwarded to the run result.
	 * @return WP_Agent_Workflow_Run_Result
	 */
	public function run( WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() ): WP_Agent_Workflow_Run_Result {
		$started_at = time();
		$run_id     = (string) ( $options['run_id'] ?? self::generate_run_id() );
		$metadata   = (array) ( $options['metadata'] ?? array() );

		// Build the initial RUNNING result and persist via recorder->start()
		// before doing anything else. Even if input validation fails on the
		// next line we want a `start → update(failed)` lifecycle so recorders
		// never see `start()` called with an already-terminal status.
		$result = new WP_Agent_Workflow_Run_Result(
			$run_id,
			$spec->get_id(),
			WP_Agent_Workflow_Run_Result::STATUS_RUNNING,
			$inputs,
			array(),
			array(),
			array(),
			$started_at,
			0,
			$metadata
		);

		if ( $this->recorder ) {
			$persisted = $this->recorder->start( $result );
			if ( is_wp_error( $persisted ) ) {
				// Recorder unavailable on entry — return a failed result without
				// running steps. The caller still gets the in-memory record so
				// observability hooks fire; the step pipeline does not run.
				return $result->with(
					array(
						'status'   => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
						'error'    => array(
							'code'    => 'recorder_start_failed',
							'message' => $persisted->get_error_message(),
						),
						'ended_at' => time(),
					)
				);
			}
			if ( '' !== $persisted ) {
				$result = $result->with( array( 'run_id' => $persisted ) );
			}
		}

		// Validate inputs against the spec's input declarations.
		$input_error = self::validate_inputs( $spec, $inputs );
		if ( null !== $input_error ) {
			$terminal = $result->with(
				array(
					'status'   => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
					'error'    => $input_error,
					'ended_at' => time(),
				)
			);
			if ( $this->recorder ) {
				$this->recorder->update( $terminal );
			}
			return $terminal;
		}

		$context = array(
			'inputs' => $inputs,
			// Step outputs accumulate here as the run progresses, keyed by step id.
			'steps'  => array(),
			'vars'   => array(),
		);

		$step_records      = array();
		$continue_on_error = ! empty( $options['continue_on_error'] );
		$failed            = false;
		$failure_error     = array();

		foreach ( $spec->get_steps() as $step ) {
			$step_id  = (string) $step['id'];
			$type     = (string) $step['type'];
			$start_ts = time();
			$resolved = 'foreach' === $type
				? self::expand_foreach_outer_step( $step, $context )
				: WP_Agent_Workflow_Bindings::expand( $step, $context );
			$record   = array(
				'id'         => $step_id,
				'type'       => $type,
				'status'     => WP_Agent_Workflow_Run_Result::STATUS_RUNNING,
				'output'     => null,
				'started_at' => $start_ts,
				'ended_at'   => 0,
			);

			$handler = $this->step_handlers[ $type ] ?? null;
			if ( ! is_callable( $handler ) ) {
				$record['status']   = WP_Agent_Workflow_Run_Result::STATUS_SKIPPED;
				$record['ended_at'] = time();
				$record['error']    = array(
					'code'    => 'no_step_handler',
					'message' => sprintf( 'no handler registered for step type `%s`', $type ),
				);
				$step_records[]     = $record;

				$failed        = true;
				$failure_error = $record['error'];
				$result        = $result->with( array( 'steps' => $step_records ) );
				if ( $this->recorder ) {
					$this->recorder->update( $result );
				}
				if ( ! $continue_on_error ) {
					break;
				}
				continue;
			}

			$step_output = call_user_func( $handler, $resolved, $context );

			if ( is_wp_error( $step_output ) ) {
				$record['status']   = WP_Agent_Workflow_Run_Result::STATUS_FAILED;
				$record['ended_at'] = time();
				$record['error']    = array(
					'code'    => $step_output->get_error_code(),
					'message' => $step_output->get_error_message(),
					'data'    => $step_output->get_error_data(),
				);
				$step_records[]     = $record;

				$failed        = true;
				$failure_error = $record['error'];
				$result        = $result->with( array( 'steps' => $step_records ) );
				if ( $this->recorder ) {
					$this->recorder->update( $result );
				}
				if ( ! $continue_on_error ) {
					break;
				}
				continue;
			}

			$record['status']   = WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED;
			$record['output']   = is_array( $step_output ) ? $step_output : array( 'value' => $step_output );
			$record['ended_at'] = time();
			$step_records[]     = $record;

			$context['steps'][ $step_id ] = array( 'output' => $record['output'] );

			$result = $result->with( array( 'steps' => $step_records ) );
			if ( $this->recorder ) {
				$this->recorder->update( $result );
			}
		}

		// Final aggregated output: every step's output keyed by id, plus a
		// convenience `last` shortcut that points at the last step's output
		// **only when that last step succeeded**. With `continue_on_error`
		// the last step in the list may be a failed one, in which case
		// `last` is intentionally absent — callers should reach for
		// `$result->get_output()['steps'][<id>]` when partial-failure
		// semantics matter.
		$final_output = array(
			'steps' => array(),
		);
		foreach ( $step_records as $rec ) {
			$final_output['steps'][ $rec['id'] ] = $rec['output'];
		}
		$last = end( $step_records );
		if ( false !== $last && WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED === $last['status'] ) {
			$final_output['last'] = $last['output'];
		}

		$result = $result->with(
			array(
				'status'   => $failed ? WP_Agent_Workflow_Run_Result::STATUS_FAILED : WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
				'output'   => $final_output,
				'error'    => $failure_error,
				'ended_at' => time(),
			)
		);

		if ( $this->recorder ) {
			$this->recorder->update( $result );
		}
		return $result;
	}

	/**
	 * Expand a foreach step's outer fields while preserving its nested step
	 * templates for each iteration's scoped variables.
	 *
	 * @since 0.107.0
	 *
	 * @param array $step
	 * @param array $context
	 * @return array
	 */
	private static function expand_foreach_outer_step( array $step, array $context ): array {
		$nested = $step['steps'] ?? array();
		unset( $step['steps'] );

		$expanded          = WP_Agent_Workflow_Bindings::expand( $step, $context );
		$expanded['steps'] = $nested;

		return $expanded;
	}

	/**
	 * Validate inputs against the spec's declared input schemas.
	 *
	 * @since 0.103.0
	 *
	 * @return array{code:string,message:string,data?:mixed}|null
	 */
	private static function validate_inputs( WP_Agent_Workflow_Spec $spec, array $inputs ): ?array {
		foreach ( $spec->get_inputs() as $name => $schema ) {
			$required = ! empty( $schema['required'] );
			$present  = array_key_exists( $name, $inputs );

			if ( $required && ! $present ) {
				return array(
					'code'    => 'missing_required_input',
					'message' => sprintf( 'workflow `%s` requires input `%s`', $spec->get_id(), $name ),
					'data'    => array( 'input' => $name ),
				);
			}
		}
		return null;
	}

	/**
	 * Default `ability` step handler: invokes a registered Abilities API
	 * ability with the step's `args` (post-binding-resolution). Returns
	 * the ability's output as the step output.
	 *
	 * @since 0.103.0
	 *
	 * @param array $step    Resolved step (bindings already expanded).
	 * @param array $context Resolution context (unused here).
	 * @return array|WP_Error
	 */
	public static function default_ability_handler( array $step, array $context ) {
		unset( $context );
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error(
				'abilities_api_missing',
				'Abilities API is not loaded; cannot dispatch ability step.'
			);
		}
		$ability_name = (string) ( $step['ability'] ?? '' );
		$ability      = wp_get_ability( $ability_name );
		if ( null === $ability ) {
			return new \WP_Error(
				'unknown_ability',
				sprintf( 'no ability registered as `%s`', $ability_name )
			);
		}
		$args   = (array) ( $step['args'] ?? array() );
		$result = $ability->execute( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return is_array( $result ) ? $result : array( 'value' => $result );
	}

	/**
	 * Default `agent` step handler: calls the canonical `agents/chat`
	 * dispatcher (per agents-api#100) with the step's agent slug + message.
	 *
	 * @since 0.103.0
	 *
	 * @param array $step    Resolved step (bindings already expanded).
	 * @param array $context Resolution context (unused here).
	 * @return array|WP_Error
	 */
	public static function default_agent_handler( array $step, array $context ) {
		unset( $context );
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error(
				'abilities_api_missing',
				'Abilities API is not loaded; cannot dispatch agent step.'
			);
		}
		$ability = wp_get_ability( 'agents/chat' );
		if ( null === $ability ) {
			return new \WP_Error(
				'agents_chat_missing',
				'agents/chat ability is not registered.'
			);
		}
		$input  = array(
			'agent'      => (string) ( $step['agent'] ?? '' ),
			'message'    => (string) ( $step['message'] ?? '' ),
			'session_id' => $step['session_id'] ?? null,
		);
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return is_array( $result ) ? $result : array( 'reply' => (string) $result );
	}

	/**
	 * Default `foreach` step handler. Iterates over a resolved array and runs
	 * an inline list of workflow steps with `${vars.<as>.*}` available.
	 *
	 * @since 0.107.0
	 *
	 * @param array $step    Resolved outer foreach step.
	 * @param array $context Resolution context.
	 * @return array|WP_Error
	 */
	public static function default_foreach_handler( array $step, array $context ) {
		$items = $step['items'] ?? array();
		if ( ! is_array( $items ) ) {
			return new \WP_Error(
				'workflow_foreach_items_invalid',
				'foreach step `items` must resolve to an array.'
			);
		}

		$steps = $step['steps'] ?? array();
		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return new \WP_Error(
				'workflow_foreach_steps_invalid',
				'foreach step must include a non-empty nested `steps` list.'
			);
		}

		$as                = isset( $step['as'] ) && '' !== (string) $step['as'] ? (string) $step['as'] : 'item';
		$index_as          = isset( $step['index_as'] ) && '' !== (string) $step['index_as'] ? (string) $step['index_as'] : 'index';
		$continue_on_error = ! empty( $step['continue_on_error'] );
		$handlers          = (array) apply_filters(
			'wp_agent_workflow_step_handlers',
			array(
				'ability' => array( __CLASS__, 'default_ability_handler' ),
				'agent'   => array( __CLASS__, 'default_agent_handler' ),
				'foreach' => array( __CLASS__, 'default_foreach_handler' ),
			)
		);
		$iterations        = array();

		foreach ( array_values( $items ) as $index => $item ) {
			$iteration_context         = $context;
			$iteration_context['vars'] = array_merge(
				(array) ( $context['vars'] ?? array() ),
				array(
					$as       => $item,
					$index_as => $index,
				)
			);
			$step_outputs              = array();
			$last_output               = null;

			foreach ( $steps as $nested_step ) {
				if ( ! is_array( $nested_step ) ) {
					return new \WP_Error(
						'workflow_foreach_step_invalid',
						sprintf( 'foreach nested step at index %d must be an array.', $index )
					);
				}

				$nested_id = (string) ( $nested_step['id'] ?? '' );
				$type      = (string) ( $nested_step['type'] ?? '' );
				$handler   = $handlers[ $type ] ?? null;
				if ( '' === $nested_id || ! is_callable( $handler ) ) {
					$error = new \WP_Error(
						'workflow_foreach_step_unhandled',
						sprintf( 'foreach nested step `%s` cannot be handled.', '' !== $nested_id ? $nested_id : (string) $index )
					);
					if ( ! $continue_on_error ) {
						return $error;
					}
					$step_outputs[ $nested_id ] = array(
						'error' => array(
							'code'    => $error->get_error_code(),
							'message' => $error->get_error_message(),
						),
					);
					continue;
				}

				$resolved      = 'foreach' === $type
					? self::expand_foreach_outer_step( $nested_step, $iteration_context )
					: WP_Agent_Workflow_Bindings::expand( $nested_step, $iteration_context );
				$nested_output = call_user_func( $handler, $resolved, $iteration_context );

				if ( is_wp_error( $nested_output ) ) {
					if ( ! $continue_on_error ) {
						return $nested_output;
					}
					$last_output = array(
						'error' => array(
							'code'    => $nested_output->get_error_code(),
							'message' => $nested_output->get_error_message(),
							'data'    => $nested_output->get_error_data(),
						),
					);
				} else {
					$last_output = is_array( $nested_output ) ? $nested_output : array( 'value' => $nested_output );
				}

				$step_outputs[ $nested_id ]             = $last_output;
				$iteration_context['steps'][ $nested_id ] = array( 'output' => $last_output );
			}

			$iterations[] = array(
				'index' => $index,
				'item'  => $item,
				'steps' => $step_outputs,
				'last'  => $last_output,
			);
		}

		return array(
			'count'      => count( $iterations ),
			'iterations' => $iterations,
		);
	}

	/**
	 * Generate a run id when the caller didn't supply one. Prefers the
	 * WordPress UUID helper when available, falls back to a uniqid-based
	 * value otherwise.
	 *
	 * @since 0.103.0
	 */
	private static function generate_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return 'wf_' . uniqid( '', true );
	}
}
