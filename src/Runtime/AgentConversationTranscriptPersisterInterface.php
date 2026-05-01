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
	 * @param array  $messages Final conversation messages.
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @param array  $payload  Runtime payload.
	 * @param array  $result   Conversation result so far.
	 * @return string Transcript ID on success, empty string when not persisted.
	 */
	public function persist( array $messages, string $provider, string $model, array $payload, array $result ): string;
}
