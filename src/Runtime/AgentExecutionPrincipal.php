<?php
/**
 * Agent execution principal context.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable identity context for one agent execution.
 *
 * This class records who is acting, which agent is effective for the run, and
 * how the request was authenticated. It intentionally does not decide access,
 * grant scoped resources, or persist tokens.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
final class AgentExecutionPrincipal {

	public const AUTH_SOURCE_USER                 = 'user';
	public const AUTH_SOURCE_APPLICATION_PASSWORD = 'application_password';
	public const AUTH_SOURCE_AGENT_TOKEN          = 'agent_token';
	public const AUTH_SOURCE_SYSTEM               = 'system';

	/**
	 * @param int         $acting_user_id       WordPress user ID on whose behalf the run executes. 0 = system/anonymous context.
	 * @param string      $effective_agent_slug Registered agent slug effective for the run.
	 * @param string      $auth_source          Authentication source identifier.
	 * @param int|null    $token_id             Optional caller-owned token identifier. Agents API does not load or store the token.
	 * @param array       $request_metadata     JSON-serializable request metadata supplied by the caller.
	 */
	public function __construct(
		public readonly int $acting_user_id,
		public readonly string $effective_agent_slug,
		public readonly string $auth_source,
		public readonly ?int $token_id = null,
		public readonly array $request_metadata = array(),
	) {
		if ( $this->acting_user_id < 0 ) {
			throw self::invalid( 'acting_user_id', 'must be zero or a positive integer' );
		}

		if ( '' === $this->effective_agent_slug ) {
			throw self::invalid( 'effective_agent_slug', 'must be a non-empty string' );
		}

		if ( '' === $this->auth_source ) {
			throw self::invalid( 'auth_source', 'must be a non-empty string' );
		}

		if ( null !== $this->token_id && $this->token_id <= 0 ) {
			throw self::invalid( 'token_id', 'must be null or a positive integer' );
		}

		if ( false === self::jsonEncode( $this->request_metadata ) ) {
			throw self::invalid( 'request_metadata', 'must be JSON serializable' );
		}
	}

	/**
	 * Build a principal from a request/context array.
	 *
	 * @param array $principal Raw principal fields.
	 * @return self
	 */
	public static function from_array( array $principal ): self {
		return new self(
			isset( $principal['acting_user_id'] ) ? (int) $principal['acting_user_id'] : 0,
			isset( $principal['effective_agent_slug'] ) ? (string) $principal['effective_agent_slug'] : '',
			isset( $principal['auth_source'] ) ? (string) $principal['auth_source'] : '',
			isset( $principal['token_id'] ) ? (int) $principal['token_id'] : null,
			isset( $principal['request_metadata'] ) && is_array( $principal['request_metadata'] ) ? $principal['request_metadata'] : array()
		);
	}

	/**
	 * Export the principal to a stable, JSON-friendly shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'acting_user_id'       => $this->acting_user_id,
			'effective_agent_slug' => $this->effective_agent_slug,
			'auth_source'          => $this->auth_source,
			'token_id'             => $this->token_id,
			'request_metadata'     => $this->request_metadata,
		);
	}

	/**
	 * Return a copy with additional request metadata.
	 *
	 * @param array $request_metadata Replacement request metadata.
	 * @return self
	 */
	public function with_request_metadata( array $request_metadata ): self {
		return new self(
			$this->acting_user_id,
			$this->effective_agent_slug,
			$this->auth_source,
			$this->token_id,
			$request_metadata
		);
	}

	/**
	 * Encode JSON without throwing on older PHP configurations.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|false
	 */
	private static function jsonEncode( $value ) {
		try {
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return false;
		}
	}

	/**
	 * Build a machine-readable validation exception.
	 *
	 * @param string $path Field path.
	 * @param string $reason Failure reason.
	 * @return \InvalidArgumentException Validation exception.
	 */
	private static function invalid( string $path, string $reason ): \InvalidArgumentException {
		return new \InvalidArgumentException( 'invalid_agent_execution_principal: ' . $path . ' ' . $reason );
	}
}
