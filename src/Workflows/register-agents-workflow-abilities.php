<?php
/**
 * Canonical workflow ability registrations.
 *
 * Three abilities, all dispatchers — agents-api itself ships no runner;
 * consumers register a runtime via the `wp_agent_workflow_handler` filter:
 *
 *   - `agents/run-workflow`      — execute a workflow by id (or inline spec).
 *   - `agents/validate-workflow` — structural validate (no DB / runtime touch).
 *   - `agents/describe-workflow` — return the registered spec + input schema.
 *
 * The dispatcher mirrors `agents/chat` (#100) so the two contracts are
 * familiar to consumers: validate input, look up a registered handler,
 * call it, fire observability hooks on failure.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

const AGENTS_RUN_WORKFLOW_ABILITY      = 'agents/run-workflow';
const AGENTS_VALIDATE_WORKFLOW_ABILITY = 'agents/validate-workflow';
const AGENTS_DESCRIBE_WORKFLOW_ABILITY = 'agents/describe-workflow';

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'agents-api',
			array(
				'label'       => 'Agents API',
				'description' => 'Cross-cutting abilities provided by the Agents API substrate (channel dispatch, canonical chat contract, workflow dispatch, future runtime resolvers).',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			AGENTS_RUN_WORKFLOW_ABILITY,
			array(
				'label'               => 'Run Workflow',
				'description'         => 'Canonical entry point for running a registered workflow. Dispatches to whichever runtime is registered via the wp_agent_workflow_handler filter.',
				'category'            => 'agents-api',
				'input_schema'        => agents_run_workflow_input_schema(),
				'output_schema'       => agents_run_workflow_output_schema(),
				'execute_callback'    => __NAMESPACE__ . '\\agents_run_workflow_dispatch',
				'permission_callback' => __NAMESPACE__ . '\\agents_run_workflow_permission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);

		wp_register_ability(
			AGENTS_VALIDATE_WORKFLOW_ABILITY,
			array(
				'label'               => 'Validate Workflow Spec',
				'description'         => 'Structural validation of a workflow spec. Returns a list of structured errors (or an empty list when valid). Does not touch any runtime or storage.',
				'category'            => 'agents-api',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'spec' ),
					'properties' => array(
						'spec' => array(
							'type'        => 'object',
							'description' => 'Raw workflow spec to validate.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'valid', 'errors' ),
					'properties' => array(
						'valid'  => array( 'type' => 'boolean' ),
						'errors' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'path'    => array( 'type' => 'string' ),
									'code'    => array( 'type' => 'string' ),
									'message' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\\agents_validate_workflow',
				'permission_callback' => __NAMESPACE__ . '\\agents_run_workflow_permission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'idempotent' => true ),
				),
			)
		);

		wp_register_ability(
			AGENTS_DESCRIBE_WORKFLOW_ABILITY,
			array(
				'label'               => 'Describe Workflow',
				'description'         => 'Return a registered workflow spec along with its input declarations. Useful for callers that want to enumerate or render workflows without executing them.',
				'category'            => 'agents-api',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'workflow_id' ),
					'properties' => array(
						'workflow_id' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'spec'   => array( 'type' => array( 'object', 'null' ) ),
						'inputs' => array( 'type' => array( 'object', 'null' ) ),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\\agents_describe_workflow',
				'permission_callback' => __NAMESPACE__ . '\\agents_run_workflow_permission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'idempotent' => true ),
				),
			)
		);
	}
);

/**
 * Dispatch a workflow run to the registered runtime.
 *
 * @since  0.103.0
 *
 * @param  array $input Canonical run-workflow input.
 * @return array|\WP_Error Canonical output, or WP_Error if no runtime is registered.
 */
function agents_run_workflow_dispatch( array $input ) {
	/**
	 * Filter the workflow runtime handler.
	 *
	 * Consumers register a callable that accepts the canonical input array
	 * and returns either the canonical output or WP_Error. The first hook
	 * to return a callable wins.
	 *
	 * @since 0.103.0
	 *
	 * @param callable|null $handler Currently registered handler. Null when
	 *                               no runtime has registered.
	 * @param array         $input   The canonical input being dispatched.
	 */
	$handler = apply_filters( 'wp_agent_workflow_handler', null, $input );

	if ( ! is_callable( $handler ) ) {
		/**
		 * Fires when agents/run-workflow dispatched but no handler was
		 * registered. Use for sysadmin-side observability.
		 *
		 * @since 0.103.0
		 *
		 * @param string $reason Always `'no_handler'` for this branch.
		 * @param array  $input  The canonical input that was rejected.
		 */
		do_action( 'agents_run_workflow_dispatch_failed', 'no_handler', $input );

		return new \WP_Error(
			'agents_run_workflow_no_handler',
			'No agents/run-workflow handler is registered. Install a consumer plugin that registers a runtime, or add a callable to the wp_agent_workflow_handler filter.'
		);
	}

	$result = call_user_func( $handler, $input );

	if ( is_wp_error( $result ) ) {
		/** This action is documented above. */
		do_action( 'agents_run_workflow_dispatch_failed', $result->get_error_code(), $input );
		return $result;
	}

	if ( ! is_array( $result ) ) {
		/** This action is documented above. */
		do_action( 'agents_run_workflow_dispatch_failed', 'invalid_result', $input );
		return new \WP_Error(
			'agents_run_workflow_invalid_result',
			'agents/run-workflow handler returned an unexpected result type. Handlers must return an array matching the canonical output shape or a WP_Error.'
		);
	}

	return $result;
}

/**
 * `agents/validate-workflow` execute callback. Pure substrate — no
 * runtime hookup needed.
 *
 * @since  0.103.0
 *
 * @param  array $input
 * @return array
 */
function agents_validate_workflow( array $input ): array {
	$errors = WP_Agent_Workflow_Spec_Validator::validate( (array) ( $input['spec'] ?? array() ) );
	return array(
		'valid'  => empty( $errors ),
		'errors' => $errors,
	);
}

/**
 * `agents/describe-workflow` execute callback. Reads the in-memory
 * registry only — Store-backed workflows are described by their consumer.
 *
 * @since  0.103.0
 *
 * @param  array $input
 * @return array
 */
function agents_describe_workflow( array $input ): array {
	$workflow_id = (string) ( $input['workflow_id'] ?? '' );
	$spec        = WP_Agent_Workflow_Registry::find( $workflow_id );
	if ( null === $spec ) {
		return array( 'spec' => null, 'inputs' => null );
	}
	return array(
		'spec'   => $spec->to_array(),
		'inputs' => $spec->get_inputs(),
	);
}

/**
 * Permission gate for the workflow abilities. Same default as
 * `agents/chat`: `manage_options`. Consumers with their own auth model
 * (HMAC-signed webhook, OAuth bearer, scheduled action) widen via the
 * `agents_run_workflow_permission` filter.
 *
 * @since 0.103.0
 *
 * @param array $input Canonical input.
 * @return bool
 */
function agents_run_workflow_permission( array $input ): bool {
	/**
	 * Filter the permission decision for the canonical workflow abilities.
	 *
	 * @since 0.103.0
	 *
	 * @param bool  $allowed Default: current_user_can( 'manage_options' ).
	 * @param array $input   The canonical input being authorized.
	 */
	return (bool) apply_filters(
		'agents_run_workflow_permission',
		current_user_can( 'manage_options' ),
		$input
	);
}

/**
 * Canonical input schema for `agents/run-workflow`.
 *
 * @since  0.103.0
 *
 * @return array
 */
function agents_run_workflow_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'workflow_id' => array(
				'type'        => array( 'string', 'null' ),
				'description' => 'Id of a registered or stored workflow to run. Pass `null` to run an inline `spec` instead.',
			),
			'spec'        => array(
				'type'        => array( 'object', 'null' ),
				'description' => 'Inline workflow spec to run. Use when the workflow is not (yet) persisted. Either `workflow_id` or `spec` must be provided.',
			),
			'inputs'      => array(
				'type'        => 'object',
				'description' => 'Map of input_name => value supplied to the workflow. Required inputs missing here cause an early failure.',
				'default'     => array(),
			),
			'options'     => array(
				'type'        => 'object',
				'description' => 'Runtime options forwarded to the runner. Recognized keys: run_id, continue_on_error, metadata.',
				'default'     => array(),
			),
		),
	);
}

/**
 * Canonical output schema for `agents/run-workflow`.
 *
 * @since  0.103.0
 *
 * @return array
 */
function agents_run_workflow_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id', 'workflow_id', 'status' ),
		'properties' => array(
			'run_id'      => array( 'type' => 'string' ),
			'workflow_id' => array( 'type' => 'string' ),
			'status'      => array(
				'type' => 'string',
				'enum' => array( 'pending', 'running', 'succeeded', 'failed', 'skipped' ),
			),
			'output'      => array( 'type' => 'object' ),
			'steps'       => array( 'type' => 'array' ),
			'error'       => array( 'type' => array( 'object', 'null' ) ),
			'started_at'  => array( 'type' => 'integer' ),
			'ended_at'    => array( 'type' => 'integer' ),
			'metadata'    => array( 'type' => 'object' ),
		),
	);
}

/**
 * Convenience helper for consumers: register a callable as the workflow
 * runtime handler.
 *
 * @since 0.103.0
 *
 * @param callable $handler  Receives the canonical input array, returns the
 *                           canonical output array or WP_Error.
 * @param int      $priority Filter priority. Default 10.
 */
function register_workflow_handler( callable $handler, int $priority = 10 ): void {
	add_filter(
		'wp_agent_workflow_handler',
		static function ( $existing, array $input ) use ( $handler ) {
			unset( $input );
			if ( null !== $existing ) {
				return $existing;
			}
			return $handler;
		},
		$priority,
		2
	);
}
