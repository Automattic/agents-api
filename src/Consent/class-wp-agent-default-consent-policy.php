<?php
/**
 * Conservative default agent consent policy.
 *
 * @package AgentsAPI
 */

use AgentsAPI\AI\Consent\AgentConsentDecision;
use AgentsAPI\AI\Consent\AgentConsentOperation;

defined( 'ABSPATH' ) || exit;

/**
 * Default policy: deny unless an interactive caller supplies explicit consent.
 */
class WP_Agent_Default_Consent_Policy implements WP_Agent_Consent_Policy_Interface {

	/**
	 * @inheritDoc
	 */
	public function can_store_memory( array $context = array() ): AgentConsentDecision {
		return $this->decide( AgentConsentOperation::STORE_MEMORY, $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_use_memory( array $context = array() ): AgentConsentDecision {
		return $this->decide( AgentConsentOperation::USE_MEMORY, $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_store_transcript( array $context = array() ): AgentConsentDecision {
		return $this->decide( AgentConsentOperation::STORE_TRANSCRIPT, $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_share_transcript( array $context = array() ): AgentConsentDecision {
		return $this->decide( AgentConsentOperation::SHARE_TRANSCRIPT, $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_escalate_to_human( array $context = array() ): AgentConsentDecision {
		return $this->decide( AgentConsentOperation::ESCALATE_TO_HUMAN, $context );
	}

	/**
	 * Make a conservative explicit-consent decision for one operation.
	 *
	 * @param string $operation Consent operation value.
	 * @param array  $context   JSON-friendly policy context.
	 * @return AgentConsentDecision
	 */
	private function decide( string $operation, array $context ): AgentConsentDecision {
		$audit_metadata = $this->audit_metadata( $operation, $context );

		if ( ! $this->is_interactive( $context ) ) {
			return AgentConsentDecision::denied( $operation, 'non_interactive_default_denied', $audit_metadata );
		}

		if ( true === $this->explicit_consent( $operation, $context ) ) {
			return AgentConsentDecision::allowed( $operation, 'explicit_consent', $audit_metadata );
		}

		return AgentConsentDecision::denied( $operation, 'explicit_consent_missing', $audit_metadata );
	}

	/**
	 * Whether the policy context represents an interactive user flow.
	 *
	 * @param array $context JSON-friendly policy context.
	 * @return bool
	 */
	private function is_interactive( array $context ): bool {
		if ( true === ( $context['interactive'] ?? null ) ) {
			return true;
		}

		$mode = (string) ( $context['mode'] ?? $context['context'] ?? $context['request_kind'] ?? $context['request_context'] ?? '' );

		return in_array( strtolower( $mode ), array( 'chat', 'interactive', 'rest' ), true );
	}

	/**
	 * Resolve explicit consent for an operation.
	 *
	 * @param string $operation Consent operation value.
	 * @param array  $context   JSON-friendly policy context.
	 * @return bool|null
	 */
	private function explicit_consent( string $operation, array $context ): ?bool {
		if ( array_key_exists( $operation, $context ) && is_bool( $context[ $operation ] ) ) {
			return $context[ $operation ];
		}

		$consent = $context['consent'] ?? array();
		if ( is_array( $consent ) && array_key_exists( $operation, $consent ) && is_bool( $consent[ $operation ] ) ) {
			return $consent[ $operation ];
		}

		return null;
	}

	/**
	 * Build audit metadata common to all decisions.
	 *
	 * @param string $operation Consent operation value.
	 * @param array  $context   JSON-friendly policy context.
	 * @return array
	 */
	private function audit_metadata( string $operation, array $context ): array {
		return array(
			'policy'      => 'default',
			'operation'   => $operation,
			'interactive' => $this->is_interactive( $context ),
			'mode'        => (string) ( $context['mode'] ?? $context['context'] ?? $context['request_kind'] ?? $context['request_context'] ?? '' ),
			'agent_id'    => (string) ( $context['agent_id'] ?? '' ),
			'user_id'     => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
		);
	}
}
