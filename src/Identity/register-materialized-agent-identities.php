<?php
/**
 * Materialized agent identity helpers.
 *
 * @package AgentsAPI
 */

use AgentsAPI\Identity\MaterializedAgentIdentity;
use AgentsAPI\Identity\MaterializedAgentIdentityStoreInterface;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_register_materialized_agent_identity_store' ) ) {
	/**
	 * Register the backing store for durable agent identities.
	 *
	 * @param MaterializedAgentIdentityStoreInterface $store Backing store.
	 * @return void
	 */
	function wp_register_materialized_agent_identity_store( MaterializedAgentIdentityStoreInterface $store ): void {
		$GLOBALS['wp_materialized_agent_identity_store'] = $store;
	}
}

if ( ! function_exists( 'wp_get_materialized_agent_identity_store' ) ) {
	/**
	 * Get the registered backing store for durable agent identities.
	 *
	 * @return MaterializedAgentIdentityStoreInterface|null Backing store, or null when none is registered.
	 */
	function wp_get_materialized_agent_identity_store(): ?MaterializedAgentIdentityStoreInterface {
		$store = $GLOBALS['wp_materialized_agent_identity_store'] ?? null;
		if ( function_exists( 'apply_filters' ) ) {
			$store = apply_filters( 'wp_materialized_agent_identity_store', $store );
		}

		return $store instanceof MaterializedAgentIdentityStoreInterface ? $store : null;
	}
}

if ( ! function_exists( 'wp_get_materialized_agent_identity_by_id' ) ) {
	/**
	 * Get a materialized agent identity by durable ID.
	 *
	 * @param int $id Durable identity ID.
	 * @return MaterializedAgentIdentity|null Identity, or null when not found.
	 */
	function wp_get_materialized_agent_identity_by_id( int $id ): ?MaterializedAgentIdentity {
		$store = wp_get_materialized_agent_identity_store();
		if ( null === $store ) {
			return null;
		}

		return $store->get_by_id( $id );
	}
}

if ( ! function_exists( 'wp_get_materialized_agent_identity' ) ) {
	/**
	 * Resolve a registered agent definition to its durable identity.
	 *
	 * @param string|WP_Agent $agent Agent slug or registered definition object.
	 * @return MaterializedAgentIdentity|null Identity, or null when not registered/materialized.
	 */
	function wp_get_materialized_agent_identity( $agent ): ?MaterializedAgentIdentity {
		$slug = $agent instanceof WP_Agent ? $agent->get_slug() : sanitize_title( (string) $agent );
		if ( '' === $slug || ! wp_has_agent( $slug ) ) {
			return null;
		}

		$store = wp_get_materialized_agent_identity_store();
		if ( null === $store ) {
			return null;
		}

		return $store->get_by_slug( $slug );
	}
}

if ( ! function_exists( 'wp_get_materialized_agent_identities_by_owner_user_id' ) ) {
	/**
	 * Get materialized agent identities owned by a WordPress user.
	 *
	 * @param int $owner_user_id Owner WordPress user ID.
	 * @return MaterializedAgentIdentity[] Identities owned by the user.
	 */
	function wp_get_materialized_agent_identities_by_owner_user_id( int $owner_user_id ): array {
		$store = wp_get_materialized_agent_identity_store();
		if ( null === $store ) {
			return array();
		}

		return $store->get_by_owner_user_id( $owner_user_id );
	}
}
