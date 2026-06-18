<?php
/**
 * Pure-PHP smoke test for tool runtime primitives.
 *
 * Run with: php tests/tool-runtime-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-tool-runtime-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$declaration = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalize(
	array(
		'name'        => 'client/choose_post',
		'source'      => 'client',
		'description' => 'Choose a post from the active client.',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
			),
		),
		'executor'    => 'client',
		'scope'       => 'run',
		'runtime'     => array(
			AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::RUNTIME_DUPLICATE_POLICY  => 'repeatable',
			AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::RUNTIME_COMPLETION_SIGNAL => 'progress',
			AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::RUNTIME_CAPABILITY_SCOPE  => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::CAPABILITY_SCOPE_RUNTIME_LOCAL,
			AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::RUNTIME_ENVIRONMENT       => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::ENVIRONMENT_RUNTIME_LOCAL,
			'unsupported'       => new stdClass(),
		),
	)
);
agents_api_smoke_assert_equals( 'client/choose_post', $declaration['name'], 'runtime declaration keeps namespaced name', $failures, $passes );
agents_api_smoke_assert_equals( 'client', $declaration['source'], 'runtime declaration records source', $failures, $passes );
agents_api_smoke_assert_equals( 'run', $declaration['scope'], 'runtime declaration records run scope', $failures, $passes );
agents_api_smoke_assert_equals( 'repeatable', $declaration['runtime']['duplicate_policy'] ?? '', 'runtime declaration preserves duplicate policy metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'progress', $declaration['runtime']['completion_signal'] ?? '', 'runtime declaration preserves completion signal metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'runtime_local', $declaration['runtime']['capability_scope'] ?? '', 'runtime declaration preserves runtime-local capability scope metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'runtime_local', $declaration['runtime']['environment'] ?? '', 'runtime declaration preserves runtime-local environment metadata', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'unsupported', $declaration['runtime'] ?? array() ), 'runtime declaration drops unsupported metadata values', $failures, $passes );

$legacy_client_declaration = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalizeForConversationRequest(
	array(
		'name' => 'client/search',
	)
);
agents_api_smoke_assert_equals( 'client', $legacy_client_declaration['source'], 'request declaration defaults legacy client source', $failures, $passes );
agents_api_smoke_assert_equals( 'client', $legacy_client_declaration['executor'], 'request declaration defaults legacy client executor', $failures, $passes );
agents_api_smoke_assert_equals( 'run', $legacy_client_declaration['scope'], 'request declaration defaults legacy client scope', $failures, $passes );
agents_api_smoke_assert_equals( array(), $legacy_client_declaration['parameters'], 'request declaration defaults legacy client parameters', $failures, $passes );

$server_declaration = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalizeForServer(
	array(
		'name'                    => 'ability/search_posts',
		'source'                  => 'abilities',
		'description'             => 'Search host-owned posts.',
		'client_context_bindings' => array( 'query' => 'search_query' ),
		'runtime'                 => array(
			AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::RUNTIME_CAPABILITY_SCOPE => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::CAPABILITY_SCOPE_CONTROL_PLANE,
		),
	)
);
agents_api_smoke_assert_equals( 'ability/search_posts', $server_declaration['name'], 'server declaration keeps namespaced name', $failures, $passes );
agents_api_smoke_assert_equals( 'abilities', $server_declaration['source'], 'server declaration accepts host source slugs independent from name namespace', $failures, $passes );
agents_api_smoke_assert_equals( 'host', $server_declaration['executor'], 'server declaration defaults to host executor', $failures, $passes );
agents_api_smoke_assert_equals( 'run', $server_declaration['scope'], 'server declaration defaults to run scope', $failures, $passes );
agents_api_smoke_assert_equals( array(), $server_declaration['parameters'], 'server declaration defaults missing parameters to an array', $failures, $passes );
agents_api_smoke_assert_equals( array( 'query' => 'search_query' ), $server_declaration['client_context_bindings'] ?? null, 'server declaration preserves generic execution extension fields', $failures, $passes );
agents_api_smoke_assert_equals( array( 'source' => 'context', 'path' => 'search_query' ), $server_declaration['parameter_bindings']['query'] ?? null, 'legacy client context bindings normalize into parameter bindings', $failures, $passes );
agents_api_smoke_assert_equals( 'control_plane', $server_declaration['runtime']['capability_scope'] ?? '', 'server declaration preserves product-neutral runtime metadata', $failures, $passes );

$host_declaration = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalizeForConversationRequest(
	array(
		'name'        => 'ability/search_posts',
		'source'      => 'ability',
		'description' => 'Search host-owned posts.',
		'parameters'  => array(
			'type'       => 'object',
			'required'   => array( 'query' ),
			'properties' => array(
				'query' => array( 'type' => 'string' ),
			),
		),
		'executor'    => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::EXECUTOR_HOST,
		'scope'       => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::SCOPE_RUN,
		'runtime'     => array(
			'duplicate_policy' => 'repeatable',
			'unsupported'      => new stdClass(),
		),
	)
);
agents_api_smoke_assert_equals( 'ability/search_posts', $host_declaration['name'], 'request declaration accepts host-owned namespaced tools', $failures, $passes );
agents_api_smoke_assert_equals( 'ability', $host_declaration['source'], 'request declaration records host-owned source', $failures, $passes );
agents_api_smoke_assert_equals( 'host', $host_declaration['executor'], 'request declaration records host executor', $failures, $passes );
agents_api_smoke_assert_equals( 'run', $host_declaration['scope'], 'request declaration records run scope for host tools', $failures, $passes );
agents_api_smoke_assert_equals( 'repeatable', $host_declaration['runtime']['duplicate_policy'] ?? '', 'request declaration preserves host runtime metadata', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'unsupported', $host_declaration['runtime'] ?? array() ), 'request declaration drops unsupported host metadata values', $failures, $passes );

$strict_rejected_host = false;
try {
	AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalize(
		array(
			'name'        => 'ability/search_posts',
			'description' => 'Search host-owned posts.',
			'executor'    => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::EXECUTOR_HOST,
			'scope'       => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::SCOPE_RUN,
		)
	);
} catch ( InvalidArgumentException $error ) {
	$strict_rejected_host = str_starts_with( $error->getMessage(), 'invalid_runtime_tool_declaration:' );
}
agents_api_smoke_assert_equals( true, $strict_rejected_host, 'strict runtime declaration still rejects host-owned tools', $failures, $passes );

$invalid_host = false;
try {
	AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalizeForConversationRequest(
		array(
			'name'        => 'ability/search_posts',
			'source'      => 'wrong',
			'description' => 'Search host-owned posts.',
			'parameters'  => 'bad-parameters',
			'executor'    => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::EXECUTOR_HOST,
			'scope'       => AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::SCOPE_RUN,
		)
	);
} catch ( InvalidArgumentException $error ) {
	$invalid_host = str_starts_with( $error->getMessage(), 'invalid_conversation_tool_declaration:' );
}
agents_api_smoke_assert_equals( true, $invalid_host, 'request declaration rejects invalid host-owned tool shapes', $failures, $passes );

$turn_request = new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
	array(
		array(
			'role'    => 'user',
			'content' => 'Search posts.',
		),
	),
	array(
		'ability/search_posts' => array(
			'source'      => 'abilities',
			'description' => 'Search host-owned posts.',
		)
	)
);
$turn_tools = $turn_request->toolDeclarations();
agents_api_smoke_assert_equals( 'host', $turn_tools['ability/search_posts']['executor'] ?? null, 'provider turn request canonicalizes server tool executor', $failures, $passes );
agents_api_smoke_assert_equals( 'run', $turn_tools['ability/search_posts']['scope'] ?? null, 'provider turn request canonicalizes server tool scope', $failures, $passes );
agents_api_smoke_assert_equals( array(), $turn_tools['ability/search_posts']['parameters'] ?? null, 'provider turn request canonicalizes server tool parameters', $failures, $passes );

$registry = new AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry();
$registry->registerSource(
	'local',
	static function () {
		return array(
			'local/summarize' => array(
				'description'              => 'Summarize text.',
				'parameters'               => array(
					'type'       => 'object',
					'required'   => array( 'text' ),
					'properties' => array(
						'text' => array( 'type' => 'string' ),
					),
				),
				// Opt this tool into pulling `text` from the runtime context. Without
				// this declaration, a `text` key in context never satisfies the
				// required parameter — keeps required-arg sourcing auditable.
				'client_context_bindings'  => array( 'text' ),
				'runtime'                  => array(
					'duplicate_policy' => 'repeatable',
					'completion_signal' => 'progress',
				),
			),
		);
	}
);
$registry->registerSource(
	'fallback',
	static function () {
		return array(
			'local/summarize' => array(
				'description' => 'Duplicate declaration that should lose precedence.',
			),
		);
	}
);

$tools = $registry->gather( array( 'agent_id' => 'writer' ) );
agents_api_smoke_assert_equals( array( 'local/summarize' ), array_keys( $tools ), 'source registry gathers unique tool declarations in precedence order', $failures, $passes );
agents_api_smoke_assert_equals( 'local', $tools['local/summarize']['source'], 'source registry annotates source slug', $failures, $passes );
agents_api_smoke_assert_equals( 'local/summarize', $tools['local/summarize']['name'], 'source registry annotates tool name', $failures, $passes );

$validation = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::validateRequiredParameters( array(), $tools['local/summarize'] );
agents_api_smoke_assert_equals( false, $validation['valid'], 'parameter validation detects missing required values', $failures, $passes );
agents_api_smoke_assert_equals( array( 'text' ), $validation['missing'], 'parameter validation reports missing names', $failures, $passes );

$parameters = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::buildParameters(
	array( 'text' => 'hello' ),
	array(
		'text'       => 'default',
		'request_id' => 'req-123',
	),
	$tools['local/summarize']
);
agents_api_smoke_assert_equals( 'hello', $parameters['text'], 'runtime parameters override declared context bindings', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'request_id', $parameters ), 'undeclared context keys do not leak into parameters', $failures, $passes );

$rename_definition = array(
	'parameters'              => array(
		'type'       => 'object',
		'required'   => array( 'user_phone' ),
		'properties' => array( 'user_phone' => array( 'type' => 'string' ) ),
	),
	'client_context_bindings' => array( 'user_phone' => 'sender_id' ),
);
$renamed = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::buildParameters(
	array(),
	array( 'sender_id' => 'whatsapp:+1', 'request_id' => 'req-123' ),
	$rename_definition
);
agents_api_smoke_assert_equals( 'whatsapp:+1', $renamed['user_phone'] ?? null, 'binding can rename context_key → parameter_name', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'sender_id', $renamed ), 'rename binding does not also expose the source key', $failures, $passes );

$dot_path_definition = AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalizeForServer(
	array(
		'name'               => 'ability/inspect_selection',
		'source'             => 'abilities',
		'description'        => 'Inspect selected client state.',
		'parameter_bindings' => array(
			'post_id' => 'client_context.selection.post_id',
		),
	)
);
$dot_path_parameters = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::buildParameters(
	array(),
	array(
		'client_context' => array(
			'selection' => array(
				'post_id' => 42,
			),
		),
	),
	$dot_path_definition
);
agents_api_smoke_assert_equals( 42, $dot_path_parameters['post_id'] ?? null, 'parameter bindings read values from allowed dot-path context sources', $failures, $passes );

$default_definition = array(
	'parameter_defaults' => array( 'query' => 'top-level-default' ),
	'parameter_bindings' => array(
		'query' => array(
			'source'  => 'client_context',
			'path'    => 'search.query',
			'default' => 'binding-default',
		),
	),
);
$defaulted = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::buildParameters( array(), array(), $default_definition );
agents_api_smoke_assert_equals( 'binding-default', $defaulted['query'] ?? null, 'binding defaults override top-level parameter defaults', $failures, $passes );
$default_context = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::buildParameters(
	array(),
	array( 'client_context' => array( 'search' => array( 'query' => 'bound-context' ) ) ),
	$default_definition
);
agents_api_smoke_assert_equals( 'bound-context', $default_context['query'] ?? null, 'context bindings override defaults', $failures, $passes );
$default_explicit = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::buildParameters(
	array( 'query' => 'explicit' ),
	array( 'client_context' => array( 'search' => array( 'query' => 'bound-context' ) ) ),
	$default_definition
);
agents_api_smoke_assert_equals( 'explicit', $default_explicit['query'] ?? null, 'explicit runtime parameters override bindings and defaults', $failures, $passes );

$malformed_rejected = false;
try {
	AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration::normalizeForServer(
		array(
			'name'               => 'ability/bad_binding',
			'source'             => 'abilities',
			'description'        => 'Bad binding.',
			'parameter_bindings' => array(
				'query' => array( 'source' => 'ambient', 'path' => 'query' ),
			),
		)
	);
} catch ( InvalidArgumentException $error ) {
	$malformed_rejected = false !== strpos( $error->getMessage(), 'parameter_bindings' );
}
agents_api_smoke_assert_equals( true, $malformed_rejected, 'malformed parameter bindings are rejected with field-scoped errors', $failures, $passes );

$sensitive_definition = array(
	'parameter_bindings' => array(
		'api_key' => array(
			'source'    => 'client_context',
			'path'      => 'secrets.api_key',
			'sensitive' => true,
		),
	),
);
agents_api_smoke_assert_equals( array( 'api_key' => AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::REDACTED_VALUE ), AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::redactedParameters( array( 'api_key' => 'secret' ), $sensitive_definition ), 'sensitive parameter binding metadata participates in redaction', $failures, $passes );

$undeclared = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::buildParameters(
	array(),
	array( 'text' => 'context text' ),
	array(
		'parameters' => array(
			'type'     => 'object',
			'required' => array( 'text' ),
		),
		// No client_context_bindings declared — context must NOT auto-satisfy.
	)
);
agents_api_smoke_assert_equals( array(), $undeclared, 'no bindings ⇒ no context auto-population', $failures, $passes );

$tool_call = AgentsAPI\AI\Tools\WP_Agent_Tool_Call::normalize(
	array(
		'name'       => 'local/summarize',
		'parameters' => array( 'text' => 'hello' ),
	)
);
agents_api_smoke_assert_equals( 'local/summarize', $tool_call['tool_name'], 'tool call normalizes name alias', $failures, $passes );
agents_api_smoke_assert_equals( array( 'text' => 'hello' ), $tool_call['parameters'], 'tool call normalizes parameters', $failures, $passes );

$adapter = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition );

		// `text` is auditable runtime input (declared parameter); `request_id` and
		// `agent_id` are ambient context that the adapter is free to consult
		// without the tool declaration having to bind them as parameters.
		return array(
			'success' => true,
			'result'  => array(
				'summary'    => strtoupper( (string) $tool_call['parameters']['text'] ),
				'request_id' => $context['request_id'] ?? null,
				'agent_id'   => $context['agent_id'] ?? null,
			),
		);
	}
};

$executor = new AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core();
$missing  = $executor->executeTool( 'local/summarize', array(), $tools, $adapter, array( 'request_id' => 'req-123' ) );
agents_api_smoke_assert_equals( false, $missing['success'], 'execution returns normalized error for missing parameters', $failures, $passes );
agents_api_smoke_assert_equals( array( 'text' ), $missing['metadata']['missing_parameters'], 'execution error includes missing parameter metadata', $failures, $passes );

$from_context = $executor->executeTool(
	'local/summarize',
	array(),
	$tools,
	$adapter,
	array(
		'agent_id'   => 'writer',
		'request_id' => 'req-123',
		'text'       => 'context text',
	)
);
agents_api_smoke_assert_equals( true, $from_context['success'], 'declared context binding satisfies required parameter', $failures, $passes );
agents_api_smoke_assert_equals( 'CONTEXT TEXT', $from_context['result']['summary'], 'bound context value reaches adapter through parameters', $failures, $passes );

// Sanity: an undeclared context key (request_id) must not silently satisfy a
// required parameter even when its name matches.
$tools_with_request_required                                                = $tools;
$tools_with_request_required['local/summarize']['parameters']['required']   = array( 'text', 'request_id' );
$tools_with_request_required['local/summarize']['parameters']['properties'] = array(
	'text'       => array( 'type' => 'string' ),
	'request_id' => array( 'type' => 'string' ),
);
$undeclared_required = $executor->executeTool(
	'local/summarize',
	array( 'text' => 'hello' ),
	$tools_with_request_required,
	$adapter,
	array( 'request_id' => 'req-undeclared' )
);
agents_api_smoke_assert_equals( false, $undeclared_required['success'], 'undeclared context key cannot satisfy a required parameter', $failures, $passes );
agents_api_smoke_assert_equals( array( 'request_id' ), $undeclared_required['metadata']['missing_parameters'], 'missing-parameter error names the undeclared slot', $failures, $passes );

$result = $executor->executeTool(
	'local/summarize',
	array( 'text' => 'hello' ),
	$tools,
	$adapter,
	array(
		'agent_id'   => 'writer',
		'request_id' => 'req-123',
	)
);
agents_api_smoke_assert_equals( true, $result['success'], 'mediation returns normalized success result', $failures, $passes );
agents_api_smoke_assert_equals( 'local/summarize', $result['tool_name'], 'mediated result records tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'HELLO', $result['result']['summary'], 'mediated result carries adapter payload', $failures, $passes );
agents_api_smoke_assert_equals( 'req-123', $result['result']['request_id'], 'adapter receives merged parameters', $failures, $passes );
agents_api_smoke_assert_equals( 'repeatable', $result['runtime']['duplicate_policy'] ?? '', 'mediated result preserves declaration duplicate policy runtime metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'progress', $result['runtime']['completion_signal'] ?? '', 'mediated result preserves declaration completion signal runtime metadata', $failures, $passes );

$result_override_adapter = new class() implements AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );

		return array(
			'success'   => true,
			'tool_name' => $tool_call['tool_name'],
			'result'    => array( 'summary' => 'OK' ),
			'runtime'   => array(
				'completion_signal' => 'complete',
			),
		);
	}
};

$result_override = $executor->executeTool(
	'local/summarize',
	array( 'text' => 'hello' ),
	$tools,
	$result_override_adapter,
	array()
);
agents_api_smoke_assert_equals( 'repeatable', $result_override['runtime']['duplicate_policy'] ?? '', 'result runtime keeps declaration metadata when executor adds runtime', $failures, $passes );
agents_api_smoke_assert_equals( 'complete', $result_override['runtime']['completion_signal'] ?? '', 'executor runtime metadata can refine result runtime metadata', $failures, $passes );

$pending_request = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::from_tool_call(
	'client/choose_post',
	'call_abc',
	array( 'post_id' => 123 ),
	array( 'run_id' => 'run-1', 'runtime_tool_timeout_at' => '2026-06-01T00:00:00Z' ),
	array( 'completion_signal' => 'external_result' ),
	array( 'transport' => 'browser' )
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING, $pending_request['status'], 'runtime tool request has canonical pending status', $failures, $passes );
agents_api_smoke_assert_equals( 'client/choose_post', $pending_request['tool_name'], 'runtime tool request carries tool name', $failures, $passes );
agents_api_smoke_assert_equals( 'call_abc', $pending_request['tool_call_id'], 'runtime tool request carries tool call id', $failures, $passes );
agents_api_smoke_assert_equals( 'browser', $pending_request['metadata']['transport'] ?? '', 'runtime tool request preserves host metadata', $failures, $passes );

$submitted_result = AgentsAPI\AI\WP_Agent_Runtime_Tool_Result::normalize(
	array(
		'request_id' => $pending_request['request_id'],
		'tool_name'  => 'client/choose_post',
		'success'    => true,
		'result'     => array( 'post_id' => 123 ),
	)
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Result::STATUS_SUBMITTED, $submitted_result['status'], 'runtime tool result has canonical submitted status', $failures, $passes );
agents_api_smoke_assert_equals( 123, $submitted_result['result']['post_id'] ?? null, 'runtime tool result preserves payload', $failures, $passes );

$runtime_tool_store = new class() implements AgentsAPI\AI\WP_Agent_Runtime_Tool_Request_Store {
	public array $requests = array();
	public array $results  = array();

	public function create( array $request ): void {
		$this->requests[ $request['request_id'] ] = $request;
	}

	public function get( string $request_id ): ?array {
		return $this->requests[ $request_id ] ?? null;
	}

	public function complete( string $request_id, array $result ): void {
		$this->requests[ $request_id ]['status'] = 'completed';
		$this->results[ $request_id ]            = $result;
	}

	public function timeout( string $request_id ): void {
		$this->requests[ $request_id ]['status'] = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT;
	}

	public function recent_pending( array $query = array() ): array {
		$limit   = isset( $query['limit'] ) && is_int( $query['limit'] ) ? $query['limit'] : 100;
		$pending = array_filter(
			$this->requests,
			static fn( array $request ): bool => AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING === ( $request['status'] ?? '' )
		);

		return array_slice( array_values( $pending ), 0, $limit );
	}
};

$created_request = AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::create_pending_request( $runtime_tool_store, $pending_request, array( 'source' => 'smoke' ) );
agents_api_smoke_assert_equals( $pending_request['request_id'], $created_request['request_id'], 'lifecycle creates normalized pending runtime tool requests', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::recent_pending_requests( $runtime_tool_store, array( 'limit' => 1 ) ) ), 'lifecycle exposes recent pending requests through store contract', $failures, $passes );

$continuation_calls = array();
$submission         = AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::submit_result(
	$runtime_tool_store,
	array(
		'request_id' => $pending_request['request_id'],
		'success'    => true,
		'result'     => array( 'post_id' => 456 ),
	),
	static function ( array $request, array $result, array $context ) use ( &$continuation_calls ): array {
		$continuation_calls[] = compact( 'request', 'result', 'context' );
		return array( 'resumed' => true );
	},
	array( 'resume_source' => 'smoke' )
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Result::STATUS_SUBMITTED, $submission['status'], 'lifecycle submission has canonical submitted status', $failures, $passes );
agents_api_smoke_assert_equals( 'client/choose_post', $submission['result']['tool_name'], 'lifecycle fills submitted result tool name from stored request', $failures, $passes );
agents_api_smoke_assert_equals( 456, $submission['tool_result_message']['result']['result']['post_id'] ?? null, 'lifecycle creates transcript-compatible tool result message payload', $failures, $passes );
agents_api_smoke_assert_equals( true, $submission['continuation_result']['resumed'] ?? false, 'lifecycle invokes continuation callback after submission', $failures, $passes );
agents_api_smoke_assert_equals( 'smoke', $continuation_calls[0]['context']['resume_source'] ?? '', 'continuation receives caller context', $failures, $passes );

$timeout_store = new class() implements AgentsAPI\AI\WP_Agent_Runtime_Tool_Request_Store {
	public array $requests = array();

	public function create( array $request ): void {
		$this->requests[ $request['request_id'] ] = $request;
	}

	public function get( string $request_id ): ?array {
		return $this->requests[ $request_id ] ?? null;
	}

	public function complete( string $request_id, array $result ): void {
		unset( $result );
		$this->requests[ $request_id ]['status'] = 'completed';
	}

	public function timeout( string $request_id ): void {
		$this->requests[ $request_id ]['status'] = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT;
	}

	public function recent_pending( array $query = array() ): array {
		unset( $query );
		return array_values( array_filter(
			$this->requests,
			static fn( array $request ): bool => AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_PENDING === ( $request['status'] ?? '' )
		) );
	}
};

$timeout_request = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::from_tool_call( 'client/choose_post', 'call_timeout', array(), array( 'run_id' => 'run-timeout' ) );
AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::create_pending_request( $timeout_store, $timeout_request );
$timeout = AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::timeout_request( $timeout_store, $timeout_request['request_id'] );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT, $timeout['status'], 'lifecycle timeout has canonical timeout status', $failures, $passes );
agents_api_smoke_assert_equals( false, $timeout['result']['success'] ?? true, 'lifecycle timeout creates failed runtime tool result', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT, $timeout_store->requests[ $timeout_request['request_id'] ]['status'] ?? '', 'lifecycle delegates timeout transition to store', $failures, $passes );

agents_api_smoke_finish( 'Agents API tool runtime', $failures, $passes );
