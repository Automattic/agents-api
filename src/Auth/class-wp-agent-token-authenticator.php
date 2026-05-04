<?php
/**
 * WP_Agent_Token_Authenticator service.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Token_Authenticator' ) ) {
	/**
	 * Resolves bearer token strings into agent execution principals.
	 */
	final class WP_Agent_Token_Authenticator {

		/**
		 * @param WP_Agent_Token_Store_Interface $token_store  Token store.
		 * @param string|null                    $token_prefix Optional token prefix this authenticator owns. Null means accept any prefix.
		 */
		public function __construct(
			private readonly WP_Agent_Token_Store_Interface $token_store,
			private readonly ?string $token_prefix = null,
		) {}

		/**
		 * Authenticate a raw bearer token into a principal.
		 *
		 * @param string              $raw_token       Raw bearer token from a request header.
		 * @param string              $request_context Request context such as rest, cli, cron, or chat.
		 * @param array<string,mixed> $metadata        Additional request metadata.
		 */
		public function authenticate_bearer_token( string $raw_token, string $request_context = AgentsAPI\AI\AgentExecutionPrincipal::REQUEST_CONTEXT_REST, array $metadata = array() ): ?AgentsAPI\AI\AgentExecutionPrincipal {
			$raw_token = trim( $raw_token );
			if ( '' === $raw_token ) {
				return null;
			}

			if ( null !== $this->token_prefix && ! str_starts_with( $raw_token, $this->token_prefix ) ) {
				return null;
			}

			$token = $this->token_store->resolve_token_hash( WP_Agent_Token::hash_token( $raw_token ) );
			if ( null === $token || $token->is_expired() ) {
				return null;
			}

			$this->token_store->touch_token( $token->token_id );

			$metadata = array_merge(
				$metadata,
				array(
					'token_prefix'                => $token->token_prefix,
					'token_label'                 => $token->label,
					'client_id'                   => $token->client_id,
					'workspace_id'                => $token->workspace_id,
					'has_capability_restrictions' => null !== $token->allowed_capabilities,
				)
			);

			return AgentsAPI\AI\AgentExecutionPrincipal::agent_token(
				$token->owner_user_id,
				$token->agent_id,
				$token->token_id,
				$request_context,
				$metadata,
				$token->workspace_id,
				$token->client_id,
				$token->capability_ceiling()
			);
		}
	}
}
