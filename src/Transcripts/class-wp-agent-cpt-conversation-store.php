<?php
/**
 * Opt-in CPT-backed conversation transcript store + lock primitive.
 *
 * A WordPress-native default implementation of the conversation-session
 * contracts, backed by `wp_posts` + `wp_postmeta`. No custom tables.
 *
 * This store is dormant by default: it registers nothing unless a consumer
 * opts in via the `agents_api_enable_default_conversation_store` filter (see
 * register-default-conversation-store.php). It exists so small consumers and
 * experiments get durable sessions with a single switch, without owning
 * persistence infrastructure or installing a separate package. High-volume
 * products keep registering their own store through the
 * `wp_agent_conversation_store` filter and never enable this one.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Default WordPress-native conversation transcript store.
 *
 * Implements `WP_Agent_Principal_Conversation_Store` (and therefore
 * `WP_Agent_Conversation_Store`) plus `WP_Agent_Conversation_Lock`. Ownership
 * is keyed by `(owner_type, owner_key)` so non-user principals (audience/token)
 * are first-class; the int-user methods delegate to the `_for_owner` variants.
 * User-owned sessions also set `post_author` for WordPress author integration,
 * but the owner meta is the source of truth.
 *
 * Storage choices: messages JSON in `post_content`, ownership / workspace /
 * provider continuity / context in dedicated meta, title in `post_title`. List
 * and dedup queries use `WP_Query` + `meta_query`; the single-writer lock uses
 * an atomic `add_post_meta($unique=true)` fast path plus a compare-and-swap
 * `$wpdb->update()` slow path for expired-lock reclamation.
 */
final class WP_Agent_Cpt_Conversation_Store implements WP_Agent_Principal_Conversation_Store, WP_Agent_Conversation_Lock {

	public const POST_TYPE = 'agents_api_session';

	public const OWNER_TYPE_USER = 'user';

	private const META_SESSION_ID           = '_agents_api_session_id';
	private const META_WORKSPACE_TYPE       = '_agents_api_workspace_type';
	private const META_WORKSPACE_ID         = '_agents_api_workspace_id';
	private const META_OWNER_TYPE           = '_agents_api_owner_type';
	private const META_OWNER_KEY            = '_agents_api_owner_key';
	private const META_AGENT_SLUG           = '_agents_api_agent_slug';
	private const META_METADATA             = '_agents_api_metadata';
	private const META_PROVIDER             = '_agents_api_provider';
	private const META_MODEL                = '_agents_api_model';
	private const META_PROVIDER_RESPONSE_ID = '_agents_api_provider_response_id';
	private const META_CONTEXT              = '_agents_api_context';
	private const META_LAST_READ_AT         = '_agents_api_last_read_at';
	private const META_EXPIRES_AT           = '_agents_api_expires_at';
	private const META_LOCK                 = '_agents_api_lock';
	private const META_TOKEN_ID             = '_agents_api_token_id';

	/**
	 * Register the session post type.
	 *
	 * Sessions are not surfaced in wp-admin (`show_ui = false`) and have no
	 * front-end permalinks (`public = false`) because they are per-owner data,
	 * not site content — mirroring how `wp_block` is registered.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Agent Sessions', 'agents-api' ),
					'singular_name' => __( 'Agent Session', 'agents-api' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => array( 'title', 'editor', 'author', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Canonical owner shape for a WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{type:string,key:string}
	 */
	public static function user_owner( int $user_id ): array {
		return array(
			'type' => self::OWNER_TYPE_USER,
			'key'  => (string) $user_id,
		);
	}

	/* --------------------- Conversation store contract -------------------- */

	public function create_session( WP_Agent_Workspace_Scope $workspace, int $user_id, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		return $this->create_session_for_owner( $workspace, self::user_owner( $user_id ), $agent_slug, $metadata, $context );
	}

	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		return $this->list_sessions_for_owner( $workspace, self::user_owner( $user_id ), $args );
	}

	public function get_recent_pending_session( WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		return $this->get_recent_pending_session_for_owner( $workspace, self::user_owner( $user_id ), $seconds, $context, $token_id );
	}

	public function get_session( string $session_id ): ?array {
		$post = $this->find_post_by_session_id( $session_id );
		return null === $post ? null : $this->session_array( $post );
	}

	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool {
		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				// wp_update_post unslashes post_content; wp_slash preserves the
				// JSON's escaped quotes (notably tool_result payloads that nest
				// JSON in their content field).
				'post_content' => wp_slash( (string) wp_json_encode( array_values( $messages ) ) ),
			),
			true
		);

		if ( is_wp_error( $updated ) || ! $updated ) {
			return false;
		}

		update_post_meta( $post->ID, self::META_METADATA, wp_json_encode( $metadata ) );
		if ( '' !== $provider ) {
			update_post_meta( $post->ID, self::META_PROVIDER, $provider );
		}
		if ( '' !== $model ) {
			update_post_meta( $post->ID, self::META_MODEL, $model );
		}
		if ( null !== $provider_response_id ) {
			update_post_meta( $post->ID, self::META_PROVIDER_RESPONSE_ID, $provider_response_id );
		}

		return true;
	}

	public function delete_session( string $session_id ): bool {
		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return true;
		}
		$result = wp_delete_post( $post->ID, true );
		return false !== $result && null !== $result;
	}

	public function update_title( string $session_id, string $title ): bool {
		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => wp_slash( $title ),
			),
			true
		);

		return ! is_wp_error( $updated ) && (bool) $updated;
	}

	/* ---------------- Principal conversation store contract --------------- */

	public function create_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		$owner      = $this->normalize_owner( $owner );
		$session_id = self::uuid4();

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_author'  => self::OWNER_TYPE_USER === $owner['type'] ? (int) $owner['key'] : 0,
				'post_title'   => '',
				// wp_insert_post unslashes post_content; wp_slash compensates so
				// JSON-escaped characters survive the round-trip.
				'post_content' => wp_slash( (string) wp_json_encode( array() ) ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return '';
		}

		update_post_meta( $post_id, self::META_SESSION_ID, $session_id );
		update_post_meta( $post_id, self::META_WORKSPACE_TYPE, $workspace->workspace_type );
		update_post_meta( $post_id, self::META_WORKSPACE_ID, $workspace->workspace_id );
		update_post_meta( $post_id, self::META_OWNER_TYPE, $owner['type'] );
		update_post_meta( $post_id, self::META_OWNER_KEY, $owner['key'] );
		update_post_meta( $post_id, self::META_AGENT_SLUG, $agent_slug );
		update_post_meta( $post_id, self::META_METADATA, wp_json_encode( $metadata ) );
		update_post_meta( $post_id, self::META_CONTEXT, $context );

		if ( isset( $metadata['token_id'] ) ) {
			update_post_meta( $post_id, self::META_TOKEN_ID, (int) $metadata['token_id'] );
		}

		return $session_id;
	}

	public function list_sessions_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, array $args = array() ): array {
		$owner            = $this->normalize_owner( $owner );
		$limit            = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50;
		$offset           = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$include_messages = ! empty( $args['include_messages'] );

		$meta_query = $this->owner_meta_query( $workspace, $owner );

		if ( isset( $args['agent_slug'] ) && '' !== $args['agent_slug'] ) {
			$meta_query[] = array(
				'key'   => self::META_AGENT_SLUG,
				'value' => (string) $args['agent_slug'],
			);
		}

		if ( isset( $args['context'] ) && '' !== $args['context'] ) {
			$meta_query[] = array(
				'key'   => self::META_CONTEXT,
				'value' => (string) $args['context'],
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		$sessions = array();
		foreach ( $query->posts as $post ) {
			$session = $this->session_array( $post );
			if ( ! $include_messages ) {
				unset( $session['messages'] );
			}
			$sessions[] = $session;
		}

		return $sessions;
	}

	public function get_recent_pending_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		$owner  = $this->normalize_owner( $owner );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $seconds ) );

		$meta_query   = $this->owner_meta_query( $workspace, $owner );
		$meta_query[] = array(
			'key'   => self::META_CONTEXT,
			'value' => $context,
		);

		if ( null !== $token_id ) {
			$meta_query[] = array(
				'key'   => self::META_TOKEN_ID,
				'value' => $token_id,
				'type'  => 'NUMERIC',
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'date_query'             => array(
					array(
						'after'     => $cutoff,
						'inclusive' => true,
						'column'    => 'post_date_gmt',
					),
				),
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'fields'                 => 'all',
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		$post     = $query->posts[0];
		$messages = $this->decode_messages( $post->post_content );

		// "Pending" = empty transcript or actively-locked session.
		if ( empty( $messages ) || $this->lock_active( $post->ID ) ) {
			return $this->session_array( $post );
		}

		return null;
	}

	/* ---------------------------- Lock contract --------------------------- */

	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return null;
		}

		$token   = self::uuid4();
		$expires = time() + max( 1, $ttl_seconds );
		$value   = wp_json_encode(
			array(
				'token'   => $token,
				'expires' => $expires,
			)
		);

		// Fast path: no lock present. add_post_meta with $unique=true is atomic.
		$added = add_post_meta( $post->ID, self::META_LOCK, $value, true );
		if ( $added ) {
			return $token;
		}

		// Slow path: a lock row exists. Read it; if not yet expired, lose.
		$existing_raw = get_post_meta( $post->ID, self::META_LOCK, true );
		if ( ! is_string( $existing_raw ) || '' === $existing_raw ) {
			// Race: meta disappeared between calls. Retry once.
			$retry = add_post_meta( $post->ID, self::META_LOCK, $value, true );
			return $retry ? $token : null;
		}

		$existing = json_decode( $existing_raw, true );
		if ( ! is_array( $existing ) || (int) ( $existing['expires'] ?? 0 ) > time() ) {
			return null;
		}

		// Atomic compare-and-swap on the expired lock. The WHERE meta_value =
		// $existing_raw clause is the test; the SET is the swap. Concurrent
		// callers that read the same expired lock race here, and only one sees
		// rows_affected = 1.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $value ),
			array(
				'post_id'    => $post->ID,
				'meta_key'   => self::META_LOCK,
				'meta_value' => $existing_raw,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);

		if ( false === $rows ) {
			return null;
		}

		// Bust the post-meta cache so subsequent reads see the new lock value.
		wp_cache_delete( $post->ID, 'post_meta' );

		return 1 === (int) $rows ? $token : null;
	}

	public function release_session_lock( string $session_id, string $lock_token ): bool {
		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return false;
		}

		$existing = $this->read_lock( $post->ID );
		if ( null === $existing ) {
			return false;
		}

		if ( ( $existing['token'] ?? '' ) !== $lock_token ) {
			return false;
		}

		delete_post_meta( $post->ID, self::META_LOCK );
		return true;
	}

	/* ----------------------------- Internals ------------------------------ */

	/**
	 * Build the workspace + owner meta_query prefix shared by list/dedup.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace Workspace scope.
	 * @param array{type:string,key:string} $owner     Normalized owner.
	 * @return array<int|string,mixed>
	 */
	private function owner_meta_query( WP_Agent_Workspace_Scope $workspace, array $owner ): array {
		return array(
			'relation' => 'AND',
			array(
				'key'   => self::META_WORKSPACE_TYPE,
				'value' => $workspace->workspace_type,
			),
			array(
				'key'   => self::META_WORKSPACE_ID,
				'value' => $workspace->workspace_id,
			),
			array(
				'key'   => self::META_OWNER_TYPE,
				'value' => $owner['type'],
			),
			array(
				'key'   => self::META_OWNER_KEY,
				'value' => $owner['key'],
			),
		);
	}

	/**
	 * Coerce a caller-supplied owner into the canonical shape.
	 *
	 * @param array{type?:string,key?:string} $owner Raw owner.
	 * @return array{type:string,key:string}
	 */
	private function normalize_owner( array $owner ): array {
		$type = isset( $owner['type'] ) && is_string( $owner['type'] ) && '' !== $owner['type'] ? $owner['type'] : self::OWNER_TYPE_USER;
		$key  = isset( $owner['key'] ) ? (string) $owner['key'] : '0';
		return array(
			'type' => $type,
			'key'  => $key,
		);
	}

	private function find_post_by_session_id( string $session_id ): ?\WP_Post {
		if ( '' === trim( $session_id ) ) {
			return null;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'meta_key'               => self::META_SESSION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $session_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	private function session_array( \WP_Post $post ): array {
		$metadata_raw = get_post_meta( $post->ID, self::META_METADATA, true );
		$metadata     = is_string( $metadata_raw ) && '' !== $metadata_raw ? json_decode( $metadata_raw, true ) : array();
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$owner_type = (string) get_post_meta( $post->ID, self::META_OWNER_TYPE, true );
		if ( '' === $owner_type ) {
			$owner_type = self::OWNER_TYPE_USER;
		}
		$owner_key = (string) get_post_meta( $post->ID, self::META_OWNER_KEY, true );

		$context = (string) get_post_meta( $post->ID, self::META_CONTEXT, true );
		if ( '' === $context ) {
			$context = 'chat';
		}

		return array(
			'session_id'           => (string) get_post_meta( $post->ID, self::META_SESSION_ID, true ),
			'workspace_type'       => (string) get_post_meta( $post->ID, self::META_WORKSPACE_TYPE, true ),
			'workspace_id'         => (string) get_post_meta( $post->ID, self::META_WORKSPACE_ID, true ),
			'owner_type'           => $owner_type,
			'owner_key'            => $owner_key,
			'user_id'              => self::OWNER_TYPE_USER === $owner_type ? (int) $owner_key : 0,
			'agent_slug'           => (string) get_post_meta( $post->ID, self::META_AGENT_SLUG, true ),
			'title'                => (string) $post->post_title,
			'messages'             => $this->decode_messages( $post->post_content ),
			'metadata'             => $metadata,
			'provider'             => (string) get_post_meta( $post->ID, self::META_PROVIDER, true ),
			'model'                => (string) get_post_meta( $post->ID, self::META_MODEL, true ),
			'provider_response_id' => $this->nullable_meta_string( $post->ID, self::META_PROVIDER_RESPONSE_ID ),
			'context'              => $context,
			'mode'                 => $context,
			'created_at'           => (string) $post->post_date_gmt,
			'updated_at'           => (string) $post->post_modified_gmt,
			'last_read_at'         => $this->nullable_meta_string( $post->ID, self::META_LAST_READ_AT ),
			'expires_at'           => $this->nullable_meta_string( $post->ID, self::META_EXPIRES_AT ),
		);
	}

	private function decode_messages( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	private function nullable_meta_string( int $post_id, string $key ): ?string {
		$value = get_post_meta( $post_id, $key, true );
		return ( '' === $value || null === $value ) ? null : (string) $value;
	}

	private function read_lock( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, self::META_LOCK, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function lock_active( int $post_id ): bool {
		$lock = $this->read_lock( $post_id );
		if ( null === $lock ) {
			return false;
		}
		return (int) ( $lock['expires'] ?? 0 ) > time();
	}

	private static function uuid4(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		// Fallback for environments without WP loaded.
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
