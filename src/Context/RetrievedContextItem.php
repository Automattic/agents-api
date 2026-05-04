<?php
/**
 * Retrieved Context Item
 *
 * Store-neutral value object for one retrieved context item.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Context;

defined( 'ABSPATH' ) || exit;

final class RetrievedContextItem {

	/**
	 * @param string      $content        Retrieved context content.
	 * @param array       $scope          Product-defined scope metadata.
	 * @param string      $authority_tier Generic authority tier.
	 * @param array       $provenance     Source/provenance metadata.
	 * @param string      $conflict_kind  Conflict behavior vocabulary.
	 * @param string|null $conflict_key   Shared key for mutually conflicting items.
	 * @param array       $metadata       Additional JSON-friendly metadata.
	 */
	public function __construct(
		public readonly string $content,
		public readonly array $scope,
		public readonly string $authority_tier,
		public readonly array $provenance,
		public readonly string $conflict_kind = ContextConflictKind::PREFERENCE,
		public readonly ?string $conflict_key = null,
		public readonly array $metadata = array(),
	) {
		ContextAuthorityTier::normalize( $this->authority_tier );
		ContextConflictKind::normalize( $this->conflict_kind );
	}

	/**
	 * Export as a JSON-friendly array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'content'        => $this->content,
			'scope'          => $this->scope,
			'authority_tier' => ContextAuthorityTier::normalize( $this->authority_tier ),
			'provenance'     => $this->provenance,
			'conflict_kind'  => ContextConflictKind::normalize( $this->conflict_kind ),
			'conflict_key'   => $this->conflict_key,
			'metadata'       => $this->metadata,
		);
	}
}
