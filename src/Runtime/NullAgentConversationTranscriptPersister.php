<?php
/**
 * Null transcript persister.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * No-op transcript persistence implementation.
 */
class NullAgentConversationTranscriptPersister implements AgentConversationTranscriptPersisterInterface {

	/**
	 * @inheritDoc
	 */
	public function persist( array $messages, string $provider, string $model, array $payload, array $result ): string {
		unset( $messages, $provider, $model, $payload, $result );

		return '';
	}
}
