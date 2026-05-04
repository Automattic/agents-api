<?php
/**
 * Agent consent policy interface.
 *
 * @package AgentsAPI
 */

use AgentsAPI\AI\Consent\AgentConsentDecision;

defined( 'ABSPATH' ) || exit;

/**
 * Generic consent policy contract for agent memory, transcripts, and escalation.
 */
interface WP_Agent_Consent_Policy_Interface {

	/**
	 * Whether consolidated agent memory may be stored.
	 *
	 * @param array $context JSON-friendly request, principal, adapter, and UX context.
	 * @return AgentConsentDecision
	 */
	public function can_store_memory( array $context = array() ): AgentConsentDecision;

	/**
	 * Whether existing agent memory may be used for a run.
	 *
	 * @param array $context JSON-friendly request, principal, adapter, and UX context.
	 * @return AgentConsentDecision
	 */
	public function can_use_memory( array $context = array() ): AgentConsentDecision;

	/**
	 * Whether a raw conversation transcript may be stored.
	 *
	 * @param array $context JSON-friendly request, principal, adapter, and UX context.
	 * @return AgentConsentDecision
	 */
	public function can_store_transcript( array $context = array() ): AgentConsentDecision;

	/**
	 * Whether a raw conversation transcript may be shared outside its owning context.
	 *
	 * @param array $context JSON-friendly request, principal, adapter, and UX context.
	 * @return AgentConsentDecision
	 */
	public function can_share_transcript( array $context = array() ): AgentConsentDecision;

	/**
	 * Whether a run or transcript may be escalated to a human/support adapter.
	 *
	 * @param array $context JSON-friendly request, principal, adapter, and UX context.
	 * @return AgentConsentDecision
	 */
	public function can_escalate_to_human( array $context = array() ): AgentConsentDecision;
}
