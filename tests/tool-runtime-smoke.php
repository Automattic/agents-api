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
	)
);
agents_api_smoke_assert_equals( 'client/choose_post', $declaration['name'], 'runtime declaration keeps namespaced name', $failures, $passes );
agents_api_smoke_assert_equals( 'client', $declaration['source'], 'runtime declaration records source', $failures, $passes );
agents_api_smoke_assert_equals( 'run', $declaration['scope'], 'runtime declaration records run scope', $failures, $passes );

$registry = new AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry();
$registry->registerSource(
	'local',
	static function () {
		return array(
			'local/summarize' => array(
				'description' => 'Summarize text.',
				'parameters'  => array(
					'type'       => 'object',
					'required'   => array( 'text' ),
					'properties' => array(
						'text' => array( 'type' => 'string' ),
					),
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
agents_api_smoke_assert_equals( 'hello', $parameters['text'], 'runtime parameters override context defaults', $failures, $passes );
agents_api_smoke_assert_equals( 'req-123', $parameters['request_id'], 'parameter builder preserves runtime context', $failures, $passes );

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

		return array(
			'success' => true,
			'result'  => array(
				'summary'    => strtoupper( (string) $tool_call['parameters']['text'] ),
				'request_id' => $tool_call['parameters']['request_id'],
				'agent_id'   => $context['agent_id'],
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
agents_api_smoke_assert_equals( true, $from_context['success'], 'context can satisfy required tool parameters', $failures, $passes );
agents_api_smoke_assert_equals( 'CONTEXT TEXT', $from_context['result']['summary'], 'context-supplied required parameter reaches adapter', $failures, $passes );

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

agents_api_smoke_finish( 'Agents API tool runtime', $failures, $passes );
