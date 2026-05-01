<?php
/**
 * Materialized Agent Identity Store Interface
 *
 * Generic persistence contract for durable agent instances. The contract only
 * resolves identity records; access grants, scoped policy, token binding, and
 * product-specific runtime behavior stay in higher-level callers.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\Identity;

defined( 'ABSPATH' ) || exit;

interface MaterializedAgentIdentityStoreInterface {

	/**
	 * Resolve an already-materialized identity by logical scope.
	 *
	 * @param AgentIdentityScope $scope Logical identity scope.
	 * @return MaterializedAgentIdentity|null Identity, or null when not materialized.
	 */
	public function resolve( AgentIdentityScope $scope ): ?MaterializedAgentIdentity;

	/**
	 * Retrieve a materialized identity by durable store ID.
	 *
	 * @param int $identity_id Durable identity store ID.
	 * @return MaterializedAgentIdentity|null Identity, or null when not found.
	 */
	public function get( int $identity_id ): ?MaterializedAgentIdentity;

	/**
	 * Resolve an existing identity or create the durable identity record.
	 *
	 * Implementations MUST make this operation idempotent for the same normalized
	 * `(agent_slug, owner_user_id, instance_key)` tuple.
	 *
	 * @param AgentIdentityScope  $scope          Logical identity scope.
	 * @param array<string,mixed> $default_config Initial config for first materialization only.
	 * @param array<string,mixed> $meta           Optional metadata for first materialization only.
	 * @return MaterializedAgentIdentity
	 */
	public function materialize( AgentIdentityScope $scope, array $default_config = array(), array $meta = array() ): MaterializedAgentIdentity;

	/**
	 * Persist replacement config and metadata for an existing identity.
	 *
	 * @param MaterializedAgentIdentity $identity Replacement identity value.
	 * @return MaterializedAgentIdentity Updated persisted value.
	 */
	public function update( MaterializedAgentIdentity $identity ): MaterializedAgentIdentity;

	/**
	 * Delete a materialized identity. Idempotent for non-existent identities.
	 *
	 * @param AgentIdentityScope $scope Logical identity scope.
	 * @return bool Whether the operation succeeded.
	 */
	public function delete( AgentIdentityScope $scope ): bool;
}
