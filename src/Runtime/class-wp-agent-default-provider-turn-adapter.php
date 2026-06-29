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
 *
 * Dispatch is the second consumer-variable concern. A consumer that needs an
 * authenticated, transport-tuned, cached, model-config-aware, or vision-capable
 * request cannot influence the bare builder this adapter constructs by default.
 * For that case the adapter exposes a second injectable "dispatch provider"
 * strategy, symmetric with the prompt-input one. When a dispatcher is injected,
 * the adapter still owns the generic mapping (provider/model/system/messages/
 * declarations) and the generic tail (tool-call extraction, text/usage
 * normalization, result-shape assembly); the consumer owns only request
 * construction and dispatch, returning a wp-ai-client `GenerativeAiResult`. When
 * no dispatcher is injected the adapter builds and dispatches the bare
 * `wp_ai_client_prompt()` builder exactly as before — the seam is a pure
 * addition with no behavior change on the default path.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Turn-dispatch exceptions are caught by the conversation loop and returned as structured arrays, never rendered.
class WP_Agent_Default_Provider_Turn_Adapter implements WP_Agent_Provider_Turn_Adapter {

	/**
	 * Default per-request HTTP timeout, in seconds, for one agentic provider turn.
	 *
	 * wp-ai-client defaults the request timeout to 30s (see
	 * WP_AI_Client_Prompt_Builder), which is tuned for a single short completion.
	 * One turn of an agentic loop is a different shape of request: a large system
	 * prompt, many tool declarations, accumulated transcript, and — for reasoning
	 * models — extended server-side thinking before any bytes return. A single
	 * such turn routinely runs for several minutes, so the 30s ceiling aborts the
	 * request mid-generation with a transport timeout (cURL error 28) and zero
	 * completion tokens.
	 *
	 * This is the PER-REQUEST (per-turn) timeout only. It bounds one provider
	 * response, not the whole agentic session — the conversation loop bounds the
	 * session separately via its own turn count and time budget. 600s (10 minutes)
	 * is a generous ceiling for a single long reasoning turn while still failing
	 * fast on a genuinely hung connection; callers needing more can raise it via
	 * the `request_timeout` option or the
	 * `agents_api_provider_turn_request_timeout` filter.
	 *
	 * @var float
	 */
	private const DEFAULT_REQUEST_TIMEOUT = 600.0;

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

	/** @var callable|null Injectable dispatch strategy, defaulting to the bare builder. */
	private $dispatch_provider;

	/**
	 * @param string                $provider_id   Provider identifier (for example `openai`).
	 * @param string                $model_id      Model identifier.
	 * @param string                $system_prompt Default system prompt.
	 * @param array<string, mixed>  $options       Dispatch options. Recognized keys:
	 *                                              `temperature` (float), `max_tokens` (int),
	 *                                              `request_timeout` (float, seconds; per-turn
	 *                                              HTTP timeout, defaults to
	 *                                              self::DEFAULT_REQUEST_TIMEOUT),
	 *                                              `prompt_input_provider` (callable),
	 *                                              `dispatch_provider` (callable). Only keys
	 *                                              the wp-ai-client builder supports are wired.
	 */
	public function __construct( string $provider_id, string $model_id, string $system_prompt = '', array $options = array() ) {
		$this->provider_id   = $provider_id;
		$this->model_id      = $model_id;
		$this->system_prompt = $system_prompt;

		$prompt_input_provider = $options['prompt_input_provider'] ?? null;
		$dispatch_provider     = $options['dispatch_provider'] ?? null;
		unset( $options['prompt_input_provider'], $options['dispatch_provider'] );
		$this->options = $options;

		$this->prompt_input_provider = is_callable( $prompt_input_provider ) ? $prompt_input_provider : null;
		$this->dispatch_provider     = is_callable( $dispatch_provider ) ? $dispatch_provider : null;
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
	 * Set the pluggable dispatch provider.
	 *
	 * When set, the adapter delegates request construction and dispatch to the
	 * provider instead of building and dispatching the bare `wp_ai_client_prompt()`
	 * builder. The adapter still owns the generic mapping that precedes dispatch
	 * (prompt-input resolution, provider/model/system resolution, the canonical
	 * message split, and tool-declaration mapping) and the generic tail that
	 * follows it (tool-call extraction, text/usage normalization, result-shape
	 * assembly). The provider owns only request construction, authentication,
	 * transport tuning, caching, model-config, multimodal parts, and the actual
	 * dispatch call.
	 *
	 * Passing `null` restores the default bare-builder dispatch.
	 *
	 * The provider is called as `$dispatcher( array $payload )` and receives a
	 * single associative array with these keys (the mapped builder inputs, not a
	 * pre-built builder, so the consumer fully owns construction):
	 *
	 * - `provider_id`           (string)              Resolved provider identifier.
	 * - `model_id`              (string)              Resolved model identifier.
	 * - `system_prompt`         (string)              Resolved system prompt ('' when none).
	 * - `messages`              (array<int,array>)    The resolved canonical messages
	 *                                                 (post prompt-input provider), so the
	 *                                                 consumer can map them however it needs.
	 * - `prompt_parts`          (array<int,object>)   wp-ai-client MessagePart objects for the
	 *                                                 latest user turn (the current prompt).
	 * - `history`               (array<int,object>)   wp-ai-client Message DTOs for earlier turns.
	 * - `function_declarations` (array<int,object>)   wp-ai-client FunctionDeclaration objects.
	 * - `options`               (array<string,mixed>) Adapter options (`temperature`, `max_tokens`).
	 * - `request`               (WP_Agent_Provider_Turn_Request) The full request, for
	 *                                                 runtime/context/model/budget/metadata access.
	 *
	 * The provider MUST return a wp-ai-client `GenerativeAiResult` (any object the
	 * shipped normalizers understand — `toText()`, `getCandidates()`,
	 * `getTokenUsage()`). To signal failure it may return a `WP_Error` or throw;
	 * both are handled identically to the bare-builder failure path (a thrown
	 * `RuntimeException` the conversation loop catches).
	 *
	 * @param callable|null $dispatch_provider Dispatch strategy.
	 * @return self
	 */
	public function set_dispatch_provider( ?callable $dispatch_provider ): self {
		$this->dispatch_provider = $dispatch_provider;
		return $this;
	}

	/**
	 * Run one provider turn through the upstream wp-ai-client builder.
	 *
	 * @param WP_Agent_Provider_Turn_Request $request Provider-turn request.
	 * @return array<string, mixed> Normalized provider-turn result.
	 */
	public function run_turn( WP_Agent_Provider_Turn_Request $request ): array {
		$resolved      = $this->resolve_prompt_input( $request );
		$system_prompt = $resolved['system_prompt'];
		$messages      = $resolved['messages'];

		$provider_id = $this->resolve_provider_id( $request );
		$model_id    = $this->resolve_model_id( $request );

		$prompt_context        = self::split_prompt_context( $messages );
		$function_declarations = self::function_declarations( $request->toolDeclarations() );

		if ( null !== $this->dispatch_provider ) {
			$result = $this->dispatch_via_provider( $request, $provider_id, $model_id, $system_prompt, $messages, $prompt_context, $function_declarations );
		} else {
			$result = $this->dispatch_via_bare_builder( $provider_id, $model_id, $system_prompt, $prompt_context, $function_declarations );
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			throw new \RuntimeException( 'wp-ai-client request failed: ' . $result->get_error_message() );
		}

		return array(
			'content'          => WP_Agent_Provider_Turn_Result::result_text( $result ),
			'tool_calls'       => WP_Agent_Provider_Turn_Result::extract_tool_calls( $result ),
			'usage'            => WP_Agent_Provider_Turn_Result::result_usage( $result ),
			'request_metadata' => array(
				'provider_id' => $provider_id,
				'model_id'    => $model_id,
			),
		);
	}

	/**
	 * Build and dispatch the bare wp-ai-client builder (default dispatch path).
	 *
	 * This is the original, unmodified dispatch: it constructs a
	 * `wp_ai_client_prompt()` builder from the mapped inputs and calls
	 * `generate_text_result()`. It runs only when no dispatch provider is injected.
	 *
	 * @param string                                                          $provider_id           Resolved provider id.
	 * @param string                                                          $model_id              Resolved model id.
	 * @param string                                                          $system_prompt         Resolved system prompt.
	 * @param array{prompt_parts:array<int,object>,history:array<int,object>} $prompt_context        Mapped prompt context.
	 * @param array<int,object>                                               $function_declarations Mapped function declarations.
	 * @return mixed wp-ai-client GenerativeAiResult or WP_Error.
	 */
	private function dispatch_via_bare_builder( string $provider_id, string $model_id, string $system_prompt, array $prompt_context, array $function_declarations ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			throw new \RuntimeException( 'wp-ai-client is unavailable: wp_ai_client_prompt() is not defined.' );
		}

		$builder = wp_ai_client_prompt();

		if ( ! empty( $prompt_context['prompt_parts'] ) ) {
			$builder = $builder->with_message_parts( ...$prompt_context['prompt_parts'] );
		}

		/*
		 * wp-ai-client's PromptBuilder::usingModel() requires a resolved
		 * ModelInterface, not a model-id string. Passing the raw string raises a
		 * TypeError before any request is dispatched, so the model id must be
		 * resolved to the concrete model object through the provider registry
		 * first. Resolution is provider-agnostic: any provider/model registered
		 * in the wp-ai-client registry resolves the same way, with no
		 * provider-specific branching.
		 */
		if ( '' !== $model_id ) {
			if ( '' !== $provider_id ) {
				$builder = $builder->using_model( $this->resolve_model_interface( $provider_id, $model_id ) );
			} else {
				/*
				 * No provider context: hand the model id to the registry as a
				 * model preference so it can discover the owning provider. This
				 * is the string-accepting sibling of usingModel() and keeps the
				 * path provider-agnostic.
				 */
				$builder = $builder->using_model_preference( $model_id );
			}
		} elseif ( '' !== $provider_id ) {
			$builder = $builder->using_provider( $provider_id );
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

		if ( ! empty( $function_declarations ) ) {
			$builder = $builder->using_function_declarations( ...$function_declarations );
		}

		$builder = $this->apply_request_timeout( $builder );

		return $builder->generate_text_result();
	}

	/**
	 * Apply the agentic per-request HTTP timeout to the bare wp-ai-client builder.
	 *
	 * Scopes the raised timeout to this adapter's own provider turn through the
	 * builder's per-request transport options (`using_request_options()` →
	 * `RequestOptions::KEY_TIMEOUT`), rather than mutating the global
	 * `wp_ai_client_default_request_timeout` filter, so every other wp-ai-client
	 * consumer in the process keeps its own default. Replacing the builder's
	 * RequestOptions overrides the 30s default wp-ai-client sets in its
	 * constructor (the only field that default populates is the timeout).
	 *
	 * No-ops when the wp-ai-client RequestOptions DTO or the builder's
	 * `using_request_options()` setter is unavailable, leaving the default
	 * dispatch unchanged.
	 *
	 * Typed via docblock only (no PHP type hint) so the duck-typed builder the
	 * bare path constructs is accepted exactly as the surrounding dispatch code
	 * accepts it.
	 *
	 * @param \WP_AI_Client_Prompt_Builder $builder wp-ai-client prompt builder.
	 * @return \WP_AI_Client_Prompt_Builder The builder, with the per-request timeout applied when supported.
	 */
	private function apply_request_timeout( $builder ) {
		$timeout = $this->resolve_request_timeout();
		if ( $timeout <= 0.0 ) {
			return $builder;
		}

		/*
		 * The RequestOptions DTO and the builder's usingRequestOptions() setter ship
		 * together in the same wp-ai-client/php-ai-client version, so the DTO's
		 * presence is the honest capability signal. (The builder proxies setters
		 * through __call, which makes a method_exists/is_callable probe unreliable.)
		 * When the DTO is absent, leave the default dispatch untouched.
		 */
		if ( ! class_exists( \WordPress\AiClient\Providers\Http\DTO\RequestOptions::class ) ) {
			return $builder;
		}

		return $builder->using_request_options(
			\WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
				array(
					\WordPress\AiClient\Providers\Http\DTO\RequestOptions::KEY_TIMEOUT => $timeout,
				)
			)
		);
	}

	/**
	 * Resolve the per-request HTTP timeout (seconds) for one agentic provider turn.
	 *
	 * Resolution order, each layer overriding the previous only with a positive
	 * numeric value:
	 *
	 * 1. {@see self::DEFAULT_REQUEST_TIMEOUT} — the adequate out-of-the-box default.
	 * 2. The `request_timeout` adapter option — an explicit caller/agent-config override.
	 * 3. The `agents_api_provider_turn_request_timeout` filter — the runtime/site lever.
	 *
	 * Provider-agnostic: provider and model ids are passed to the filter purely as
	 * context for callers that want to tune by deployment, never branched on here.
	 *
	 * @return float Timeout in seconds.
	 */
	private function resolve_request_timeout(): float {
		$timeout = self::DEFAULT_REQUEST_TIMEOUT;

		if ( isset( $this->options['request_timeout'] ) && is_numeric( $this->options['request_timeout'] ) && (float) $this->options['request_timeout'] > 0.0 ) {
			$timeout = (float) $this->options['request_timeout'];
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the per-request HTTP timeout for one agentic provider turn.
			 *
			 * Scopes only to the default provider-turn adapter's own request, not
			 * to every wp-ai-client request in the process. The value bounds a
			 * single provider response; the conversation loop bounds the overall
			 * session separately.
			 *
			 * @param float  $timeout     Timeout in seconds.
			 * @param string $provider_id Resolved provider identifier (context only).
			 * @param string $model_id    Resolved model identifier (context only).
			 */
			/** @var mixed $filtered Filters may return anything; validate before trusting it. */
			$filtered = apply_filters( 'agents_api_provider_turn_request_timeout', $timeout, $this->provider_id, $this->model_id );
			if ( is_numeric( $filtered ) && (float) $filtered > 0.0 ) {
				$timeout = (float) $filtered;
			}
		}

		return $timeout;
	}

	/**
	 * Resolve a provider id + model id string pair into a concrete wp-ai-client ModelInterface.
	 *
	 * wp-ai-client's `PromptBuilder::usingModel()` requires a resolved
	 * {@see \WordPress\AiClient\Providers\Models\Contracts\ModelInterface}, never
	 * a model-id string. The provider registry (`AiClient::defaultRegistry()`,
	 * the same registry the bare builder is constructed from) is the canonical
	 * resolver: `getProviderModel()` maps a registered provider id + model id to
	 * the concrete model object. This stays provider-agnostic — it never special
	 * cases a provider or model id; any registered provider/model resolves
	 * through the identical call.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $model_id    Model identifier.
	 * @return \WordPress\AiClient\Providers\Models\Contracts\ModelInterface Resolved model instance.
	 * @throws \RuntimeException When wp-ai-client is unavailable or the provider/model cannot be resolved.
	 */
	private function resolve_model_interface( string $provider_id, string $model_id ): \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			throw new \RuntimeException( 'wp-ai-client is unavailable: WordPress\\AiClient\\AiClient is not loaded.' );
		}

		try {
			$model = \WordPress\AiClient\AiClient::defaultRegistry()->getProviderModel( $provider_id, $model_id );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException(
				sprintf(
					'Unable to resolve model "%s" for provider "%s" through the wp-ai-client provider registry: %s',
					$model_id,
					$provider_id,
					$error->getMessage()
				)
			);
		}

		return $model;
	}

	/**
	 * Delegate request construction and dispatch to the injected dispatch provider.
	 *
	 * The provider receives the mapped builder inputs (see {@see self::set_dispatch_provider()}
	 * for the exact payload contract) and returns a wp-ai-client GenerativeAiResult
	 * (or a WP_Error / throws on failure). The adapter retains ownership of the
	 * generic tail in {@see self::run_turn()}.
	 *
	 * @param WP_Agent_Provider_Turn_Request                                  $request               Provider-turn request.
	 * @param string                                                          $provider_id           Resolved provider id.
	 * @param string                                                          $model_id              Resolved model id.
	 * @param string                                                          $system_prompt         Resolved system prompt.
	 * @param array<int,array<string,mixed>>                                  $messages              Resolved canonical messages.
	 * @param array{prompt_parts:array<int,object>,history:array<int,object>} $prompt_context        Mapped prompt context.
	 * @param array<int,object>                                               $function_declarations Mapped function declarations.
	 * @return mixed wp-ai-client GenerativeAiResult or WP_Error.
	 */
	private function dispatch_via_provider( WP_Agent_Provider_Turn_Request $request, string $provider_id, string $model_id, string $system_prompt, array $messages, array $prompt_context, array $function_declarations ) {
		$dispatcher = $this->dispatch_provider;
		if ( ! is_callable( $dispatcher ) ) {
			throw new \RuntimeException( 'Dispatch provider is unavailable.' );
		}

		$payload = array(
			'provider_id'           => $provider_id,
			'model_id'              => $model_id,
			'system_prompt'         => $system_prompt,
			'messages'              => $messages,
			'prompt_parts'          => $prompt_context['prompt_parts'],
			'history'               => $prompt_context['history'],
			'function_declarations' => $function_declarations,
			'options'               => $this->options,
			'request'               => $request,
		);

		return call_user_func( $dispatcher, $payload );
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
				self::function_declaration_parameters( $parameters )
			);
		}

		return $declarations;
	}

	/**
	 * Guarantee a function declaration's parameter schema serializes as a JSON object.
	 *
	 * The tools API contract (OpenAI and other JSON-Schema providers) requires each
	 * `tools[].parameters` to be a JSON Schema OBJECT. A tool with no parameters — or
	 * whose registered ability schema resolves empty — yields an empty PHP `array()`,
	 * which `json_encode`s to `[]` (an array, not an object). The provider then rejects
	 * the whole request (`Invalid type for 'tools[0].parameters': expected an object,
	 * but got an array instead`), so every turn fails before any tool can run.
	 *
	 * This normalizes the empty/missing case to the minimal valid empty-object schema
	 * (`{"type":"object","properties":{}}`), using a `stdClass` for `properties` so the
	 * inner collection also encodes as `{}` rather than `[]`. A declaration that already
	 * carries a real schema is passed through unchanged; only an empty `properties`
	 * collection inside an otherwise-real schema is cast to an object for the same
	 * reason. The normalization is provider-agnostic and keys on schema shape, never on
	 * specific tool names.
	 *
	 * @param array<mixed> $parameters Resolved tool parameter schema.
	 * @return array<mixed> Schema guaranteed to JSON-encode as an object.
	 */
	private static function function_declaration_parameters( array $parameters ): array {
		if ( array() === $parameters ) {
			return array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			);
		}

		if ( array_key_exists( 'properties', $parameters ) && array() === $parameters['properties'] ) {
			$parameters['properties'] = new \stdClass();
		}

		return $parameters;
	}
}
