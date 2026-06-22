<?php
/**
 * Shared pure-PHP harness for Agents API module smokes.
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['__agents_api_smoke_actions'] = array();
$GLOBALS['__agents_api_smoke_wrong']   = array();
$GLOBALS['__agents_api_smoke_current'] = array();
$GLOBALS['__agents_api_smoke_done']    = array();
$GLOBALS['__agents_api_smoke_post_types'] = array();
$GLOBALS['__agents_api_smoke_taxonomies'] = array();
$GLOBALS['__agents_api_smoke_terms']      = array();
$GLOBALS['__agents_api_smoke_posts']      = array();
$GLOBALS['__agents_api_smoke_post_meta']  = array();

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		unset( $context, $domain );
		return $text;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $value ): string {
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		return trim( (string) $value, '-' );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $value ): string {
		return basename( $value );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $accepted_args );
		$GLOBALS['__agents_api_smoke_actions'][ $hook ][ $priority ][] = $callback;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['__agents_api_smoke_current'][] = $hook;
		$callbacks = $GLOBALS['__agents_api_smoke_actions'][ $hook ] ?? array();
		ksort( $callbacks );

		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				call_user_func_array( $callback, $args );
			}
		}

		array_pop( $GLOBALS['__agents_api_smoke_current'] );
		$GLOBALS['__agents_api_smoke_done'][ $hook ] = ( $GLOBALS['__agents_api_smoke_done'][ $hook ] ?? 0 ) + 1;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$GLOBALS['__agents_api_smoke_current'][] = $hook;
		$callbacks = $GLOBALS['__agents_api_smoke_actions'][ $hook ] ?? array();
		ksort( $callbacks );

		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
			}
		}

		array_pop( $GLOBALS['__agents_api_smoke_current'] );
		$GLOBALS['__agents_api_smoke_done'][ $hook ] = ( $GLOBALS['__agents_api_smoke_done'][ $hook ] ?? 0 ) + 1;

		return $value;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		return in_array( $hook, $GLOBALS['__agents_api_smoke_current'], true );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return (int) ( $GLOBALS['__agents_api_smoke_done'][ $hook ] ?? 0 );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, max( 1, $depth ) );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( string $function_name, string $message, string $version ): void {
		$GLOBALS['__agents_api_smoke_wrong'][] = array(
			'function' => $function_name,
			'message'  => $message,
			'version'  => $version,
		);
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( string $post_type ): bool {
		return isset( $GLOBALS['__agents_api_smoke_post_types'][ $post_type ] );
	}
}

if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( string $post_type, array $args = array() ) {
		$GLOBALS['__agents_api_smoke_post_types'][ $post_type ] = $args;
		return (object) array(
			'name' => $post_type,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ) {
		return $GLOBALS['__agents_api_smoke_posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		$meta = $GLOBALS['__agents_api_smoke_post_meta'][ $post_id ] ?? array();

		if ( '' === $key ) {
			return $meta;
		}

		$value = $meta[ $key ] ?? ( $single ? '' : array() );
		return $single && is_array( $value ) ? reset( $value ) : $value;
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( string $taxonomy ): bool {
		return isset( $GLOBALS['__agents_api_smoke_taxonomies'][ $taxonomy ] );
	}
}

if ( ! function_exists( 'register_taxonomy' ) ) {
	function register_taxonomy( string $taxonomy, $object_type, array $args = array() ) {
		$GLOBALS['__agents_api_smoke_taxonomies'][ $taxonomy ] = array(
			'object_type' => $object_type,
			'args'        => $args,
		);
		return (object) array(
			'name' => $taxonomy,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( int $post_id ) {
		unset( $post_id );
		return false;
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( int $post_id, string $taxonomy ) {
		return $GLOBALS['__agents_api_smoke_object_terms'][ $post_id ][ $taxonomy ] ?? array();
	}
}

if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( string $term, string $taxonomy ) {
		return $GLOBALS['__agents_api_smoke_terms'][ $taxonomy ][ $term ] ?? null;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( string $term, string $taxonomy, array $args = array() ) {
		$slug    = isset( $args['slug'] ) ? (string) $args['slug'] : sanitize_title( $term );
		$term_id = count( $GLOBALS['__agents_api_smoke_terms'][ $taxonomy ] ?? array() ) + 1;
		$created = array(
			'term_id' => $term_id,
			'slug'    => $slug,
			'name'    => $term,
		);
		$GLOBALS['__agents_api_smoke_terms'][ $taxonomy ][ $slug ] = $created;
		return $created;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( int $post_id, $terms, string $taxonomy ): void {
		$GLOBALS['__agents_api_smoke_object_terms'][ $post_id ][ $taxonomy ] = (array) $terms;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return class_exists( 'WP_Error' ) && $value instanceof WP_Error;
	}
}

function agents_api_smoke_assert_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo '  PASS ' . esc_html( $name ) . "\n";
		return;
	}

	$failures[] = $name;
	echo '  FAIL ' . esc_html( $name ) . "\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

function agents_api_smoke_require_module(): void {
	require_once __DIR__ . '/../agents-api.php';
}

function agents_api_smoke_finish( string $label, array $failures, int $passes ): void {
	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " {$label} assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} {$label} assertions passed.\n";
}
