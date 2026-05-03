<?php
/**
 * Generic pending approval action value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a proposed action awaiting approval.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class PendingAction {

	/** @var string */
	private $action_id;

	/** @var string */
	private $kind;

	/** @var string */
	private $summary;

	/** @var mixed */
	private $preview;

	/** @var mixed */
	private $apply_input;

	/** @var string|null */
	private $created_by;

	/** @var string|null */
	private $agent_id;

	/** @var array<string, mixed> */
	private $context;

	/** @var string */
	private $created_at;

	/** @var string|null */
	private $expires_at;

	/**
	 * @param string               $action_id   Stable action identifier.
	 * @param string               $kind        Generic action kind.
	 * @param string               $summary     Human-readable summary.
	 * @param mixed                $preview     JSON-serializable preview payload.
	 * @param mixed                $apply_input JSON-serializable apply payload.
	 * @param string|null          $created_by  Optional creator identifier.
	 * @param string|null          $agent_id    Optional agent identifier.
	 * @param mixed                $context     Optional JSON-serializable context array.
	 * @param string               $created_at  Creation timestamp string.
	 * @param string|null          $expires_at  Optional expiration timestamp string.
	 */
	public function __construct(
		$action_id,
		$kind,
		$summary,
		$preview,
		$apply_input,
		$created_by,
		$agent_id,
		$context,
		$created_at,
		$expires_at
	) {
		$this->action_id   = self::normalize_string( $action_id, 'action_id' );
		$this->kind        = self::normalize_string( $kind, 'kind' );
		$this->summary     = self::normalize_string( $summary, 'summary' );
		$this->preview     = self::normalize_json_value( $preview, 'preview' );
		$this->apply_input = self::normalize_json_value( $apply_input, 'apply_input' );
		$this->created_by  = self::normalize_optional_string( $created_by, 'created_by' );
		$this->agent_id    = self::normalize_optional_string( $agent_id, 'agent_id' );
		$this->context     = self::normalize_json_array( $context, 'context' );
		$this->created_at  = self::normalize_string( $created_at, 'created_at' );
		$this->expires_at  = self::normalize_optional_string( $expires_at, 'expires_at' );
	}

	/**
	 * Build a pending action from an array shape.
	 *
	 * @param array<string, mixed> $action Raw action data.
	 * @return self
	 */
	public static function from_array( array $action ): self {
		return new self(
			self::required_value( $action, 'action_id' ),
			self::required_value( $action, 'kind' ),
			self::required_value( $action, 'summary' ),
			self::required_value( $action, 'preview' ),
			self::required_value( $action, 'apply_input' ),
			$action['created_by'] ?? null,
			$action['agent_id'] ?? null,
			$action['context'] ?? array(),
			self::required_value( $action, 'created_at' ),
			$action['expires_at'] ?? null
		);
	}

	/**
	 * Convert the action to its canonical public shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'action_id'   => $this->action_id,
			'kind'        => $this->kind,
			'summary'     => $this->summary,
			'preview'     => $this->preview,
			'apply_input' => $this->apply_input,
			'created_by'  => $this->created_by,
			'agent_id'    => $this->agent_id,
			'context'     => $this->context,
			'created_at'  => $this->created_at,
			'expires_at'  => $this->expires_at,
		);
	}

	/**
	 * @return string
	 */
	public function get_action_id(): string {
		return $this->action_id;
	}

	/**
	 * @return string
	 */
	public function get_kind(): string {
		return $this->kind;
	}

	/**
	 * @return string
	 */
	public function get_summary(): string {
		return $this->summary;
	}

	/**
	 * @return mixed
	 */
	public function get_preview() {
		return $this->preview;
	}

	/**
	 * @return mixed
	 */
	public function get_apply_input() {
		return $this->apply_input;
	}

	/**
	 * @return string|null
	 */
	public function get_created_by(): ?string {
		return $this->created_by;
	}

	/**
	 * @return string|null
	 */
	public function get_agent_id(): ?string {
		return $this->agent_id;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * @return string
	 */
	public function get_created_at(): string {
		return $this->created_at;
	}

	/**
	 * @return string|null
	 */
	public function get_expires_at(): ?string {
		return $this->expires_at;
	}

	/**
	 * Read a required array value.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @param string               $field  Field name.
	 * @return mixed
	 */
	private static function required_value( array $source, string $field ) {
		if ( ! array_key_exists( $field, $source ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: ' . $field . ' is required' );
		}

		return $source[ $field ];
	}

	/**
	 * Normalize a required string field.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return string
	 */
	private static function normalize_string( $value, string $field ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: ' . $field . ' must be a non-empty string' );
		}

		return trim( $value );
	}

	/**
	 * Normalize an optional string field.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return string|null
	 */
	private static function normalize_optional_string( $value, string $field ): ?string {
		if ( null === $value ) {
			return null;
		}

		return self::normalize_string( $value, $field );
	}

	/**
	 * Normalize a JSON-serializable array.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return array<string, mixed>
	 */
	private static function normalize_json_array( $value, string $field ): array {
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: ' . $field . ' must be an array' );
		}

		self::assert_json_serializable( $value, $field );
		return $value;
	}

	/**
	 * Normalize any JSON-serializable value.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return mixed
	 */
	private static function normalize_json_value( $value, string $field ) {
		self::assert_json_serializable( $value, $field );
		return $value;
	}

	/**
	 * Validate JSON serializability with a pure-PHP fallback for smokes.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 */
	private static function assert_json_serializable( $value, string $field ): void {
		$encoded = self::json_encode( $value );
		if ( false === $encoded || JSON_ERROR_NONE !== json_last_error() ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: ' . $field . ' must be JSON serializable' );
		}
	}

	/**
	 * Encode data with a WordPress-aware fallback.
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false Encoded JSON or false on failure.
	 */
	private static function json_encode( $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
		return json_encode( $data );
	}
}
