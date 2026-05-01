<?php
/**
 * Tool parameter normalization helpers.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the final parameter array passed to a tool executor.
 */
class ToolParameters {

	/**
	 * Merge caller context with runtime/client-provided tool parameters.
	 *
	 * Runtime parameters win over context keys so tool calls can override optional
	 * defaults supplied by the host runtime. The tool declaration is accepted for
	 * future schema-aware normalization without forcing a runtime dependency now.
	 *
	 * @param array $tool_parameters Runtime tool-call parameters.
	 * @param array $context         Host runtime context for this invocation.
	 * @param array $tool_definition Normalized tool declaration.
	 * @return array Complete parameters for execution.
	 */
	public static function buildParameters( array $tool_parameters, array $context = array(), array $tool_definition = array() ): array {
		unset( $tool_definition );

		$parameters = $context;
		foreach ( $tool_parameters as $key => $value ) {
			if ( ! is_string( $key ) && ! is_int( $key ) ) {
				continue;
			}

			$parameters[ $key ] = $value;
		}

		return $parameters;
	}

	/**
	 * Validate required parameters declared by a tool definition.
	 *
	 * Supports both the compact Agents API shape (`required` as a list of names)
	 * and legacy per-property `required => true` flags.
	 *
	 * @param array $tool_parameters Runtime tool-call parameters.
	 * @param array $tool_definition Normalized tool declaration.
	 * @return array{valid: bool, required: array<int, string>, missing: array<int, string>}
	 */
	public static function validateRequiredParameters( array $tool_parameters, array $tool_definition ): array {
		$required = self::requiredParameterNames( $tool_definition );
		$missing  = array();

		foreach ( $required as $parameter_name ) {
			if ( ! array_key_exists( $parameter_name, $tool_parameters ) || '' === $tool_parameters[ $parameter_name ] || null === $tool_parameters[ $parameter_name ] ) {
				$missing[] = $parameter_name;
			}
		}

		return array(
			'valid'    => empty( $missing ),
			'required' => $required,
			'missing'  => $missing,
		);
	}

	/**
	 * Extract required parameter names from known declaration shapes.
	 *
	 * @param array $tool_definition Normalized tool declaration.
	 * @return array<int, string>
	 */
	private static function requiredParameterNames( array $tool_definition ): array {
		$parameters = $tool_definition['parameters'] ?? array();
		if ( ! is_array( $parameters ) ) {
			return array();
		}

		$required = array();
		if ( isset( $parameters['required'] ) && is_array( $parameters['required'] ) ) {
			foreach ( $parameters['required'] as $parameter_name ) {
				if ( is_string( $parameter_name ) && '' !== $parameter_name ) {
					$required[] = $parameter_name;
				}
			}
		}

		$properties = $parameters['properties'] ?? $parameters;
		if ( is_array( $properties ) ) {
			foreach ( $properties as $parameter_name => $parameter_config ) {
				if ( is_string( $parameter_name ) && is_array( $parameter_config ) && ! empty( $parameter_config['required'] ) ) {
					$required[] = $parameter_name;
				}
			}
		}

		return array_values( array_unique( $required ) );
	}
}
