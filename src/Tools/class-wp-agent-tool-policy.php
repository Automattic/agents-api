<?php
/**
 * Generic agent tool visibility policy resolver.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Tool_Policy' ) ) {
	/**
	 * Resolves visible tools for a runtime context.
	 */
	class WP_Agent_Tool_Policy {

		public const MODE_ALLOW = 'allow';
		public const MODE_DENY  = 'deny';

		public const RUNTIME_CHAT     = 'chat';
		public const RUNTIME_PIPELINE = 'pipeline';
		public const RUNTIME_SYSTEM   = 'system';

		/**
		 * @var WP_Agent_Tool_Policy_Filter
		 */
		private WP_Agent_Tool_Policy_Filter $filter;

		/**
		 * @var WP_Agent_Tool_Access_Policy_Interface[]
		 */
		private array $policy_providers;

		/**
		 * Constructor.
		 *
		 * @param WP_Agent_Tool_Access_Policy_Interface[]|null $policy_providers Host policy providers.
		 * @param WP_Agent_Tool_Policy_Filter|null             $filter           Tool policy filter.
		 */
		public function __construct( ?array $policy_providers = null, ?WP_Agent_Tool_Policy_Filter $filter = null ) {
			$this->policy_providers = is_array( $policy_providers ) ? $policy_providers : array();
			$this->filter           = $filter ?? new WP_Agent_Tool_Policy_Filter();
		}

		/**
		 * Resolve the visible tool set for a runtime context.
		 *
		 * @param array<string, array> $tools   Tool definitions keyed by tool name.
		 * @param array<string, mixed> $context Runtime context.
		 * @return array<string, array> Visible tools keyed by tool name.
		 */
		public function resolve( array $tools, array $context = array() ): array {
			$mode            = (string) ( $context['mode'] ?? self::RUNTIME_CHAT );
			$policies        = $this->collect_policies( $context );
			$mandatory_tools = $this->collect_policy_list( $policies, 'mandatory_tools' );
			$mandatory_cats  = $this->collect_policy_list( $policies, 'mandatory_categories' );
			$preserve_tool   = function ( array $tool, string $name ) use ( $mandatory_tools, $mandatory_cats ): bool {
				return in_array( $name, $mandatory_tools, true )
					|| true === ( $tool['mandatory'] ?? false )
					|| $this->filter->tool_matches_categories( $tool, $mandatory_cats );
			};

			$tools = $this->filter->filter_by_mode( $tools, $mode );

			$access_checker = $context['tool_access_checker'] ?? null;
			if ( is_callable( $access_checker ) ) {
				$tools = $this->filter->filter_by_access_checker( $tools, $access_checker );
			}

			foreach ( $policies as $policy ) {
				$tools = $this->filter->apply_named_policy( $tools, $this->normalize_named_policy( $policy ), $preserve_tool );
			}

			$categories = $this->string_list( $context['categories'] ?? array() );
			if ( ! empty( $categories ) ) {
				$tools = $this->filter->filter_by_categories( $tools, $categories, $preserve_tool );
			}

			$allow_only = $this->string_list( $context['allow_only'] ?? array() );
			foreach ( $policies as $policy ) {
				$allow_only = array_merge( $allow_only, $this->string_list( $policy['allow_only'] ?? array() ) );
			}
			if ( ! empty( $allow_only ) ) {
				$tools = $this->filter->filter_by_allow_only( $tools, array_values( array_unique( $allow_only ) ), $preserve_tool );
			}

			$deny = $this->string_list( $context['deny'] ?? array() );
			foreach ( $policies as $policy ) {
				$deny = array_merge( $deny, $this->string_list( $policy['deny'] ?? array() ) );
			}
			if ( ! empty( $deny ) ) {
				$tools = array_diff_key( $tools, array_flip( array_values( array_unique( $deny ) ) ) );
			}

			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'agents_api_resolved_tools', $tools, $mode, $context, $this );
				$tools    = is_array( $filtered ) ? $filtered : $tools;
			}

			return $tools;
		}

		/**
		 * Collect policy fragments from agent config, runtime context, and providers.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return array<int, array<string, mixed>> Policy fragments.
		 */
		private function collect_policies( array $context ): array {
			$policies = array();

			$agent_config = $this->agent_config_from_context( $context );
			if ( is_array( $agent_config['tool_policy'] ?? null ) ) {
				$policies[] = $agent_config['tool_policy'];
			}

			if ( is_array( $context['tool_policy'] ?? null ) ) {
				$policies[] = $context['tool_policy'];
			}

			foreach ( $this->get_policy_providers( $context ) as $provider ) {
				$policy = $provider->get_tool_policy( $context );
				if ( is_array( $policy ) ) {
					$policies[] = $policy;
				}
			}

			return $policies;
		}

		/**
		 * Return policy providers from constructor, context, and filter.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return WP_Agent_Tool_Access_Policy_Interface[] Providers.
		 */
		private function get_policy_providers( array $context ): array {
			$providers = $this->policy_providers;
			if ( is_array( $context['tool_policy_providers'] ?? null ) ) {
				$providers = array_merge( $providers, $context['tool_policy_providers'] );
			}

			if ( function_exists( 'apply_filters' ) ) {
				$providers = apply_filters( 'agents_api_tool_policy_providers', $providers, $context, $this );
			}

			return array_values(
				array_filter(
					is_array( $providers ) ? $providers : array(),
					static fn( $provider ): bool => $provider instanceof WP_Agent_Tool_Access_Policy_Interface
				)
			);
		}

		/**
		 * Return registered or runtime agent config from context.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return array<string, mixed> Agent config.
		 */
		private function agent_config_from_context( array $context ): array {
			if ( is_array( $context['agent_config'] ?? null ) ) {
				return $context['agent_config'];
			}

			$agent = $context['agent'] ?? null;
			if ( $agent instanceof WP_Agent ) {
				return $agent->get_default_config();
			}

			$agent_slug = (string) ( $context['agent_slug'] ?? ( $context['agent_id'] ?? '' ) );
			if ( '' !== $agent_slug && function_exists( 'wp_get_agent' ) ) {
				$registered = wp_get_agent( $agent_slug );
				if ( $registered instanceof WP_Agent ) {
					return $registered->get_default_config();
				}
			}

			return array();
		}

		/**
		 * Normalize allow/deny policy shape.
		 *
		 * @param array<string, mixed> $policy Raw policy.
		 * @return array<string, mixed>|null Normalized policy.
		 */
		private function normalize_named_policy( array $policy ): ?array {
			$mode = (string) ( $policy['mode'] ?? self::MODE_DENY );
			if ( ! in_array( $mode, array( self::MODE_ALLOW, self::MODE_DENY ), true ) ) {
				return null;
			}

			return array(
				'mode'       => $mode,
				'tools'      => $this->string_list( $policy['tools'] ?? array() ),
				'categories' => $this->string_list( $policy['categories'] ?? array() ),
			);
		}

		/**
		 * Collect list values from every policy fragment.
		 *
		 * @param array<int, array<string, mixed>> $policies Policy fragments.
		 * @param string                          $key      Policy key.
		 * @return string[] Values.
		 */
		private function collect_policy_list( array $policies, string $key ): array {
			$values = array();
			foreach ( $policies as $policy ) {
				$values = array_merge( $values, $this->string_list( $policy[ $key ] ?? array() ) );
			}

			return array_values( array_unique( $values ) );
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
