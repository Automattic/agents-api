<?php
/**
 * Tool execution result normalizer.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes tool execution results to a stable JSON-friendly shape.
 */
class WP_Agent_Tool_Result {

	/**
	 * Build a successful result.
	 *
	 * @param string $tool_name  Tool identifier.
	 * @param mixed  $result     Executor result payload.
	 * @param array  $metadata   Optional result metadata.
	 * @return array<string, mixed>
	 */
	public static function success( string $tool_name, $result, array $metadata = array() ): array {
		return self::normalize(
			array(
				'success'   => true,
				'tool_name' => $tool_name,
				'result'    => $result,
				'metadata'  => $metadata,
			)
		);
	}

	/**
	 * Build an error result.
	 *
	 * @param string $tool_name Tool identifier.
	 * @param string $error     Human-readable error.
	 * @param array  $metadata  Optional result metadata.
	 * @return array<string, mixed>
	 */
	public static function error( string $tool_name, string $error, array $metadata = array() ): array {
		return self::normalize(
			array(
				'success'   => false,
				'tool_name' => $tool_name,
				'error'     => $error,
				'metadata'  => $metadata,
			)
		);
	}

	/**
	 * Normalize arbitrary executor output.
	 *
	 * @param array $result Raw result.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $result ): array {
		$tool_name = $result['tool_name'] ?? '';
		if ( ! is_string( $tool_name ) || '' === $tool_name ) {
			throw new \InvalidArgumentException( 'invalid_tool_execution_result: tool_name must be a non-empty string' );
		}

		$success = (bool) ( $result['success'] ?? false );
		$metadata = $result['metadata'] ?? array();
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$normalized = array(
			'success'   => $success,
			'tool_name' => $tool_name,
			'metadata'  => $metadata,
		);

		if ( $success ) {
			$normalized['result'] = $result['result'] ?? array();
			return $normalized;
		}

		$error = $result['error'] ?? 'Tool execution failed.';
		$normalized['error'] = is_string( $error ) && '' !== $error ? $error : 'Tool execution failed.';

		return $normalized;
	}
}
