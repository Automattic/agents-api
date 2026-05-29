<?php
/**
 * Contract smoke for the opt-in default CPT conversation store.
 *
 * Exercises WP_Agent_Cpt_Conversation_Store against the canonical interfaces
 * using a focused in-memory WordPress shim (posts + postmeta + a minimal
 * WP_Query + a $wpdb stub for the lock CAS), so the full create -> get ->
 * update -> delete -> list -> lock round-trip runs without a database. The
 * shim implements exactly the WordPress surface this store touches; it is not
 * a general WP emulator.
 *
 * Run with: php tests/cpt-conversation-store-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-cpt-conversation-store-smoke\n";

/* ----------------------------- in-memory WP shim ---------------------------- */

$GLOBALS['__posts']   = array();
$GLOBALS['__meta']    = array();
$GLOBALS['__next_id'] = 1;

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID                  = 0;
		public int $post_author         = 0;
		public string $post_title       = '';
		public string $post_content     = '';
		public string $post_type        = '';
		public string $post_status      = 'publish';
		public string $post_date_gmt    = '';
		public string $post_modified_gmt = '';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_code(): string {
			return $this->code; }
		public function get_error_message(): string {
			return $this->message; }
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

// No-op hook stubs: loading agents-api.php registers init/abilities callbacks
// at file scope. The smoke calls the store directly, so these need not fire.
function add_action( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
	unset( $hook, $cb, $priority, $args );
	return true;
}
function add_filter( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
	unset( $hook, $cb, $priority, $args );
	return true;
}
function apply_filters( string $hook, $value, ...$args ) {
	unset( $hook, $args );
	return $value;
}
function do_action( string $hook, ...$args ): void {
	unset( $hook, $args );
}

function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
	return json_encode( $data, $options, max( 1, $depth ) );
}

function wp_slash( $value ) {
	return $value;
}

function wp_cache_delete( $key, $group = '' ): bool {
	unset( $key, $group );
	return true;
}

$GLOBALS['__uuid_seq'] = 0;
function wp_generate_uuid4(): string {
	++$GLOBALS['__uuid_seq'];
	return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['__uuid_seq'] );
}

function wp_insert_post( array $postarr, bool $wp_error = false ) {
	unset( $wp_error );
	$id                      = $GLOBALS['__next_id']++;
	$now                     = gmdate( 'Y-m-d H:i:s' );
	$post                    = new WP_Post();
	$post->ID                = $id;
	$post->post_author       = (int) ( $postarr['post_author'] ?? 0 );
	$post->post_title        = (string) ( $postarr['post_title'] ?? '' );
	$post->post_content      = (string) ( $postarr['post_content'] ?? '' );
	$post->post_type         = (string) ( $postarr['post_type'] ?? 'post' );
	$post->post_status       = (string) ( $postarr['post_status'] ?? 'publish' );
	$post->post_date_gmt     = $now;
	$post->post_modified_gmt = $now;

	$GLOBALS['__posts'][ $id ] = $post;
	$GLOBALS['__meta'][ $id ]  = array();
	return $id;
}

function wp_update_post( array $postarr, bool $wp_error = false ) {
	unset( $wp_error );
	$id = (int) ( $postarr['ID'] ?? 0 );
	if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) {
		return 0;
	}
	$post = $GLOBALS['__posts'][ $id ];
	if ( array_key_exists( 'post_content', $postarr ) ) {
		$post->post_content = (string) $postarr['post_content'];
	}
	if ( array_key_exists( 'post_title', $postarr ) ) {
		$post->post_title = (string) $postarr['post_title'];
	}
	$post->post_modified_gmt = gmdate( 'Y-m-d H:i:s', time() + 1 );
	return $id;
}

function wp_delete_post( int $post_id, bool $force = false ) {
	unset( $force );
	if ( ! isset( $GLOBALS['__posts'][ $post_id ] ) ) {
		return false;
	}
	$post = $GLOBALS['__posts'][ $post_id ];
	unset( $GLOBALS['__posts'][ $post_id ], $GLOBALS['__meta'][ $post_id ] );
	return $post;
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	$all = $GLOBALS['__meta'][ $post_id ] ?? array();
	if ( '' === $key ) {
		return $all;
	}
	$values = $all[ $key ] ?? array();
	if ( $single ) {
		return empty( $values ) ? '' : $values[0];
	}
	return $values;
}

function update_post_meta( int $post_id, string $key, $value ): bool {
	$GLOBALS['__meta'][ $post_id ][ $key ] = array( $value );
	return true;
}

function add_post_meta( int $post_id, string $key, $value, bool $unique = false ) {
	if ( $unique && ! empty( $GLOBALS['__meta'][ $post_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['__meta'][ $post_id ][ $key ][] = $value;
	return $post_id * 1000 + 1;
}

function delete_post_meta( int $post_id, string $key ): bool {
	unset( $GLOBALS['__meta'][ $post_id ][ $key ] );
	return true;
}

/**
 * Minimal WP_Query supporting only the shapes this store issues:
 * post_type filter, optional meta_key/meta_value lookup, meta_query (AND
 * equality + NUMERIC), date_query (after/before on a *_gmt column), order by
 * date, posts_per_page + offset.
 */
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		/** @var WP_Post[] */
		public array $posts = array();

		public function __construct( array $args ) {
			$post_type = (string) ( $args['post_type'] ?? 'post' );
			$matches   = array();

			foreach ( $GLOBALS['__posts'] as $post ) {
				if ( $post->post_type !== $post_type ) {
					continue;
				}
				if ( isset( $args['meta_key'] ) ) {
					$single = get_post_meta( $post->ID, (string) $args['meta_key'], true );
					if ( (string) $single !== (string) ( $args['meta_value'] ?? '' ) ) {
						continue;
					}
				}
				if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) && ! $this->meta_query_matches( $post->ID, $args['meta_query'] ) ) {
					continue;
				}
				if ( isset( $args['date_query'] ) && is_array( $args['date_query'] ) && ! $this->date_query_matches( $post, $args['date_query'] ) ) {
					continue;
				}
				$matches[] = $post;
			}

			usort(
				$matches,
				static function ( WP_Post $a, WP_Post $b ): int {
					return strcmp( $b->post_date_gmt, $a->post_date_gmt ) ?: ( $b->ID <=> $a->ID );
				}
			);

			$offset  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
			$limit   = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : -1;
			$matches = array_slice( $matches, $offset, $limit < 0 ? null : $limit );

			$this->posts = $matches;
		}

		private function meta_query_matches( int $post_id, array $meta_query ): bool {
			foreach ( $meta_query as $key => $clause ) {
				if ( 'relation' === $key || ! is_array( $clause ) ) {
					continue;
				}
				$meta_key = (string) ( $clause['key'] ?? '' );
				$expected = $clause['value'] ?? '';
				$actual   = get_post_meta( $post_id, $meta_key, true );
				if ( 'NUMERIC' === ( $clause['type'] ?? '' ) ) {
					if ( (int) $actual !== (int) $expected ) {
						return false;
					}
					continue;
				}
				if ( (string) $actual !== (string) $expected ) {
					return false;
				}
			}
			return true;
		}

		private function date_query_matches( WP_Post $post, array $date_query ): bool {
			foreach ( $date_query as $clause ) {
				if ( ! is_array( $clause ) ) {
					continue;
				}
				$column = (string) ( $clause['column'] ?? 'post_date_gmt' );
				$value  = $post->{$column} ?? '';
				if ( isset( $clause['after'] ) && strcmp( $value, (string) $clause['after'] ) < 0 ) {
					return false;
				}
				if ( isset( $clause['before'] ) && strcmp( $value, (string) $clause['before'] ) > 0 ) {
					return false;
				}
			}
			return true;
		}
	}
}

/** Minimal $wpdb supporting the lock compare-and-swap. */
if ( ! class_exists( 'WPDB_Cpt_Store_Shim' ) ) {
	class WPDB_Cpt_Store_Shim {
		public string $postmeta = 'wp_postmeta';

		public function update( string $table, array $data, array $where, array $data_format = array(), array $where_format = array() ) {
			unset( $table, $data_format, $where_format );
			$post_id = (int) ( $where['post_id'] ?? 0 );
			$key     = (string) ( $where['meta_key'] ?? '' );
			$old     = (string) ( $where['meta_value'] ?? '' );
			$new     = (string) ( $data['meta_value'] ?? '' );

			$values = $GLOBALS['__meta'][ $post_id ][ $key ] ?? array();
			if ( empty( $values ) || (string) $values[0] !== $old ) {
				return 0;
			}
			$GLOBALS['__meta'][ $post_id ][ $key ][0] = $new;
			return 1;
		}
	}
}
$GLOBALS['wpdb'] = new WPDB_Cpt_Store_Shim();

/* ------------------------------ load + assert ------------------------------ */

require_once __DIR__ . '/../agents-api.php';

use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock;
use AgentsAPI\Core\Database\Chat\WP_Agent_Principal_Conversation_Store;
use AgentsAPI\Core\Database\Chat\WP_Agent_Cpt_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

function smoke_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}
	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

$store     = new WP_Agent_Cpt_Conversation_Store();
$workspace = WP_Agent_Workspace_Scope::from_parts( 'site', '42' );

echo "\n[1] Implements the canonical contracts:\n";
smoke_assert( true, $store instanceof WP_Agent_Conversation_Store, 'implements WP_Agent_Conversation_Store', $failures, $passes );
smoke_assert( true, $store instanceof WP_Agent_Principal_Conversation_Store, 'implements WP_Agent_Principal_Conversation_Store', $failures, $passes );
smoke_assert( true, $store instanceof WP_Agent_Conversation_Lock, 'implements WP_Agent_Conversation_Lock', $failures, $passes );

echo "\n[2] User-keyed create -> get round-trips with the contract shape:\n";
$session_id = $store->create_session( $workspace, 7, 'demo-agent', array( 'k' => 'v' ), 'chat' );
smoke_assert( true, '' !== $session_id, 'create_session returns a non-empty id', $failures, $passes );
$session = $store->get_session( $session_id );
smoke_assert( true, is_array( $session ), 'get_session returns an array', $failures, $passes );
smoke_assert( $session_id, $session['session_id'] ?? null, 'session_id round-trips', $failures, $passes );
smoke_assert( 'site', $session['workspace_type'] ?? null, 'workspace_type preserved', $failures, $passes );
smoke_assert( '42', $session['workspace_id'] ?? null, 'workspace_id preserved', $failures, $passes );
smoke_assert( 7, $session['user_id'] ?? null, 'user_id derived from user owner', $failures, $passes );
smoke_assert( 'user', $session['owner_type'] ?? null, 'owner_type recorded as user', $failures, $passes );
smoke_assert( '7', $session['owner_key'] ?? null, 'owner_key is the user id string', $failures, $passes );
smoke_assert( 'demo-agent', $session['agent_slug'] ?? null, 'agent_slug preserved', $failures, $passes );
smoke_assert( 'chat', $session['context'] ?? null, 'context preserved', $failures, $passes );
smoke_assert( array(), $session['messages'] ?? null, 'messages start empty', $failures, $passes );
smoke_assert( true, array_key_exists( 'provider_response_id', $session ) && null === $session['provider_response_id'], 'provider_response_id present and null until set', $failures, $passes );

echo "\n[3] update_session persists messages + provider continuity:\n";
$ok = $store->update_session( $session_id, array( array( 'role' => 'user', 'content' => 'hi' ) ), array( 'k' => 'v2' ), 'anthropic', 'claude', 'resp_123' );
smoke_assert( true, $ok, 'update_session returns true', $failures, $passes );
$session = $store->get_session( $session_id );
smoke_assert( 1, count( $session['messages'] ), 'messages persisted', $failures, $passes );
smoke_assert( 'anthropic', $session['provider'], 'provider persisted', $failures, $passes );
smoke_assert( 'claude', $session['model'], 'model persisted', $failures, $passes );
smoke_assert( 'resp_123', $session['provider_response_id'], 'provider_response_id persisted', $failures, $passes );

echo "\n[4] update_title + delete:\n";
smoke_assert( true, $store->update_title( $session_id, 'My session' ), 'update_title returns true', $failures, $passes );
smoke_assert( 'My session', $store->get_session( $session_id )['title'], 'title persisted', $failures, $passes );
smoke_assert( true, $store->delete_session( $session_id ), 'delete_session returns true', $failures, $passes );
smoke_assert( null, $store->get_session( $session_id ), 'session gone after delete', $failures, $passes );
smoke_assert( true, $store->delete_session( $session_id ), 'delete is idempotent on missing session', $failures, $passes );

echo "\n[5] Principal (audience) owner is first-class, distinct from user owner:\n";
$aud_owner = array( 'type' => 'audience', 'key' => 'browser:one' );
$aud_id    = $store->create_session_for_owner( $workspace, $aud_owner, 'demo-agent', array(), 'chat' );
$aud       = $store->get_session( $aud_id );
smoke_assert( 'audience', $aud['owner_type'], 'audience owner_type recorded', $failures, $passes );
smoke_assert( 'browser:one', $aud['owner_key'], 'audience owner_key recorded', $failures, $passes );
smoke_assert( 0, $aud['user_id'], 'audience session has user_id 0', $failures, $passes );

echo "\n[6] list scopes by owner — user and audience do not bleed:\n";
$u1 = $store->create_session( $workspace, 7, 'demo-agent', array(), 'chat' );
$u2 = $store->create_session( $workspace, 7, 'demo-agent', array(), 'chat' );
$store->create_session( $workspace, 8, 'demo-agent', array(), 'chat' );
$user7 = $store->list_sessions( $workspace, 7, array() );
smoke_assert( 2, count( $user7 ), 'user 7 sees only its two sessions', $failures, $passes );
$aud_list = $store->list_sessions_for_owner( $workspace, $aud_owner, array() );
smoke_assert( 1, count( $aud_list ), 'audience owner sees only its one session', $failures, $passes );

echo "\n[7] recent pending session dedup respects owner + empty transcript:\n";
$pending = $store->get_recent_pending_session( $workspace, 7, 600, 'chat' );
smoke_assert( true, is_array( $pending ), 'an empty recent session is treated as pending', $failures, $passes );
$store->update_session( $user7[0]['session_id'], array( array( 'role' => 'user', 'content' => 'x' ) ) );
$store->update_session( $user7[1]['session_id'], array( array( 'role' => 'user', 'content' => 'y' ) ) );
$store->update_session( $u1, array( array( 'role' => 'user', 'content' => 'z' ) ) );
$store->update_session( $u2, array( array( 'role' => 'user', 'content' => 'w' ) ) );
$pending_after = $store->get_recent_pending_session( $workspace, 7, 600, 'chat' );
smoke_assert( null, $pending_after, 'no pending session once all are non-empty and unlocked', $failures, $passes );

echo "\n[8] Lock acquire / release / contention / CAS reclaim:\n";
$lock_session = $store->create_session( $workspace, 7, 'demo-agent', array(), 'chat' );
$token        = $store->acquire_session_lock( $lock_session, 300 );
smoke_assert( true, is_string( $token ) && '' !== $token, 'acquire returns a token on a free session', $failures, $passes );
smoke_assert( null, $store->acquire_session_lock( $lock_session, 300 ), 'second acquire loses while lock is active', $failures, $passes );
smoke_assert( false, $store->release_session_lock( $lock_session, 'wrong-token' ), 'release with wrong token fails', $failures, $passes );
smoke_assert( true, $store->release_session_lock( $lock_session, $token ), 'release with correct token succeeds', $failures, $passes );
$token2 = $store->acquire_session_lock( $lock_session, 300 );
smoke_assert( true, is_string( $token2 ) && '' !== $token2, 'reacquire after release succeeds', $failures, $passes );

// Force an expired lock and confirm CAS reclaim.
$post_id = null;
foreach ( $GLOBALS['__posts'] as $pid => $p ) {
	if ( (string) get_post_meta( $pid, '_agents_api_session_id', true ) === $lock_session ) {
		$post_id = $pid;
		break;
	}
}
update_post_meta( $post_id, '_agents_api_lock', wp_json_encode( array( 'token' => 'stale', 'expires' => time() - 10 ) ) );
$token3 = $store->acquire_session_lock( $lock_session, 300 );
smoke_assert( true, is_string( $token3 ) && '' !== $token3, 'expired lock is reclaimed via compare-and-swap', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " CPT conversation store assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} CPT conversation store assertions passed.\n";
