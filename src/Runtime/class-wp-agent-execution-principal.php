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
 * This class records who is acting, which agent is effective for the run, which
 * workspace/client scope applies, and how the request was authenticated. It
 * intentionally does not decide access, grant scoped resources, or persist tokens.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
final class WP_Agent_Execution_Principal {

	public const AUTH_SOURCE_USER                 = 'user';
	public const AUTH_SOURCE_APPLICATION_PASSWORD = 'application_password';
	public const AUTH_SOURCE_AGENT_TOKEN          = 'agent_token';
	public const AUTH_SOURCE_AUDIENCE             = 'audience';
	public const AUTH_SOURCE_SYSTEM               = 'system';

	public const OWNER_TYPE_USER     = 'user';
	public const OWNER_TYPE_AUDIENCE = 'audience';
	public const OWNER_TYPE_TOKEN    = 'token';
	public const OWNER_TYPE_SYSTEM   = 'system';

	public const REQUEST_CONTEXT_REST = 'rest';
	public const REQUEST_CONTEXT_CLI  = 'cli';
	public const REQUEST_CONTEXT_CRON = 'cron';
	public const REQUEST_CONTEXT_CHAT = 'chat';

	/**
	 * @param int         $acting_user_id    WordPress user ID on whose behalf the run executes. 0 = system/anonymous context.
	 * @param string      $effective_agent_id Registered agent ID/slug effective for the run.
	 * @param string      $auth_source       Authentication source identifier.
	 * @param string      $request_context   Request context such as rest, cli, cron, or chat.
	 * @param int|null    $token_id          Optional caller-owned token identifier. Agents API does not load or store the token.
	 * @param array       $request_metadata  JSON-serializable request metadata supplied by the caller.
	 * @param string|null $workspace_id      Optional host workspace/scope identifier.
	 * @param string|null $client_id         Optional client/login identifier.
	 * @param \WP_Agent_Capability_Ceiling|null $capability_ceiling Optional capability ceiling for this execution.
	 * @param \WP_Agent_Caller_Context|null     $caller_context     Optional cross-site caller context claims.
	 * @param string|null                       $audience_id        Optional non-user audience/principal identifier.
	 * @param array<string,mixed>               $audience_claims    Optional host-owned audience claims.
	 * @param string|null                       $owner_type         Optional canonical transcript owner type.
	 * @param string|null                       $owner_key          Optional opaque transcript owner key scoped to the owner type.
	 */
	public function __construct(
		public readonly int $acting_user_id,
		public readonly string $effective_agent_id,
		public readonly string $auth_source,
		public readonly string $request_context,
		public readonly ?int $token_id = null,
		public readonly array $request_metadata = array(),
		public readonly ?string $workspace_id = null,
		public readonly ?string $client_id = null,
		public readonly ?\WP_Agent_Capability_Ceiling $capability_ceiling = null,
		public readonly ?\WP_Agent_Caller_Context $caller_context = null,
		public readonly ?string $audience_id = null,
		public readonly array $audience_claims = array(),
		public readonly ?string $owner_type = null,
		public readonly ?string $owner_key = null,
	) {
		if ( $this->acting_user_id < 0 ) {
			throw self::invalid( 'acting_user_id', 'must be zero or a positive integer' );
		}

		if ( '' === $this->effective_agent_id ) {
			throw self::invalid( 'effective_agent_id', 'must be a non-empty string' );
		}

		if ( '' === $this->auth_source ) {
			throw self::invalid( 'auth_source', 'must be a non-empty string' );
		}

		if ( '' === $this->request_context ) {
			throw self::invalid( 'request_context', 'must be a non-empty string' );
		}

		if ( null !== $this->token_id && $this->token_id <= 0 ) {
			throw self::invalid( 'token_id', 'must be null or a positive integer' );
		}

		if ( null !== $this->audience_id && '' === trim( $this->audience_id ) ) {
			throw self::invalid( 'audience_id', 'must be null or a non-empty string' );
		}

		if ( false === self::jsonEncode( $this->request_metadata ) ) {
			throw self::invalid( 'request_metadata', 'must be JSON serializable' );
		}

		if ( false === self::jsonEncode( $this->audience_claims ) ) {
			throw self::invalid( 'audience_claims', 'must be JSON serializable' );
		}

		if ( ( null === $this->owner_type ) !== ( null === $this->owner_key ) ) {
			throw self::invalid( 'owner', 'type and key must both be present or both be null' );
		}

		if ( null !== $this->owner_type && '' === trim( $this->owner_type ) ) {
			throw self::invalid( 'owner_type', 'must be null or a non-empty string' );
		}

		if ( null !== $this->owner_key && '' === trim( $this->owner_key ) ) {
			throw self::invalid( 'owner_key', 'must be null or a non-empty string' );
		}
	}

	/**
	 * Resolve a principal through host-provided request hooks.
	 *
	 * Host plugins can derive principals from REST, CLI, cron, bearer-token, or
	 * user-session state by returning either an WP_Agent_Execution_Principal instance
	 * or a raw principal array from the `agents_api_execution_principal` filter.
	 *
	 * @param array<string, mixed> $request_context Request-specific context for resolvers.
	 * @return self|null Principal when a resolver provides one.
	 */
	public static function resolve( array $request_context = array() ): ?self {
		$principal = null;

		if ( function_exists( 'apply_filters' ) ) {
			$principal = apply_filters( 'agents_api_execution_principal', $principal, $request_context );
		}

		if ( null === $principal || $principal instanceof self ) {
			return $principal;
		}

		if ( is_array( $principal ) ) {
			return self::from_array( $principal );
		}

		throw self::invalid( 'principal', 'resolver must return null, an array, or an WP_Agent_Execution_Principal' );
	}

	/**
	 * Build a principal from a user-session request shape.
	 *
	 * @param int    $acting_user_id    WordPress user ID.
	 * @param string $effective_agent_id Registered agent ID/slug.
	 * @param string $request_context   Request context.
	 * @param array  $request_metadata  Request metadata.
	 * @return self
	 */
	public static function user_session( int $acting_user_id, string $effective_agent_id, string $request_context = self::REQUEST_CONTEXT_REST, array $request_metadata = array(), ?string $workspace_id = null, ?string $client_id = null, ?\WP_Agent_Capability_Ceiling $capability_ceiling = null, ?\WP_Agent_Caller_Context $caller_context = null ): self {
		return new self( $acting_user_id, $effective_agent_id, self::AUTH_SOURCE_USER, $request_context, null, $request_metadata, $workspace_id, $client_id, $capability_ceiling, $caller_context );
	}

	/**
	 * Build a principal from a caller-owned agent token shape.
	 *
	 * @param int    $acting_user_id    WordPress user ID represented by the token.
	 * @param string $effective_agent_id Registered agent ID/slug.
	 * @param int    $token_id          Caller-owned token identifier.
	 * @param string $request_context   Request context.
	 * @param array  $request_metadata  Request metadata.
	 * @return self
	 */
	public static function agent_token( int $acting_user_id, string $effective_agent_id, int $token_id, string $request_context = self::REQUEST_CONTEXT_REST, array $request_metadata = array(), ?string $workspace_id = null, ?string $client_id = null, ?\WP_Agent_Capability_Ceiling $capability_ceiling = null, ?\WP_Agent_Caller_Context $caller_context = null ): self {
		return new self( $acting_user_id, $effective_agent_id, self::AUTH_SOURCE_AGENT_TOKEN, $request_context, $token_id, $request_metadata, $workspace_id, $client_id, $capability_ceiling, $caller_context );
	}

	/**
	 * Build a principal for a non-user audience resolved by the host.
	 *
	 * @param string $audience_id        Host-owned audience identifier.
	 * @param string $effective_agent_id Registered agent ID/slug effective for the run.
	 * @param string $request_context    Request context.
	 * @param array  $request_metadata   Request metadata.
	 * @param string|null $workspace_id  Optional host workspace/scope identifier.
	 * @param string|null $client_id     Optional client/login identifier.
	 * @param array  $audience_claims    Host-owned audience claims.
	 * @return self
	 */
	public static function audience( string $audience_id, string $effective_agent_id, string $request_context = self::REQUEST_CONTEXT_REST, array $request_metadata = array(), ?string $workspace_id = null, ?string $client_id = null, array $audience_claims = array(), ?string $owner_key = null ): self {
		return new self( 0, $effective_agent_id, self::AUTH_SOURCE_AUDIENCE, $request_context, null, $request_metadata, $workspace_id, $client_id, null, null, $audience_id, $audience_claims, null !== $owner_key ? self::OWNER_TYPE_AUDIENCE : null, $owner_key );
	}

	/**
	 * Build a principal from a request/context array.
	 *
	 * @param array $principal Raw principal fields.
	 * @return self
	 */
	public static function from_array( array $principal ): self {
		$capability_ceiling = null;
		if ( isset( $principal['capability_ceiling'] ) ) {
			if ( $principal['capability_ceiling'] instanceof \WP_Agent_Capability_Ceiling ) {
				$capability_ceiling = $principal['capability_ceiling'];
			} elseif ( is_array( $principal['capability_ceiling'] ) && class_exists( '\WP_Agent_Capability_Ceiling' ) ) {
				$capability_ceiling = \WP_Agent_Capability_Ceiling::from_array( $principal['capability_ceiling'] );
			}
		}

		$caller_context = null;
		if ( isset( $principal['caller_context'] ) ) {
			if ( $principal['caller_context'] instanceof \WP_Agent_Caller_Context ) {
				$caller_context = $principal['caller_context'];
			} elseif ( is_array( $principal['caller_context'] ) && class_exists( '\WP_Agent_Caller_Context' ) ) {
				$caller_context = \WP_Agent_Caller_Context::from_array( $principal['caller_context'] );
			}
		}

		return new self(
			isset( $principal['acting_user_id'] ) ? (int) $principal['acting_user_id'] : 0,
			isset( $principal['effective_agent_id'] ) ? (string) $principal['effective_agent_id'] : '',
			isset( $principal['auth_source'] ) ? (string) $principal['auth_source'] : '',
			isset( $principal['request_context'] ) ? (string) $principal['request_context'] : '',
			isset( $principal['token_id'] ) ? (int) $principal['token_id'] : null,
			isset( $principal['request_metadata'] ) && is_array( $principal['request_metadata'] ) ? $principal['request_metadata'] : array(),
			array_key_exists( 'workspace_id', $principal ) && null !== $principal['workspace_id'] ? (string) $principal['workspace_id'] : null,
			array_key_exists( 'client_id', $principal ) && null !== $principal['client_id'] ? (string) $principal['client_id'] : null,
			$capability_ceiling,
			$caller_context,
			array_key_exists( 'audience_id', $principal ) && null !== $principal['audience_id'] ? (string) $principal['audience_id'] : null,
			isset( $principal['audience_claims'] ) && is_array( $principal['audience_claims'] ) ? $principal['audience_claims'] : array(),
			array_key_exists( 'owner_type', $principal ) && null !== $principal['owner_type'] ? (string) $principal['owner_type'] : null,
			array_key_exists( 'owner_key', $principal ) && null !== $principal['owner_key'] ? (string) $principal['owner_key'] : null
		);
	}

	/**
	 * Export the principal to a stable, JSON-friendly shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'acting_user_id'     => $this->acting_user_id,
			'effective_agent_id' => $this->effective_agent_id,
			'auth_source'        => $this->auth_source,
			'request_context'    => $this->request_context,
			'token_id'           => $this->token_id,
			'request_metadata'   => $this->request_metadata,
			'workspace_id'       => $this->workspace_id,
			'client_id'          => $this->client_id,
			'capability_ceiling' => $this->capability_ceiling instanceof \WP_Agent_Capability_Ceiling ? $this->capability_ceiling->to_array() : null,
			'caller_context'     => $this->caller_context instanceof \WP_Agent_Caller_Context ? $this->caller_context->to_array() : null,
			'audience_id'        => $this->audience_id,
			'audience_claims'    => $this->audience_claims,
			'owner_type'         => $this->owner_type,
			'owner_key'          => $this->owner_key,
		);
	}

	/**
	 * Return the canonical transcript owner for this principal.
	 *
	 * Runtime authorization and transcript ownership are intentionally separate.
	 * User principals can safely derive ownership from the WordPress user ID. Non-user
	 * principals must provide an opaque owner key resolved by the host, such as a
	 * browser-session key; audience access alone is not a transcript owner.
	 *
	 * @return array{type:string,key:string}|null Principal owner, or null when this principal is not transcript-ownable.
	 */
	public function conversation_owner(): ?array {
		if ( null !== $this->owner_type && null !== $this->owner_key ) {
			return array(
				'type' => $this->owner_type,
				'key'  => $this->owner_key,
			);
		}

		if ( $this->acting_user_id > 0 && self::AUTH_SOURCE_AGENT_TOKEN !== $this->auth_source ) {
			return array(
				'type' => self::OWNER_TYPE_USER,
				'key'  => (string) $this->acting_user_id,
			);
		}

		if ( self::AUTH_SOURCE_AGENT_TOKEN === $this->auth_source && null !== $this->token_id ) {
			return array(
				'type' => self::OWNER_TYPE_TOKEN,
				'key'  => (string) $this->token_id,
			);
		}

		return null;
	}

	/**
	 * Whether this principal represents a host-resolved non-user audience.
	 */
	public function has_audience(): bool {
		return null !== $this->audience_id;
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
			$this->effective_agent_id,
			$this->auth_source,
			$this->request_context,
			$this->token_id,
			$request_metadata,
			$this->workspace_id,
			$this->client_id,
			$this->capability_ceiling,
			$this->caller_context,
			$this->audience_id,
			$this->audience_claims,
			$this->owner_type,
			$this->owner_key
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This pure-PHP value object also runs outside WordPress in smoke tests.
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
