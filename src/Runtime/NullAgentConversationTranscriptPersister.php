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
	public function persist( array $messages, AgentConversationRequest $request, array $result ): string {
		unset( $messages, $request, $result );

		return '';
	}
}
