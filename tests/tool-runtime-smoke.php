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
			'duplicate_policy' => 'repeatable',
			'completion_signal' => 'progress',
			'unsupported'       => new stdClass(),
		),
	)
);
agents_api_smoke_assert_equals( 'client/choose_post', $declaration['name'], 'runtime declaration keeps namespaced name', $failures, $passes );
agents_api_smoke_assert_equals( 'client', $declaration['source'], 'runtime declaration records source', $failures, $passes );
agents_api_smoke_assert_equals( 'run', $declaration['scope'], 'runtime declaration records run scope', $failures, $passes );
agents_api_smoke_assert_equals( 'repeatable', $declaration['runtime']['duplicate_policy'] ?? '', 'runtime declaration preserves duplicate policy metadata', $failures, $passes );
agents_api_smoke_assert_equals( 'progress', $declaration['runtime']['completion_signal'] ?? '', 'runtime declaration preserves completion signal metadata', $failures, $passes );
agents_api_smoke_assert_equals( false, array_key_exists( 'unsupported', $declaration['runtime'] ?? array() ), 'runtime declaration drops unsupported metadata values', $failures, $passes );

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

agents_api_smoke_finish( 'Agents API tool runtime', $failures, $passes );
