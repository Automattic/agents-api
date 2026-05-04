<?php
/**
 * Generic tool action policy resolver.
 *
 * @package AgentsAPI
 */

use AgentsAPI\AI\Tools\ActionPolicy;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Action_Policy_Resolver' ) ) {
	/**
	 * Resolves whether a tool call runs directly, previews, or is forbidden.
	 */
	class WP_Agent_Action_Policy_Resolver {

		/**
		 * @var WP_Agent_Action_Policy_Provider_Interface[]
		 */
		private array $policy_providers;

		/**
		 * @var WP_Agent_Tool_Policy_Filter
		 */
		private WP_Agent_Tool_Policy_Filter $tool_filter;

		/**
		 * Constructor.
		 *
		 * @param WP_Agent_Action_Policy_Provider_Interface[]|null $policy_providers Host policy providers.
		 * @param WP_Agent_Tool_Policy_Filter|null                 $tool_filter      Shared tool filter.
		 */
		public function __construct( ?array $policy_providers = null, ?WP_Agent_Tool_Policy_Filter $tool_filter = null ) {
			$this->policy_providers = is_array( $policy_providers ) ? $policy_providers : array();
			$this->tool_filter      = $tool_filter ?? new WP_Agent_Tool_Policy_Filter();
		}

		/**
		 * Resolve action policy for one tool invocation.
		 *
		 * @param array<string, mixed> $context Resolution context.
		 * @return string One of direct, preview, forbidden.
		 */
		public function resolve_for_tool( array $context ): string {
			$tool_name = (string) ( $context['tool_name'] ?? '' );
			$mode      = (string) ( $context['mode'] ?? WP_Agent_Tool_Policy::RUNTIME_CHAT );
			$tool_def  = is_array( $context['tool_def'] ?? null ) ? $context['tool_def'] : array();

			if ( '' === $tool_name ) {
				return ActionPolicy::DIRECT;
			}

			if ( in_array( $tool_name, $this->string_list( $context['deny'] ?? array() ), true ) ) {
				return $this->apply_filter( ActionPolicy::FORBIDDEN, $tool_name, $mode, $context );
			}

			$agent_policy = $this->agent_action_policy_from_context( $context );
			$agent_tool   = $this->agent_tool_override( $agent_policy, $tool_name );
			if ( null !== $agent_tool ) {
				return $this->apply_filter( $agent_tool, $tool_name, $mode, $context );
			}

			$agent_category = $this->agent_category_override( $agent_policy, $tool_def );
			if ( null !== $agent_category ) {
				return $this->apply_filter( $agent_category, $tool_name, $mode, $context );
			}

			foreach ( $this->get_policy_providers( $context ) as $provider ) {
				$provided = ActionPolicy::normalize( $provider->get_action_policy( $context ) );
				if ( null !== $provided ) {
					return $this->apply_filter( $provided, $tool_name, $mode, $context );
				}
			}

			$tool_default = $this->tool_declared_default( $tool_def );
			if ( null !== $tool_default ) {
				return $this->apply_filter( $tool_default, $tool_name, $mode, $context );
			}

			$mode_default = $this->mode_declared_default( $tool_def, $mode );
			if ( null !== $mode_default ) {
				return $this->apply_filter( $mode_default, $tool_name, $mode, $context );
			}

			return $this->apply_filter( ActionPolicy::DIRECT, $tool_name, $mode, $context );
		}

		/**
		 * Return policy providers from constructor, context, and filters.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return WP_Agent_Action_Policy_Provider_Interface[] Providers.
		 */
		private function get_policy_providers( array $context ): array {
			$providers = $this->policy_providers;
			if ( is_array( $context['action_policy_providers'] ?? null ) ) {
				$providers = array_merge( $providers, $context['action_policy_providers'] );
			}

			if ( function_exists( 'apply_filters' ) ) {
				$providers = apply_filters( 'agents_api_action_policy_providers', $providers, $context, $this );
			}

			return array_values(
				array_filter(
					is_array( $providers ) ? $providers : array(),
					static fn( $provider ): bool => $provider instanceof WP_Agent_Action_Policy_Provider_Interface
				)
			);
		}

		/**
		 * Return action policy from runtime or registered agent config.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return array<string, mixed> Policy map.
		 */
		private function agent_action_policy_from_context( array $context ): array {
			if ( is_array( $context['action_policy'] ?? null ) ) {
				return $context['action_policy'];
			}

			$agent_config = array();
			if ( is_array( $context['agent_config'] ?? null ) ) {
				$agent_config = $context['agent_config'];
			} elseif ( ( $context['agent'] ?? null ) instanceof WP_Agent ) {
				$agent_config = $context['agent']->get_default_config();
			} else {
				$agent_slug = (string) ( $context['agent_slug'] ?? ( $context['agent_id'] ?? '' ) );
				if ( '' !== $agent_slug && function_exists( 'wp_get_agent' ) ) {
					$agent = wp_get_agent( $agent_slug );
					if ( $agent instanceof WP_Agent ) {
						$agent_config = $agent->get_default_config();
					}
				}
			}

			return is_array( $agent_config['action_policy'] ?? null ) ? $agent_config['action_policy'] : array();
		}

		/**
		 * Resolve per-tool agent override.
		 *
		 * @param array<string, mixed> $policy    Agent policy.
		 * @param string               $tool_name Tool name.
		 * @return string|null Normalized policy or null.
		 */
		private function agent_tool_override( array $policy, string $tool_name ): ?string {
			$tools = is_array( $policy['tools'] ?? null ) ? $policy['tools'] : array();
			return ActionPolicy::normalize( $tools[ $tool_name ] ?? null );
		}

		/**
		 * Resolve per-category agent override.
		 *
		 * @param array<string, mixed> $policy   Agent policy.
		 * @param array<string, mixed> $tool_def Tool definition.
		 * @return string|null Normalized policy or null.
		 */
		private function agent_category_override( array $policy, array $tool_def ): ?string {
			$categories = is_array( $policy['categories'] ?? null ) ? $policy['categories'] : array();
			foreach ( $categories as $category => $raw_policy ) {
				if ( ! is_string( $category ) || ! $this->tool_filter->tool_matches_categories( $tool_def, array( $category ) ) ) {
					continue;
				}

				$policy_value = ActionPolicy::normalize( $raw_policy );
				if ( null !== $policy_value ) {
					return $policy_value;
				}
			}

			return null;
		}

		/**
		 * Return tool-declared default action policy.
		 *
		 * @param array<string, mixed> $tool_def Tool definition.
		 * @return string|null Normalized policy or null.
		 */
		private function tool_declared_default( array $tool_def ): ?string {
			return ActionPolicy::normalize( $tool_def['action_policy'] ?? null );
		}

		/**
		 * Return mode-specific tool-declared action policy.
		 *
		 * @param array<string, mixed> $tool_def Tool definition.
		 * @param string               $mode     Runtime mode.
		 * @return string|null Normalized policy or null.
		 */
		private function mode_declared_default( array $tool_def, string $mode ): ?string {
			return ActionPolicy::normalize( $tool_def[ 'action_policy_' . $mode ] ?? null );
		}

		/**
		 * Apply final WordPress filter and keep only canonical values.
		 *
		 * @param string               $policy    Computed policy.
		 * @param string               $tool_name Tool name.
		 * @param string               $mode      Runtime mode.
		 * @param array<string, mixed> $context   Resolution context.
		 * @return string Filtered policy.
		 */
		private function apply_filter( string $policy, string $tool_name, string $mode, array $context ): string {
			if ( ! function_exists( 'apply_filters' ) ) {
				return $policy;
			}

			$filtered = apply_filters( 'agents_api_tool_action_policy', $policy, $tool_name, $mode, $context, $this );
			return ActionPolicy::normalize( $filtered ) ?? $policy;
		}

		/**
		 * Normalize a list of strings.
		 *
		 * @param mixed $values Raw list.
		 * @return string[] Non-empty strings.
		 */
		private function string_list( $values ): array {
			$values = is_array( $values ) ? $values : array( $values );
			$values = array_filter(
				array_map(
					static fn( $value ) => is_string( $value ) ? trim( $value ) : '',
					$values
				),
				static fn( string $value ): bool => '' !== $value
			);

			return array_values( array_unique( $values ) );
		}
	}
}
