<?php
/**
 * Agent conversation runner request contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Neutral request object for conversation runner implementations.
 */
class AgentConversationRequest {

	public const DEFAULT_MAX_TURNS = 10;

	/** @var array<int, array<string, mixed>> Initial conversation messages. */
	private array $messages;

	/** @var array<int, array<string, mixed>> Available tools. */
	private array $tools;

	/** @var array<string, mixed> Provider/model configuration. */
	private array $model_config;

	/** @var string Execution mode. */
	private string $mode;

	/** @var array<string, mixed> Runtime payload. */
	private array $payload;

	/** @var int Maximum turn budget requested by caller. */
	private int $max_turns;

	/** @var bool Whether to stop after one model turn. */
	private bool $single_turn;

	/**
	 * Build a request from a common runner argument list.
	 *
	 * @param array  $messages    Initial conversation messages.
	 * @param array  $tools       Available tools.
	 * @param string $provider    Provider identifier.
	 * @param string $model       Model identifier.
	 * @param string $mode        Execution mode.
	 * @param array  $payload     Runtime payload.
	 * @param int    $max_turns   Maximum conversation turns.
	 * @param bool   $single_turn Single-turn mode flag.
	 * @return self Request object.
	 */
	public static function fromRunArgs(
		array $messages,
		array $tools,
		string $provider,
		string $model,
		string $mode,
		array $payload = array(),
		int $max_turns = self::DEFAULT_MAX_TURNS,
		bool $single_turn = false
	): self {
		return new self(
			$messages,
			$tools,
			array(
				'provider' => $provider,
				'model'    => $model,
			),
			$mode,
			$payload,
			$max_turns,
			$single_turn
		);
	}

	/**
	 * @param array  $messages     Initial conversation messages.
	 * @param array  $tools        Available tools.
	 * @param array  $model_config Provider/model configuration.
	 * @param string $mode         Execution mode.
	 * @param array  $payload      Runtime payload.
	 * @param int    $max_turns    Maximum conversation turns.
	 * @param bool   $single_turn  Single-turn mode flag.
	 */
	public function __construct(
		array $messages,
		array $tools,
		array $model_config,
		string $mode,
		array $payload = array(),
		int $max_turns = self::DEFAULT_MAX_TURNS,
		bool $single_turn = false
	) {
		$this->messages     = AgentMessageEnvelope::normalize_many( $messages );
		$this->tools        = self::normalize_list_of_arrays( $tools, 'tools' );
		$this->model_config = self::normalize_model_config( $model_config );
		$this->mode         = self::normalize_string( $mode, 'mode' );
		$this->payload      = $payload;
		$this->max_turns    = max( 1, $max_turns );
		$this->single_turn  = $single_turn;
	}

	/** @return array<int, array<string, mixed>> Initial conversation messages. */
	public function messages(): array {
		return $this->messages;
	}

	/** @return array<int, array<string, mixed>> Available tools. */
	public function tools(): array {
		return $this->tools;
	}

	/** @return array<string, mixed> Provider/model configuration. */
	public function modelConfig(): array {
		return $this->model_config;
	}

	/** @return string Provider identifier. */
	public function provider(): string {
		return $this->model_config['provider'];
	}

	/** @return string Model identifier. */
	public function model(): string {
		return $this->model_config['model'];
	}

	/** @return string Execution mode. */
	public function mode(): string {
		return $this->mode;
	}

	/** @return array<string, mixed> Runtime payload. */
	public function payload(): array {
		return $this->payload;
	}

	/** @return int Maximum turn budget requested by caller. */
	public function maxTurns(): int {
		return $this->max_turns;
	}

	/** @return bool Whether to stop after one model turn. */
	public function singleTurn(): bool {
		return $this->single_turn;
	}

	/**
	 * Return a normalized array representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'messages'     => $this->messages,
			'tools'        => $this->tools,
			'model_config' => $this->model_config,
			'mode'         => $this->mode,
			'payload'      => $this->payload,
			'max_turns'    => $this->max_turns,
			'single_turn'  => $this->single_turn,
		);
	}

	/**
	 * Normalize a list whose entries must be arrays.
	 *
	 * @param array  $items List items.
	 * @param string $path  Field path for validation errors.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_list_of_arrays( array $items, string $path ): array {
		$normalized = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				throw new \InvalidArgumentException( 'invalid_agent_conversation_request: ' . $path . '[' . $index . '] must be an array' );
			}
			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * Normalize provider/model configuration.
	 *
	 * @param array $model_config Raw model configuration.
	 * @return array<string, mixed>
	 */
	private static function normalize_model_config( array $model_config ): array {
		$model_config['provider'] = self::normalize_string( $model_config['provider'] ?? '', 'model_config.provider' );
		$model_config['model']    = self::normalize_string( $model_config['model'] ?? '', 'model_config.model' );

		return $model_config;
	}

	/**
	 * Normalize a required non-empty string.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $path  Field path.
	 * @return string
	 */
	private static function normalize_string( $value, string $path ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_agent_conversation_request: ' . $path . ' must be a non-empty string' );
		}

		return trim( $value );
	}
}
