<?php
/**
 * Generic frontend chat REST adapter.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

const AGENTS_FRONTEND_CHAT_REST_NAMESPACE = 'agents-api/v1';
const AGENTS_FRONTEND_CHAT_REST_ROUTE     = '/chat';

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			AGENTS_FRONTEND_CHAT_REST_NAMESPACE,
			AGENTS_FRONTEND_CHAT_REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\agents_frontend_chat_rest_dispatch',
				'permission_callback' => __NAMESPACE__ . '\\agents_frontend_chat_rest_permission',
				'args'                => agents_frontend_chat_rest_args(),
			)
		);
	}
);

/**
 * Dispatch one REST chat turn through the canonical agents/chat ability.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function agents_frontend_chat_rest_dispatch( \WP_REST_Request $request ) {
	$input = agents_frontend_chat_rest_input( $request );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( AGENTS_CHAT_ABILITY ) : null;

	if ( ! $ability ) {
		return new \WP_Error(
			'agents_frontend_chat_ability_unavailable',
			'The agents/chat ability is not available.',
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Permission gate for the frontend chat REST route.
 *
 * @param \WP_REST_Request $request REST request.
 */
function agents_frontend_chat_rest_permission( \WP_REST_Request $request ) {
	$input = agents_frontend_chat_rest_input( $request );
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$agent = isset( $input['agent'] ) ? (string) $input['agent'] : '';
	$allowed = '' !== $agent && agents_chat_permission( $input );

	if ( ! $allowed && '' !== $agent ) {
		$allowed = \WP_Agent_Access::can_current_principal_access_agent( $agent, \WP_Agent_Access_Grant::ROLE_OPERATOR, agents_frontend_chat_rest_scope( $request ) );
	}

	/**
	 * Filter the frontend chat REST permission decision.
	 *
	 * @param bool             $allowed Default access decision.
	 * @param array            $input   Canonical agents/chat input.
	 * @param \WP_REST_Request $request REST request.
	 */
	$allowed = (bool) apply_filters( 'agents_frontend_chat_rest_permission', $allowed, $input, $request );

	if ( $allowed ) {
		return true;
	}

	return new \WP_Error(
		'agents_frontend_chat_forbidden',
		'You are not allowed to chat with this agent.',
		array( 'status' => 403 )
	);
}

/**
 * Build canonical agents/chat input from a REST request.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array<string,mixed>|\WP_Error
 */
function agents_frontend_chat_rest_input( \WP_REST_Request $request ) {
	static $cache = null;

	if ( ! $cache instanceof \SplObjectStorage ) {
		$cache = new \SplObjectStorage();
	}

	if ( $cache->offsetExists( $request ) ) {
		return $cache[ $request ];
	}

	$client_context = $request->get_param( 'client_context' );
	$client_context = is_array( $client_context ) ? $client_context : array();
	$session_id     = $request->get_param( 'session_id' );
	$client_context = array_merge(
		$client_context,
		array(
			'source'      => 'rest',
			'client_name' => isset( $client_context['client_name'] ) && '' !== (string) $client_context['client_name'] ? (string) $client_context['client_name'] : 'frontend-chat',
		)
	);

	$attachments = $request->get_param( 'attachments' );
	$input       = array(
		'agent'          => sanitize_title( (string) $request->get_param( 'agent' ) ),
		'message'        => (string) $request->get_param( 'message' ),
		'session_id'     => null !== $session_id ? (string) $session_id : null,
		'attachments'    => is_array( $attachments ) ? $attachments : array(),
		'client_context' => $client_context,
	);

	/**
	 * Filter the canonical agents/chat input built by the REST adapter.
	 *
	 * @param array            $input   Canonical agents/chat input.
	 * @param \WP_REST_Request $request REST request.
	 */
	/** @var mixed $filtered_input Hosts may accidentally return invalid values from this filter. */
	$filtered_input = apply_filters( 'agents_frontend_chat_rest_input', $input, $request );
	if ( ! is_array( $filtered_input ) ) {
		$cache[ $request ] = new \WP_Error(
			'agents_frontend_chat_invalid_input',
			'The frontend chat REST input filter must return an array.',
			array( 'status' => 400 )
		);

		return $cache[ $request ];
	}
	$input = $filtered_input;

	if ( '' === (string) ( $input['agent'] ?? '' ) || '' === trim( (string) ( $input['message'] ?? '' ) ) ) {
		$cache[ $request ] = new \WP_Error(
			'agents_frontend_chat_invalid_input',
			'The frontend chat REST request requires a non-empty agent and message.',
			array( 'status' => 400 )
		);

		return $cache[ $request ];
	}

	$cache[ $request ] = $input;
	return $input;
}

/**
 * Build request context for principal/access helpers.
 *
 * @param \WP_REST_Request $request REST request.
 * @return array<string,mixed>
 */
function agents_frontend_chat_rest_scope( \WP_REST_Request $request ): array {
	$scope = \AgentsAPI\AI\Auth\agents_access_request_scope(
		array(
			'workspace_id' => $request->get_param( 'workspace_id' ),
			'client_id'    => $request->get_param( 'client_id' ),
		)
	);
	$scope['request_metadata'] = array(
		'rest_route' => AGENTS_FRONTEND_CHAT_REST_NAMESPACE . AGENTS_FRONTEND_CHAT_REST_ROUTE,
	);

	return $scope;
}

/**
 * REST argument schema.
 *
 * @return array<string,array<string,mixed>>
 */
function agents_frontend_chat_rest_args(): array {
	$schema     = agents_chat_input_schema();
	$properties = $schema['properties'] ?? array();

	return array(
		'agent'          => array_merge(
			$properties['agent'] ?? array( 'type' => 'string' ),
			array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => __NAMESPACE__ . '\\agents_frontend_chat_rest_validate_non_empty_string',
			)
		),
		'message'        => array_merge(
			$properties['message'] ?? array( 'type' => 'string' ),
			array(
				'required'          => true,
				'validate_callback' => __NAMESPACE__ . '\\agents_frontend_chat_rest_validate_non_empty_string',
			)
		),
		'session_id'     => array_merge( $properties['session_id'] ?? array( 'type' => array( 'string', 'null' ) ), array( 'required' => false ) ),
		'attachments'    => array_merge( $properties['attachments'] ?? array( 'type' => 'array' ), array( 'required' => false ) ),
		'client_context' => array_merge( $properties['client_context'] ?? array( 'type' => 'object' ), array( 'required' => false ) ),
		'workspace_id'   => array(
			'type'        => array( 'string', 'null' ),
			'required'    => false,
			'description' => 'Optional host workspace/scope identifier for access checks.',
		),
		'client_id'      => array(
			'type'        => array( 'string', 'null' ),
			'required'    => false,
			'description' => 'Optional host client identifier for access checks.',
		),
	);
}

/**
 * Validate non-empty REST string arguments.
 *
 * @param mixed $value REST argument value.
 */
function agents_frontend_chat_rest_validate_non_empty_string( $value ): bool {
	return is_string( $value ) && '' !== trim( $value );
}
