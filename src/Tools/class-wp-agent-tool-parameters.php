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
class WP_Agent_Tool_Parameters {

	/**
	 * Merge declared client-context bindings with runtime tool parameters.
	 *
	 * Caller-supplied parameters always win. Values from `$context` are only
	 * pulled in for parameter slots that the tool declaration explicitly opts
	 * into via `client_context_bindings`. This keeps sensitive parameters
	 * auditable: a context key can never silently satisfy a required tool
	 * argument the tool author didn't expect to come from context.
	 *
	 * Two declaration shapes are accepted:
	 *
	 *   - Associative: `[ 'user_phone' => 'sender_id' ]` — pull
	 *     `context['sender_id']` into the `user_phone` parameter slot.
	 *   - Flat list:   `[ 'sender_id', 'connector_id' ]`  — same-name
	 *     binding, equivalent to `[ 'sender_id' => 'sender_id', ... ]`.
	 *
	 * Empty / null context values are ignored so they don't silently
	 * satisfy a required-parameter check.
	 *
	 * @param array $tool_parameters Runtime tool-call parameters.
	 * @param array $context         Host runtime context for this invocation.
	 * @param array $tool_definition Normalized tool declaration.
	 * @return array Complete parameters for execution.
	 */
	public static function buildParameters( array $tool_parameters, array $context = array(), array $tool_definition = array() ): array {
		$parameters = array();

		foreach ( self::clientContextBindings( $tool_definition ) as $parameter_name => $context_key ) {
			if ( ! array_key_exists( $context_key, $context ) ) {
				continue;
			}
			$value = $context[ $context_key ];
			if ( '' === $value || null === $value ) {
				continue;
			}
			$parameters[ $parameter_name ] = $value;
		}

		foreach ( $tool_parameters as $key => $value ) {
			$parameters[ $key ] = $value;
		}

		return $parameters;
	}

	/**
	 * Normalize the tool's `client_context_bindings` declaration into a
	 * `parameter_name => context_key` map. Skips malformed entries silently
	 * — the declaration is best-effort metadata, not validated input.
	 *
	 * @param array $tool_definition Normalized tool declaration.
	 * @return array<string, string>
	 */
	private static function clientContextBindings( array $tool_definition ): array {
		$bindings = $tool_definition['client_context_bindings'] ?? array();
		if ( ! is_array( $bindings ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $bindings as $parameter_name => $context_key ) {
			if ( is_int( $parameter_name ) && is_string( $context_key ) && '' !== $context_key ) {
				$normalized[ $context_key ] = $context_key;
				continue;
			}
			if ( is_string( $parameter_name ) && '' !== $parameter_name && is_string( $context_key ) && '' !== $context_key ) {
				$normalized[ $parameter_name ] = $context_key;
			}
		}

		return $normalized;
	}

	/**
	 * Validate required parameters declared by a tool definition.
	 *
	 * Supports both the compact Agents API shape (`required` as a list of names)
	 * and per-property `required => true` flags.
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
