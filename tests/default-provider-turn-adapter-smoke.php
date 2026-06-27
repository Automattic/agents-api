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

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
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
		public function using_model( string $model ): self {
			$GLOBALS['__adapter_smoke']['model'] = $model;
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
			public function __construct( private string $code = '', private string $message = '' ) {}
			public function get_error_code(): string {
				return $this->code;
			}
			public function get_error_message(): string {
				return $this->message;
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
					'required'   => array( 'query' ),
					'properties' => array( 'query' => array( 'type' => 'string' ) ),
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
	agents_api_smoke_assert_equals( 'fake-provider', $GLOBALS['__adapter_smoke']['provider'] ?? '', 'run_turn passes provider id to the builder', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-model', $GLOBALS['__adapter_smoke']['model'] ?? '', 'run_turn passes model id to the builder', $failures, $passes );
	agents_api_smoke_assert_equals( 'You are a test agent.', $GLOBALS['__adapter_smoke']['system'] ?? '', 'run_turn passes default system prompt to the builder', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $GLOBALS['__adapter_smoke']['prompt_parts'] ?? array() ), 'run_turn sends the latest user turn as the current prompt', $failures, $passes );
	agents_api_smoke_assert_equals( 2, count( $GLOBALS['__adapter_smoke']['history'] ?? array() ), 'run_turn sends earlier turns as history', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $GLOBALS['__adapter_smoke']['declarations'] ?? array() ), 'run_turn maps tool declarations to function declarations', $failures, $passes );
	agents_api_smoke_assert_equals( 'client/lookup', $GLOBALS['__adapter_smoke']['declarations'][0]->name ?? '', 'function declaration carries the logical tool name', $failures, $passes );

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

	agents_api_smoke_finish( 'Agents API default provider-turn adapter', $failures, $passes );
}
