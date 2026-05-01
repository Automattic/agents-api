<?php
/**
 * Agent conversation runner interface.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Transport-neutral runner boundary for conversation execution.
 */
interface AgentConversationRunnerInterface {

	/**
	 * Run an agent conversation request.
	 *
	 * @param AgentConversationRequest $request Conversation request.
	 * @return array<string, mixed> Raw conversation result shape.
	 */
	public function run( AgentConversationRequest $request ): array;
}
