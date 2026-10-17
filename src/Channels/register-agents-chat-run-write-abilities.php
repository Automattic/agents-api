<?php
/**
 * Claim-bound chat run write ability registration.
 *
 * `agents/chat` can route a turn to an out-of-process runtime through the
 * `wp_agent_chat_handler` filter. These abilities are the canonical write
 * surface for that runtime: they let the holder of a run claim append
 * assistant/tool/notice messages to the session transcript, edit a message it
 * already emitted (streamed text gets edited in place), append lifecycle
 * events, transition run status, and drain queued user answers for
 * interactive (`input_required` / `approval_required`) turns — all through the
 * same stores the in-process conversation loop uses.
 *
 * Authorization is bound to the run claim. When `agents/chat` reserves a run
 * it injects the opaque `_agents_run_claim_token` into the handler input; the
 * handler hands that token to its runtime, and the runtime presents it back
 * as `claim_token`. The runtime can only write runs it claimed, for sessions
 * it was handed. A client-supplied principal never grants write access: these
 * permission callbacks never consult the current user, capabilities, or any
 * `principal`/`session_owner` input.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Channels;

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Sessions;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

const AGENTS_APPEND_CHAT_RUN_MESSAGES_ABILITY = 'agents/append-chat-run-messages';
const AGENTS_UPDATE_CHAT_RUN_MESSAGE_ABILITY  = 'agents/update-chat-run-message';
const AGENTS_APPEND_CHAT_RUN_EVENTS_ABILITY   = 'agents/append-chat-run-events';
const AGENTS_SET_CHAT_RUN_STATUS_ABILITY      = 'agents/set-chat-run-status';
const AGENTS_CLAIM_CHAT_RUN_INPUT_ABILITY     = 'agents/claim-chat-run-input';

add_action(
	'wp_abilities_api_init',
	static function (): void {
		$abilities = array(
			AGENTS_APPEND_CHAT_RUN_MESSAGES_ABILITY => array(
				'label'            => 'Append Chat Run Messages',
				'description'      => 'Append canonical message envelopes to the transcript of a chat run the caller holds the run claim for.',
				'input_schema'     => agents_append_chat_run_messages_input_schema(),
				'output_schema'    => agents_chat_run_write_messages_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_append_chat_run_messages',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			AGENTS_UPDATE_CHAT_RUN_MESSAGE_ABILITY  => array(
				'label'            => 'Update Chat Run Message',
				'description'      => 'Replace one transcript message previously appended to a claimed chat run, addressed by its stable message id.',
				'input_schema'     => agents_update_chat_run_message_input_schema(),
				'output_schema'    => agents_chat_run_write_message_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_update_chat_run_message',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			AGENTS_APPEND_CHAT_RUN_EVENTS_ABILITY   => array(
				'label'            => 'Append Chat Run Events',
				'description'      => 'Append canonical lifecycle events to a chat run the caller holds the run claim for.',
				'input_schema'     => agents_append_chat_run_events_input_schema(),
				'output_schema'    => agents_chat_run_write_events_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_append_chat_run_events',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			AGENTS_SET_CHAT_RUN_STATUS_ABILITY      => array(
				'label'            => 'Set Chat Run Status',
				'description'      => 'Transition the status of a claimed chat run (running to completed/failed/cancelled or a suspended status). The response carries cancellation visibility.',
				'input_schema'     => agents_set_chat_run_status_input_schema(),
				'output_schema'    => agents_chat_run_write_run_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_set_chat_run_status',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
				),
			),
			AGENTS_CLAIM_CHAT_RUN_INPUT_ABILITY     => array(
				'label'            => 'Claim Chat Run Input',
				'description'      => 'Claim queued user answers (for example to an input_required message) for a chat run the caller holds the run claim for.',
				'input_schema'     => agents_chat_run_claim_input_schema(),
				'output_schema'    => agents_chat_run_claim_input_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_claim_chat_run_input',
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
					'permission_callback' => __NAMESPACE__ . '\\agents_chat_run_write_claim_permission',
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
 * Claim-bound permission gate for chat run write abilities.
 *
 * The presented `claim_token` is the only credential. Client-supplied
 * principals, session-owner claims, and the current user's capabilities are
 * intentionally never consulted: possession of the run claim authorizes writes
 * to exactly the run it was minted for.
 *
 * @param array<string,mixed> $input Ability input.
 */
function agents_chat_run_write_claim_permission( array $input ): bool {
	$workspace = agents_chat_run_write_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return false;
	}

	try {
		$verified = WP_Agent_Chat_Run_Control::verify_run_claim(
			agents_chat_run_control_string( $input['run_id'] ?? '' ),
			agents_chat_run_control_string( $input['claim_token'] ?? '' ),
			$workspace
		);
	} catch ( \RuntimeException $error ) {
		unset( $error );
		return false;
	}

	$allowed = ! is_wp_error( $verified );

	/**
	 * Filter permission for claim-bound chat run write abilities.
	 *
	 * Hosts can only narrow the decision for a valid claim or veto writes
	 * entirely; granting access without a valid run claim remains unsupported
	 * because the execute callbacks re-verify the claim.
	 *
	 * @param bool                 $allowed Claim verification result.
	 * @param array<string,mixed>  $input   Ability input.
	 */
	$allowed = (bool) apply_filters( 'agents_chat_run_write_claim_permission', $allowed, $input );
	return (bool) apply_filters( 'agents_chat_run_control_permission', $allowed, $input );
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_append_chat_run_messages( array $input ) {
	$workspace = agents_chat_run_write_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	$messages = is_array( $input['messages'] ?? null ) ? array_values( $input['messages'] ) : array();

	try {
		return WP_Agent_Chat_Run_Control::append_run_messages(
			agents_chat_run_control_string( $input['run_id'] ?? '' ),
			agents_chat_run_control_string( $input['claim_token'] ?? '' ),
			$messages,
			$workspace,
			WP_Agent_Conversation_Sessions::get_store( agents_chat_run_control_string_keyed_array( $input ) )
		);
	} catch ( \RuntimeException $error ) {
		return new \WP_Error( 'agents_chat_run_workspace_unsupported', $error->getMessage() );
	}
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_update_chat_run_message( array $input ) {
	$workspace = agents_chat_run_write_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	try {
		return WP_Agent_Chat_Run_Control::update_run_message(
			agents_chat_run_control_string( $input['run_id'] ?? '' ),
			agents_chat_run_control_string( $input['claim_token'] ?? '' ),
			agents_chat_run_control_string( $input['message_id'] ?? '' ),
			is_array( $input['message'] ?? null ) ? $input['message'] : array(),
			$workspace,
			WP_Agent_Conversation_Sessions::get_store( agents_chat_run_control_string_keyed_array( $input ) )
		);
	} catch ( \RuntimeException $error ) {
		return new \WP_Error( 'agents_chat_run_workspace_unsupported', $error->getMessage() );
	}
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_append_chat_run_events( array $input ) {
	$workspace = agents_chat_run_write_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	try {
		return WP_Agent_Chat_Run_Control::append_run_events(
			agents_chat_run_control_string( $input['run_id'] ?? '' ),
			agents_chat_run_control_string( $input['claim_token'] ?? '' ),
			is_array( $input['events'] ?? null ) ? array_values( $input['events'] ) : array(),
			$workspace
		);
	} catch ( \RuntimeException $error ) {
		return new \WP_Error( 'agents_chat_run_workspace_unsupported', $error->getMessage() );
	}
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_set_chat_run_status( array $input ) {
	$workspace = agents_chat_run_write_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	try {
		return WP_Agent_Chat_Run_Control::set_run_status(
			agents_chat_run_control_string( $input['run_id'] ?? '' ),
			agents_chat_run_control_string( $input['claim_token'] ?? '' ),
			agents_chat_run_control_string( $input['status'] ?? '' ),
			$workspace
		);
	} catch ( \RuntimeException $error ) {
		return new \WP_Error( 'agents_chat_run_workspace_unsupported', $error->getMessage() );
	}
}

/**
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_claim_chat_run_input( array $input ) {
	$workspace = agents_chat_run_write_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	try {
		return WP_Agent_Chat_Run_Control::claim_run_input(
			agents_chat_run_control_string( $input['run_id'] ?? '' ),
			agents_chat_run_control_string( $input['claim_token'] ?? '' ),
			$workspace
		);
	} catch ( \RuntimeException $error ) {
		return new \WP_Error( 'agents_chat_run_workspace_unsupported', $error->getMessage() );
	}
}

/**
 * Parse the optional canonical workspace scope for claim-bound writes.
 *
 * @param array<string,mixed> $input Ability input.
 * @return WP_Agent_Workspace_Scope|null|\WP_Error
 */
function agents_chat_run_write_workspace( array $input ) {
	if ( ! array_key_exists( 'workspace', $input ) || null === $input['workspace'] ) {
		return null;
	}
	if ( ! is_array( $input['workspace'] ) ) {
		return new \WP_Error( 'agents_chat_run_invalid_workspace', 'workspace must be a canonical workspace object.' );
	}

	try {
		return WP_Agent_Workspace_Scope::from_array( agents_chat_run_control_string_keyed_array( $input['workspace'] ) );
	} catch ( \InvalidArgumentException $error ) {
		return new \WP_Error( 'agents_chat_run_invalid_workspace', $error->getMessage() );
	}
}

/** @return array<string,mixed> */
function agents_chat_run_write_base_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id', 'claim_token' ),
		'properties' => array(
			'workspace'   => agents_chat_workspace_schema(),
			'run_id'      => array( 'type' => 'string' ),
			'claim_token' => array(
				'type'        => 'string',
				'description' => 'Opaque run claim token handed to the chat handler as _agents_run_claim_token when agents/chat reserved this run.',
			),
		),
	);
}

/** @return array<string,mixed> */
function agents_chat_run_write_message_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'role', 'content' ),
		'properties' => array(
			'schema'   => array( 'type' => 'string' ),
			'version'  => array( 'type' => 'integer' ),
			'id'       => array(
				'type'        => 'string',
				'description' => 'Stable message id. Assigned on append when omitted; required to address updates.',
			),
			'type'     => array(
				'type' => 'string',
				'enum' => \AgentsAPI\AI\WP_Agent_Message::supported_types(),
			),
			'role'     => array( 'type' => 'string' ),
			'content'  => array( 'type' => array( 'string', 'array' ) ),
			'payload'  => array( 'type' => 'object' ),
			'metadata' => array( 'type' => 'object' ),
		),
	);
}

/**
 * Extend the base claim-bound input schema with ability-specific fields.
 *
 * @param array<int,string>          $required   Extra required property names.
 * @param array<string,array<mixed>> $properties Extra property schemas.
 * @param array<string,mixed>        $schema     Base schema to extend.
 * @return array<string,mixed>
 */
function agents_chat_run_write_extend_schema( array $required, array $properties, array $schema ): array {
	$base_required   = is_array( $schema['required'] ?? null ) ? array_values( $schema['required'] ) : array();
	$base_properties = is_array( $schema['properties'] ?? null ) ? agents_chat_run_control_string_keyed_array( $schema['properties'] ) : array();

	$schema['required']   = array_merge( $base_required, $required );
	$schema['properties'] = array_merge( $base_properties, $properties );

	return $schema;
}

/** @return array<string,mixed> */
function agents_append_chat_run_messages_input_schema(): array {
	return agents_chat_run_write_extend_schema(
		array( 'messages' ),
		array(
			'messages' => array(
				'type'     => 'array',
				'minItems' => 1,
				'items'    => agents_chat_run_write_message_schema(),
			),
		),
		agents_chat_run_write_base_input_schema()
	);
}

/** @return array<string,mixed> */
function agents_update_chat_run_message_input_schema(): array {
	return agents_chat_run_write_extend_schema(
		array( 'message_id', 'message' ),
		array(
			'message_id' => array( 'type' => 'string' ),
			'message'    => agents_chat_run_write_message_schema(),
		),
		agents_chat_run_write_base_input_schema()
	);
}

/** @return array<string,mixed> */
function agents_append_chat_run_events_input_schema(): array {
	return agents_chat_run_write_extend_schema(
		array( 'events' ),
		array(
			'events' => array(
				'type'     => 'array',
				'minItems' => 1,
				'items'    => array(
					'type'       => 'object',
					'required'   => array( 'type' ),
					'properties' => array(
						'type'     => array( 'type' => 'string' ),
						'message'  => array( 'type' => 'string' ),
						'metadata' => array( 'type' => 'object' ),
					),
				),
			),
		),
		agents_chat_run_write_base_input_schema()
	);
}

/** @return array<string,mixed> */
function agents_set_chat_run_status_input_schema(): array {
	return agents_chat_run_write_extend_schema(
		array( 'status' ),
		array(
			'status' => array(
				'type' => 'string',
				'enum' => array_values( array_diff( WP_Agent_Chat_Run_Control::statuses(), array( WP_Agent_Chat_Run_Control::STATUS_QUEUED ) ) ),
			),
		),
		agents_chat_run_write_base_input_schema()
	);
}

/** @return array<string,mixed> */
function agents_chat_run_claim_input_schema(): array {
	return agents_chat_run_write_base_input_schema();
}

/** @return array<string,mixed> */
function agents_chat_run_write_run_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id', 'session_id', 'status', 'cancelled' ),
		'properties' => array(
			'run_id'     => array( 'type' => 'string' ),
			'session_id' => array( 'type' => 'string' ),
			'status'     => array(
				'type' => 'string',
				'enum' => WP_Agent_Chat_Run_Control::statuses(),
			),
			'cancelled'  => array(
				'type'        => 'boolean',
				'description' => 'True when cancellation was requested via agents/cancel-chat-run; the runtime should stop and finalize as cancelled.',
			),
			'started_at' => array( 'type' => 'string' ),
			'updated_at' => array( 'type' => 'string' ),
			'metadata'   => array( 'type' => 'object' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_chat_run_write_messages_output_schema(): array {
	return agents_chat_run_write_extend_schema(
		array( 'messages', 'count' ),
		array(
			'messages' => array(
				'type'  => 'array',
				'items' => agents_chat_run_write_message_schema(),
			),
			'count'    => array( 'type' => 'integer' ),
		),
		agents_chat_run_write_run_output_schema()
	);
}

/** @return array<string,mixed> */
function agents_chat_run_write_message_output_schema(): array {
	return agents_chat_run_write_extend_schema(
		array( 'message' ),
		array( 'message' => agents_chat_run_write_message_schema() ),
		agents_chat_run_write_run_output_schema()
	);
}

/** @return array<string,mixed> */
function agents_chat_run_write_events_output_schema(): array {
	return agents_chat_run_write_extend_schema(
		array( 'events', 'count' ),
		array(
			'events' => array(
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
			'count'  => array( 'type' => 'integer' ),
		),
		agents_chat_run_write_run_output_schema()
	);
}

/** @return array<string,mixed> */
function agents_chat_run_claim_input_output_schema(): array {
	return agents_chat_run_write_extend_schema(
		array( 'messages', 'count' ),
		array(
			'messages' => array(
				'type'        => 'array',
				'description' => 'Queued user answers claimed for this run, in queue order.',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'queued_message_id' => array( 'type' => 'string' ),
						'session_id'        => array( 'type' => 'string' ),
						'run_id'            => array( 'type' => 'string' ),
						'agent'             => array( 'type' => 'string' ),
						'message'           => array( 'type' => 'string' ),
						'attachments'       => array( 'type' => 'array' ),
						'client_context'    => array( 'type' => 'object' ),
						'created_at'        => array( 'type' => 'string' ),
					),
				),
			),
			'count'    => array( 'type' => 'integer' ),
		),
		agents_chat_run_write_run_output_schema()
	);
}
