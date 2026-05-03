<?php
/**
 * Generic pending-action approval decision.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable accept/reject decision for a pending action.
 */
final class ApprovalDecision {

	public const ACCEPTED = 'accepted';
	public const REJECTED = 'rejected';

	/** @var string Normalized decision value. */
	private string $value;

	/**
	 * @param string $value Decision value.
	 */
	private function __construct( string $value ) {
		if ( ! in_array( $value, array( self::ACCEPTED, self::REJECTED ), true ) ) {
			throw new \InvalidArgumentException( 'Approval decision must be accepted or rejected.' );
		}

		$this->value = $value;
	}

	/** @return self Accepted decision. */
	public static function accepted(): self {
		return new self( self::ACCEPTED );
	}

	/** @return self Rejected decision. */
	public static function rejected(): self {
		return new self( self::REJECTED );
	}

	/**
	 * Build a decision from a stored or request value.
	 *
	 * @param string $value Decision value.
	 * @return self
	 */
	public static function from_string( string $value ): self {
		return new self( $value );
	}

	/** @return bool Whether the pending action was accepted. */
	public function is_accepted(): bool {
		return self::ACCEPTED === $this->value;
	}

	/** @return bool Whether the pending action was rejected. */
	public function is_rejected(): bool {
		return self::REJECTED === $this->value;
	}

	/** @return string Normalized decision value. */
	public function value(): string {
		return $this->value;
	}

	/** @return string Normalized decision value. */
	public function __toString(): string {
		return $this->value;
	}
}
