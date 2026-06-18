<?php
/**
 * Canonical runtime package execution ability.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

const AGENTS_RUN_RUNTIME_PACKAGE_ABILITY = 'agents/run-runtime-package';

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( wp_has_ability_category( 'agents-api' ) ) {
			return;
		}

		wp_register_ability_category(
			'agents-api',
			array(
				'label'       => 'Agents API',
				'description' => 'Cross-cutting abilities provided by the Agents API substrate.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( wp_has_ability( AGENTS_RUN_RUNTIME_PACKAGE_ABILITY ) ) {
			return;
		}

		wp_register_ability(
			AGENTS_RUN_RUNTIME_PACKAGE_ABILITY,
			array(
				'label'               => 'Run Runtime Package',
				'description'         => 'Canonical entry point for running a portable agent package workflow. Dispatches to a consumer-provided runtime handler.',
				'category'            => 'agents-api',
				'input_schema'        => agents_runtime_package_run_input_schema(),
				'output_schema'       => agents_runtime_package_run_output_schema(),
				'execute_callback'    => __NAMESPACE__ . '\agents_runtime_package_run_dispatch',
				'permission_callback' => __NAMESPACE__ . '\agents_runtime_package_run_permission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}
);

/**
 * Dispatch a runtime package workflow run to a registered consumer handler.
 *
 * @param array<mixed> $input Canonical input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_runtime_package_run_dispatch( array $input ) {
	$request = WP_Agent_Runtime_Package_Run_Request::from_array( $input );
	if ( is_wp_error( $request ) ) {
		do_action( 'agents_runtime_package_run_dispatch_failed', $request->get_error_code(), $input );
		return $request;
	}

	/**
	 * Filters the runtime package execution handler.
	 *
	 * Handlers receive the value object and raw input and must return a canonical
	 * result array, WP_Agent_Runtime_Package_Run_Result, or WP_Error.
	 *
	 * @param callable|null $handler Current handler, or null.
	 * @param WP_Agent_Runtime_Package_Run_Request $request Normalized request.
	 * @param array<mixed> $input Raw ability input.
	 */
	$handler = apply_filters( 'wp_agent_runtime_package_run_handler', null, $request, $input );
	if ( ! is_callable( $handler ) ) {
		do_action( 'agents_runtime_package_run_dispatch_failed', 'no_handler', $input );
		return new \WP_Error(
			'agents_runtime_package_run_no_handler',
			'No agents/run-runtime-package handler is registered. Install a consumer runtime or add a callable to the wp_agent_runtime_package_run_handler filter.'
		);
	}

	$result = call_user_func( $handler, $request, $input );
	if ( is_wp_error( $result ) ) {
		do_action( 'agents_runtime_package_run_dispatch_failed', $result->get_error_code(), $input );
		return $result;
	}

	if ( $result instanceof WP_Agent_Runtime_Package_Run_Result ) {
		return $result->to_array();
	}

	if ( ! is_array( $result ) ) {
		do_action( 'agents_runtime_package_run_dispatch_failed', 'invalid_result', $input );
		return new \WP_Error(
			'agents_runtime_package_run_invalid_result',
			'agents/run-runtime-package handlers must return an array, WP_Agent_Runtime_Package_Run_Result, or WP_Error.'
		);
	}

	return WP_Agent_Runtime_Package_Run_Result::from_array( $result )->to_array();
}

/**
 * Permission gate for runtime package execution.
 *
 * @param array<mixed> $input Canonical input.
 */
function agents_runtime_package_run_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;

	/**
	 * Filters permission for agents/run-runtime-package.
	 *
	 * @param bool $allowed Default permission result.
	 * @param array<mixed> $input Canonical input.
	 */
	return (bool) apply_filters( 'agents_runtime_package_run_permission', $allowed, $input );
}

/** @return array<string,mixed> */
function agents_runtime_package_run_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'package', 'workflow' ),
		'properties' => array(
			'package'  => array(
				'type'        => 'object',
				'description' => 'Portable package descriptor. Use source for a path/URI or slug/id for a runtime-resolved package.',
			),
			'workflow' => array(
				'type'        => 'object',
				'description' => 'Workflow selector or inline spec. Provide id or spec.',
			),
			'input'    => array( 'type' => 'object' ),
			'options'  => array( 'type' => 'object' ),
			'metadata' => array( 'type' => 'object' ),
			'replay'   => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_runtime_package_run_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'status', 'result', 'evidence_refs' ),
		'properties' => array(
			'status'        => array(
				'type' => 'string',
				'enum' => WP_Agent_Runtime_Package_Run_Result::statuses(),
			),
			'run_id'        => array( 'type' => 'string' ),
			'result'        => array( 'type' => 'object' ),
			'error'         => array( 'type' => 'object' ),
			'evidence_refs' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'metadata'      => array( 'type' => 'object' ),
			'replay'        => array( 'type' => 'object' ),
		),
	);
}
