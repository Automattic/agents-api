<?php
/**
 * Pure-PHP smoke test for the default provider-turn adapter and run_conversation facade.
 *
 * Run with: php tests/default-provider-turn-adapter-smoke.php
 *
 * @package AgentsAPI\Tests
 */

/*
 * Minimal wp-ai-client doubles. The adapter reaches the upstream builder through
 * class_exists() guards and the wp_ai_client_prompt() entrypoint, so the smoke
 * stands in fakes that record builder calls and return a GenerativeAiResult-shaped
 * object the already-shipped extraction/normalization helpers understand.
 *
 * This file uses bracketed namespace blocks so the wp-ai-client DTO doubles can
 * live under their real namespaces alongside the global test code.
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, Generic.Commenting

namespace WordPress\AiClient\Messages\DTO {
	if ( ! class_exists( __NAMESPACE__ . '\\MessagePart' ) ) {
		class MessagePart {
			public $value;
			public function __construct( $value ) {
				$this->value = $value;
			}
		}
	}
	if ( ! class_exists( __NAMESPACE__ . '\\UserMessage' ) ) {
		class UserMessage {
			public array $parts;
			public function __construct( array $parts ) {
				$this->parts = $parts;
			}
		}
	}
	if ( ! class_exists( __NAMESPACE__ . '\\ModelMessage' ) ) {
		class ModelMessage {
			public array $parts;
			public function __construct( array $parts ) {
				$this->parts = $parts;
			}
		}
	}
}

namespace WordPress\AiClient\Tools\DTO {
	if ( ! class_exists( __NAMESPACE__ . '\\FunctionCall' ) ) {
		class FunctionCall {
			public $id;
			public $name;
			public $args;
			public function __construct( $id, $name, $args ) {
				$this->id   = $id;
				$this->name = $name;
				$this->args = $args;
			}
		}
	}
	if ( ! class_exists( __NAMESPACE__ . '\\FunctionResponse' ) ) {
		class FunctionResponse {
			public $id;
			public $name;
			public $payload;
			public function __construct( $id, $name, $payload ) {
				$this->id      = $id;
				$this->name    = $name;
				$this->payload = $payload;
			}
		}
	}
	if ( ! class_exists( __NAMESPACE__ . '\\FunctionDeclaration' ) ) {
		class FunctionDeclaration {
			public string $name;
			public string $description;
			public array $parameters;
			public function __construct( string $name, string $description, array $parameters ) {
				$this->name        = $name;
				$this->description  = $description;
				$this->parameters   = $parameters;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Models\Contracts {
	if ( ! interface_exists( __NAMESPACE__ . '\\ModelInterface' ) ) {
		interface ModelInterface {}
	}
}

namespace WordPress\AiClient\Providers\Http\DTO {
	if ( ! class_exists( __NAMESPACE__ . '\\RequestOptions' ) ) {
		/**
		 * Minimal stand-in for the wp-ai-client RequestOptions DTO so the adapter's
		 * per-request timeout path (using_request_options + RequestOptions::fromArray
		 * keyed on KEY_TIMEOUT) is exercised exactly as it is against the shipped DTO.
		 */
		class RequestOptions {
			public const KEY_TIMEOUT = 'timeout';
			private ?float $timeout = null;
			public static function fromArray( array $array ): self {
				$instance = new self();
				if ( isset( $array[ self::KEY_TIMEOUT ] ) ) {
					$instance->timeout = (float) $array[ self::KEY_TIMEOUT ];
				}
				return $instance;
			}
			public function getTimeout(): ?float {
				return $this->timeout;
			}
		}
	}
}

namespace WordPress\AiClient\Providers {
	if ( ! class_exists( __NAMESPACE__ . '\\ProviderRegistry' ) ) {
		/**
		 * Fake provider registry mirroring the resolver surface the adapter calls.
		 *
		 * The real registry resolves a provider id + model id string into a
		 * concrete ModelInterface. This double does the same for any pair and
		 * throws InvalidArgumentException for the sentinel unregistered model id,
		 * so the adapter's resolution + failure paths can be driven without the
		 * real php-ai-client SDK.
		 */
		class ProviderRegistry {
			/**
			 * @param mixed $model_config Optional model config.
			 */
			public function getProviderModel( string $provider_id, string $model_id, $model_config = null ): Models\Contracts\ModelInterface {
				unset( $model_config );
				if ( '__unregistered__' === $model_id ) {
					throw new \InvalidArgumentException( sprintf( 'Provider model not registered: %s/%s', $provider_id, $model_id ) );
				}
				return new \Agents_API_Fake_Model( $provider_id, $model_id );
			}
		}
	}
}

namespace WordPress\AiClient {
	if ( ! class_exists( __NAMESPACE__ . '\\AiClient' ) ) {
		class AiClient {
			public static function defaultRegistry(): Providers\ProviderRegistry {
				return new Providers\ProviderRegistry();
			}
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	/**
	 * Fake resolved model: a real ModelInterface instance (never a string) that
	 * carries the provider/model ids it was resolved from, so assertions can
	 * prove using_model() received an object with the requested identity.
	 */
	class Agents_API_Fake_Model implements \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
		public function __construct( public string $provider_id, public string $model_id ) {}
	}

	$failures = array();
	$passes   = 0;

	echo "agents-api-default-provider-turn-adapter-smoke\n";

	require_once __DIR__ . '/agents-api-smoke-helpers.php';
	require_once __DIR__ . '/../agents-api.php';

	/**
	 * Shared recorder so assertions can inspect what the adapter handed the builder.
	 *
	 * @var array<string, mixed> $GLOBALS['__adapter_smoke']
	 */
	$GLOBALS['__adapter_smoke'] = array();

	/** Fake GenerativeAiResult exposing the surface the substrate reads. */
	class Agents_API_Fake_Token_Usage {
		public function __construct( private int $prompt, private int $completion, private int $total ) {}
		public function getPromptTokens(): int {
			return $this->prompt;
		}
		public function getCompletionTokens(): int {
			return $this->completion;
		}
		public function getTotalTokens(): int {
			return $this->total;
		}
	}

	class Agents_API_Fake_Function_Call {
		public function __construct( private string $name, private string $args, private string $id ) {}
		public function getName(): string {
			return $this->name;
		}
		public function getArgs(): string {
			return $this->args;
		}
		public function getId(): string {
			return $this->id;
		}
	}

	class Agents_API_Fake_Part {
		public function __construct( private ?Agents_API_Fake_Function_Call $call, private string $text ) {}
		public function getFunctionCall(): ?Agents_API_Fake_Function_Call {
			return $this->call;
		}
		public function getText(): string {
			return $this->text;
		}
	}

	class Agents_API_Fake_Message {
		/** @param array<int, Agents_API_Fake_Part> $parts */
		public function __construct( private array $parts ) {}
		public function getParts(): array {
			return $this->parts;
		}
	}

	class Agents_API_Fake_Candidate {
		public function __construct( private Agents_API_Fake_Message $message ) {}
		public function getMessage(): Agents_API_Fake_Message {
			return $this->message;
		}
	}

	class Agents_API_Fake_Generative_Result {
		/** @param array<int, Agents_API_Fake_Candidate> $candidates */
		public function __construct( private string $text, private array $candidates, private Agents_API_Fake_Token_Usage $usage ) {}
		public function toText(): string {
			if ( '' === $this->text ) {
				throw new \RuntimeException( 'No text content found in result.' );
			}
			return $this->text;
		}
		public function getCandidates(): array {
			return $this->candidates;
		}
		public function getTokenUsage(): Agents_API_Fake_Token_Usage {
			return $this->usage;
		}
	}

	/** Fake builder recording the fluent calls the adapter makes. */
	class Agents_API_Fake_Prompt_Builder {
		public function with_message_parts( ...$parts ): self {
			$GLOBALS['__adapter_smoke']['prompt_parts'] = $parts;
			return $this;
		}
		public function using_provider( string $provider ): self {
			$GLOBALS['__adapter_smoke']['provider'] = $provider;
			return $this;
		}
		public function using_model( $model ): self {
			$GLOBALS['__adapter_smoke']['model'] = $model;
			return $this;
		}
		public function using_model_preference( ...$preferred_models ): self {
			$GLOBALS['__adapter_smoke']['model_preference'] = $preferred_models;
			return $this;
		}
		public function using_system_instruction( string $system ): self {
			$GLOBALS['__adapter_smoke']['system'] = $system;
			return $this;
		}
		public function using_temperature( float $temperature ): self {
			$GLOBALS['__adapter_smoke']['temperature'] = $temperature;
			return $this;
		}
		public function using_max_tokens( int $max_tokens ): self {
			$GLOBALS['__adapter_smoke']['max_tokens'] = $max_tokens;
			return $this;
		}
		public function with_history( ...$history ): self {
			$GLOBALS['__adapter_smoke']['history'] = $history;
			return $this;
		}
		public function using_function_declarations( ...$declarations ): self {
			$GLOBALS['__adapter_smoke']['declarations'] = $declarations;
			return $this;
		}
		public function using_request_options( \WordPress\AiClient\Providers\Http\DTO\RequestOptions $options ): self {
			$GLOBALS['__adapter_smoke']['request_timeout'] = $options->getTimeout();
			return $this;
		}
		public function generate_text_result() {
			if ( isset( $GLOBALS['__adapter_smoke']['select_next'] ) && is_callable( $GLOBALS['__adapter_smoke']['select_next'] ) ) {
				return call_user_func( $GLOBALS['__adapter_smoke']['select_next'] );
			}
			return $GLOBALS['__adapter_smoke']['next_result'];
		}
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		function wp_ai_client_prompt( $prompt = null ): Agents_API_Fake_Prompt_Builder {
			unset( $prompt );
			return new Agents_API_Fake_Prompt_Builder();
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			/** @param array<string, mixed> $data */
			public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
			public function get_error_code(): string {
				return $this->code;
			}
			public function get_error_message(): string {
				return $this->message;
			}
			/** @return array<string, mixed> */
			public function get_error_data() {
				return $this->data;
			}
		}
	}

	$make_result = static function ( string $text, array $tool_calls, array $usage ): Agents_API_Fake_Generative_Result {
		$parts = array();
		foreach ( $tool_calls as $call ) {
			$parts[] = new Agents_API_Fake_Part(
				new Agents_API_Fake_Function_Call( $call['name'], json_encode( $call['parameters'] ), $call['id'] ),
				''
			);
		}
		if ( '' !== $text ) {
			$parts[] = new Agents_API_Fake_Part( null, $text );
		}
		return new Agents_API_Fake_Generative_Result(
			$text,
			array( new Agents_API_Fake_Candidate( new Agents_API_Fake_Message( $parts ) ) ),
			new Agents_API_Fake_Token_Usage( $usage[0], $usage[1], $usage[2] )
		);
	};

	echo "\n[1] run_turn() returns a normalized result from a mocked wp-ai-client dispatch:\n";
	$GLOBALS['__adapter_smoke']             = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result(
		'Final answer.',
		array( array( 'name' => 'client/lookup', 'parameters' => array( 'query' => 'alpha' ), 'id' => 'call-1' ) ),
		array( 5, 7, 12 )
	);

	$adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'You are a test agent.' );
	$request = new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
		array(
			array( 'role' => 'user', 'content' => 'earlier turn' ),
			array( 'role' => 'assistant', 'content' => 'earlier answer' ),
			array( 'role' => 'user', 'content' => 'look up alpha' ),
		),
		array(
			'client/lookup' => array(
				'name'        => 'client/lookup',
				'source'      => 'client',
				'description' => 'Look up one value.',
				'parameters'  => array(
					'type'       => 'object',
					'required'   => array( 'query', 'scope_id' ),
					'properties' => array(
						'query'    => array( 'type' => 'string' ),
						'scope_id' => array( 'type' => 'integer' ),
					),
				),
				'parameter_bindings' => array(
					'scope_id' => array(
						'source'        => 'caller_context',
						'path'          => 'scope.id',
						'authoritative' => true,
					),
				),
				'executor'    => 'client',
				'scope'       => 'run',
			),
		),
		array( 'provider_id' => 'fake-provider', 'model_id' => 'fake-model' )
	);

	$raw    = $adapter->run_turn( $request );
	$result = AgentsAPI\AI\WP_Agent_Provider_Turn_Result::normalize( $raw );

	agents_api_smoke_assert_equals( 'Final answer.', $result['content'], 'run_turn surfaces assistant text', $failures, $passes );
	agents_api_smoke_assert_equals( 'client/lookup', $result['tool_calls'][0]['name'] ?? '', 'run_turn extracts tool calls via shipped extractor', $failures, $passes );
	agents_api_smoke_assert_equals( 'alpha', $result['tool_calls'][0]['parameters']['query'] ?? '', 'run_turn decodes tool-call arguments', $failures, $passes );
	agents_api_smoke_assert_equals( 12, $result['usage']['total_tokens'], 'run_turn normalizes token usage', $failures, $passes );
	$recorded_model = $GLOBALS['__adapter_smoke']['model'] ?? null;
	agents_api_smoke_assert_equals( true, $recorded_model instanceof \WordPress\AiClient\Providers\Models\Contracts\ModelInterface, 'run_turn resolves the model id to a ModelInterface before calling using_model() (not a string)', $failures, $passes );
	agents_api_smoke_assert_equals( false, is_string( $recorded_model ), 'run_turn never hands using_model() a raw model-id string', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-provider', $recorded_model->provider_id ?? '', 'the resolved model carries the requested provider id', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-model', $recorded_model->model_id ?? '', 'the resolved model carries the requested model id', $failures, $passes );
	agents_api_smoke_assert_equals( 'You are a test agent.', $GLOBALS['__adapter_smoke']['system'] ?? '', 'run_turn passes default system prompt to the builder', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $GLOBALS['__adapter_smoke']['prompt_parts'] ?? array() ), 'run_turn sends the latest user turn as the current prompt', $failures, $passes );
	agents_api_smoke_assert_equals( 2, count( $GLOBALS['__adapter_smoke']['history'] ?? array() ), 'run_turn sends earlier turns as history', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $GLOBALS['__adapter_smoke']['declarations'] ?? array() ), 'run_turn maps tool declarations to function declarations', $failures, $passes );
	agents_api_smoke_assert_equals( 'client/lookup', $GLOBALS['__adapter_smoke']['declarations'][0]->name ?? '', 'function declaration carries the logical tool name', $failures, $passes );
	$model_parameters = $GLOBALS['__adapter_smoke']['declarations'][0]->parameters ?? array();
	agents_api_smoke_assert_equals( false, array_key_exists( 'scope_id', $model_parameters['properties'] ?? array() ), 'function declaration excludes authoritative parameters from model input', $failures, $passes );
	agents_api_smoke_assert_equals( array( 'query' ), $model_parameters['required'] ?? array(), 'function declaration excludes authoritative parameters from model requirements', $failures, $passes );
	agents_api_smoke_assert_equals( 600.0, $GLOBALS['__adapter_smoke']['request_timeout'] ?? null, 'run_turn applies the raised 600s agentic per-request timeout to the builder by default', $failures, $passes );

	echo "\n[1b] request timeout is configurable and honors a caller-supplied override + filter:\n";
	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result( 'ok', array(), array( 1, 1, 2 ) );
	$override_timeout_adapter                  = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter(
		'fake-provider',
		'fake-model',
		'sys',
		array( 'request_timeout' => 900 )
	);
	$override_timeout_adapter->run_turn(
		new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'hi' ) ) )
	);
	agents_api_smoke_assert_equals( 900.0, $GLOBALS['__adapter_smoke']['request_timeout'] ?? null, 'request_timeout option overrides the default and reaches the request', $failures, $passes );

	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result( 'ok', array(), array( 1, 1, 2 ) );
	$filter_seen                               = array();
	add_filter(
		'agents_api_provider_turn_request_timeout',
		static function ( $timeout, $provider_id, $model_id ) use ( &$filter_seen ) {
			$filter_seen[] = array( $timeout, $provider_id, $model_id );
			return 1200;
		},
		10,
		3
	);
	$filter_timeout_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'sys' );
	$filter_timeout_adapter->run_turn(
		new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'hi' ) ) )
	);
	agents_api_smoke_assert_equals( 1200.0, $GLOBALS['__adapter_smoke']['request_timeout'] ?? null, 'agents_api_provider_turn_request_timeout filter overrides the timeout reaching the request', $failures, $passes );
	agents_api_smoke_assert_equals( 600.0, $filter_seen[0][0] ?? null, 'timeout filter receives the resolved default as the incoming value', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-provider', $filter_seen[0][1] ?? '', 'timeout filter receives the provider id as context', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-model', $filter_seen[0][2] ?? '', 'timeout filter receives the model id as context', $failures, $passes );
	$GLOBALS['__agents_api_smoke_actions']['agents_api_provider_turn_request_timeout'] = array();

	echo "\n[2] Prompt-input seam defaults to identity and applies an override:\n";
	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result( 'ok', array(), array( 1, 1, 2 ) );

	$identity_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'identity-system' );
	$identity_adapter->run_turn(
		new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
			array( array( 'role' => 'user', 'content' => 'hello' ) )
		)
	);
	agents_api_smoke_assert_equals( 'identity-system', $GLOBALS['__adapter_smoke']['system'] ?? '', 'identity seam passes the request/default system prompt through', $failures, $passes );

	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result( 'ok', array(), array( 1, 1, 2 ) );
	$seam_called                               = array();
	$override_adapter                          = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter(
		'fake-provider',
		'fake-model',
		'base-system',
		array(
			'prompt_input_provider' => static function ( string $system_prompt, array $messages, array $context ) use ( &$seam_called ): array {
				$seam_called[] = array( $system_prompt, count( $messages ), $context );
				return array(
					'system_prompt' => 'composed-system',
					'messages'      => array( array( 'role' => 'user', 'content' => 'composed prompt' ) ),
				);
			},
		)
	);
	$override_adapter->run_turn(
		new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
			array( array( 'role' => 'user', 'content' => 'original prompt' ) ),
			array(),
			array(),
			array(),
			array( 'turn' => 1 )
		)
	);
	agents_api_smoke_assert_equals( 'composed-system', $GLOBALS['__adapter_smoke']['system'] ?? '', 'prompt-input override replaces the system prompt before dispatch', $failures, $passes );
	agents_api_smoke_assert_equals( 'base-system', $seam_called[0][0] ?? '', 'prompt-input override receives the request/default system prompt', $failures, $passes );
	agents_api_smoke_assert_equals( 1, $seam_called[0][1] ?? 0, 'prompt-input override receives the request messages', $failures, $passes );

	echo "\n[3] run_turn() converts a wp-ai-client failure into a thrown RuntimeException:\n";
	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = new WP_Error( 'wp_ai_client_text_exception', 'provider exploded' );
	$threw                                     = false;
	try {
		$adapter->run_turn(
			new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
				array( array( 'role' => 'user', 'content' => 'fail please' ) )
			)
		);
	} catch ( \RuntimeException $error ) {
		$threw = false !== strpos( $error->getMessage(), 'provider exploded' );
	}
	agents_api_smoke_assert_equals( true, $threw, 'run_turn throws on a WP_Error dispatch result', $failures, $passes );

	echo "\n[3b] Retry-on-transient: a 429 rate limit is retried with exponential backoff, then succeeds:\n";
	$GLOBALS['__adapter_smoke'] = array();
	$retry_attempts            = 0;
	$retry_sleeps              = array();
	// Fail the first two dispatches with HTTP 429, then succeed on the third.
	$GLOBALS['__adapter_smoke']['select_next'] = static function () use ( &$retry_attempts, $make_result ) {
		++$retry_attempts;
		if ( $retry_attempts <= 2 ) {
			return new WP_Error( 'prompt_client_error', 'Too Many Requests (429)', array( 'status' => 429 ) );
		}
		return $make_result( 'recovered after backoff', array(), array( 1, 1, 2 ) );
	};
	$retry_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter(
		'fake-provider',
		'fake-model',
		'sys',
		array(
			'retry_base_delay'   => 2,
			'retry_max_attempts' => 5,
			// Non-sleeping spy: record the requested wait without ever sleeping.
			'retry_sleeper'      => static function ( $seconds ) use ( &$retry_sleeps ) {
				$retry_sleeps[] = $seconds;
			},
			// Zero jitter so the backoff schedule is the deterministic half-of-capped sequence.
			'retry_randomizer'   => static function () {
				return 0.0;
			},
		)
	);
	$retry_raw    = $retry_adapter->run_turn(
		new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'hi' ) ) )
	);
	$retry_result = AgentsAPI\AI\WP_Agent_Provider_Turn_Result::normalize( $retry_raw );
	agents_api_smoke_assert_equals( 'recovered after backoff', $retry_result['content'], 'run_turn retries past transient 429s and returns the eventual success', $failures, $passes );
	agents_api_smoke_assert_equals( 3, $retry_attempts, 'run_turn dispatched 3 times (2 retried 429s + 1 success)', $failures, $passes );
	agents_api_smoke_assert_equals( 2, count( $retry_sleeps ), 'run_turn waited once before each of the 2 retries (and never after success)', $failures, $passes );
	agents_api_smoke_assert_equals( 2.0, $retry_sleeps[0] ?? null, 'first backoff wait is the 2s exponential floor (zero jitter)', $failures, $passes );
	agents_api_smoke_assert_equals( 4.0, $retry_sleeps[1] ?? null, 'second backoff wait doubles to the 4s exponential floor', $failures, $passes );
	agents_api_smoke_assert_equals( true, ( $retry_sleeps[1] ?? 0 ) > ( $retry_sleeps[0] ?? 0 ), 'backoff waits strictly increase across retries', $failures, $passes );

	echo "\n[3c] Fail-fast: a deterministic 400 is NOT retried:\n";
	$GLOBALS['__adapter_smoke'] = array();
	$ff_attempts               = 0;
	$ff_sleeps                 = array();
	$GLOBALS['__adapter_smoke']['select_next'] = static function () use ( &$ff_attempts ) {
		++$ff_attempts;
		return new WP_Error( 'prompt_client_error', 'Bad Request (400)', array( 'status' => 400 ) );
	};
	$ff_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter(
		'fake-provider',
		'fake-model',
		'sys',
		array(
			'retry_sleeper'    => static function ( $seconds ) use ( &$ff_sleeps ) {
				$ff_sleeps[] = $seconds;
			},
			'retry_randomizer' => static function () {
				return 0.0;
			},
		)
	);
	$ff_threw = false;
	try {
		$ff_adapter->run_turn(
			new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'hi' ) ) )
		);
	} catch ( \RuntimeException $error ) {
		$ff_threw = false !== strpos( $error->getMessage(), '400' );
	}
	agents_api_smoke_assert_equals( true, $ff_threw, 'a 400 fails fast as a RuntimeException carrying the real message', $failures, $passes );
	agents_api_smoke_assert_equals( 1, $ff_attempts, 'a 400 is dispatched exactly once (never retried)', $failures, $passes );
	agents_api_smoke_assert_equals( 0, count( $ff_sleeps ), 'a 400 never triggers a backoff wait', $failures, $passes );

	echo "\n[3d] Transient 5xx is retried; exhausting the attempt budget surfaces the original error:\n";
	$GLOBALS['__adapter_smoke'] = array();
	$exhaust_attempts          = 0;
	$exhaust_sleeps            = array();
	$GLOBALS['__adapter_smoke']['select_next'] = static function () use ( &$exhaust_attempts ) {
		++$exhaust_attempts;
		return new WP_Error( 'prompt_upstream_server_error', 'Service Unavailable (503)', array( 'status' => 503 ) );
	};
	$exhaust_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter(
		'fake-provider',
		'fake-model',
		'sys',
		array(
			'retry_max_attempts' => 3,
			'retry_base_delay'   => 2,
			'retry_sleeper'      => static function ( $seconds ) use ( &$exhaust_sleeps ) {
				$exhaust_sleeps[] = $seconds;
			},
			'retry_randomizer'   => static function () {
				return 0.0;
			},
		)
	);
	$exhaust_threw = false;
	try {
		$exhaust_adapter->run_turn(
			new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'hi' ) ) )
		);
	} catch ( \RuntimeException $error ) {
		$exhaust_threw = false !== strpos( $error->getMessage(), '503' );
	}
	agents_api_smoke_assert_equals( true, $exhaust_threw, 'an unrecovered 5xx surfaces the original error after the budget is exhausted', $failures, $passes );
	agents_api_smoke_assert_equals( 3, $exhaust_attempts, 'a persistent 5xx is dispatched exactly retry_max_attempts (3) times', $failures, $passes );
	agents_api_smoke_assert_equals( 2, count( $exhaust_sleeps ), 'a persistent 5xx waits between attempts but not after the final one', $failures, $passes );

	echo "\n[3e] A provider Retry-After hint is honored as the wait when present:\n";
	$GLOBALS['__adapter_smoke'] = array();
	$ra_attempts               = 0;
	$ra_sleeps                 = array();
	$GLOBALS['__adapter_smoke']['select_next'] = static function () use ( &$ra_attempts, $make_result ) {
		++$ra_attempts;
		if ( 1 === $ra_attempts ) {
			return new WP_Error( 'prompt_client_error', 'Too Many Requests (429)', array( 'status' => 429, 'retry_after' => 5 ) );
		}
		return $make_result( 'recovered after retry-after', array(), array( 1, 1, 2 ) );
	};
	$ra_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter(
		'fake-provider',
		'fake-model',
		'sys',
		array(
			'retry_base_delay' => 2,
			'retry_sleeper'    => static function ( $seconds ) use ( &$ra_sleeps ) {
				$ra_sleeps[] = $seconds;
			},
			'retry_randomizer' => static function () {
				return 0.0;
			},
		)
	);
	$ra_adapter->run_turn(
		new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'hi' ) ) )
	);
	agents_api_smoke_assert_equals( 5.0, $ra_sleeps[0] ?? null, 'a Retry-After hint (5s) is used as the wait instead of the exponential step', $failures, $passes );

	echo "\n[3f] Retry policy is configurable via filters, and each retry emits a diagnostic event:\n";
	$GLOBALS['__adapter_smoke'] = array();
	$filter_attempts           = 0;
	$filter_sleeps             = array();
	$retry_events              = array();
	add_action(
		'agents_api_provider_turn_retry',
		static function ( $context ) use ( &$retry_events ) {
			$retry_events[] = $context;
		}
	);
	add_filter(
		'agents_api_provider_turn_retry_max_attempts',
		static function ( $attempts, $provider_id, $model_id ) {
			unset( $provider_id, $model_id );
			return 2;
		},
		10,
		3
	);
	$GLOBALS['__adapter_smoke']['select_next'] = static function () use ( &$filter_attempts ) {
		++$filter_attempts;
		return new WP_Error( 'prompt_client_error', 'Too Many Requests (429)', array( 'status' => 429 ) );
	};
	$filter_retry_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter(
		'fake-provider',
		'fake-model',
		'sys',
		array(
			'retry_sleeper'    => static function ( $seconds ) use ( &$filter_sleeps ) {
				$filter_sleeps[] = $seconds;
			},
			'retry_randomizer' => static function () {
				return 0.0;
			},
		)
	);
	try {
		$filter_retry_adapter->run_turn(
			new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'hi' ) ) )
		);
	} catch ( \RuntimeException $error ) {
		unset( $error );
	}
	agents_api_smoke_assert_equals( 2, $filter_attempts, 'the retry_max_attempts filter caps the dispatch count (2)', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $retry_events ), 'one retry diagnostic event fires before the single retry wait', $failures, $passes );
	agents_api_smoke_assert_equals( 429, $retry_events[0]['status'] ?? null, 'the retry event reports the transient HTTP status', $failures, $passes );
	agents_api_smoke_assert_equals( 1, $retry_events[0]['attempt'] ?? null, 'the retry event reports the failed attempt number', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-provider', $retry_events[0]['provider_id'] ?? '', 'the retry event carries the provider id', $failures, $passes );
	$GLOBALS['__agents_api_smoke_actions']['agents_api_provider_turn_retry']             = array();
	$GLOBALS['__agents_api_smoke_actions']['agents_api_provider_turn_retry_max_attempts'] = array();

	echo "\n[4] run_conversation() drives the loop end-to-end through the default adapter:\n";
	$turn = 0;
	$GLOBALS['__adapter_smoke'] = array();

	$executor = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
		/** @var array<int, array<string, mixed>> Executed tool calls. */
		public array $executed = array();
		public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
			unset( $tool_definition, $context );
			$this->executed[] = $tool_call;
			return array(
				'success'   => true,
				'tool_name' => $tool_call['tool_name'],
				'result'    => array( 'value' => 'looked-up' ),
			);
		}
	};

	/*
	 * The loop calls the adapter once per turn. First turn emits a tool call, the
	 * loop mediates it through the executor, then the second turn returns final text.
	 */
	$results_by_turn = array(
		1 => $make_result( 'Need a lookup.', array( array( 'name' => 'client/lookup', 'parameters' => array( 'query' => 'beta' ), 'id' => 'call-conv-1' ) ), array( 3, 4, 7 ) ),
		2 => $make_result( 'All done from default adapter.', array(), array( 2, 2, 4 ) ),
	);

	// Recreate the fake builder so each turn pulls its own queued result.
	$GLOBALS['__adapter_smoke']['next_result'] = $results_by_turn[1];

	// The builder reads next_result lazily at generate time, so swap it as turns advance
	// by wrapping wp_ai_client_prompt via a turn counter on the recorder.
	$GLOBALS['__adapter_smoke']['turn'] = 0;
	$GLOBALS['__adapter_smoke']['results_by_turn'] = $results_by_turn;

	// Override the builder's generate to advance the queued result per call.
	// Achieved by a subclass installed through a closure-bound result selector.
	$GLOBALS['__adapter_smoke']['select_next'] = static function () use ( $results_by_turn ) {
		$GLOBALS['__adapter_smoke']['turn'] = ( $GLOBALS['__adapter_smoke']['turn'] ?? 0 ) + 1;
		$index                              = $GLOBALS['__adapter_smoke']['turn'];
		return $results_by_turn[ $index ] ?? $results_by_turn[ 2 ];
	};

	$conversation = AgentsAPI\AI\WP_Agent_Conversation_Loop::run_conversation(
		array( array( 'role' => 'user', 'content' => 'look up beta' ) ),
		array(
			'client/lookup' => array(
				'name'        => 'client/lookup',
				'source'      => 'client',
				'description' => 'Look up one value.',
				'parameters'  => array( 'type' => 'object', 'properties' => array( 'query' => array( 'type' => 'string' ) ) ),
				'executor'    => 'client',
				'scope'       => 'run',
			),
		),
		'fake-provider',
		'fake-model',
		array(
			'system_prompt' => 'run-conversation-system',
			'tool_executor' => $executor,
			'max_turns'     => 3,
		)
	);

	agents_api_smoke_assert_equals( 1, count( $executor->executed ), 'run_conversation mediated the adapter tool call through the executor', $failures, $passes );
	agents_api_smoke_assert_equals( 'call-conv-1', $executor->executed[0]['id'] ?? '', 'run_conversation preserved the provider tool-call id', $failures, $passes );
	agents_api_smoke_assert_equals( 'All done from default adapter.', $conversation['final_content'], 'run_conversation surfaces the adapter final content', $failures, $passes );
	agents_api_smoke_assert_equals( 11, $conversation['usage']['total_tokens'], 'run_conversation accumulates adapter usage across turns', $failures, $passes );
	agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Conversation_Result::OUTCOME_STATUS_COMPLETED, $conversation['run_outcome']['status'] ?? '', 'run_conversation completes the run', $failures, $passes );

	echo "\n[5] Dispatch seam: injected dispatcher receives the documented payload and its result drives the shared tail:\n";
	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result( 'should-not-be-used', array(), array( 99, 99, 99 ) );

	$dispatch_payload  = null;
	$dispatcher_result = $make_result(
		'Dispatched answer.',
		array( array( 'name' => 'client/lookup', 'parameters' => array( 'query' => 'gamma' ), 'id' => 'call-dispatch-1' ) ),
		array( 8, 9, 17 )
	);

	$dispatch_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'dispatch-system', array( 'temperature' => 0.5, 'max_tokens' => 256 ) );
	$dispatch_adapter->set_dispatch_provider(
		static function ( array $payload ) use ( &$dispatch_payload, $dispatcher_result ) {
			$dispatch_payload = $payload;
			return $dispatcher_result;
		}
	);

	$dispatch_request = new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
		array(
			array( 'role' => 'user', 'content' => 'earlier turn' ),
			array( 'role' => 'assistant', 'content' => 'earlier answer' ),
			array( 'role' => 'user', 'content' => 'look up gamma' ),
		),
		array(
			'client/lookup' => array(
				'name'        => 'client/lookup',
				'source'      => 'client',
				'description' => 'Look up one value.',
				'parameters'  => array(
					'type'       => 'object',
					'required'   => array( 'query' ),
					'properties' => array( 'query' => array( 'type' => 'string' ) ),
				),
				'executor'    => 'client',
				'scope'       => 'run',
			),
		),
		array( 'provider_id' => 'fake-provider', 'model_id' => 'fake-model' )
	);

	$dispatch_raw    = $dispatch_adapter->run_turn( $dispatch_request );
	$dispatch_result = AgentsAPI\AI\WP_Agent_Provider_Turn_Result::normalize( $dispatch_raw );

	// The injected dispatcher's result must drive the shared tail, NOT the bare builder.
	agents_api_smoke_assert_equals( 'Dispatched answer.', $dispatch_result['content'], 'dispatch seam: adapter normalizes the dispatcher result text', $failures, $passes );
	agents_api_smoke_assert_equals( 'client/lookup', $dispatch_result['tool_calls'][0]['name'] ?? '', 'dispatch seam: adapter still extracts tool calls from the dispatcher result', $failures, $passes );
	agents_api_smoke_assert_equals( 'gamma', $dispatch_result['tool_calls'][0]['parameters']['query'] ?? '', 'dispatch seam: adapter decodes tool-call args from the dispatcher result', $failures, $passes );
	agents_api_smoke_assert_equals( 17, $dispatch_result['usage']['total_tokens'], 'dispatch seam: adapter normalizes usage from the dispatcher result', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-provider', $dispatch_result['request_metadata']['provider_id'] ?? '', 'dispatch seam: adapter assembles request metadata', $failures, $passes );
	// The bare builder must NOT have been invoked (its recorded result is unused).
	agents_api_smoke_assert_equals( true, ! isset( $GLOBALS['__adapter_smoke']['provider'] ), 'dispatch seam: bare builder is bypassed entirely when a dispatcher is set', $failures, $passes );

	// The documented payload contract.
	agents_api_smoke_assert_equals( 'fake-provider', $dispatch_payload['provider_id'] ?? '', 'dispatch payload carries provider_id', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-model', $dispatch_payload['model_id'] ?? '', 'dispatch payload carries model_id', $failures, $passes );
	agents_api_smoke_assert_equals( 'dispatch-system', $dispatch_payload['system_prompt'] ?? '', 'dispatch payload carries the resolved system prompt', $failures, $passes );
	agents_api_smoke_assert_equals( 3, count( $dispatch_payload['messages'] ?? array() ), 'dispatch payload carries the resolved canonical messages', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $dispatch_payload['prompt_parts'] ?? array() ), 'dispatch payload carries the current-prompt message parts', $failures, $passes );
	agents_api_smoke_assert_equals( 2, count( $dispatch_payload['history'] ?? array() ), 'dispatch payload carries the history messages', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $dispatch_payload['function_declarations'] ?? array() ), 'dispatch payload carries the function declarations', $failures, $passes );
	agents_api_smoke_assert_equals( 'client/lookup', $dispatch_payload['function_declarations'][0]->name ?? '', 'dispatch payload function declaration carries the logical tool name', $failures, $passes );
	agents_api_smoke_assert_equals( 0.5, $dispatch_payload['options']['temperature'] ?? null, 'dispatch payload carries adapter options (temperature)', $failures, $passes );
	agents_api_smoke_assert_equals( 256, $dispatch_payload['options']['max_tokens'] ?? null, 'dispatch payload carries adapter options (max_tokens)', $failures, $passes );
	agents_api_smoke_assert_equals( true, ( $dispatch_payload['request'] ?? null ) instanceof AgentsAPI\AI\WP_Agent_Provider_Turn_Request, 'dispatch payload carries the WP_Agent_Provider_Turn_Request', $failures, $passes );

	echo "\n[6] Dispatch seam honors the prompt-input seam (mapping runs before dispatch):\n";
	$GLOBALS['__adapter_smoke'] = array();
	$seam_payload              = null;
	$composed_adapter          = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter(
		'fake-provider',
		'fake-model',
		'base-system',
		array(
			'prompt_input_provider' => static function ( string $system_prompt, array $messages, array $context ): array {
				unset( $messages, $context );
				return array(
					'system_prompt' => 'composed-before-dispatch',
					'messages'      => array( array( 'role' => 'user', 'content' => 'composed for dispatch' ) ),
				);
			},
			'dispatch_provider'     => static function ( array $payload ) use ( &$seam_payload, $make_result ) {
				$seam_payload = $payload;
				return $make_result( 'composed dispatched', array(), array( 1, 1, 2 ) );
			},
		)
	);
	$composed_adapter->run_turn(
		new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
			array( array( 'role' => 'user', 'content' => 'original' ) )
		)
	);
	agents_api_smoke_assert_equals( 'composed-before-dispatch', $seam_payload['system_prompt'] ?? '', 'dispatch payload reflects the prompt-input seam transform', $failures, $passes );

	echo "\n[7] Dispatch seam: dispatcher failure (WP_Error and throw) is handled like the bare path:\n";
	$wp_error_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'sys' );
	$wp_error_adapter->set_dispatch_provider(
		static function ( array $payload ) {
			unset( $payload );
			return new WP_Error( 'wp_ai_client_text_exception', 'dispatcher exploded' );
		}
	);
	$wp_error_threw = false;
	try {
		$wp_error_adapter->run_turn(
			new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'fail' ) ) )
		);
	} catch ( \RuntimeException $error ) {
		$wp_error_threw = false !== strpos( $error->getMessage(), 'dispatcher exploded' );
	}
	agents_api_smoke_assert_equals( true, $wp_error_threw, 'dispatch seam: a WP_Error from the dispatcher throws a RuntimeException', $failures, $passes );

	$throw_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'sys' );
	$throw_adapter->set_dispatch_provider(
		static function ( array $payload ) {
			unset( $payload );
			throw new \RuntimeException( 'dispatcher threw directly' );
		}
	);
	$throw_propagated = false;
	try {
		$throw_adapter->run_turn(
			new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'fail' ) ) )
		);
	} catch ( \RuntimeException $error ) {
		$throw_propagated = false !== strpos( $error->getMessage(), 'dispatcher threw directly' );
	}
	agents_api_smoke_assert_equals( true, $throw_propagated, 'dispatch seam: a thrown error from the dispatcher propagates to the caller', $failures, $passes );

	echo "\n[8] set_dispatch_provider( null ) restores the default bare-builder dispatch:\n";
	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result( 'bare again', array(), array( 1, 2, 3 ) );
	$restore_adapter                           = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'restore-system' );
	$restore_adapter->set_dispatch_provider( static fn ( array $payload ) => $make_result( 'via dispatcher', array(), array( 0, 0, 0 ) ) );
	$restore_adapter->set_dispatch_provider( null );
	$restore_raw = $restore_adapter->run_turn(
		new AgentsAPI\AI\WP_Agent_Provider_Turn_Request( array( array( 'role' => 'user', 'content' => 'hi' ) ) )
	);
	agents_api_smoke_assert_equals( 'bare again', $restore_raw['content'], 'set_dispatch_provider(null) falls back to the bare builder', $failures, $passes );
	agents_api_smoke_assert_equals( true, ( $GLOBALS['__adapter_smoke']['model'] ?? null ) instanceof \WordPress\AiClient\Providers\Models\Contracts\ModelInterface, 'restored bare path drives the wp_ai_client builder again with a resolved ModelInterface', $failures, $passes );

	echo "\n[9] Public result normalization helpers on WP_Agent_Provider_Turn_Result:\n";
	$helper_result = $make_result( 'helper text', array(), array( 4, 6, 10 ) );
	agents_api_smoke_assert_equals( 'helper text', AgentsAPI\AI\WP_Agent_Provider_Turn_Result::result_text( $helper_result ), 'public result_text() extracts assistant text', $failures, $passes );
	$helper_usage = AgentsAPI\AI\WP_Agent_Provider_Turn_Result::result_usage( $helper_result );
	agents_api_smoke_assert_equals( 4, $helper_usage['prompt_tokens'], 'public result_usage() extracts prompt tokens', $failures, $passes );
	agents_api_smoke_assert_equals( 6, $helper_usage['completion_tokens'], 'public result_usage() extracts completion tokens', $failures, $passes );
	agents_api_smoke_assert_equals( 10, $helper_usage['total_tokens'], 'public result_usage() extracts total tokens', $failures, $passes );
	$tool_only_result = $make_result( '', array( array( 'name' => 'client/lookup', 'parameters' => array( 'q' => 'x' ), 'id' => 'tc-1' ) ), array( 1, 0, 1 ) );
	agents_api_smoke_assert_equals( '', AgentsAPI\AI\WP_Agent_Provider_Turn_Result::result_text( $tool_only_result ), 'public result_text() returns empty for a tool-only turn', $failures, $passes );

	echo "\n[10] Regression: a STRING model id is resolved to a ModelInterface and the turn dispatches (no TypeError):\n";
	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result( 'resolved-and-dispatched', array(), array( 1, 2, 3 ) );

	$regression_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'regression-system' );
	$regression_request = new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
		array( array( 'role' => 'user', 'content' => 'resolve my model' ) ),
		array(),
		// Model metadata supplied as plain STRINGS, exactly like the native runtime.
		array( 'provider_id' => 'gpt-provider', 'model_id' => 'gpt-5.5' )
	);
	$regression_raw   = $regression_adapter->run_turn( $regression_request );
	$regression_model = $GLOBALS['__adapter_smoke']['model'] ?? null;

	agents_api_smoke_assert_equals( true, $regression_model instanceof \WordPress\AiClient\Providers\Models\Contracts\ModelInterface, 'string model id "gpt-5.5" is resolved to a ModelInterface (the exact TypeError regression)', $failures, $passes );
	agents_api_smoke_assert_equals( 'gpt-provider', $regression_model->provider_id ?? '', 'resolution is provider-agnostic: an arbitrary provider id resolves the same way', $failures, $passes );
	agents_api_smoke_assert_equals( 'gpt-5.5', $regression_model->model_id ?? '', 'the resolved ModelInterface carries the requested string model id', $failures, $passes );
	agents_api_smoke_assert_equals( 'resolved-and-dispatched', $regression_raw['content'], 'the turn dispatches and produces content (no TypeError, no empty completion)', $failures, $passes );

	echo "\n[11] Regression: an unresolvable model id surfaces a clear RuntimeException, not a TypeError:\n";
	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result( 'unused', array(), array( 0, 0, 0 ) );
	$bad_model_adapter                         = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'sys' );
	$bad_model_message                         = '';
	try {
		$bad_model_adapter->run_turn(
			new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
				array( array( 'role' => 'user', 'content' => 'bad model' ) ),
				array(),
				array( 'provider_id' => 'fake-provider', 'model_id' => '__unregistered__' )
			)
		);
	} catch ( \RuntimeException $error ) {
		$bad_model_message = $error->getMessage();
	}
	agents_api_smoke_assert_equals( true, false !== strpos( $bad_model_message, 'Unable to resolve model' ), 'an unresolvable model id throws an actionable RuntimeException (not a TypeError)', $failures, $passes );
	agents_api_smoke_assert_equals( true, false !== strpos( $bad_model_message, '__unregistered__' ), 'the resolution error names the unresolvable model id', $failures, $passes );

	echo "\n[12] Regression: a no-parameter tool's function declaration serializes 'parameters' as a JSON OBJECT, never an array:\n";
	$GLOBALS['__adapter_smoke']                = array();
	$GLOBALS['__adapter_smoke']['next_result'] = $make_result( 'ok', array(), array( 1, 1, 2 ) );

	$params_adapter = new AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter( 'fake-provider', 'fake-model', 'sys' );
	$params_request = new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
		array( array( 'role' => 'user', 'content' => 'use the tools' ) ),
		array(
			// A no-arg tool whose registered schema resolved empty — the exact native-runtime
			// case (workspace_*, *_github_* tools) that produced `"parameters":[]` → OpenAI 400.
			'workspace_show' => array(
				'name'        => 'workspace_show',
				'source'      => 'agents',
				'description' => 'Show a workspace.',
				'parameters'  => array(),
				'executor'    => 'host',
				'scope'       => 'run',
			),
			// A tool that already carries a real object schema — must pass through unchanged.
			'client/lookup'  => array(
				'name'        => 'client/lookup',
				'source'      => 'client',
				'description' => 'Look up one value.',
				'parameters'  => array(
					'type'       => 'object',
					'required'   => array( 'query' ),
					'properties' => array( 'query' => array( 'type' => 'string' ) ),
				),
				'executor'    => 'client',
				'scope'       => 'run',
			),
		),
		array( 'provider_id' => 'fake-provider', 'model_id' => 'fake-model' )
	);

	$params_adapter->run_turn( $params_request );
	$mapped_declarations = $GLOBALS['__adapter_smoke']['declarations'] ?? array();
	agents_api_smoke_assert_equals( 2, count( $mapped_declarations ), 'both tool declarations are mapped to function declarations', $failures, $passes );

	$decls_by_name = array();
	foreach ( $mapped_declarations as $decl ) {
		$decls_by_name[ $decl->name ?? '' ] = $decl;
	}

	// The no-arg tool: parameters MUST json-encode to an object, never `[]`.
	$empty_json = json_encode( $decls_by_name['workspace_show']->parameters ?? null );
	agents_api_smoke_assert_equals( '{"type":"object","properties":{}}', $empty_json, 'no-parameter tool emits the minimal valid empty-object schema', $failures, $passes );
	agents_api_smoke_assert_equals( true, false !== strpos( $empty_json, '"properties":{' ), 'empty parameters serialize properties as an object ({), not an array ([)', $failures, $passes );
	agents_api_smoke_assert_equals( false, '[]' === $empty_json, "no-parameter tool never serializes 'parameters' as []", $failures, $passes );
	agents_api_smoke_assert_equals( true, '{' === substr( $empty_json, 0, 1 ), 'no-parameter tool parameters JSON begins with { (an object)', $failures, $passes );

	// Prove the exact provider-facing payload shape: `"parameters":{` must appear and
	// `"parameters":[` must never appear when the declaration is wrapped for the API.
	$wrapped_empty = json_encode(
		array(
			'type'       => 'function',
			'name'       => $decls_by_name['workspace_show']->name ?? '',
			'parameters' => $decls_by_name['workspace_show']->parameters ?? null,
		)
	);
	agents_api_smoke_assert_equals( true, false !== strpos( $wrapped_empty, '"parameters":{' ), 'wrapped tool payload contains "parameters":{ (an object)', $failures, $passes );
	agents_api_smoke_assert_equals( false, false !== strpos( $wrapped_empty, '"parameters":[' ), 'wrapped tool payload never contains "parameters":[ (the OpenAI 400 shape)', $failures, $passes );

	// The real-schema tool: object stays an object and the declared schema is preserved verbatim.
	$real_json = json_encode( $decls_by_name['client/lookup']->parameters ?? null );
	agents_api_smoke_assert_equals( true, '{' === substr( $real_json, 0, 1 ), 'real-schema tool parameters remain a JSON object', $failures, $passes );
	agents_api_smoke_assert_equals( true, false !== strpos( $real_json, '"query"' ), 'real-schema tool preserves its declared properties unchanged', $failures, $passes );
	agents_api_smoke_assert_equals( 'object', $decls_by_name['client/lookup']->parameters['type'] ?? '', 'real-schema tool keeps its declared type', $failures, $passes );

	agents_api_smoke_finish( 'Agents API default provider-turn adapter', $failures, $passes );
}
