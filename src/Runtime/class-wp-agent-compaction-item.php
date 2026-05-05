<?php
/**
 * Generic compaction item normalization contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes ordered compaction inputs into a generic item shape.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Compaction_Item {

	public const SCHEMA  = 'agents-api.compaction-item';
	public const VERSION = 1;

	/**
	 * Normalize a raw item to the canonical compaction item shape.
	 *
	 * @param array    $item  Raw compaction item.
	 * @param int|null $index Optional item position used for generated IDs.
	 * @return array<string, mixed> Normalized compaction item.
	 * @throws \InvalidArgumentException When the item is invalid.
	 */
	public static function normalize( array $item, ?int $index = null ): array {
		$type     = self::normalize_string( $item['type'] ?? null, 'type' );
		$content  = self::normalize_content( $item );
		$metadata = self::normalize_metadata( $item['metadata'] ?? array() );
		$group    = self::normalize_optional_string( $item['group'] ?? null, 'group' );
		$boundary = self::normalize_boundary( $item['boundary'] ?? null );

		$normalized = array(
			'schema'   => self::SCHEMA,
			'version'  => self::VERSION,
			'id'       => self::normalize_id( $item['id'] ?? null, $type, $content, $metadata, $group, $boundary, $index ),
			'type'     => $type,
			'content'  => $content,
			'metadata' => $metadata,
		);

		if ( null !== $group ) {
			$normalized['group'] = $group;
		}

		if ( null !== $boundary ) {
			$normalized['boundary'] = $boundary;
		}

		if ( false === self::json_encode( $normalized ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_compaction_item: item must be JSON serializable' );
		}

		return $normalized;
	}

	/**
	 * Normalize an ordered list of compaction items.
	 *
	 * @param array $items Raw compaction items.
	 * @return array<int, array<string, mixed>>
	 * @throws \InvalidArgumentException When an item is invalid.
	 */
	public static function normalize_many( array $items ): array {
		$normalized = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				throw new \InvalidArgumentException( 'invalid_ai_compaction_item: item must be an array' );
			}

			$normalized[] = self::normalize( $item, is_int( $index ) ? $index : count( $normalized ) );
		}

		return $normalized;
	}

	/**
	 * Project a message envelope into the generic compaction item contract.
	 *
	 * @param array    $message Message envelope or legacy message.
	 * @param int|null $index   Optional item position used for generated IDs.
	 * @return array<string, mixed> Normalized compaction item.
	 */
	public static function from_message( array $message, ?int $index = null ): array {
		$envelope            = WP_Agent_Message::normalize( $message );
		$metadata            = $envelope['metadata'];
		$metadata['message'] = array(
			'role'    => $envelope['role'],
			'payload' => $envelope['payload'],
		);

		$item = array(
			'type'     => 'message:' . $envelope['type'],
			'content'  => $envelope['content'],
			'metadata' => $metadata,
		);

		if ( isset( $envelope['id'] ) ) {
			$item['id'] = $envelope['id'];
		}

		return self::normalize( $item, $index );
	}

	/**
	 * Project message envelopes into ordered compaction items.
	 *
	 * @param array $messages Message envelopes or legacy messages.
	 * @return array<int, array<string, mixed>>
	 */
	public static function from_messages( array $messages ): array {
		$items = array();
		foreach ( $messages as $index => $message ) {
			if ( ! is_array( $message ) ) {
				throw new \InvalidArgumentException( 'invalid_ai_message_envelope: message must be an array' );
			}

			$items[] = self::from_message( $message, is_int( $index ) ? $index : count( $items ) );
		}

		return $items;
	}

	/**
	 * Normalize a required string field.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return string
	 */
	private static function normalize_string( $value, string $field ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_compaction_item: ' . $field . ' must be a non-empty string' );
		}

		return trim( $value );
	}

	/**
	 * Normalize an optional string field.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return string|null
	 */
	private static function normalize_optional_string( $value, string $field ): ?string {
		if ( null === $value ) {
			return null;
		}

		return self::normalize_string( $value, $field );
	}

	/**
	 * Normalize item content.
	 *
	 * @param array $item Raw item.
	 * @return string|array
	 */
	private static function normalize_content( array $item ) {
		if ( ! array_key_exists( 'content', $item ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_compaction_item: content is required' );
		}

		$content = $item['content'];
		if ( ! is_string( $content ) && ! is_array( $content ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_compaction_item: content must be a string or array' );
		}

		return $content;
	}

	/**
	 * Normalize metadata.
	 *
	 * @param mixed $metadata Raw metadata.
	 * @return array<string, mixed>
	 */
	private static function normalize_metadata( $metadata ): array {
		if ( ! is_array( $metadata ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_compaction_item: metadata must be an array' );
		}

		return $metadata;
	}

	/**
	 * Normalize optional grouping or boundary hints.
	 *
	 * @param mixed $boundary Raw boundary hints.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_boundary( $boundary ): ?array {
		if ( null === $boundary ) {
			return null;
		}

		if ( ! is_array( $boundary ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_compaction_item: boundary must be an array' );
		}

		return $boundary;
	}

	/**
	 * Normalize or generate a stable item ID.
	 *
	 * @param mixed       $id       Raw ID.
	 * @param string      $type     Item type.
	 * @param string|array $content Item content.
	 * @param array       $metadata Item metadata.
	 * @param string|null $group    Item group.
	 * @param array|null  $boundary Boundary hints.
	 * @param int|null    $index    Item position.
	 * @return string
	 */
	private static function normalize_id( $id, string $type, $content, array $metadata, ?string $group, ?array $boundary, ?int $index ): string {
		if ( null !== $id ) {
			return self::normalize_string( $id, 'id' );
		}

		$source = array(
			'index'    => $index,
			'type'     => $type,
			'content'  => $content,
			'metadata' => $metadata,
			'group'    => $group,
			'boundary' => $boundary,
		);

		return 'item-' . substr( hash( 'sha256', (string) self::json_encode( self::sort_recursive( $source ) ) ), 0, 16 );
	}

	/**
	 * Sort associative array keys recursively for deterministic ID hashes.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $nested_value ) {
			$value[ $key ] = self::sort_recursive( $nested_value );
		}

		return $value;
	}

	/**
	 * Encode data for serializability checks with a pure-PHP fallback for smokes.
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false Encoded JSON or false on failure.
	 */
	private static function json_encode( $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
		return json_encode( $data );
	}
}
