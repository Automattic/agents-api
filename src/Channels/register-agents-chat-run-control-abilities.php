<?php
/**
 * Canonical chat run-control ability registration.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Channels;

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;

defined( 'ABSPATH' ) || exit;

const AGENTS_GET_CHAT_RUN_ABILITY         = 'agents/get-chat-run';
const AGENTS_CANCEL_CHAT_RUN_ABILITY      = 'agents/cancel-chat-run';
const AGENTS_QUEUE_CHAT_MESSAGE_ABILITY   = 'agents/queue-chat-message';
const AGENTS_LIST_CHAT_RUN_EVENTS_ABILITY = 'agents/list-chat-run-events';

add_action(
	'wp_abilities_api_init',
	static function (): void {
		$abilities = array(
			AGENTS_LIST_CHAT_RUN_EVENTS_ABILITY => array(
				'label'            => 'List Chat Run Events',
				'description'      => 'List canonical lifecycle events for an addressable chat run.',
				'input_schema'     => agents_chat_run_events_input_schema(),
				'output_schema'    => agents_chat_run_events_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_list_chat_run_events',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_GET_CHAT_RUN_ABILITY       => array(
				'label'            => 'Get Chat Run',
				'description'      => 'Read the canonical status for an addressable chat run.',
				'input_schema'     => agents_chat_run_id_input_schema(),
				'output_schema'    => agents_chat_run_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_get_chat_run',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_CANCEL_CHAT_RUN_ABILITY    => array(
				'label'            => 'Cancel Chat Run',
				'description'      => 'Request best-effort cancellation for an addressable chat run.',
				'input_schema'     => agents_chat_run_id_input_schema(),
				'output_schema'    => agents_cancel_chat_run_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_cancel_chat_run',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			AGENTS_QUEUE_CHAT_MESSAGE_ABILITY => array(
				'label'            => 'Queue Chat Message',
				'description'      => 'Queue a user message for a conversation while another chat run is active.',
				'input_schema'     => agents_queue_chat_message_input_schema(),
				'output_schema'    => agents_queue_chat_message_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_queue_chat_message',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		);

		foreach ( $abilities as $ability => $args ) {
			if ( wp_has_ability( $ability ) ) {
				continue;
			}

			wp_register_ability(
				$ability,
				array(
					'label'               => $args['label'],
					'description'         => $args['description'],
					'category'            => 'agents-api',
					'input_schema'        => $args['input_schema'],
					'output_schema'       => $args['output_schema'],
					'execute_callback'    => $args['execute_callback'],
					'permission_callback' => __NAMESPACE__ . '\\agents_chat_run_control_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => $args['annotations'],
					),
				)
			);
		}
	}
);

/** @return array<string,mixed>|\WP_Error */
function agents_get_chat_run( array $input ) {
	$handler = apply_filters( 'wp_agent_chat_run_status_handler', null, $input );
	if ( is_callable( $handler ) ) {
		return agents_chat_run_control_normalize_result( call_user_func( $handler, $input ), 'agents_chat_run_invalid_status' );
	}

	$run                  = WP_Agent_Chat_Run_Control::get_run( (string) ( $input['run_id'] ?? '' ) );
	$requested_session_id = (string) ( $input['session_id'] ?? '' );
	if ( null !== $run && $requested_session_id !== (string) $run['session_id'] ) {
		return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested session_id and run_id.' );
	}
	if ( null !== $run ) {
		return $run;
	}

	return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested run_id.' );
}

/** @return array<string,mixed>|\WP_Error */
function agents_list_chat_run_events( array $input ) {
	$handler = apply_filters( 'wp_agent_chat_run_events_handler', null, $input );
	if ( is_callable( $handler ) ) {
		$result = call_user_func( $handler, $input );
		return agents_chat_run_events_normalize_result( $result );
	}

	try {
		return WP_Agent_Chat_Run_Control::list_events(
			(string) ( $input['session_id'] ?? '' ),
			(string) ( $input['run_id'] ?? '' ),
			(string) ( $input['cursor'] ?? '' ),
			(int) ( $input['limit'] ?? 100 )
		);
	} catch ( \InvalidArgumentException $error ) {
		return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', $error->getMessage() );
	}
}

/** @return array<string,mixed>|\WP_Error */
function agents_cancel_chat_run( array $input ) {
	$handler = apply_filters( 'wp_agent_chat_run_cancel_handler', null, $input );
	if ( is_callable( $handler ) ) {
		$result = agents_chat_run_control_normalize_result( call_user_func( $handler, $input ), 'agents_chat_run_invalid_cancel_result' );
	} else {
		$run                  = WP_Agent_Chat_Run_Control::get_run( (string) ( $input['run_id'] ?? '' ) );
		$requested_session_id = (string) ( $input['session_id'] ?? '' );
		if ( null === $run ) {
			return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested run_id.' );
		}
		if ( $requested_session_id !== (string) $run['session_id'] ) {
			return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested session_id and run_id.' );
		}

		$result = WP_Agent_Chat_Run_Control::request_cancel( (string) ( $input['run_id'] ?? '' ) );
		if ( null === $result ) {
			return agents_chat_run_control_no_handler( 'agents_chat_run_not_found', 'No chat run was found for the requested run_id.' );
		}
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result['cancelled'] = (bool) ( $result['cancelled'] ?? in_array(
		$result['status'],
		array(
			WP_Agent_Chat_Run_Control::STATUS_CANCELLING,
			WP_Agent_Chat_Run_Control::STATUS_CANCELLED,
		),
		true
	) );

	return $result;
}

/** @return array<string,mixed>|\WP_Error */
function agents_queue_chat_message( array $input ) {
	$handler = apply_filters( 'wp_agent_chat_message_queue_handler', null, $input );
	if ( is_callable( $handler ) ) {
		$result = agents_chat_run_control_normalize_result( call_user_func( $handler, $input ), 'agents_chat_message_queue_invalid_result' );
	} else {
		try {
			$result = WP_Agent_Chat_Run_Control::queue_message( $input );
		} catch ( \InvalidArgumentException $error ) {
			return new \WP_Error( 'agents_chat_message_queue_invalid_result', $error->getMessage() );
		}
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( empty( $result['queued_message_id'] ) ) {
		return new \WP_Error( 'agents_chat_message_queue_invalid_result', 'Queued message results must include queued_message_id.' );
	}

	$result['queued_message_id'] = (string) $result['queued_message_id'];
	$result['position']          = max( 0, (int) ( $result['position'] ?? 0 ) );

	return $result;
}

function agents_chat_run_control_permission( array $input ): bool {
	$agent = sanitize_title( (string) ( $input['agent'] ?? '' ) );
	if ( '' !== $agent && class_exists( '\WP_Agent_Access' ) && class_exists( '\WP_Agent_Access_Grant' ) ) {
		$allowed = \WP_Agent_Access::can_current_principal_access_agent(
			$agent,
			\WP_Agent_Access_Grant::ROLE_VIEWER,
			agents_chat_run_control_request_scope( $input )
		);
	} else {
		$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'read' ) : false;
	}

	return (bool) apply_filters( 'agents_chat_run_control_permission', $allowed, $input );
}

/**
 * Extract request-scope fields for run-control access checks.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed> Access request scope.
 */
function agents_chat_run_control_request_scope( array $input ): array {
	$scope = array();
	foreach ( array( 'workspace_id', 'workspace_type', 'request_context', 'client_id', 'audience_id' ) as $key ) {
		if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
			$scope[ $key ] = (string) $input[ $key ];
		}
	}

	return $scope;
}

/** @return array<string,mixed>|\WP_Error */
function agents_chat_run_control_normalize_result( $result, string $error_code ) {
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) ) {
		return new \WP_Error( $error_code, 'Chat run-control handlers must return an array or WP_Error.' );
	}

	try {
		return WP_Agent_Chat_Run_Control::normalize_run( $result );
	} catch ( \InvalidArgumentException $error ) {
		return new \WP_Error( $error_code, $error->getMessage() );
	}
}

/** @return array<string,mixed>|\WP_Error */
function agents_chat_run_events_normalize_result( $result ) {
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) ) {
		return new \WP_Error( 'agents_chat_run_invalid_events_result', 'Chat run event handlers must return an array or WP_Error.' );
	}

	$result['run_id']     = (string) ( $result['run_id'] ?? '' );
	$result['session_id'] = (string) ( $result['session_id'] ?? '' );
	$result['status']     = WP_Agent_Chat_Run_Control::normalize_status( $result['status'] ?? WP_Agent_Chat_Run_Control::STATUS_RUNNING );
	$result['events']     = is_array( $result['events'] ?? null ) ? array_values( $result['events'] ) : array();
	$result['cursor']     = (string) ( $result['cursor'] ?? '' );
	$result['has_more']   = (bool) ( $result['has_more'] ?? false );

	return $result;
}

function agents_chat_run_control_no_handler( string $code, string $message ): \WP_Error {
	return new \WP_Error( $code, $message );
}

function agents_chat_run_id_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'session_id', 'run_id' ),
		'properties' => array(
			'session_id'    => array( 'type' => 'string' ),
			'run_id'        => array( 'type' => 'string' ),
			'session_owner' => agents_chat_session_owner_schema(),
		),
	);
}

function agents_chat_run_events_input_schema(): array {
	$schema                                 = agents_chat_run_id_input_schema();
	$schema['properties']['cursor']         = array( 'type' => 'string' );
	$schema['properties']['limit']          = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 );
	return $schema;
}

function agents_chat_run_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id', 'session_id', 'status' ),
		'properties' => array(
			'run_id'     => array( 'type' => 'string' ),
			'session_id' => array( 'type' => 'string' ),
			'status'     => array(
				'type' => 'string',
				'enum' => WP_Agent_Chat_Run_Control::statuses(),
			),
			'started_at' => array( 'type' => 'string' ),
			'updated_at' => array( 'type' => 'string' ),
			'metadata'   => array( 'type' => 'object' ),
		),
	);
}

function agents_chat_run_events_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id', 'session_id', 'status', 'events', 'cursor' ),
		'properties' => array(
			'run_id'     => array( 'type' => 'string' ),
			'session_id' => array( 'type' => 'string' ),
			'status'     => array(
				'type' => 'string',
				'enum' => WP_Agent_Chat_Run_Control::statuses(),
			),
			'events'     => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'required'   => array( 'id', 'type', 'created_at', 'metadata' ),
					'properties' => array(
						'id'         => array( 'type' => 'string' ),
						'type'       => array( 'type' => 'string' ),
						'message'    => array( 'type' => 'string' ),
						'created_at' => array( 'type' => 'string' ),
						'metadata'   => array( 'type' => 'object' ),
					),
				),
			),
			'cursor'     => array( 'type' => 'string' ),
			'has_more'   => array( 'type' => 'boolean' ),
		),
	);
}

function agents_cancel_chat_run_output_schema(): array {
	$schema                            = agents_chat_run_output_schema();
	$schema['required'][]              = 'cancelled';
	$schema['properties']['cancelled'] = array( 'type' => 'boolean' );
	return $schema;
}

function agents_queue_chat_message_input_schema(): array {
	$schema               = agents_chat_input_schema();
	$schema['required'][] = 'session_id';
	return $schema;
}

function agents_queue_chat_message_output_schema(): array {
	$schema                                    = agents_chat_run_output_schema();
	$schema['required'][]                      = 'queued_message_id';
	$schema['properties']['queued_message_id'] = array( 'type' => 'string' );
	$schema['properties']['position']          = array( 'type' => 'integer' );
	return $schema;
}
