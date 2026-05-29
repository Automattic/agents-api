<?php
/**
 * Canonical chat run-control ability registration.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Channels;

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;

defined( 'ABSPATH' ) || exit;

const AGENTS_GET_CHAT_RUN_ABILITY                  = 'agents/get-chat-run';
const AGENTS_CANCEL_CHAT_RUN_ABILITY               = 'agents/cancel-chat-run';
const AGENTS_QUEUE_CHAT_MESSAGE_ABILITY            = 'agents/queue-chat-message';
const AGENTS_CHAT_RUN_CONTROL_CAPABILITIES_ABILITY = 'agents/chat-run-control-capabilities';

add_action(
	'wp_abilities_api_init',
	static function (): void {
		$abilities = array(
			AGENTS_CHAT_RUN_CONTROL_CAPABILITIES_ABILITY => array(
				'label'            => 'Get Chat Run-Control Capabilities',
				'description'      => 'Detect run-control support for a selected agent and runtime.',
				'input_schema'     => agents_chat_run_control_capabilities_input_schema(),
				'output_schema'    => agents_chat_run_control_capabilities_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\agents_chat_run_control_capabilities',
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

/**
 * Detect contextual chat run-control support for the selected runtime.
 *
 * The canonical run-control abilities are registered globally, but support is
 * runtime and agent specific. This probe lets adapters ask Agents API whether
 * a selected agent can actually honor status, cancel, and queued-message
 * controls before exposing UI for them.
 *
 * @param array<string,mixed> $input Capability probe input.
 * @return array{chat_run_status:bool,chat_run_cancel:bool,chat_message_queue:bool}
 */
function agents_chat_run_control_capabilities( array $input ): array {
	return array(
		'chat_run_status'   => agents_chat_run_control_capability_supported(
			$input,
			AGENTS_GET_CHAT_RUN_ABILITY,
			'wp_agent_chat_run_status_handler'
		),
		'chat_run_cancel'   => agents_chat_run_control_capability_supported(
			$input,
			AGENTS_CANCEL_CHAT_RUN_ABILITY,
			'wp_agent_chat_run_cancel_handler'
		),
		'chat_message_queue' => agents_chat_run_control_capability_supported(
			$input,
			AGENTS_QUEUE_CHAT_MESSAGE_ABILITY,
			'wp_agent_chat_message_queue_handler'
		),
	);
}

/**
 * Check one run-control capability for ability, permission, and runtime handler.
 *
 * @param array<string,mixed> $input          Capability probe input.
 * @param string              $ability        Canonical run-control ability name.
 * @param string              $handler_filter Runtime handler filter name.
 * @return bool Whether the selected runtime supports the capability.
 */
function agents_chat_run_control_capability_supported( array $input, string $ability, string $handler_filter ): bool {
	$agent = sanitize_title( (string) ( $input['agent'] ?? '' ) );
	if ( '' === $agent ) {
		return false;
	}

	if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $ability ) ) {
		return false;
	}

	$probe_input          = $input;
	$probe_input['agent'] = $agent;

	if ( ! agents_chat_run_control_permission( $probe_input ) ) {
		return false;
	}

	return is_callable( apply_filters( $handler_filter, null, $probe_input ) );
}

/** @return array<string,mixed>|\WP_Error */
function agents_get_chat_run( array $input ) {
	$handler = apply_filters( 'wp_agent_chat_run_status_handler', null, $input );
	if ( ! is_callable( $handler ) ) {
		return agents_chat_run_control_no_handler( 'agents_chat_run_status_unsupported', 'No chat run status handler is registered.' );
	}

	return agents_chat_run_control_normalize_result( call_user_func( $handler, $input ), 'agents_chat_run_invalid_status' );
}

/** @return array<string,mixed>|\WP_Error */
function agents_cancel_chat_run( array $input ) {
	$handler = apply_filters( 'wp_agent_chat_run_cancel_handler', null, $input );
	if ( ! is_callable( $handler ) ) {
		return agents_chat_run_control_no_handler( 'agents_chat_run_cancel_unsupported', 'No chat run cancellation handler is registered.' );
	}

	$result = agents_chat_run_control_normalize_result( call_user_func( $handler, $input ), 'agents_chat_run_invalid_cancel_result' );
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
	if ( ! is_callable( $handler ) ) {
		return agents_chat_run_control_no_handler( 'agents_chat_message_queue_unsupported', 'No chat message queue handler is registered.' );
	}

	$result = agents_chat_run_control_normalize_result( call_user_func( $handler, $input ), 'agents_chat_message_queue_invalid_result' );
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
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'read' ) : false;
	return (bool) apply_filters( 'agents_chat_run_control_permission', $allowed, $input );
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

function agents_chat_run_control_capabilities_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'agent' ),
		'properties' => array(
			'agent'         => array(
				'type'        => 'string',
				'description' => 'Slug or ID of the selected agent/runtime to probe.',
			),
			'session_owner' => agents_chat_session_owner_schema(),
		),
	);
}

function agents_chat_run_control_capabilities_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'chat_run_status', 'chat_run_cancel', 'chat_message_queue' ),
		'properties' => array(
			'chat_run_status'    => array( 'type' => 'boolean' ),
			'chat_run_cancel'    => array( 'type' => 'boolean' ),
			'chat_message_queue' => array( 'type' => 'boolean' ),
		),
	);
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
