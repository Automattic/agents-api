<?php
/**
 * Canonical chat ability registration.
 *
 * Registers `agents/chat` as the stable, runtime-agnostic entry point for any
 * caller (channel, bridge, REST surface, block) that wants to send one user
 * message to a registered agent and receive an assistant reply. The ability
 * itself is a dispatcher: it validates the canonical input/output shape from
 * https://github.com/Automattic/agents-api/issues/100 and routes execution
 * to whichever runtime registered itself via the `wp_agent_chat_handler` filter.
 * Consumers register a runtime in their own bootstrap; agents-api itself ships
 * no chat runtime.
 *
 * Consumers register a runtime by hooking the filter:
 *
 *     add_filter(
 *         'wp_agent_chat_handler',
 *         function ( $handler, array $input ) {
 *             if ( null !== $handler ) {
 *                 return $handler; // earlier hook already won
 *             }
 *             return [ My_Plugin\Chat_Adapter::class, 'execute' ];
 *         },
 *         10,
 *         2
 *     );
 *
 * The handler receives the canonical input map and must return either an
 * array matching the canonical output shape or a `WP_Error`.
 *
 * Observability hooks fired by the dispatcher:
 *   `agents_chat_dispatch_failed` — fires once per failed dispatch with
 *     `( string $reason, array $input )`. Reasons: `no_handler`,
 *     `invalid_result`, or any `WP_Error::get_error_code()` from a handler.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * The slug under which this ability is registered. Stable. Consumers and
	 * channels should target this string rather than a runtime-specific slug.
 *
 * @since 0.103.0
 */
const AGENTS_CHAT_ABILITY = 'agents/chat';

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'agents-api',
			array(
				'label'       => 'Agents API',
				'description' => 'Cross-cutting abilities provided by the Agents API substrate (channel dispatch and canonical chat contract).',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			AGENTS_CHAT_ABILITY,
			array(
				'label'               => 'Agents Chat',
				'description'         => 'Canonical entry point for sending one user message to a registered agent and receiving an assistant reply. Dispatches to whichever runtime is registered via the wp_agent_chat_handler filter.',
				'category'            => 'agents-api',
				'input_schema'        => agents_chat_input_schema(),
				'output_schema'       => agents_chat_output_schema(),
				'execute_callback'    => __NAMESPACE__ . '\\agents_chat_dispatch',
				'permission_callback' => __NAMESPACE__ . '\\agents_chat_permission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}
);

/**
 * Dispatch a chat request to the registered runtime.
 *
 * @since  0.103.0
 *
 * @param  array $input Canonical chat-ability input.
 * @return array|\WP_Error Canonical output, or WP_Error if no runtime is registered.
 */
function agents_chat_dispatch( array $input ) {
	/**
	 * Filter the chat runtime handler.
	 *
	 * Consumers register a callable that accepts the canonical input array
	 * and returns either the canonical output or WP_Error. The first hook
	 * to return a callable wins; later hooks should respect that decision
	 * unless they intentionally take over (e.g. an agent-specific override).
	 *
	 * @param callable|null $handler Currently registered handler. Null when
	 *                               no runtime has registered.
	 * @param array         $input   The canonical input being dispatched. Use
	 *                               $input['agent'] to route per agent slug.
	 */
	$handler = apply_filters( 'wp_agent_chat_handler', null, $input );

	if ( ! is_callable( $handler ) ) {
		/**
		 * Fires when agents/chat dispatched but no handler was registered.
		 * Use for sysadmin-side observability — alerting, Site Health, logs.
		 *
		 * @since 0.103.0
		 *
		 * @param string $reason Dispatch failure reason. Always `'no_handler'`.
		 * @param array  $input  The canonical input that was rejected.
		 */
		do_action( 'agents_chat_dispatch_failed', 'no_handler', $input );

		return new \WP_Error(
			'agents_chat_no_handler',
			'No agents/chat handler is registered. Install a consumer plugin that registers a runtime, or add a callable to the wp_agent_chat_handler filter.'
		);
	}

	$result = call_user_func( $handler, $input );

	if ( is_wp_error( $result ) ) {
		/** This action is documented above. */
		do_action( 'agents_chat_dispatch_failed', $result->get_error_code(), $input );

		return $result;
	}

	if ( ! is_array( $result ) ) {
		/** This action is documented above. */
		do_action( 'agents_chat_dispatch_failed', 'invalid_result', $input );

		return new \WP_Error(
			'agents_chat_invalid_result',
			'agents/chat handler returned an unexpected result type. Handlers must return an array matching the canonical output shape or a WP_Error.'
		);
	}

	return $result;
}

/**
 * Permission gate for `agents/chat`. Defaults to `manage_options`; consumers
 * with their own auth model (HMAC-signed webhook, OAuth bearer, etc.) can
 * widen the gate per-request via the `agents_chat_permission` filter.
 *
 * @since 0.103.0
 *
 * @param array $input Canonical input.
 * @return bool
 */
function agents_chat_permission( array $input ): bool {
	/**
	 * Filter the permission decision for the canonical chat ability.
	 *
	 * @param bool  $allowed Default: current_user_can( 'manage_options' ).
	 * @param array $input   The canonical input being authorized.
	 */
	return (bool) apply_filters(
		'agents_chat_permission',
		current_user_can( 'manage_options' ),
		$input
	);
}

/**
 * Canonical input JSON schema (per agents-api#100).
 *
 * @since  0.103.0
 *
 * @return array
 */
function agents_chat_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'agent', 'message' ),
		'properties' => array(
			'agent'          => array(
				'type'        => 'string',
				'description' => 'Slug or ID of the registered agent that should handle this turn.',
			),
			'message'        => array(
				'type'        => 'string',
				'description' => 'User-side text for the agent to respond to.',
			),
			'session_id'     => array(
				'type'        => array( 'string', 'null' ),
				'description' => 'Existing session ID to continue, or null to start a new session.',
			),
			'attachments'    => array(
				'type'        => 'array',
				'description' => 'Channel-side attachments (images, voice notes, files, link previews). Shape is runtime-defined; runtimes ignore unknown attachment types.',
				'default'     => array(),
				'items'       => array( 'type' => 'object' ),
			),
			'client_context' => array(
				'type'        => 'object',
				'description' => 'Transport-level context describing where this turn originated.',
				'properties'  => array(
					'source'                   => array(
						'type'        => 'string',
						'enum'        => array( 'channel', 'bridge', 'rest', 'block' ),
						'description' => 'How the request reached this dispatcher.',
					),
					'client_name'              => array(
						'type'        => 'string',
						'description' => 'Specific client identifier within the source (e.g. "cli-relay" or "messaging-bot").',
					),
					'connector_id'             => array(
						'type'        => 'string',
						'description' => 'Stable connector or channel instance id used for settings, attribution, and external conversation session mapping.',
					),
					'external_provider'        => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'External network identifier (e.g. "whatsapp", "slack", "email"). Null if not applicable.',
					),
					'external_conversation_id' => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Opaque external conversation id (chat JID, channel id, thread root). Null if the source has no per-conversation isolation.',
					),
					'external_message_id'      => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Stable transport-side message id, used for reply threading / dedup / audit.',
					),
					'room_kind'                => array(
						'type'        => array( 'string', 'null' ),
						'enum'        => array( 'dm', 'group', 'channel' ),
						'description' => 'Conversation kind: direct message, multi-participant group, broadcast channel. Null when the source has no notion of room kind.',
					),
				),
			),
		),
	);
}

/**
 * Canonical output JSON schema (per agents-api#100).
 *
 * @since  0.103.0
 *
 * @return array
 */
function agents_chat_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'session_id', 'reply' ),
		'properties' => array(
			'session_id' => array(
				'type'        => 'string',
				'description' => 'Session ID to thread subsequent turns under.',
			),
			'reply'      => array(
				'type'        => 'string',
				'description' => 'Primary assistant text. Must be set even when the runtime supplies multi-message output via `messages`.',
			),
			'messages'   => array(
				'type'        => 'array',
				'description' => 'Optional multi-message expansion (e.g. assistant emitted multiple turns or split a long answer). When present, each entry is `{ role, content }`. The single-string `reply` is still required for clients that don\'t parse `messages`.',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'role'    => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
					),
				),
			),
			'completed'  => array(
				'type'        => 'boolean',
				'description' => 'Whether the agent considers this turn complete (true) or expects further work (false, e.g. tool approvals pending).',
			),
			'metadata'   => array(
				'type'        => 'object',
				'description' => 'Runtime-specific metadata (token usage, model, latency, tool calls). Opaque to the dispatcher.',
			),
		),
	);
}

/**
 * Convenience helper for consumers: register a callable as the chat handler.
 *
 * Equivalent to `add_filter( 'wp_agent_chat_handler', ... )` but reads more
 * intentionally at the call site.
 *
 * @since 0.103.0
 *
 * @param callable $handler  Receives the canonical input array, returns the
 *                           canonical output array or WP_Error.
 * @param int      $priority Filter priority. Default 10.
 */
function register_chat_handler( callable $handler, int $priority = 10 ): void {
	add_filter(
		'wp_agent_chat_handler',
		static function ( $existing, array $input ) use ( $handler ) {
			unset( $input );
			if ( null !== $existing ) {
				return $existing;
			}
			return $handler;
		},
		$priority,
		2
	);
}
