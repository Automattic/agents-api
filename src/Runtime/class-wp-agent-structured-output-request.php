<?php
/**
 * Structured provider-output request contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, provider-neutral JSON Schema output request.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Structured_Output_Request {

	public const FORMAT_JSON_SCHEMA = 'json_schema';

	private string $format;

	private ?string $name;

	/** @var array<string,mixed> */
	private array $schema;

	private bool $strict;

	/**
	 * @param array<string,mixed> $schema JSON Schema object.
	 */
	public function __construct( array $schema, ?string $name = null, bool $strict = true, string $format = self::FORMAT_JSON_SCHEMA ) {
		if ( self::FORMAT_JSON_SCHEMA !== $format ) {
			throw self::invalid( 'format', 'must be json_schema' );
		}
		if ( false === wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) {
			throw self::invalid( 'schema', 'must be JSON serializable' );
		}

		$name = null === $name ? null : trim( $name );
		if ( null !== $name && ( '' === $name || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name ) ) ) {
			throw self::invalid( 'name', 'must match /^[A-Za-z][A-Za-z0-9_-]{0,63}$/' );
		}

		$this->format = $format;
		$this->name   = $name;
		$this->schema = $schema;
		$this->strict = $strict;
	}

	/**
	 * @param mixed $value Raw request configuration.
	 */
	public static function from_array( $value ): self {
		if ( ! is_array( $value ) ) {
			throw self::invalid( 'structured_output', 'must be an array' );
		}
		if ( ! array_key_exists( 'schema', $value ) || ! is_array( $value['schema'] ) ) {
			throw self::invalid( 'schema', 'must be an array' );
		}
		if ( array_key_exists( 'name', $value ) && ! is_string( $value['name'] ) && null !== $value['name'] ) {
			throw self::invalid( 'name', 'must be a string or null' );
		}
		if ( array_key_exists( 'strict', $value ) && ! is_bool( $value['strict'] ) ) {
			throw self::invalid( 'strict', 'must be a boolean' );
		}
		if ( array_key_exists( 'format', $value ) && ! is_string( $value['format'] ) ) {
			throw self::invalid( 'format', 'must be a string' );
		}

		$schema = array();
		foreach ( $value['schema'] as $key => $item ) {
			if ( is_string( $key ) ) {
				$schema[ $key ] = $item;
			}
		}
		$name   = isset( $value['name'] ) && is_string( $value['name'] ) ? $value['name'] : null;
		$strict = isset( $value['strict'] ) && is_bool( $value['strict'] ) ? $value['strict'] : true;
		$format = isset( $value['format'] ) && is_string( $value['format'] ) ? $value['format'] : self::FORMAT_JSON_SCHEMA;

		return new self( $schema, $name, $strict, $format );
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		$result = array(
			'format' => $this->format,
			'schema' => $this->schema,
			'strict' => $this->strict,
		);
		if ( null !== $this->name ) {
			$result['name'] = $this->name;
		}
		return $result;
	}

	/** @return array<string,mixed> */
	public function schema(): array { return $this->schema; }

	private static function invalid( string $path, string $reason ): \InvalidArgumentException {
		return new \InvalidArgumentException( 'invalid_agent_structured_output_request: ' . $path . ' ' . $reason );
	}
}
