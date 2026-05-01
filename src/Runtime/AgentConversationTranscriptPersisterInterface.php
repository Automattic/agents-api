<?php
/**
 * Runtime transcript persistence contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Persists a completed or failed conversation transcript when requested.
 */
interface AgentConversationTranscriptPersisterInterface {

	/**
	 * Persist a runtime transcript.
	 *
	 * @param array                    $messages Final conversation messages.
	 * @param AgentConversationRequest $request  Original conversation request.
	 * @param array                    $result   Conversation result so far.
	 * @return string Transcript ID on success, empty string when not persisted.
	 */
	public function persist( array $messages, AgentConversationRequest $request, array $result ): string;
}
