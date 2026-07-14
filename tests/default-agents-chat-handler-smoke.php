<?php
/**
 * Pure-PHP smoke test for the default agents/chat runtime handler.
 *
 * Proves that Agents API runs a real agent loop turn natively behind the
 * canonical `agents/chat` ability — no Data Machine, no external runtime — and
 * that dispatch is provider-agnostic (driven only by the requested provider +
 * model through the wp-ai-client builder, here stubbed with a deterministic
 * fake provider that emits a tool call then a final assistant message).
 *
 * Run with: php tests/default-agents-chat-handler-smoke.php
 *
 * @package AgentsAPI\Tests
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
				$this->description = $description;
				$this->parameters  = $parameters;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Models\Contracts {
	if ( ! interface_exists( __NAMESPACE__ . '\\ModelInterface' ) ) {
		interface ModelInterface {}
	}
}

namespace WordPress\AiClient\Providers {
	if ( ! class_exists( __NAMESPACE__ . '\\ProviderRegistry' ) ) {
		/**
		 * Fake provider registry mirroring the resolver surface the adapter calls.
		 *
		 * Resolves any provider id + model id string pair into a concrete
		 * ModelInterface, exactly like the real registry, so provider-agnostic
		 * dispatch can be exercised without the php-ai-client SDK.
		 */
		class ProviderRegistry {
			/**
			 * @param mixed $model_config Optional model config.
			 */
			public function getProviderModel( string $provider_id, string $model_id, $model_config = null ): Models\Contracts\ModelInterface {
				unset( $model_config );
				return new \Agents_Chat_Fake_Model( $provider_id, $model_id );
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
	 * Fake resolved model: a real ModelInterface instance (never a string),
	 * carrying the provider/model ids it was resolved from.
	 */
	if ( ! class_exists( 'Agents_Chat_Fake_Model' ) ) {
		class Agents_Chat_Fake_Model implements \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
			public function __construct( public string $provider_id, public string $model_id ) {}
		}
	}

	$failures = array();
	$passes   = 0;

	echo "agents-api-default-agents-chat-handler-smoke\n";

	require_once __DIR__ . '/agents-api-smoke-helpers.php';

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
			public function get_error_code(): string {
				return $this->code;
			}
			public function get_error_message(): string {
				return $this->message;
			}
			public function get_error_data(): mixed {
				return $this->data;
			}
		}
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( string $cap ): bool {
			unset( $cap );
			return false;
		}
	}

	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id(): int {
			return 0;
		}
	}

	// --- Minimal Abilities API doubles so the ability tool executor can dispatch. ---
	if ( ! class_exists( 'WP_Ability' ) ) {
		class WP_Ability {
			/** @var callable */
			public $runner;
			public function __construct(
				private string $name,
				private string $description,
				private array $input_schema,
				callable $runner
			) {
				$this->runner = $runner;
			}
			public function get_name(): string {
				return $this->name;
			}
			public function get_label(): string {
				return $this->name;
			}
			public function get_category(): string {
				return 'test';
			}
			public function get_description(): string {
				return $this->description;
			}
			public function get_input_schema(): array {
				return $this->input_schema;
			}
			public function execute( array $parameters ) {
				return call_user_func( $this->runner, $parameters );
			}
		}
	}

	$GLOBALS['__chat_handler_abilities'] = array();
	$GLOBALS['__chat_handler_ability_calls'] = array();

	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ) {
			return $GLOBALS['__chat_handler_abilities'][ $name ] ?? null;
		}
	}
	if ( ! function_exists( 'wp_has_ability' ) ) {
		function wp_has_ability( string $name ): bool {
			return isset( $GLOBALS['__chat_handler_abilities'][ $name ] );
		}
	}

	$GLOBALS['__chat_handler_abilities']['kitchen/lookup'] = new WP_Ability(
		'kitchen/lookup',
		'Look up a kitchen fact.',
		array(
			'type'       => 'object',
			'required'   => array( 'query' ),
			'properties' => array( 'query' => array( 'type' => 'string' ) ),
		),
		static function ( array $parameters ): array {
			$GLOBALS['__chat_handler_ability_calls'][] = $parameters;
			return array( 'answer' => 'mise en place for ' . ( $parameters['query'] ?? '' ) );
		}
	);

	// --- Deterministic fake wp-ai-client provider (provider-agnostic dispatch). ---
	$GLOBALS['__adapter_smoke'] = array();

	class Agents_Chat_Fake_Token_Usage {
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
	class Agents_Chat_Fake_Function_Call {
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
	class Agents_Chat_Fake_Part {
		public function __construct( private ?Agents_Chat_Fake_Function_Call $call, private string $text ) {}
		public function getFunctionCall(): ?Agents_Chat_Fake_Function_Call {
			return $this->call;
		}
		public function getText(): string {
			return $this->text;
		}
	}
	class Agents_Chat_Fake_Message {
		public function __construct( private array $parts ) {}
		public function getParts(): array {
			return $this->parts;
		}
	}
	class Agents_Chat_Fake_Candidate {
		public function __construct( private Agents_Chat_Fake_Message $message ) {}
		public function getMessage(): Agents_Chat_Fake_Message {
			return $this->message;
		}
	}
	class Agents_Chat_Fake_Generative_Result {
		public function __construct( private string $text, private array $candidates, private Agents_Chat_Fake_Token_Usage $usage ) {}
		public function toText(): string {
			if ( '' === $this->text ) {
				throw new \RuntimeException( 'No text content found in result.' );
			}
			return $this->text;
		}
		public function getCandidates(): array {
			return $this->candidates;
		}
		public function getTokenUsage(): Agents_Chat_Fake_Token_Usage {
			return $this->usage;
		}
	}
	class Agents_Chat_Fake_Prompt_Builder {
		public function with_message_parts( ...$parts ): self {
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
			return $this;
		}
		public function using_max_tokens( int $max_tokens ): self {
			return $this;
		}
		public function with_history( ...$history ): self {
			return $this;
		}
		public function using_function_declarations( ...$declarations ): self {
			$GLOBALS['__adapter_smoke']['declarations'] = $declarations;
			return $this;
		}
		public function generate_text_result() {
			$turn = ( $GLOBALS['__adapter_smoke']['turn'] ?? 0 ) + 1;
			$GLOBALS['__adapter_smoke']['turn'] = $turn;
			$results = $GLOBALS['__adapter_smoke']['results_by_turn'] ?? array();
			return $results[ $turn ] ?? end( $results );
		}
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		function wp_ai_client_prompt( $prompt = null ): Agents_Chat_Fake_Prompt_Builder {
			unset( $prompt );
			return new Agents_Chat_Fake_Prompt_Builder();
		}
	}

	$make_result = static function ( string $text, array $tool_calls, array $usage ): Agents_Chat_Fake_Generative_Result {
		$parts = array();
		foreach ( $tool_calls as $call ) {
			$parts[] = new Agents_Chat_Fake_Part(
				new Agents_Chat_Fake_Function_Call( $call['name'], json_encode( $call['parameters'] ), $call['id'] ),
				''
			);
		}
		if ( '' !== $text ) {
			$parts[] = new Agents_Chat_Fake_Part( null, $text );
		}
		return new Agents_Chat_Fake_Generative_Result(
			$text,
			array( new Agents_Chat_Fake_Candidate( new Agents_Chat_Fake_Message( $parts ) ) ),
			new Agents_Chat_Fake_Token_Usage( $usage[0], $usage[1], $usage[2] )
		);
	};

	require_once __DIR__ . '/../agents-api.php';

	class Agents_Chat_Runtime_Overlay_Executor implements \AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
		/** @var array<int,array<string,mixed>> */
		public array $calls = array();

		public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
			unset( $context );
			$this->calls[] = array(
				'parameters' => $tool_call['parameters'] ?? array(),
				'definition' => $tool_definition,
			);
			return array( 'success' => true, 'result' => array( 'executor' => 'runtime-overlay' ) );
		}
	}

	use function AgentsAPI\AI\Channels\agents_chat_dispatch;
	use function AgentsAPI\AI\Channels\register_chat_handler;

	// Mark `init` as fired so the registry can be read, then register an agent
	// directly. This mirrors a runtime-bundle import that registered an agent
	// with provider/model/system-prompt/tools in its default config.
	$GLOBALS['__agents_api_smoke_done']['init'] = 1;
	$registry = WP_Agents_Registry::get_instance();
	$registry->register(
		'kitchen-brain',
		array(
			'label'          => 'Kitchen Brain',
			'default_config' => array(
				'provider'      => 'fake-provider',
				'model'         => 'fake-model',
				'system_prompt' => 'You are the kitchen brain.',
				'tools'         => array( 'kitchen/lookup' ),
			),
		)
	);

	// Queue the two provider turns: turn 1 calls the tool, turn 2 answers.
	$reset_provider = static function () use ( $make_result ): void {
		$GLOBALS['__adapter_smoke'] = array(
			'turn'            => 0,
			'results_by_turn' => array(
				1 => $make_result(
					'',
					array( array( 'name' => 'kitchen/lookup', 'parameters' => array( 'query' => 'risotto' ), 'id' => 'call-1' ) ),
					array( 5, 3, 8 )
				),
				2 => $make_result( 'All set, chef.', array(), array( 4, 6, 10 ) ),
			),
		);
	};

	echo "\n[1] Default handler runs a native agent loop turn through the registered agent:\n";
	$GLOBALS['__chat_handler_ability_calls'] = array();
	$reset_provider();

	$output = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent'   => 'kitchen-brain',
			'message' => 'How do I prep risotto?',
		)
	);

	agents_api_smoke_assert_equals( false, $output instanceof WP_Error, 'handler returns canonical output, not WP_Error', $failures, $passes );
	agents_api_smoke_assert_equals( true, is_string( $output['session_id'] ?? null ) && '' !== $output['session_id'], 'output carries a non-empty session_id', $failures, $passes );
	agents_api_smoke_assert_equals( 'All set, chef.', $output['reply'] ?? null, 'output reply is the final assistant message from the loop', $failures, $passes );
	agents_api_smoke_assert_equals( true, $output['completed'] ?? false, 'output marks the turn completed', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $GLOBALS['__chat_handler_ability_calls'] ), 'the loop mediated exactly one tool call through the ability executor', $failures, $passes );
	agents_api_smoke_assert_equals( 'risotto', $GLOBALS['__chat_handler_ability_calls'][0]['query'] ?? '', 'the mediated tool received the model-supplied parameters', $failures, $passes );
	$kitchen_model = $GLOBALS['__adapter_smoke']['model'] ?? null;
	agents_api_smoke_assert_equals( true, $kitchen_model instanceof \WordPress\AiClient\Providers\Models\Contracts\ModelInterface, 'dispatch resolved the model id to a ModelInterface before using_model() (not a string)', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-provider', $kitchen_model->provider_id ?? '', 'dispatch is provider-agnostic: the resolved model carries the requested provider id', $failures, $passes );
	agents_api_smoke_assert_equals( 'fake-model', $kitchen_model->model_id ?? '', 'dispatch is provider-agnostic: the resolved model carries the requested model id', $failures, $passes );
	agents_api_smoke_assert_equals( 'You are the kitchen brain.', $GLOBALS['__adapter_smoke']['system'] ?? '', 'the agent default-config system prompt drove the turn', $failures, $passes );
	agents_api_smoke_assert_equals( 2, (int) ( $output['metadata']['agents_api']['turn_count'] ?? 0 ), 'metadata records the two-turn native loop', $failures, $passes );
	$canonical_roles = array_map( static fn( array $m ): string => $m['role'], $output['messages'] ?? array() );
	agents_api_smoke_assert_equals( true, in_array( 'user', $canonical_roles, true ), 'canonical messages include the user turn', $failures, $passes );
	agents_api_smoke_assert_equals( true, in_array( 'assistant', $canonical_roles, true ), 'canonical messages include the assistant reply', $failures, $passes );
	agents_api_smoke_assert_equals( true, ! in_array( 'tool', $canonical_roles, true ), 'canonical messages omit raw tool envelopes', $failures, $passes );

	echo "\n[1b] Runtime-bundle agents declare their toolset as `enabled_tools` and the loop wires it:\n";
	// Native runtime agent bundles place their toolset under
	// `agent_config.enabled_tools` (the field the bundle schema/validators use),
	// which the importer forwards verbatim as the agent default config. The
	// default handler must recognize it, or the agent runs with zero tools, the
	// model narrates instead of acting, and the loop stops after one tool-less turn.
	$registry->register(
		'bundle-brain',
		array(
			'label'          => 'Bundle Brain',
			'default_config' => array(
				'provider'      => 'fake-provider',
				'model'         => 'fake-model',
				'system_prompt' => 'You are the bundle brain.',
				'enabled_tools' => array( 'kitchen/lookup' ),
			),
		)
	);

	$GLOBALS['__chat_handler_ability_calls'] = array();
	$reset_provider();

	$bundle_output = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent'   => 'bundle-brain',
			'message' => 'How do I prep risotto?',
		)
	);

	agents_api_smoke_assert_equals( false, $bundle_output instanceof WP_Error, 'enabled_tools agent returns canonical output, not WP_Error', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $GLOBALS['__chat_handler_ability_calls'] ), 'enabled_tools wired the toolset so the loop mediated the tool call', $failures, $passes );
	agents_api_smoke_assert_equals( 'risotto', $GLOBALS['__chat_handler_ability_calls'][0]['query'] ?? '', 'the enabled_tools-declared tool received the model-supplied parameters', $failures, $passes );
	agents_api_smoke_assert_equals( 2, (int) ( $bundle_output['metadata']['agents_api']['turn_count'] ?? 0 ), 'enabled_tools agent ran the full tool-mediated loop, not a single tool-less turn', $failures, $passes );

	echo "\n[1c] A trusted runtime overlay can bind an enabled tool to a registered executor:\n";
	$overlay_executor = new Agents_Chat_Runtime_Overlay_Executor();
	add_filter(
		'agents_api_tool_executors',
		static function ( array $executors ) use ( $overlay_executor ): array {
			$executors['test/runtime-overlay'] = $overlay_executor;
			return $executors;
		}
	);
	$GLOBALS['__chat_handler_ability_calls'] = array();
	$reset_provider();
	$overlay_output = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent'   => 'bundle-brain',
			'message' => 'Use the runtime tool.',
			'client_context' => array(
				'api_token' => 'never-forward-this',
				'runtime_tool_declarations' => array(
					'kitchen/lookup' => array(
						'name'        => 'kitchen/lookup',
						'source'      => 'kitchen',
						'description' => 'Runtime kitchen lookup.',
						'parameters'  => array(
							'type'       => 'object',
							'required'   => array( 'query' ),
							'properties' => array(
								'query'  => array( 'type' => 'string' ),
								'token'  => array( 'type' => 'string' ),
							),
						),
						'parameter_bindings' => array( 'token' => 'client_context.api_token' ),
						'executor'    => 'host',
						'scope'       => 'run',
						'runtime'     => array( 'executor_target' => 'test/runtime-overlay' ),
					),
				),
			),
		)
	);
	agents_api_smoke_assert_equals( false, $overlay_output instanceof WP_Error, 'trusted enabled-tool overlay runs successfully', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $overlay_executor->calls ), 'registered runtime executor receives the overlay call', $failures, $passes );
	agents_api_smoke_assert_equals( 0, count( $GLOBALS['__chat_handler_ability_calls'] ), 'overlay target replaces the default ability executor', $failures, $passes );
	agents_api_smoke_assert_equals( 'risotto', $overlay_executor->calls[0]['parameters']['query'] ?? '', 'overlay executor receives model parameters', $failures, $passes );
	agents_api_smoke_assert_equals( false, array_key_exists( 'token', $overlay_executor->calls[0]['parameters'] ?? array() ), 'client context is not ambiently exposed to parameter bindings', $failures, $passes );
	agents_api_smoke_assert_equals( 'string', $overlay_executor->calls[0]['definition']['parameters']['properties']['query']['type'] ?? '', 'overlay executor receives the normalized overlay schema', $failures, $passes );

	$extra_overlay = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent' => 'bundle-brain', 'message' => 'reject extra',
			'client_context' => array( 'runtime_tool_declarations' => array(
				'kitchen/extra' => array( 'name' => 'kitchen/extra', 'source' => 'kitchen', 'description' => 'Extra.', 'parameters' => array(), 'executor' => 'host', 'scope' => 'run' ),
			) ),
		)
	);
	agents_api_smoke_assert_equals( 'agents_chat_invalid_runtime_tool_declaration', $extra_overlay instanceof WP_Error ? $extra_overlay->get_error_code() : '', 'overlay cannot add an undeclared tool', $failures, $passes );

	$malformed_overlay = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent' => 'bundle-brain', 'message' => 'reject malformed',
			'client_context' => array( 'runtime_tool_declarations' => array(
				'kitchen/lookup' => array( 'name' => 'kitchen/lookup', 'source' => 'kitchen', 'description' => 'Malformed.', 'parameters' => 'not-a-schema', 'executor' => 'host', 'scope' => 'run' ),
			) ),
		)
	);
	agents_api_smoke_assert_equals( 'agents_chat_invalid_runtime_tool_declaration', $malformed_overlay instanceof WP_Error ? $malformed_overlay->get_error_code() : '', 'malformed overlay is rejected', $failures, $passes );

	$alias_overlay = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent' => 'bundle-brain', 'message' => 'reject alias',
			'client_context' => array( 'runtime_tool_declarations' => array(
				'kitchen_lookup' => array( 'name' => 'kitchen/lookup', 'source' => 'kitchen', 'description' => 'Alias.', 'parameters' => array(), 'executor' => 'host', 'scope' => 'run' ),
			) ),
		)
	);
	agents_api_smoke_assert_equals( 'agents_chat_invalid_runtime_tool_declaration', $alias_overlay instanceof WP_Error ? $alias_overlay->get_error_code() : '', 'canonical-id aliases are rejected', $failures, $passes );

	$unregistered_target = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent' => 'bundle-brain', 'message' => 'reject target',
			'client_context' => array( 'runtime_tool_declarations' => array(
				'kitchen/lookup' => array( 'name' => 'kitchen/lookup', 'source' => 'kitchen', 'description' => 'Missing target.', 'parameters' => array(), 'executor' => 'host', 'scope' => 'run', 'runtime' => array( 'executor_target' => 'test/not-registered' ) ),
			) ),
		)
	);
	agents_api_smoke_assert_equals( 'agents_chat_invalid_runtime_tool_declaration', $unregistered_target instanceof WP_Error ? $unregistered_target->get_error_code() : '', 'unregistered overlay executor target is rejected', $failures, $passes );

	$overlay_executor->calls = array();
	$reset_provider();
	$policy_output = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent' => 'bundle-brain', 'message' => 'policy narrows tools', 'allow_only' => array( 'other/tool' ),
			'client_context' => array( 'runtime_tool_declarations' => array(
				'kitchen/lookup' => array( 'name' => 'kitchen/lookup', 'source' => 'kitchen', 'description' => 'Overlay.', 'parameters' => array(), 'executor' => 'host', 'scope' => 'run', 'runtime' => array( 'executor_target' => 'test/runtime-overlay' ) ),
			) ),
		)
	);
	agents_api_smoke_assert_equals( false, $policy_output instanceof WP_Error, 'allow_only can narrow an overlay turn', $failures, $passes );
	agents_api_smoke_assert_equals( 0, count( $overlay_executor->calls ), 'allow_only is enforced before runtime overlay execution', $failures, $passes );

	$overlay_executor->calls = array();
	$reset_provider();
	$tool_policy_output = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent' => 'bundle-brain', 'message' => 'policy narrows tools', 'tool_policy' => array( 'mode' => 'allow', 'tools' => array( 'other/tool' ) ),
			'client_context' => array( 'runtime_tool_declarations' => array(
				'kitchen/lookup' => array( 'name' => 'kitchen/lookup', 'source' => 'kitchen', 'description' => 'Overlay.', 'parameters' => array(), 'executor' => 'host', 'scope' => 'run', 'runtime' => array( 'executor_target' => 'test/runtime-overlay' ) ),
			) ),
		)
	);
	agents_api_smoke_assert_equals( false, $tool_policy_output instanceof WP_Error, 'tool policy can narrow an overlay turn', $failures, $passes );
	agents_api_smoke_assert_equals( 0, count( $overlay_executor->calls ), 'tool policy is enforced before runtime overlay execution', $failures, $passes );

	echo "\n[2] Provider/model fall back to the request when the agent config omits them:\n";
	$registry->register(
		'bare-brain',
		array(
			'label'          => 'Bare Brain',
			'default_config' => array( 'system_prompt' => 'You are bare.' ),
		)
	);
	$reset_provider();
	$request_provider_output = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute(
		array(
			'agent'    => 'bare-brain',
			'message'  => 'hi',
			'provider' => 'request-provider',
			'model'    => 'request-model',
		)
	);
	agents_api_smoke_assert_equals( false, $request_provider_output instanceof WP_Error, 'request-supplied provider/model drive an agent without configured defaults', $failures, $passes );
	$request_model = $GLOBALS['__adapter_smoke']['model'] ?? null;
	agents_api_smoke_assert_equals( true, $request_model instanceof \WordPress\AiClient\Providers\Models\Contracts\ModelInterface, 'request-supplied provider/model resolve to a ModelInterface for dispatch', $failures, $passes );
	agents_api_smoke_assert_equals( 'request-provider', $request_model->provider_id ?? '', 'request provider overrides/supplies the dispatch provider', $failures, $passes );
	agents_api_smoke_assert_equals( 'request-model', $request_model->model_id ?? '', 'request model overrides/supplies the dispatch model', $failures, $passes );

	echo "\n[3] Error contracts: empty message, unknown agent, missing provider:\n";
	$empty = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute( array( 'agent' => 'kitchen-brain', 'message' => '   ' ) );
	agents_api_smoke_assert_equals( 'agents_chat_empty_message', $empty instanceof WP_Error ? $empty->get_error_code() : '', 'empty message is rejected', $failures, $passes );

	$missing_agent = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute( array( 'agent' => 'ghost-brain', 'message' => 'hi' ) );
	agents_api_smoke_assert_equals( 'agents_chat_agent_not_found', $missing_agent instanceof WP_Error ? $missing_agent->get_error_code() : '', 'unknown agent is rejected', $failures, $passes );

	$registry->register( 'no-model-brain', array( 'label' => 'No Model', 'default_config' => array( 'provider' => 'fake-provider' ) ) );
	$no_model = AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::execute( array( 'agent' => 'no-model-brain', 'message' => 'hi' ) );
	agents_api_smoke_assert_equals( 'agents_chat_model_required', $no_model instanceof WP_Error ? $no_model->get_error_code() : '', 'missing model is rejected', $failures, $passes );

	echo "\n[4] The default handler is a fallback: an explicit consumer runtime wins:\n";
	// A consumer registers at the default priority (10); the default sits at 1000.
	register_chat_handler(
		static function ( array $input ): array {
			unset( $input );
			return array( 'session_id' => 'consumer-session', 'reply' => 'consumer reply', 'completed' => true );
		},
		10
	);
	$reset_provider();
	$GLOBALS['__chat_handler_ability_calls'] = array();
	$dispatched = agents_chat_dispatch( array( 'agent' => 'kitchen-brain', 'message' => 'who answers?' ) );
	agents_api_smoke_assert_equals( 'consumer reply', $dispatched['reply'] ?? null, 'an explicit consumer handler overrides the default fallback', $failures, $passes );
	agents_api_smoke_assert_equals( 0, count( $GLOBALS['__chat_handler_ability_calls'] ), 'the default loop did not run when a consumer handler is present', $failures, $passes );

	echo "\n[5] With no consumer registered, dispatch resolves to the default native handler:\n";
	if ( function_exists( 'remove_all_filters' ) ) {
		remove_all_filters( 'wp_agent_chat_handler' );
	} else {
		unset( $GLOBALS['__agents_api_smoke_actions']['wp_agent_chat_handler'] );
	}
	// Re-register only the default (module load already did, but filters were just cleared).
	AgentsAPI\AI\Channels\WP_Agent_Default_Chat_Handler::register();
	$reset_provider();
	$GLOBALS['__chat_handler_ability_calls'] = array();
	$dispatched_default = agents_chat_dispatch( array( 'agent' => 'kitchen-brain', 'message' => 'native please' ) );
	agents_api_smoke_assert_equals( false, $dispatched_default instanceof WP_Error, 'dispatch resolves to the default native handler with no consumer present', $failures, $passes );
	agents_api_smoke_assert_equals( 'All set, chef.', $dispatched_default['reply'] ?? null, 'the default native handler answers through agents/chat dispatch', $failures, $passes );
	agents_api_smoke_assert_equals( 1, count( $GLOBALS['__chat_handler_ability_calls'] ), 'the default native loop mediated the tool call via dispatch', $failures, $passes );

	agents_api_smoke_finish( 'Agents API default agents/chat handler', $failures, $passes );
}
