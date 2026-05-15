<?php
/**
 * WP_Agent_Access helpers.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Access' ) ) {
	/**
	 * Host-store discovery and current-principal access helpers.
	 */
	final class WP_Agent_Access {

		private const CURRENT_USER_EFFECTIVE_AGENT_ID = '__wordpress_user__';
		private const PUBLIC_AUDIENCE_ID              = 'audience:public';

		/**
		 * Resolve the host-provided access store.
		 *
		 * @param array<string,mixed> $context Host-owned request context.
		 */
		public static function get_store( array $context = array() ): ?WP_Agent_Access_Store {
			if ( isset( $context['access_store'] ) && $context['access_store'] instanceof WP_Agent_Access_Store ) {
				return $context['access_store'];
			}

			$store = function_exists( 'apply_filters' ) ? apply_filters( 'wp_agent_access_store', null, $context ) : null;
			return $store instanceof WP_Agent_Access_Store ? $store : null;
		}

		/**
		 * Resolve the principal for the current request.
		 *
		 * @param array<string,mixed> $context Host-owned request context.
		 */
		public static function get_current_principal( array $context = array() ): ?AgentsAPI\AI\WP_Agent_Execution_Principal {
			if ( isset( $context['principal'] ) && $context['principal'] instanceof AgentsAPI\AI\WP_Agent_Execution_Principal ) {
				return $context['principal'];
			}

			$principal = AgentsAPI\AI\WP_Agent_Execution_Principal::resolve( $context );
			if ( null !== $principal ) {
				return $principal;
			}

			$user_id = self::get_current_user_id();
			if ( $user_id <= 0 ) {
				if ( array_key_exists( 'allow_anonymous_audience', $context ) && false === (bool) $context['allow_anonymous_audience'] ) {
					return null;
				}

				return AgentsAPI\AI\WP_Agent_Execution_Principal::audience(
					self::PUBLIC_AUDIENCE_ID,
					self::PUBLIC_AUDIENCE_ID,
					isset( $context['request_context'] ) ? (string) $context['request_context'] : AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
					isset( $context['request_metadata'] ) && is_array( $context['request_metadata'] ) ? $context['request_metadata'] : array(),
					array_key_exists( 'workspace_id', $context ) && null !== $context['workspace_id'] ? (string) $context['workspace_id'] : null,
					array_key_exists( 'client_id', $context ) && null !== $context['client_id'] ? (string) $context['client_id'] : null
				);
			}

			return AgentsAPI\AI\WP_Agent_Execution_Principal::user_session(
				$user_id,
				self::CURRENT_USER_EFFECTIVE_AGENT_ID,
				isset( $context['request_context'] ) ? (string) $context['request_context'] : AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
				isset( $context['request_metadata'] ) && is_array( $context['request_metadata'] ) ? $context['request_metadata'] : array(),
				array_key_exists( 'workspace_id', $context ) && null !== $context['workspace_id'] ? (string) $context['workspace_id'] : null,
				array_key_exists( 'client_id', $context ) && null !== $context['client_id'] ? (string) $context['client_id'] : null
			);
		}

		/**
		 * Check whether the current request principal can access an agent.
		 *
		 * @param string              $agent_id     Registered agent slug/id.
		 * @param string              $minimum_role Minimum access role.
		 * @param array<string,mixed> $context      Host-owned request context.
		 */
		public static function can_current_principal_access_agent( string $agent_id, string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): bool {
			$principal = self::get_current_principal( $context );
			if ( null === $principal ) {
				return false;
			}

			return self::can_principal_access_agent( $principal, $agent_id, $minimum_role, $context );
		}

		/**
		 * Check whether a principal can access an agent.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal    Execution principal.
		 * @param string                                    $agent_id     Registered agent slug/id.
		 * @param string                                    $minimum_role Minimum access role.
		 * @param array<string,mixed>                       $context      Host-owned request context.
		 */
		public static function can_principal_access_agent( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, string $agent_id, string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): bool {
			$store  = self::get_store( $context );
			$policy = new WP_Agent_WordPress_Authorization_Policy( $store );

			return $policy->can_access_agent( $principal, $agent_id, $minimum_role, $context );
		}

		/**
		 * List registered agents accessible to the current request principal.
		 *
		 * @param string              $minimum_role Minimum access role.
		 * @param array<string,mixed> $context      Host-owned request context.
		 * @return array<int,array<string,mixed>>
		 */
		public static function list_accessible_agents_for_current_principal( string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): array {
			$principal = self::get_current_principal( $context );
			if ( null === $principal ) {
				return array();
			}

			return self::list_accessible_agents_for_principal( $principal, $minimum_role, $context );
		}

		/**
		 * List registered agents accessible to a principal.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal    Execution principal.
		 * @param string                                    $minimum_role Minimum access role.
		 * @param array<string,mixed>                       $context      Host-owned request context.
		 * @return array<int,array<string,mixed>>
		 */
		public static function list_accessible_agents_for_principal( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): array {
			if ( ! WP_Agent_Access_Grant::is_valid_role( $minimum_role ) ) {
				return array();
			}

			$agent_ids = array();
			$store     = self::get_store( $context );
			if ( $store instanceof WP_Agent_Principal_Access_Store ) {
				$agent_ids = $store->get_agent_ids_for_principal( $principal, $minimum_role, $principal->workspace_id );
			} elseif ( $store instanceof WP_Agent_Access_Store && $principal->acting_user_id > 0 ) {
				$agent_ids = $store->get_agent_ids_for_user( $principal->acting_user_id, $minimum_role, $principal->workspace_id );
			}

			if ( null === $principal->audience_id && self::CURRENT_USER_EFFECTIVE_AGENT_ID !== $principal->effective_agent_id ) {
				$agent_ids[] = $principal->effective_agent_id;
			}

			$agent_ids = array_values( array_unique( array_filter( array_map( 'sanitize_title', $agent_ids ) ) ) );
			$agents    = array();
			foreach ( $agent_ids as $agent_id ) {
				$agent = function_exists( 'wp_get_agent' ) ? wp_get_agent( $agent_id ) : null;
				if ( $agent instanceof WP_Agent ) {
					$agents[] = self::agent_to_access_summary( $agent );
				}
			}

			return $agents;
		}

		/**
		 * Export a registered agent summary for access-listing clients.
		 *
		 * @return array<string,mixed>
		 */
		private static function agent_to_access_summary( WP_Agent $agent ): array {
			return array(
				'slug'        => $agent->get_slug(),
				'label'       => $agent->get_label(),
				'description' => $agent->get_description(),
				'meta'        => $agent->get_meta(),
			);
		}

		/**
		 * Return the current WordPress user ID when WordPress is loaded.
		 */
		private static function get_current_user_id(): int {
			if ( function_exists( 'get_current_user_id' ) ) {
				return (int) get_current_user_id();
			}

			return 0;
		}
	}
}
