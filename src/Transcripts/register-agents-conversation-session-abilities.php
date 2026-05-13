<?php
/**
 * Generic conversation session ability registrations.
 *
 * These abilities expose the host-provided WP_Agent_Conversation_Store to
 * frontend clients without coupling Agents API to a concrete table or product.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

const AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY        = 'agents/list-conversation-sessions';
const AGENTS_GET_CONVERSATION_SESSION_ABILITY          = 'agents/get-conversation-session';
const AGENTS_CREATE_CONVERSATION_SESSION_ABILITY       = 'agents/create-conversation-session';
const AGENTS_UPDATE_CONVERSATION_SESSION_TITLE_ABILITY = 'agents/update-conversation-session-title';
const AGENTS_DELETE_CONVERSATION_SESSION_ABILITY       = 'agents/delete-conversation-session';

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( wp_has_ability_category( 'agents-api' ) ) {
			return;
		}

		wp_register_ability_category(
			'agents-api',
			array(
				'label'       => 'Agents API',
				'description' => 'Cross-cutting abilities provided by the Agents API substrate.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		$abilities = array(
			AGENTS_LIST_CONVERSATION_SESSIONS_ABILITY  => array(
				'label'            => 'List Conversation Sessions',
				'description'      => 'List conversation sessions for the current principal in a workspace.',
				'input_schema'     => agents_conversation_sessions_list_input_schema(),
				'output_schema'    => agents_conversation_sessions_list_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_list_conversation_sessions',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_GET_CONVERSATION_SESSION_ABILITY    => array(
				'label'            => 'Get Conversation Session',
				'description'      => 'Read one conversation session owned by the current principal.',
				'input_schema'     => agents_conversation_session_id_input_schema(),
				'output_schema'    => agents_conversation_session_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_get_conversation_session',
				'annotations'      => array( 'idempotent' => true ),
			),
			AGENTS_CREATE_CONVERSATION_SESSION_ABILITY => array(
				'label'            => 'Create Conversation Session',
				'description'      => 'Create an empty conversation session for the current principal in a workspace.',
				'input_schema'     => agents_conversation_sessions_create_input_schema(),
				'output_schema'    => agents_conversation_session_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_create_conversation_session',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			AGENTS_UPDATE_CONVERSATION_SESSION_TITLE_ABILITY => array(
				'label'            => 'Update Conversation Session Title',
				'description'      => 'Update the stored display title for a conversation session owned by the current principal.',
				'input_schema'     => agents_conversation_sessions_update_title_input_schema(),
				'output_schema'    => agents_conversation_session_output_schema(),
				'execute_callback' => __NAMESPACE__ . '\\agents_update_conversation_session_title',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			AGENTS_DELETE_CONVERSATION_SESSION_ABILITY => array(
				'label'            => 'Delete Conversation Session',
				'description'      => 'Delete a conversation session owned by the current principal.',
				'input_schema'     => agents_conversation_session_id_input_schema(),
				'output_schema'    => array(
					'type'       => 'object',
					'required'   => array( 'deleted' ),
					'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
				),
				'execute_callback' => __NAMESPACE__ . '\\agents_delete_conversation_session',
				'annotations'      => array(
					'destructive' => true,
					'idempotent'  => true,
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
					'permission_callback' => __NAMESPACE__ . '\\agents_conversation_sessions_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => $args['annotations'],
					),
				)
			);
		}
	}
);

/** @return array|\WP_Error */
function agents_list_conversation_sessions( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$workspace = agents_conversation_sessions_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	$args = array(
		'limit'            => 50,
		'offset'           => 0,
		'include_messages' => false,
	);
	if ( isset( $input['limit'] ) ) {
		$args['limit'] = max( 1, min( 100, (int) $input['limit'] ) );
	}
	if ( isset( $input['offset'] ) ) {
		$args['offset'] = max( 0, (int) $input['offset'] );
	}
	if ( isset( $input['agent'] ) ) {
		$args['agent_slug'] = (string) $input['agent'];
	}
	if ( isset( $input['context'] ) ) {
		$args['context'] = (string) $input['context'];
	}

	$sessions = $context['store']->list_sessions( $workspace, $context['principal']->acting_user_id, $args );

	return array(
		'sessions' => array_map( __NAMESPACE__ . '\\agents_conversation_session_summary', $sessions ),
	);
}

/** @return array|\WP_Error */
function agents_get_conversation_session( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$session = agents_conversation_sessions_owned_session( (string) ( $input['session_id'] ?? '' ), $context );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return array( 'session' => agents_conversation_session_full( $session ) );
}

/** @return array|\WP_Error */
function agents_create_conversation_session( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$workspace = agents_conversation_sessions_workspace( $input );
	if ( is_wp_error( $workspace ) ) {
		return $workspace;
	}

	$metadata   = isset( $input['metadata'] ) && is_array( $input['metadata'] ) ? $input['metadata'] : array();
	$agent_slug = isset( $input['agent'] ) ? (string) $input['agent'] : $context['principal']->effective_agent_id;
	$mode       = isset( $input['context'] ) ? (string) $input['context'] : WP_Agent_Execution_Principal::REQUEST_CONTEXT_CHAT;
	$session_id = $context['store']->create_session( $workspace, $context['principal']->acting_user_id, $agent_slug, $metadata, $mode );

	if ( '' === $session_id ) {
		return new \WP_Error( 'agents_conversation_session_create_failed', 'The conversation session store did not create a session.' );
	}

	$session = $context['store']->get_session( $session_id );
	return array( 'session' => agents_conversation_session_full( is_array( $session ) ? $session : array( 'session_id' => $session_id ) ) );
}

/** @return array|\WP_Error */
function agents_update_conversation_session_title( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$session = agents_conversation_sessions_owned_session( (string) ( $input['session_id'] ?? '' ), $context );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$title = trim( (string) ( $input['title'] ?? '' ) );
	if ( '' === $title ) {
		return new \WP_Error( 'agents_conversation_session_invalid_title', 'Conversation session title must be a non-empty string.' );
	}

	if ( ! $context['store']->update_title( $session['session_id'], $title ) ) {
		return new \WP_Error( 'agents_conversation_session_update_failed', 'The conversation session store did not update the title.' );
	}

	$session['title'] = $title;

	return array( 'session' => agents_conversation_session_full( $session ) );
}

/** @return array|\WP_Error */
function agents_delete_conversation_session( array $input ) {
	$context = agents_conversation_sessions_context( $input );
	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$session = agents_conversation_sessions_owned_session( (string) ( $input['session_id'] ?? '' ), $context );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! $context['store']->delete_session( $session['session_id'] ) ) {
		return new \WP_Error( 'agents_conversation_session_delete_failed', 'The conversation session store did not delete the session.' );
	}

	return array( 'deleted' => true );
}

function agents_conversation_sessions_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'read' ) : false;
	return (bool) apply_filters( 'agents_conversation_sessions_permission', $allowed, $input );
}

/** @return array{store:WP_Agent_Conversation_Store,principal:WP_Agent_Execution_Principal}|\WP_Error */
function agents_conversation_sessions_context( array $input ) {
	$principal = agents_conversation_sessions_principal( $input );
	if ( ! $principal instanceof WP_Agent_Execution_Principal ) {
		return new \WP_Error( 'agents_conversation_session_unauthenticated', 'A conversation session principal could not be resolved.' );
	}

	$store = WP_Agent_Conversation_Sessions::get_store( array( 'principal' => $principal ) + $input );
	if ( ! $store instanceof WP_Agent_Conversation_Store ) {
		return new \WP_Error( 'agents_conversation_session_no_store', 'No WP_Agent_Conversation_Store is registered. Provide one with the wp_agent_conversation_store filter.' );
	}

	return array(
		'store'     => $store,
		'principal' => $principal,
	);
}

function agents_conversation_sessions_principal( array $input ): ?WP_Agent_Execution_Principal {
	if ( isset( $input['principal'] ) && $input['principal'] instanceof WP_Agent_Execution_Principal ) {
		return $input['principal'];
	}

	if ( isset( $input['principal'] ) && is_array( $input['principal'] ) ) {
		return WP_Agent_Execution_Principal::from_array( $input['principal'] );
	}

	$principal = WP_Agent_Execution_Principal::resolve( array( 'request_context' => WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST ) + $input );
	if ( $principal instanceof WP_Agent_Execution_Principal ) {
		return $principal;
	}

	$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( $user_id <= 0 ) {
		return null;
	}

	return WP_Agent_Execution_Principal::user_session(
		$user_id,
		isset( $input['agent'] ) ? (string) $input['agent'] : '__wordpress_user__',
		WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
		array(),
		agents_conversation_sessions_workspace_key( $input )
	);
}

/** @return WP_Agent_Workspace_Scope|\WP_Error */
function agents_conversation_sessions_workspace( array $input ) {
	try {
		if ( isset( $input['workspace'] ) && is_array( $input['workspace'] ) ) {
			return WP_Agent_Workspace_Scope::from_array( $input['workspace'] );
		}

		return WP_Agent_Workspace_Scope::from_parts(
			(string) ( $input['workspace_type'] ?? 'site' ),
			(string) ( $input['workspace_id'] ?? agents_conversation_sessions_default_workspace_id() )
		);
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'agents_conversation_session_invalid_workspace', $exception->getMessage() );
	}
}

function agents_conversation_sessions_workspace_key( array $input ): ?string {
	$workspace = agents_conversation_sessions_workspace( $input );
	return $workspace instanceof WP_Agent_Workspace_Scope ? $workspace->key() : null;
}

function agents_conversation_sessions_default_workspace_id(): string {
	if ( function_exists( 'get_current_blog_id' ) ) {
		return (string) get_current_blog_id();
	}

	return 'default';
}

/** @return array<string,mixed>|\WP_Error */
function agents_conversation_sessions_owned_session( string $session_id, array $context ) {
	if ( '' === trim( $session_id ) ) {
		return new \WP_Error( 'agents_conversation_session_invalid_id', 'Conversation session ID must be a non-empty string.' );
	}

	$session = $context['store']->get_session( $session_id );
	if ( ! is_array( $session ) ) {
		return new \WP_Error( 'agents_conversation_session_not_found', 'Conversation session not found.' );
	}

	$principal = $context['principal'];
	if ( (int) ( $session['user_id'] ?? 0 ) !== $principal->acting_user_id && ! agents_conversation_sessions_can_manage_any() ) {
		return new \WP_Error( 'agents_conversation_session_forbidden', 'The current principal cannot access this conversation session.' );
	}

	return $session;
}

function agents_conversation_sessions_can_manage_any(): bool {
	return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

/** @return array<string,mixed> */
function agents_conversation_session_summary( array $session ): array {
	unset( $session['messages'] );
	return $session;
}

/** @return array<string,mixed> */
function agents_conversation_session_full( array $session ): array {
	if ( ! isset( $session['messages'] ) || ! is_array( $session['messages'] ) ) {
		$session['messages'] = array();
	}

	if ( ! isset( $session['metadata'] ) || ! is_array( $session['metadata'] ) ) {
		$session['metadata'] = array();
	}

	return $session;
}

function agents_conversation_sessions_workspace_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'workspace_type', 'workspace_id' ),
		'properties' => array(
			'workspace_type' => array( 'type' => 'string' ),
			'workspace_id'   => array( 'type' => 'string' ),
		),
	);
}

function agents_conversation_sessions_list_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'workspace' => agents_conversation_sessions_workspace_schema(),
			'limit'     => array( 'type' => 'integer' ),
			'offset'    => array( 'type' => 'integer' ),
			'agent'     => array( 'type' => 'string' ),
			'context'   => array( 'type' => 'string' ),
		),
	);
}

function agents_conversation_sessions_create_input_schema(): array {
	$schema                           = agents_conversation_sessions_list_input_schema();
	$schema['properties']['metadata'] = array( 'type' => 'object' );
	return $schema;
}

function agents_conversation_session_id_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'session_id' ),
		'properties' => array( 'session_id' => array( 'type' => 'string' ) ),
	);
}

function agents_conversation_sessions_update_title_input_schema(): array {
	$schema                        = agents_conversation_session_id_input_schema();
	$schema['required'][]          = 'title';
	$schema['properties']['title'] = array( 'type' => 'string' );
	return $schema;
}

function agents_conversation_sessions_list_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'sessions' ),
		'properties' => array(
			'sessions' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
		),
	);
}

function agents_conversation_session_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'session' ),
		'properties' => array( 'session' => array( 'type' => 'object' ) ),
	);
}
