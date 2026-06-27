<?php
/**
 * Default provider-turn adapter.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Reference provider-turn adapter that dispatches one model turn through the
 * upstream wp-ai-client prompt builder.
 *
 * This is the first concrete implementation of {@see WP_Agent_Provider_Turn_Adapter}.
 * It owns only the generic turn: map canonical agent messages and tool
 * declarations into the wp-ai-client builder, dispatch via
 * `generate_text_result()`, then normalize the assistant text, tool calls, and
 * token usage into the shape {@see WP_Agent_Provider_Turn_Result::normalize()}
 * expects. The conversation loop keeps ownership of continuation, mediated tool
 * execution, transcript events, and stop conditions.
 *
 * The adapter consumes the UPSTREAM wp-ai-client builder reached through the
 * `wp_ai_client_prompt()` entrypoint. It deliberately does NOT introduce a new
 * prompt-builder abstraction — the only generic work it adds is the mapping
 * step into that existing builder.
 *
 * Prompt assembly is the one genuinely consumer-variable concern, so the
 * adapter exposes a single injectable "prompt-input provider" strategy. The
 * default is identity (pass the request's system prompt + messages straight
 * through). A consumer with its own directive-composition layer can inject a
 * callable that transforms `(system_prompt, messages, context)` into
 * `(system_prompt, messages)` before the builder is populated, without
 * replacing the dispatch, extraction, or normalization the adapter provides.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Turn-dispatch exceptions are caught by the conversation loop and returned as structured arrays, never rendered.
class WP_Agent_Default_Provider_Turn_Adapter implements WP_Agent_Provider_Turn_Adapter {

	/** @var string Provider identifier passed to the wp-ai-client builder. */
	private string $provider_id;

	/** @var string Model identifier passed to the wp-ai-client builder. */
	private string $model_id;

	/** @var string Default system prompt applied when the request omits one. */
	private string $system_prompt;

	/** @var array<string, mixed> Dispatch options (temperature, max_tokens). */
	private array $options;

	/** @var callable|null Injectable prompt-input strategy, defaulting to identity. */
	private $prompt_input_provider;

	/**
	 * @param string                $provider_id   Provider identifier (for example `openai`).
	 * @param string                $model_id      Model identifier.
	 * @param string                $system_prompt Default system prompt.
	 * @param array<string, mixed>  $options       Dispatch options. Recognized keys:
	 *                                              `temperature` (float), `max_tokens` (int),
	 *                                              `prompt_input_provider` (callable). Only keys
	 *                                              the wp-ai-client builder supports are wired.
	 */
	public function __construct( string $provider_id, string $model_id, string $system_prompt = '', array $options = array() ) {
		$this->provider_id   = $provider_id;
		$this->model_id      = $model_id;
		$this->system_prompt = $system_prompt;

		$prompt_input_provider = $options['prompt_input_provider'] ?? null;
		unset( $options['prompt_input_provider'] );
		$this->options = $options;

		$this->prompt_input_provider = is_callable( $prompt_input_provider ) ? $prompt_input_provider : null;
	}

	/**
	 * Set the pluggable prompt-input provider.
	 *
	 * The provider receives `(string $system_prompt, array $messages, array $context)`
	 * and must return either `[ $system_prompt, $messages ]` or
	 * `[ 'system_prompt' => ..., 'messages' => ... ]`. Passing `null` restores the
	 * identity (pass-through) default.
	 *
	 * @param callable|null $prompt_input_provider Prompt-input strategy.
	 * @return self
	 */
	public function set_prompt_input_provider( ?callable $prompt_input_provider ): self {
		$this->prompt_input_provider = $prompt_input_provider;
		return $this;
	}

	/**
	 * Run one provider turn through the upstream wp-ai-client builder.
	 *
	 * @param WP_Agent_Provider_Turn_Request $request Provider-turn request.
	 * @return array<string, mixed> Normalized provider-turn result.
	 */
	public function run_turn( WP_Agent_Provider_Turn_Request $request ): array {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			throw new \RuntimeException( 'wp-ai-client is unavailable: wp_ai_client_prompt() is not defined.' );
		}

		$resolved      = $this->resolve_prompt_input( $request );
		$system_prompt = $resolved['system_prompt'];
		$messages      = $resolved['messages'];

		$provider_id = $this->resolve_provider_id( $request );
		$model_id    = $this->resolve_model_id( $request );

		$prompt_context = self::split_prompt_context( $messages );

		$builder = wp_ai_client_prompt();

		if ( ! empty( $prompt_context['prompt_parts'] ) ) {
			$builder = $builder->with_message_parts( ...$prompt_context['prompt_parts'] );
		}

		if ( '' !== $provider_id ) {
			$builder = $builder->using_provider( $provider_id );
		}

		if ( '' !== $model_id ) {
			$builder = $builder->using_model( $model_id );
		}

		if ( '' !== $system_prompt ) {
			$builder = $builder->using_system_instruction( $system_prompt );
		}

		if ( isset( $this->options['temperature'] ) && is_numeric( $this->options['temperature'] ) ) {
			$builder = $builder->using_temperature( (float) $this->options['temperature'] );
		}

		if ( isset( $this->options['max_tokens'] ) && is_numeric( $this->options['max_tokens'] ) ) {
			$builder = $builder->using_max_tokens( (int) $this->options['max_tokens'] );
		}

		if ( ! empty( $prompt_context['history'] ) ) {
			$builder = $builder->with_history( ...$prompt_context['history'] );
		}

		$function_declarations = self::function_declarations( $request->toolDeclarations() );
		if ( ! empty( $function_declarations ) ) {
			$builder = $builder->using_function_declarations( ...$function_declarations );
		}

		$result = $builder->generate_text_result();

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			throw new \RuntimeException( 'wp-ai-client request failed: ' . $result->get_error_message() );
		}

		return array(
			'content'          => self::result_text( $result ),
			'tool_calls'       => WP_Agent_Provider_Turn_Result::extract_tool_calls( $result ),
			'usage'            => self::result_usage( $result ),
			'request_metadata' => array(
				'provider_id' => $provider_id,
				'model_id'    => $model_id,
			),
		);
	}

	/**
	 * Resolve the system prompt + message set handed to the wp-ai-client builder.
	 *
	 * Default behavior is identity: the request's system prompt and messages are
	 * used as-is. When a prompt-input provider is injected, it owns the transform.
	 *
	 * @param WP_Agent_Provider_Turn_Request $request Provider-turn request.
	 * @return array{system_prompt:string,messages:array<int,array<string,mixed>>}
	 */
	private function resolve_prompt_input( WP_Agent_Provider_Turn_Request $request ): array {
		$system_prompt = $this->request_system_prompt( $request );
		$messages      = $request->messages();

		if ( null === $this->prompt_input_provider ) {
			return array(
				'system_prompt' => $system_prompt,
				'messages'      => $messages,
			);
		}

		$produced = call_user_func( $this->prompt_input_provider, $system_prompt, $messages, $request->context() );

		if ( is_array( $produced ) ) {
			if ( array_key_exists( 'system_prompt', $produced ) || array_key_exists( 'messages', $produced ) ) {
				$system_prompt = is_string( $produced['system_prompt'] ?? null ) ? $produced['system_prompt'] : $system_prompt;
				$messages      = is_array( $produced['messages'] ?? null ) ? $produced['messages'] : $messages;
			} elseif ( array_is_list( $produced ) ) {
				$system_prompt = is_string( $produced[0] ?? null ) ? $produced[0] : $system_prompt;
				$messages      = is_array( $produced[1] ?? null ) ? $produced[1] : $messages;
			}
		}

		return array(
			'system_prompt' => $system_prompt,
			'messages'      => WP_Agent_Message::normalize_many( $messages ),
		);
	}

	/**
	 * Resolve the request system prompt, falling back to the constructed default.
	 *
	 * @param WP_Agent_Provider_Turn_Request $request Provider-turn request.
	 * @return string
	 */
	private function request_system_prompt( WP_Agent_Provider_Turn_Request $request ): string {
		$context = $request->context();
		$runtime = $request->runtime();
		foreach ( array( $context['system_prompt'] ?? null, $runtime['system_prompt'] ?? null ) as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate ) {
				return $candidate;
			}
		}

		return $this->system_prompt;
	}

	/**
	 * Resolve the provider id from request metadata or the constructor default.
	 *
	 * @param WP_Agent_Provider_Turn_Request $request Provider-turn request.
	 * @return string
	 */
	private function resolve_provider_id( WP_Agent_Provider_Turn_Request $request ): string {
		$model = $request->model();
		$value = $model['provider_id'] ?? null;

		return is_string( $value ) && '' !== $value ? $value : $this->provider_id;
	}

	/**
	 * Resolve the model id from request metadata or the constructor default.
	 *
	 * @param WP_Agent_Provider_Turn_Request $request Provider-turn request.
	 * @return string
	 */
	private function resolve_model_id( WP_Agent_Provider_Turn_Request $request ): string {
		$model = $request->model();
		$value = $model['model_id'] ?? null;

		return is_string( $value ) && '' !== $value ? $value : $this->model_id;
	}

	/**
	 * Split canonical messages into the wp-ai-client current prompt + history.
	 *
	 * wp-ai-client expects the current user turn supplied through the builder
	 * (here via `with_message_parts()`) and earlier turns through
	 * `with_history()`. The latest user message becomes the current prompt; all
	 * other messages become history.
	 *
	 * @param array<int, mixed> $messages Canonical messages.
	 * @return array{prompt_parts:array<int,object>,history:array<int,object>}
	 */
	private static function split_prompt_context( array $messages ): array {
		$prompt_index = null;
		$prompt_parts = array();

		foreach ( $messages as $index => $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			if ( 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			$candidate_parts = self::message_parts( $message );
			if ( ! empty( $candidate_parts ) ) {
				$prompt_index = $index;
				$prompt_parts = $candidate_parts;
			}
		}

		$history = array();
		foreach ( $messages as $index => $message ) {
			if ( $index === $prompt_index || ! is_array( $message ) ) {
				continue;
			}

			$history_message = self::history_message( $message );
			if ( null !== $history_message ) {
				$history[] = $history_message;
			}
		}

		return array(
			'prompt_parts' => $prompt_parts,
			'history'      => $history,
		);
	}

	/**
	 * Convert a canonical message envelope into a wp-ai-client history Message DTO.
	 *
	 * @param array<mixed> $message Canonical message envelope.
	 * @return object|null wp-ai-client Message DTO, or null when unmappable.
	 */
	private static function history_message( array $message ): ?object {
		$role  = is_string( $message['role'] ?? null ) ? $message['role'] : '';
		$parts = self::message_parts( $message );
		if ( empty( $parts ) ) {
			return null;
		}

		if ( ( 'assistant' === $role || 'model' === $role ) && class_exists( \WordPress\AiClient\Messages\DTO\ModelMessage::class ) ) {
			return new \WordPress\AiClient\Messages\DTO\ModelMessage( $parts );
		}

		if ( class_exists( \WordPress\AiClient\Messages\DTO\UserMessage::class ) ) {
			return new \WordPress\AiClient\Messages\DTO\UserMessage( $parts );
		}

		return null;
	}

	/**
	 * Convert a canonical message envelope into wp-ai-client MessagePart objects.
	 *
	 * Handles plain text plus the canonical tool-call and tool-result envelopes
	 * so multi-turn tool transcripts replay correctly. Unmappable shapes yield an
	 * empty list and are skipped by callers.
	 *
	 * @param array<mixed> $message Canonical message envelope.
	 * @return array<int, object> wp-ai-client MessagePart objects.
	 */
	private static function message_parts( array $message ): array {
		if ( ! class_exists( \WordPress\AiClient\Messages\DTO\MessagePart::class ) ) {
			return array();
		}

		try {
			$envelope = WP_Agent_Message::normalize( $message );
		} catch ( \Throwable $error ) {
			unset( $error );
			return self::text_parts( $message['content'] ?? '' );
		}

		$type     = is_string( $envelope['type'] ?? null ) ? $envelope['type'] : WP_Agent_Message::TYPE_TEXT;
		$payload  = is_array( $envelope['payload'] ?? null ) ? $envelope['payload'] : array();
		$metadata = is_array( $envelope['metadata'] ?? null ) ? $envelope['metadata'] : array();

		if ( WP_Agent_Message::TYPE_TOOL_CALL === $type ) {
			$tool_name = is_string( $payload['tool_name'] ?? null ) ? $payload['tool_name'] : '';
			$call_id   = self::tool_call_id( $metadata, $payload );
			if ( ( '' === $tool_name && '' === $call_id ) || ! class_exists( \WordPress\AiClient\Tools\DTO\FunctionCall::class ) ) {
				return array();
			}

			$parameters = is_array( $payload['parameters'] ?? null ) ? $payload['parameters'] : array();

			return array(
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionCall(
						'' !== $call_id ? $call_id : null,
						'' !== $tool_name ? $tool_name : null,
						$parameters
					)
				),
			);
		}

		if ( WP_Agent_Message::TYPE_TOOL_RESULT === $type ) {
			$tool_name = is_string( $payload['tool_name'] ?? null ) ? $payload['tool_name'] : '';
			$call_id   = self::tool_call_id( $metadata, $payload );
			if ( ( '' === $tool_name && '' === $call_id ) || ! class_exists( \WordPress\AiClient\Tools\DTO\FunctionResponse::class ) ) {
				return array();
			}

			return array(
				new \WordPress\AiClient\Messages\DTO\MessagePart(
					new \WordPress\AiClient\Tools\DTO\FunctionResponse(
						'' !== $call_id ? $call_id : null,
						'' !== $tool_name ? $tool_name : null,
						$payload
					)
				),
			);
		}

		return self::text_parts( $envelope['content'] ?? '' );
	}

	/**
	 * Build text-only wp-ai-client MessagePart objects from message content.
	 *
	 * @param mixed $content Message content (string or list of blocks).
	 * @return array<int, object>
	 */
	private static function text_parts( $content ): array {
		if ( ! class_exists( \WordPress\AiClient\Messages\DTO\MessagePart::class ) ) {
			return array();
		}

		if ( is_string( $content ) ) {
			return '' !== $content ? array( new \WordPress\AiClient\Messages\DTO\MessagePart( $content ) ) : array();
		}

		if ( ! is_array( $content ) ) {
			return array();
		}

		$parts = array();
		foreach ( $content as $part ) {
			if ( is_string( $part ) && '' !== $part ) {
				$parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart( $part );
				continue;
			}

			if ( ! is_array( $part ) ) {
				continue;
			}

			$text = $part['text'] ?? $part['content'] ?? null;
			if ( is_string( $text ) && '' !== $text ) {
				$parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart( $text );
			}
		}

		return $parts;
	}

	/**
	 * Resolve the provider tool-call id from envelope metadata or payload.
	 *
	 * @param array<mixed> $metadata Envelope metadata.
	 * @param array<mixed> $payload  Envelope payload.
	 * @return string
	 */
	private static function tool_call_id( array $metadata, array $payload ): string {
		foreach ( array( $metadata['tool_call_id'] ?? null, $payload['tool_call_id'] ?? null ) as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Convert normalized tool declarations into wp-ai-client FunctionDeclaration objects.
	 *
	 * @param array<string, array<string, mixed>> $tool_declarations Tool declarations keyed by name.
	 * @return array<int, object>
	 */
	private static function function_declarations( array $tool_declarations ): array {
		if ( ! class_exists( \WordPress\AiClient\Tools\DTO\FunctionDeclaration::class ) ) {
			return array();
		}

		$declarations = array();
		foreach ( $tool_declarations as $name => $tool ) {
			$tool_name = is_string( $tool['name'] ?? null ) && '' !== $tool['name'] ? $tool['name'] : (string) $name;
			if ( '' === $tool_name ) {
				continue;
			}

			$description = is_string( $tool['description'] ?? null ) ? $tool['description'] : '';
			$parameters  = is_array( $tool['parameters'] ?? null ) ? $tool['parameters'] : array();

			$declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
				$tool_name,
				$description,
				$parameters
			);
		}

		return $declarations;
	}

	/**
	 * Extract assistant text from a wp-ai-client result.
	 *
	 * @param mixed $result wp-ai-client GenerativeAiResult.
	 * @return string
	 */
	private static function result_text( $result ): string {
		if ( is_object( $result ) && method_exists( $result, 'toText' ) ) {
			try {
				$text = $result->toText();

				return is_string( $text ) ? $text : '';
			} catch ( \Throwable $error ) {
				// Results without text content (tool-only turns) throw; treat as empty.
				if ( false !== strpos( $error->getMessage(), 'No text content found' ) ) {
					return '';
				}

				throw $error;
			}
		}

		return '';
	}

	/**
	 * Normalize token usage from a wp-ai-client result.
	 *
	 * @param mixed $result wp-ai-client GenerativeAiResult.
	 * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
	 */
	private static function result_usage( $result ): array {
		$usage = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		);

		if ( ! is_object( $result ) || ! method_exists( $result, 'getTokenUsage' ) ) {
			return $usage;
		}

		$token_usage = $result->getTokenUsage();
		if ( ! is_object( $token_usage ) ) {
			return $usage;
		}

		if ( method_exists( $token_usage, 'getPromptTokens' ) ) {
			$prompt_tokens          = $token_usage->getPromptTokens();
			$usage['prompt_tokens'] = is_numeric( $prompt_tokens ) ? (int) $prompt_tokens : 0;
		}

		if ( method_exists( $token_usage, 'getCompletionTokens' ) ) {
			$completion_tokens          = $token_usage->getCompletionTokens();
			$usage['completion_tokens'] = is_numeric( $completion_tokens ) ? (int) $completion_tokens : 0;
		}

		if ( method_exists( $token_usage, 'getTotalTokens' ) ) {
			$total_tokens          = $token_usage->getTotalTokens();
			$usage['total_tokens'] = is_numeric( $total_tokens ) ? (int) $total_tokens : 0;
		}

		return $usage;
	}
}
