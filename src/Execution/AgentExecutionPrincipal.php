<?php
/**
 * Generic agent execution principal contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Execution;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object for the identity behind one agent execution request.
 */
final class AgentExecutionPrincipal {

	public const SCHEMA  = 'agents-api.execution-principal';
	public const VERSION = 1;

	public const AUTH_SOURCE_USER_SESSION = 'user_session';
	public const AUTH_SOURCE_BEARER_TOKEN = 'bearer_token';
	public const AUTH_SOURCE_REST         = 'rest';
	public const AUTH_SOURCE_CLI          = 'cli';
	public const AUTH_SOURCE_CRON         = 'cron';
	public const AUTH_SOURCE_SYSTEM       = 'system';

	/**
	 * @param int         $acting_user_id     WordPress user ID acting on the request. 0 = no local user.
	 * @param int         $effective_agent_id Effective agent ID for the execution. 0 = unresolved / host-defined.
	 * @param string      $auth_source        Credential source identifier.
	 * @param string|null $token_id           Optional stable token identifier, never the token secret.
	 * @param string      $request_context    Host-defined context such as rest, cli, cron, chat, or scheduled_work.
	 */
	public function __construct(
		public readonly int $acting_user_id,
		public readonly int $effective_agent_id,
		public readonly string $auth_source,
		public readonly ?string $token_id = null,
		public readonly string $request_context = '',
	) {
		if ( $this->acting_user_id < 0 ) {
			throw new \InvalidArgumentException( 'invalid_agent_execution_principal: acting_user_id must be non-negative' );
		}

		if ( $this->effective_agent_id < 0 ) {
			throw new \InvalidArgumentException( 'invalid_agent_execution_principal: effective_agent_id must be non-negative' );
		}

		if ( '' === $this->auth_source ) {
			throw new \InvalidArgumentException( 'invalid_agent_execution_principal: auth_source must be non-empty' );
		}
	}

	/**
	 * Build a principal from a serialized array shape.
	 *
	 * @param array<string, mixed> $value Serialized principal.
	 * @return self
	 */
	public static function from_array( array $value ): self {
		return new self(
			(int) ( $value['acting_user_id'] ?? 0 ),
			(int) ( $value['effective_agent_id'] ?? 0 ),
			(string) ( $value['auth_source'] ?? '' ),
			self::normalize_optional_string( $value['token_id'] ?? null ),
			(string) ( $value['request_context'] ?? '' )
		);
	}

	/**
	 * Return the stable JSON-friendly shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema'             => self::SCHEMA,
			'version'            => self::VERSION,
			'acting_user_id'     => $this->acting_user_id,
			'effective_agent_id' => $this->effective_agent_id,
			'auth_source'        => $this->auth_source,
			'token_id'           => $this->token_id,
			'request_context'    => $this->request_context,
		);
	}

	/**
	 * Stable string key for cache and log correlation.
	 *
	 * @return string
	 */
	public function key(): string {
		return sprintf(
			'%d:%d:%s:%s:%s',
			$this->acting_user_id,
			$this->effective_agent_id,
			$this->auth_source,
			$this->token_id ?? '',
			$this->request_context
		);
	}

	/**
	 * Normalize optional strings without leaking empty strings as token IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function normalize_optional_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = (string) $value;
		return '' === $value ? null : $value;
	}
}
