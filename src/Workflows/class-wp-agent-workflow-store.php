<?php
/**
 * Storage contract for workflow specs.
 *
 * agents-api ships no default implementation. Consumers implement this
 * against their preferred storage layer — a custom post type, a custom
 * table, an external service, an in-memory store for tests. Each consumer
 * picks indexes and durability guarantees that match its product surface.
 *
 * This interface deliberately covers durable persistence (find, save,
 * delete, list). Code-defined workflows registered via
 * {@see WP_Agent_Workflow_Registry::register()} are an in-memory layer
 * that sits in front of any store; consumers compose the two however they
 * like.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Workflow_Store {

	/**
	 * Look up a workflow by its id. Returns null when not found.
	 *
	 * @since 0.103.0
	 *
	 * @param string $workflow_id
	 * @return WP_Agent_Workflow_Spec|null
	 */
	public function find( string $workflow_id ): ?WP_Agent_Workflow_Spec;

	/**
	 * Persist a workflow spec, creating or updating in place.
	 *
	 * @since 0.103.0
	 *
	 * @param WP_Agent_Workflow_Spec $spec
	 * @return true|WP_Error
	 */
	public function save( WP_Agent_Workflow_Spec $spec );

	/**
	 * Remove a workflow spec by id.
	 *
	 * @since 0.103.0
	 *
	 * @param string $workflow_id
	 * @return true|WP_Error
	 */
	public function delete( string $workflow_id );

	/**
	 * List stored workflows. Implementations may paginate / filter via
	 * `$args` — accepted keys are `limit`, `offset`, and arbitrary
	 * implementation-specific keys (the substrate doesn't enforce a
	 * uniform query DSL — consumers know their own indexes best).
	 *
	 * @since 0.103.0
	 *
	 * @param array $args
	 * @return WP_Agent_Workflow_Spec[]
	 */
	public function all( array $args = array() ): array;
}
