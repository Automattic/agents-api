<?php
/**
 * Agent conversation transcript persistence contract.
 *
 * This is the narrow, generic storage seam for complete conversation
 * transcripts. It covers session row creation, transcript reads/writes,
 * deletion, retry deduplication, and the stored display title that belongs
 * to a transcript row. It deliberately does not include chat UI listing,
 * read-state, retention scheduling, or reporting/metrics responsibilities.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Conversation_Store {

	/**
	 * Convention key for storing the agent's registered slug inside session
	 * `metadata`.
	 *
	 * Agents are slug-keyed in this substrate (`wp_register_agent`,
	 * `wp_get_agent`, `wp_has_agent` all take strings) but the conversation
	 * store contract carries `int $agent_id`. Until the contract is widened
	 * to a string identifier, callers that registered an agent via
	 * `wp_register_agent` should mirror its slug into the session metadata
	 * under this key so that downstream consumers can resolve the agent the
	 * same way the registry does. The value is a `WP_Agent::get_slug()`
	 * string; recommended reading path is
	 * `$session['metadata'][ WP_Agent_Conversation_Store::META_KEY_AGENT_SLUG ]`.
	 *
	 * Tracking issue: https://github.com/Automattic/agents-api/issues/95
	 */
	public const META_KEY_AGENT_SLUG = 'agent_slug';

	/**
	 * Create a new conversation transcript session and return its ID.
	 *
	 * `$agent_id` is an opaque integer; consumers without an integer agent
	 * identifier should pass `0` and mirror the slug into `$metadata` under
	 * {@see self::META_KEY_AGENT_SLUG}. See #95 for context on the slug-vs-int gap.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the session.
	 * @param int                      $user_id   WordPress user ID owning the session.
	 * @param int                      $agent_id  Agent ID (0 = agent-less or slug-only session).
	 * @param array                    $metadata  Arbitrary session metadata (JSON-serializable). When the
	 *                                            session belongs to a slug-registered agent, callers should
	 *                                            include `[ self::META_KEY_AGENT_SLUG => $slug ]`.
	 * @param string                   $context   Execution mode ('chat', 'pipeline', 'system').
	 * @return string Session ID (UUIDv4), or empty string on failure.
	 */
	public function create_session( WP_Agent_Workspace_Scope $workspace, int $user_id, int $agent_id = 0, array $metadata = array(), string $context = 'chat' ): string;

	/**
	 * Retrieve a transcript session by ID.
	 *
	 * Returns the session as an associative array with keys:
	 * session_id, workspace_type, workspace_id, user_id, agent_id, title, messages (decoded array),
	 * metadata (decoded array), provider, model, provider_response_id, context/mode, created_at,
	 * updated_at, last_read_at, expires_at.
	 *
	 * The agent slug, when present, lives at
	 * `$session['metadata'][ self::META_KEY_AGENT_SLUG ]`.
	 *
	 * @param string $session_id Session UUID.
	 * @return array|null Session data or null if not found.
	 */
	public function get_session( string $session_id ): ?array;

	/**
	 * Replace a session's messages + metadata.
	 *
	 * @param string      $session_id           Session UUID.
	 * @param array       $messages             Complete messages array (not a delta).
	 * @param array       $metadata             Updated metadata. Slug-registered agents should keep
	 *                                          the {@see self::META_KEY_AGENT_SLUG} entry in sync.
	 * @param string      $provider             Optional AI provider identifier.
	 * @param string      $model                Optional AI model identifier.
	 * @param string|null $provider_response_id Opaque provider-side response/state ID, or null when none.
	 * @return bool True on success.
	 */
	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool;

	/**
	 * Delete a session by ID. Idempotent.
	 *
	 * @param string $session_id Session UUID.
	 * @return bool True on success.
	 */
	public function delete_session( string $session_id ): bool;

	/**
	 * Find a recent pending session for deduplication after request timeouts.
	 *
	 * Returns the most recent session that belongs to $workspace and $user_id,
	 * was created within $seconds, and is either empty or actively processing.
	 * Used by the orchestrator to avoid duplicate sessions when a timeout
	 * triggers a client retry while PHP keeps executing.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the session.
	 * @param int                 $user_id   WordPress user ID.
	 * @param int                 $seconds   Lookback window (default 600 = 10 minutes).
	 * @param string              $context   Context filter.
	 * @param int|null            $token_id  Optional token ID for login-scoped dedup.
	 * @return array|null Session data or null if none.
	 */
	public function get_recent_pending_session( WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array;

	/**
	 * Set a transcript session's stored display title.
	 *
	 * Title generation and UI policy stay above the store. This mutator remains
	 * here because the persisted title is part of the transcript/session record.
	 *
	 * @param string $session_id Session UUID.
	 * @param string $title      New title.
	 * @return bool True on success.
	 */
	public function update_title( string $session_id, string $title ): bool;
}
