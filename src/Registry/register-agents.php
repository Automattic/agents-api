<?php
/**
 * Agent registration helper.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_register_agent' ) ) {
	/**
	 * Register an agent definition.
	 *
	 * Call from inside a `wp_agents_api_init` action callback. The registry
	 * collects definitions without deciding whether they should be materialized.
	 *
	 * @param string|WP_Agent $agent Agent slug or definition object.
	 * @param array<mixed>           $args  Registration arguments. Use `meta.source_plugin`,
	 *                               `meta.source_type`, `meta.source_package`, and
	 *                               `meta.source_version` to declare source provenance.
	 * @return WP_Agent|null Registered agent, or null on invalid arguments.
	 */
	function wp_register_agent( $agent, array $args = array() ): ?WP_Agent {
		$slug = $agent instanceof WP_Agent ? $agent->get_slug() : (string) $agent;
		if ( ! doing_action( 'wp_agents_api_init' ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				sprintf(
					'Agents must be registered on the %1$s action. The agent %2$s was not registered.',
					'<code>wp_agents_api_init</code>',
					'<code>' . esc_html( $slug ) . '</code>'
				),
				'0.102.8'
			);
			return null;
		}

		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->register( $agent, $args );
	}
}

if ( ! function_exists( 'wp_get_agent' ) ) {
	/**
	 * Retrieves a registered agent object.
	 *
	 * @param string $slug Agent slug.
	 * @return WP_Agent|null Registered agent, or null when not registered.
	 */
	function wp_get_agent( string $slug ): ?WP_Agent {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->get_registered( $slug );
	}
}

if ( ! function_exists( 'wp_get_agents' ) ) {
	/**
	 * Retrieves all registered agent objects.
	 *
	 * @return array<string, WP_Agent>
	 */
	function wp_get_agents(): array {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return array();
		}

		return $registry->get_all_registered();
	}
}

if ( ! function_exists( 'wp_has_agent' ) ) {
	/**
	 * Checks whether an agent is registered.
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	function wp_has_agent( string $slug ): bool {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return false;
		}

		return $registry->is_registered( $slug );
	}
}

if ( ! function_exists( 'wp_unregister_agent' ) ) {
	/**
	 * Unregisters an agent definition.
	 *
	 * @param string $slug Agent slug.
	 * @return WP_Agent|null Removed agent, or null when not registered.
	 */
	function wp_unregister_agent( string $slug ): ?WP_Agent {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->unregister( $slug );
	}
}

if ( ! function_exists( 'wp_materialize_registered_agents' ) ) {
	/**
	 * Materializes registered agents through a host-provided adapter.
	 *
	 * Agents API collects declarative definitions only. This helper exposes the
	 * generic lifecycle seam for products that want to reconcile those definitions
	 * into runtime or persisted agents without making Agents API choose storage.
	 *
	 * Callers may pass an adapter directly or provide one with the
	 * `wp_agent_registered_agent_materialization_adapter` filter. No adapter means
	 * no materialization and an empty result set.
	 *
	 * @param WP_Agent_Registered_Agent_Materialization_Adapter|null $adapter Adapter, or null to use the filter.
	 * @param array<string,mixed>                                    $args    Host-owned materialization options and context.
	 * @return array<string,WP_Agent_Materialization_Result> Results keyed by registered slug or adapter-owned removed-state key.
	 */
	function wp_materialize_registered_agents( ?WP_Agent_Registered_Agent_Materialization_Adapter $adapter = null, array $args = array() ): array {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return array();
		}

		/**
		 * Filters the adapter used to materialize registered agents.
		 *
		 * The adapter decides storage, runtime activation, owner resolution,
		 * duplicate-update behavior for durable identities, and removed-definition
		 * reconciliation. Agents API passes only the current registered definition
		 * snapshot plus caller-provided options.
		 *
		 * @param WP_Agent_Registered_Agent_Materialization_Adapter|null $adapter Adapter.
		 * @param WP_Agents_Registry                                      $registry Registry snapshot source.
		 * @param array<string,mixed>                                     $args     Host-owned materialization options and context.
		 */
		$adapter = apply_filters( 'wp_agent_registered_agent_materialization_adapter', $adapter, $registry, $args );

		if ( ! $adapter instanceof WP_Agent_Registered_Agent_Materialization_Adapter ) {
			return array();
		}

		return $adapter->materialize_registered_agents( $registry->get_all_registered(), $args );
	}
}
