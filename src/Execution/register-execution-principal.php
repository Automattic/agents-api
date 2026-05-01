<?php
/**
 * Agent execution principal resolver helpers.
 *
 * @package AgentsAPI
 */

use AgentsAPI\Execution\AgentExecutionPrincipal;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_get_agent_execution_principal' ) ) {
	/**
	 * Resolve the generic execution principal for an agent request.
	 *
	 * Host plugins may return an AgentExecutionPrincipal or serialized principal
	 * array from the `wp_agents_api_execution_principal` filter. Returning null
	 * leaves the request unauthenticated/unresolved; this helper does not enforce
	 * access grants or scoped resource policy.
	 *
	 * @param array<string, mixed> $context Host request context.
	 * @return AgentExecutionPrincipal|null
	 */
	function wp_get_agent_execution_principal( array $context = array() ): ?AgentExecutionPrincipal {
		$principal = null;

		if ( function_exists( 'get_current_user_id' ) ) {
			$user_id = (int) get_current_user_id();
			if ( $user_id > 0 ) {
				$principal = new AgentExecutionPrincipal(
					$user_id,
					(int) ( $context['effective_agent_id'] ?? $context['agent_id'] ?? 0 ),
					AgentExecutionPrincipal::AUTH_SOURCE_USER_SESSION,
					null,
					(string) ( $context['request_context'] ?? $context['context'] ?? 'user_session' )
				);
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$principal = apply_filters( 'wp_agents_api_execution_principal', $principal, $context );
		}

		if ( is_array( $principal ) ) {
			return AgentExecutionPrincipal::from_array( $principal );
		}

		if ( null !== $principal && ! $principal instanceof AgentExecutionPrincipal ) {
			throw new InvalidArgumentException( 'invalid_agent_execution_principal: resolver must return an AgentExecutionPrincipal, array, or null' );
		}

		return $principal;
	}
}
