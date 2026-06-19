<?php
/**
 * Pure-PHP smoke test for runtime-tool lifecycle abilities.
 *
 * Run with: php tests/runtime-tool-lifecycle-abilities-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-runtime-tool-lifecycle-abilities-smoke\n";

$GLOBALS['__agents_api_smoke_abilities']           = array();
$GLOBALS['__agents_api_smoke_ability_categories'] = array();

require_once __DIR__ . '/agents-api-smoke-helpers.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'wp_has_ability_category' ) ) {
	function wp_has_ability_category( string $category ): bool {
		return isset( $GLOBALS['__agents_api_smoke_ability_categories'][ $category ] );
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $category, array $args ): void {
		$GLOBALS['__agents_api_smoke_ability_categories'][ $category ] = $args;
	}
}

if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( string $ability ): bool {
		return isset( $GLOBALS['__agents_api_smoke_abilities'][ $ability ] );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $ability, array $args ): void {
		$GLOBALS['__agents_api_smoke_abilities'][ $ability ] = $args;
	}
}

function agents_api_smoke_execute_runtime_tool_ability( string $ability, array $input ) {
	if ( ! isset( $GLOBALS['__agents_api_smoke_abilities'][ $ability ]['execute_callback'] ) ) {
		return new WP_Error( 'missing_ability', 'Ability is not registered.' );
	}

	return call_user_func( $GLOBALS['__agents_api_smoke_abilities'][ $ability ]['execute_callback'], $input );
}

agents_api_smoke_require_module();
do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\AGENTS_LIST_RUNTIME_TOOL_REQUESTS_ABILITY ), 'list runtime-tool requests ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\AGENTS_GET_RUNTIME_TOOL_REQUEST_ABILITY ), 'get runtime-tool request ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\AGENTS_SUBMIT_RUNTIME_TOOL_RESULT_ABILITY ), 'submit runtime-tool result ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\AGENTS_TIMEOUT_RUNTIME_TOOL_REQUEST_ABILITY ), 'timeout runtime-tool request ability registers', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( AgentsAPI\AI\AGENTS_CANCEL_RUNTIME_TOOL_REQUEST_ABILITY ), 'cancel runtime-tool request ability registers', $failures, $passes );

$submit_schema = $GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\AI\AGENTS_SUBMIT_RUNTIME_TOOL_RESULT_ABILITY ]['input_schema'] ?? array();
agents_api_smoke_assert_equals( array( 'request_id' ), $submit_schema['required'] ?? array(), 'submit result schema requires request_id', $failures, $passes );
agents_api_smoke_assert_equals( 'boolean', $submit_schema['properties']['resume']['type'] ?? '', 'submit result schema exposes generic resume flag', $failures, $passes );

$list_schema = $GLOBALS['__agents_api_smoke_abilities'][ AgentsAPI\AI\AGENTS_LIST_RUNTIME_TOOL_REQUESTS_ABILITY ]['input_schema'] ?? array();
agents_api_smoke_assert_equals( 'integer', $list_schema['properties']['limit']['type'] ?? '', 'list schema exposes generic limit query hint', $failures, $passes );

$runtime_tool_store = new class() implements AgentsAPI\AI\WP_Agent_Runtime_Tool_Request_Store {
	public array $requests = array();

	public function create( array $request ): void {
		$this->requests[ $request['request_id'] ] = $request;
	}

	public function get( string $request_id ): ?array {
		return $this->requests[ $request_id ] ?? null;
	}

	public function complete( string $request_id, array $result ): void {
		$this->requests[ $request_id ]['status'] = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_COMPLETED;
		$this->requests[ $request_id ]['result'] = $result;
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

add_filter(
	'wp_agent_runtime_tool_request_store',
	static function () use ( $runtime_tool_store ) {
		return $runtime_tool_store;
	}
);

$resume_calls = array();
add_filter(
	'wp_agent_runtime_tool_continuation',
	static function () use ( &$resume_calls ) {
		return static function ( array $request, array $result, array $context ) use ( &$resume_calls ): array {
			$resume_calls[] = compact( 'request', 'result', 'context' );
			return array( 'resumed' => true );
		};
	}
);

$pending_request = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::from_tool_call(
	'client/choose_post',
	'call_lifecycle_ability',
	array( 'post_id' => 123 ),
	array( 'run_id' => 'run-lifecycle-ability' )
);
AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::create_pending_request( $runtime_tool_store, $pending_request );

$list = agents_api_smoke_execute_runtime_tool_ability(
	AgentsAPI\AI\AGENTS_LIST_RUNTIME_TOOL_REQUESTS_ABILITY,
	array( 'limit' => 1 )
);
agents_api_smoke_assert_equals( 1, $list['count'] ?? 0, 'list ability returns pending request count', $failures, $passes );
agents_api_smoke_assert_equals( $pending_request['request_id'], $list['requests'][0]['request_id'] ?? '', 'list ability returns pending request payload', $failures, $passes );

$submission = agents_api_smoke_execute_runtime_tool_ability(
	AgentsAPI\AI\AGENTS_SUBMIT_RUNTIME_TOOL_RESULT_ABILITY,
	array(
		'request_id' => $pending_request['request_id'],
		'success'    => true,
		'result'     => array( 'post_id' => 456 ),
		'resume'     => true,
		'context'    => array( 'source' => 'ability-smoke' ),
	)
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Result::STATUS_SUBMITTED, $submission['status'] ?? '', 'submit ability returns submitted status', $failures, $passes );
agents_api_smoke_assert_equals( true, $submission['continuation_result']['resumed'] ?? false, 'submit ability resumes through continuation adapter', $failures, $passes );
agents_api_smoke_assert_equals( 'ability-smoke', $resume_calls[0]['context']['source'] ?? '', 'submit ability passes context to continuation', $failures, $passes );

$get = agents_api_smoke_execute_runtime_tool_ability(
	AgentsAPI\AI\AGENTS_GET_RUNTIME_TOOL_REQUEST_ABILITY,
	array( 'request_id' => $pending_request['request_id'] )
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_COMPLETED, $get['status'] ?? '', 'get ability preserves completed request status', $failures, $passes );
agents_api_smoke_assert_equals( 456, $get['request']['result']['result']['post_id'] ?? null, 'get ability exposes retained result payload', $failures, $passes );

$timeout_request = AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::from_tool_call( 'client/choose_post', 'call_cancel_ability', array(), array( 'run_id' => 'run-cancel' ) );
AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle::create_pending_request( $runtime_tool_store, $timeout_request );

$cancel = agents_api_smoke_execute_runtime_tool_ability(
	AgentsAPI\AI\AGENTS_CANCEL_RUNTIME_TOOL_REQUEST_ABILITY,
	array(
		'request_id' => $timeout_request['request_id'],
		'resume'     => false,
	)
);
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT, $cancel['status'] ?? '', 'cancel ability applies canonical timeout transition', $failures, $passes );
agents_api_smoke_assert_equals( true, $cancel['cancelled'] ?? false, 'cancel ability marks cancellation envelope', $failures, $passes );
agents_api_smoke_assert_equals( AgentsAPI\AI\WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT, $runtime_tool_store->requests[ $timeout_request['request_id'] ]['status'] ?? '', 'cancel ability delegates terminal transition to store', $failures, $passes );

agents_api_smoke_finish( 'Agents API runtime-tool lifecycle abilities', $failures, $passes );
