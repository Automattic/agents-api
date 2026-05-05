<?php
/**
 * Tool call normalizer.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes runtime tool calls into a stable JSON-friendly shape.
 */
class WP_Agent_Tool_Call {

	/**
	 * Normalize a runtime tool call.
	 *
	 * @param array $tool_call Raw tool call.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $tool_call ): array {
		$tool_name = $tool_call['tool_name'] ?? $tool_call['name'] ?? '';
		if ( ! is_string( $tool_name ) || '' === $tool_name ) {
			throw new \InvalidArgumentException( 'invalid_tool_call: tool_name must be a non-empty string' );
		}

		$parameters = $tool_call['parameters'] ?? array();
		if ( ! is_array( $parameters ) ) {
			throw new \InvalidArgumentException( 'invalid_tool_call: parameters must be an array' );
		}

		$metadata = $tool_call['metadata'] ?? array();
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		return array(
			'tool_name'  => $tool_name,
			'parameters' => $parameters,
			'metadata'   => $metadata,
		);
	}
}
