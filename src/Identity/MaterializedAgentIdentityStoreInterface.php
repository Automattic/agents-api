<?php
/**
 * Materialized agent identity store contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only lookup surface for durable agent instances.
 */
interface MaterializedAgentIdentityStoreInterface {

	/**
	 * Get a materialized agent identity by durable ID.
	 *
	 * @param int $id Durable identity ID.
	 * @return MaterializedAgentIdentity|null Identity, or null when not found.
	 */
	public function get_by_id( int $id ): ?MaterializedAgentIdentity;

	/**
	 * Get a materialized agent identity by registered slug.
	 *
	 * @param string $slug Registered agent slug.
	 * @return MaterializedAgentIdentity|null Identity, or null when not found.
	 */
	public function get_by_slug( string $slug ): ?MaterializedAgentIdentity;

	/**
	 * Get materialized agent identities owned by a WordPress user.
	 *
	 * @param int $owner_user_id Owner WordPress user ID.
	 * @return MaterializedAgentIdentity[] Identities owned by the user.
	 */
	public function get_by_owner_user_id( int $owner_user_id ): array;
}
