<?php
/**
 * Provider-turn result contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalizes provider-turn adapter results.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Provider_Turn_Result {

	/**
	 * Normalize provider-turn adapter output.
	 *
	 * The adapter reports one assistant turn only. The conversation loop owns
	 * continuation, mediated tool execution, transcript events, and final result
	 * assembly.
	 *
	 * @param array<mixed> $result Raw provider-turn adapter result.
	 * @return array<string, mixed> Normalized provider-turn result.
	 */
	public static function normalize( array $result ): array {
		$normalized = array(
			'content'              => self::string_value( $result['content'] ?? '' ),
			'message'              => null,
			'tool_calls'           => array(),
			'usage'                => self::assoc_array( $result['usage'] ?? array(), 'usage' ),
			'request_metadata'     => self::assoc_array( $result['request_metadata'] ?? array(), 'request_metadata' ),
			'provider_diagnostics' => self::assoc_array( $result['provider_diagnostics'] ?? array(), 'provider_diagnostics' ),
		);

		if ( isset( $result['message'] ) ) {
			if ( ! is_array( $result['message'] ) ) {
				throw self::invalid( 'message', 'must be an array when present' );
			}
			$normalized['message'] = WP_Agent_Message::normalize( $result['message'] );
		}

		if ( isset( $result['tool_calls'] ) ) {
			if ( ! is_array( $result['tool_calls'] ) ) {
				throw self::invalid( 'tool_calls', 'must be an array when present' );
			}
			$normalized['tool_calls'] = self::normalize_tool_calls( $result['tool_calls'] );
		}

		if ( isset( $result['failure'] ) ) {
			if ( ! is_array( $result['failure'] ) ) {
				throw self::invalid( 'failure', 'must be an array when present' );
			}
			$failure = self::assoc_array( $result['failure'], 'failure' );
			foreach ( array( 'type', 'message' ) as $field ) {
				if ( ! isset( $failure[ $field ] ) || ! is_string( $failure[ $field ] ) || '' === $failure[ $field ] ) {
					throw self::invalid( 'failure.' . $field, 'must be a non-empty string' );
				}
			}
			$normalized['failure'] = $failure;
		}

		return $normalized;
	}

	/**
	 * Normalize provider tool calls.
	 *
	 * @param array<mixed> $tool_calls Raw tool calls.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_tool_calls( array $tool_calls ): array {
		$normalized = array();
		foreach ( $tool_calls as $index => $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				throw self::invalid( 'tool_calls[' . $index . ']', 'must be an array' );
			}

			$tool_call = self::assoc_array( $tool_call, 'tool_calls[' . $index . ']' );
			$name      = $tool_call['name'] ?? $tool_call['tool_name'] ?? '';
			if ( ! is_string( $name ) || '' === $name ) {
				throw self::invalid( 'tool_calls[' . $index . '].name', 'must be a non-empty string' );
			}

			$parameters = $tool_call['parameters'] ?? array();
			if ( ! is_array( $parameters ) ) {
				throw self::invalid( 'tool_calls[' . $index . '].parameters', 'must be an array when present' );
			}

			$normalized_call = array(
				'name'       => $name,
				'parameters' => self::assoc_array( $parameters, 'tool_calls[' . $index . '].parameters' ),
			);

			if ( isset( $tool_call['id'] ) && is_string( $tool_call['id'] ) && '' !== $tool_call['id'] ) {
				$normalized_call['id'] = $tool_call['id'];
			}

			if ( isset( $tool_call['metadata'] ) && is_array( $tool_call['metadata'] ) ) {
				$normalized_call['metadata'] = self::assoc_array( $tool_call['metadata'], 'tool_calls[' . $index . '].metadata' );
			}

			$normalized[] = $normalized_call;
		}

		return $normalized;
	}

	/**
	 * Normalize an associative array.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $path  Field path.
	 * @return array<string, mixed>
	 */
	private static function assoc_array( $value, string $path ): array {
		if ( ! is_array( $value ) ) {
			throw self::invalid( $path, 'must be an array' );
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		if ( false === wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) {
			throw self::invalid( $path, 'must be JSON serializable' );
		}

		return $normalized;
	}

	/**
	 * Return a string value or an empty string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function string_value( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Build a machine-readable validation exception.
	 *
	 * @param string $path Field path.
	 * @param string $reason Failure reason.
	 * @return \InvalidArgumentException Validation exception.
	 */
	private static function invalid( string $path, string $reason ): \InvalidArgumentException {
		return new \InvalidArgumentException( 'invalid_agent_provider_turn_result: ' . $path . ' ' . $reason );
	}
}
