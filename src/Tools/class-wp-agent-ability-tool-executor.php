<?php
/**
 * Ability-backed tool executor.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Executes host-owned tool calls through the WordPress Abilities API.
 */
class WP_Agent_Ability_Tool_Executor implements WP_Agent_Tool_Executor {

	/**
	 * Execute a prepared tool call by invoking its mapped ability.
	 *
	 * Tool declarations may specify `ability` or `ability_name` when the model-facing
	 * tool name differs from the registered ability name. Otherwise the tool name is
	 * used directly as the ability name.
	 *
	 * @param array<mixed> $tool_call       Normalized prepared tool call.
	 * @param array<mixed> $tool_definition Tool declaration selected for the call.
	 * @param array<mixed> $context         Host runtime context for this invocation.
	 * @return array<mixed> Normalized tool execution result.
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $context );

		$tool_call    = WP_Agent_Tool_Call::normalize( $tool_call );
		$tool_name    = is_string( $tool_call['tool_name'] ?? null ) ? $tool_call['tool_name'] : '';
		$ability_name = $this->ability_name( $tool_call, $tool_definition );

		if ( '' === $ability_name ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				'Tool declaration does not identify an ability.',
				array( 'error_type' => 'ability_name_missing' )
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				'WordPress Abilities API is not available.',
				array(
					'ability_name' => $ability_name,
					'error_type'   => 'abilities_api_unavailable',
				)
			);
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability instanceof \WP_Ability ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				'Ability is not registered.',
				array(
					'ability_name' => $ability_name,
					'error_type'   => 'ability_not_found',
				)
			);
		}

		$result = $ability->execute( $tool_call['parameters'] );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				$this->wp_error_message( $result ),
				array(
					'ability_name' => $ability_name,
					'error_code'   => $result->get_error_code(),
					'error_type'   => 'ability_error',
				)
			);
		}

		return WP_Agent_Tool_Result::success(
			$tool_name,
			$result,
			array( 'ability_name' => $ability_name )
		);
	}

	/**
	 * Resolve the registered ability name for a tool call.
	 *
	 * @param array<mixed> $tool_call       Normalized prepared tool call.
	 * @param array<mixed> $tool_definition Tool declaration selected for the call.
	 * @return string Ability name.
	 */
	private function ability_name( array $tool_call, array $tool_definition ): string {
		$metadata = isset( $tool_call['metadata'] ) && is_array( $tool_call['metadata'] ) ? $tool_call['metadata'] : array();

		$candidates = array(
			$tool_definition['ability'] ?? null,
			$tool_definition['ability_name'] ?? null,
			$metadata['ability_name'] ?? null,
			$tool_call['tool_name'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}

		return '';
	}

	/**
	 * Return a human-readable WP_Error message without requiring full WP stubs.
	 *
	 * @param mixed $error WP_Error-like value.
	 * @return string Error message.
	 */
	private function wp_error_message( $error ): string {
		if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
			$message = $error->get_error_message();
			if ( is_string( $message ) && '' !== $message ) {
				return $message;
			}
		}

		return 'Ability execution failed.';
	}
}
