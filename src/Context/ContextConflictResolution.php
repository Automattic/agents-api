<?php
/**
 * Context Conflict Resolution
 *
 * Describes the selected item and rejected alternatives for one conflict key.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Context;

defined( 'ABSPATH' ) || exit;

final class ContextConflictResolution {

	/**
	 * @param string                 $conflict_key Conflict key being resolved.
	 * @param RetrievedContextItem   $winner       Selected context item.
	 * @param RetrievedContextItem[] $rejected     Rejected context items.
	 * @param string                 $strategy     Resolution strategy identifier.
	 * @param string                 $reason       Human-readable reason.
	 */
	public function __construct(
		public readonly string $conflict_key,
		public readonly RetrievedContextItem $winner,
		public readonly array $rejected,
		public readonly string $strategy,
		public readonly string $reason,
	) {}

	/**
	 * Export as a JSON-friendly array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'conflict_key' => $this->conflict_key,
			'winner'       => $this->winner->to_array(),
			'rejected'     => array_map(
				static fn ( RetrievedContextItem $item ): array => $item->to_array(),
				$this->rejected
			),
			'strategy'     => $this->strategy,
			'reason'       => $this->reason,
		);
	}
}
