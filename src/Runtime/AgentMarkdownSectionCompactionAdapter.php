<?php
/**
 * Markdown section compaction adapter.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects markdown documents to ordered section compaction items and back.
 */
class AgentMarkdownSectionCompactionAdapter {

	public const ITEM_SCHEMA  = 'agents-api.compaction-item';
	public const ITEM_VERSION = 1;

	public const TYPE_PREAMBLE        = 'markdown_preamble';
	public const TYPE_SECTION         = 'markdown_section';
	public const TYPE_SECTION_SUMMARY = 'markdown_section_summary';
	public const TYPE_SECTION_POINTER = 'markdown_section_pointer';

	/**
	 * Parse markdown into ordered compaction items keyed by heading path.
	 *
	 * @param string $markdown Markdown document.
	 * @return array<int, array<string, mixed>> Ordered compaction items.
	 */
	public static function parse( string $markdown ): array {
		$items            = array();
		$lines            = self::split_lines( $markdown );
		$path_stack       = array();
		$heading_counters = array();
		$current          = self::preamble_item();

		foreach ( $lines as $line ) {
			$heading = self::parse_heading( $line );
			if ( null === $heading ) {
				$current['content'] .= $line;
				continue;
			}

			$items[] = self::finalize_item( $current, count( $items ) );

			$level            = $heading['level'];
			$path_stack_count = count( $path_stack );
			while ( $path_stack_count >= $level ) {
				array_pop( $path_stack );
				--$path_stack_count;
			}

			$path_stack[]                     = $heading['text'];
			$heading_path                     = $path_stack;
			$heading_key                      = self::heading_key( $heading_path );
			$heading_counters[ $heading_key ] = ( $heading_counters[ $heading_key ] ?? 0 ) + 1;

			if ( $heading_counters[ $heading_key ] > 1 ) {
				$heading_key .= '-' . $heading_counters[ $heading_key ];
			}

			$current = self::section_item( $heading, $heading_path, $heading_key );
		}

		$items[] = self::finalize_item( $current, count( $items ) );

		return $items;
	}

	/**
	 * Reconstruct markdown from retained, summary, or pointer items.
	 *
	 * @param array<int, array<string, mixed>> $items Ordered compaction items.
	 * @return string Markdown document.
	 */
	public static function reconstruct( array $items ): string {
		$markdown = '';

		foreach ( $items as $item ) {
			$type     = (string) ( $item['type'] ?? '' );
			$content  = (string) ( $item['content'] ?? '' );
			$metadata = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();

			if ( self::TYPE_PREAMBLE === $type ) {
				$markdown .= $content;
				continue;
			}

			if ( ! in_array( $type, array( self::TYPE_SECTION, self::TYPE_SECTION_SUMMARY, self::TYPE_SECTION_POINTER ), true ) ) {
				throw new \InvalidArgumentException( 'invalid_markdown_section_item: unsupported item type' );
			}

			$heading_line = (string) ( $metadata['heading_line'] ?? '' );
			if ( '' === $heading_line ) {
				throw new \InvalidArgumentException( 'invalid_markdown_section_item: section item missing heading line' );
			}

			$markdown .= $heading_line . $content;
		}

		return $markdown;
	}

	/**
	 * Build a summary item that keeps the source section heading intact.
	 *
	 * @param array<string, mixed> $section_item Source section item.
	 * @param string               $summary      Summary markdown.
	 * @return array<string, mixed>
	 */
	public static function summary_item( array $section_item, string $summary ): array {
		$item = self::replacement_item( $section_item, self::TYPE_SECTION_SUMMARY, $summary );

		$item['metadata']['source_item_type'] = $section_item['type'] ?? '';
		return $item;
	}

	/**
	 * Build a pointer item that keeps destinations product-owned and opaque.
	 *
	 * @param array<string, mixed> $section_item Source section item.
	 * @param string               $destination  Consumer-owned destination string.
	 * @return array<string, mixed>
	 */
	public static function pointer_item( array $section_item, string $destination ): array {
		$destination = trim( $destination );
		if ( '' === $destination ) {
			throw new \InvalidArgumentException( 'invalid_markdown_section_pointer: destination must be non-empty' );
		}

		$item = self::replacement_item( $section_item, self::TYPE_SECTION_POINTER, '[Archived section: ' . $destination . ']' . "\n" );

		$item['metadata']['pointer_destination'] = $destination;
		return $item;
	}

	/**
	 * Group items by the nearest heading at the requested boundary level.
	 *
	 * @param array<int, array<string, mixed>> $items Ordered compaction items.
	 * @param int                             $level Heading level to group by.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function group_by_heading_boundary( array $items, int $level = 1 ): array {
		$groups = array();
		$level  = max( 1, min( 6, $level ) );

		foreach ( $items as $item ) {
			$metadata = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();
			$path     = is_array( $metadata['heading_path'] ?? null ) ? array_values( $metadata['heading_path'] ) : array();
			$key      = empty( $path ) ? '__preamble' : self::heading_key( array_slice( $path, 0, $level ) );

			$groups[ $key ][] = $item;
		}

		return $groups;
	}

	/**
	 * Split markdown into lines while preserving newline characters.
	 *
	 * @param string $markdown Markdown document.
	 * @return array<int, string>
	 */
	private static function split_lines( string $markdown ): array {
		if ( '' === $markdown ) {
			return array();
		}

		$lines = preg_split( '/(?<=\n)|(?<=\r)(?!\n)/', $markdown );
		if ( false === $lines ) {
			return array( $markdown );
		}

		if ( array( '' ) === array_slice( $lines, -1 ) ) {
			array_pop( $lines );
		}

		return $lines;
	}

	/**
	 * Parse an ATX heading line.
	 *
	 * @param string $line Markdown line including optional newline.
	 * @return array{level: int, text: string, line: string}|null Parsed heading.
	 */
	private static function parse_heading( string $line ): ?array {
		$line_without_newline = preg_replace( '/\r\n|\n|\r$/', '', $line );
		if ( ! is_string( $line_without_newline ) ) {
			return null;
		}

		if ( ! preg_match( '/^(#{1,6})(?:[ \t]+(.*))?[ \t]*$/', $line_without_newline, $matches ) ) {
			return null;
		}

		$text = isset( $matches[2] ) ? (string) $matches[2] : '';
		$text = (string) preg_replace( '/[ \t]+#+[ \t]*$/', '', $text );
		$text = trim( $text );

		return array(
			'level' => strlen( $matches[1] ),
			'text'  => $text,
			'line'  => $line,
		);
	}

	/**
	 * Build the initial preamble item.
	 *
	 * @return array<string, mixed>
	 */
	private static function preamble_item(): array {
		return array(
			'schema'   => self::ITEM_SCHEMA,
			'version'  => self::ITEM_VERSION,
			'id'       => '__preamble',
			'type'     => self::TYPE_PREAMBLE,
			'content'  => '',
			'metadata' => array(
				'heading_path'         => array(),
				'heading_key'          => '__preamble',
				'heading_level'        => 0,
				'heading_text'         => '',
				'heading_line'         => '',
				'boundary_heading_key' => '__preamble',
			),
		);
	}

	/**
	 * Build a section item shell.
	 *
	 * @param array<string, mixed> $heading      Parsed heading.
	 * @param array<int, string>   $heading_path Heading path.
	 * @param string               $heading_key  Stable heading key.
	 * @return array<string, mixed>
	 */
	private static function section_item( array $heading, array $heading_path, string $heading_key ): array {
		return array(
			'schema'   => self::ITEM_SCHEMA,
			'version'  => self::ITEM_VERSION,
			'id'       => 'section:' . $heading_key,
			'type'     => self::TYPE_SECTION,
			'content'  => '',
			'metadata' => array(
				'heading_path'         => $heading_path,
				'heading_key'          => $heading_key,
				'heading_level'        => $heading['level'],
				'heading_text'         => $heading['text'],
				'heading_line'         => $heading['line'],
				'boundary_heading_key' => self::heading_key( array_slice( $heading_path, 0, 1 ) ),
			),
		);
	}

	/**
	 * Add ordering metadata.
	 *
	 * @param array<string, mixed> $item  Item.
	 * @param int                  $order Original order.
	 * @return array<string, mixed>
	 */
	private static function finalize_item( array $item, int $order ): array {
		$item['metadata']['order'] = $order;
		return $item;
	}

	/**
	 * Build a replacement item for summaries or pointers.
	 *
	 * @param array<string, mixed> $section_item Source section item.
	 * @param string               $type         Replacement type.
	 * @param string               $content      Replacement content.
	 * @return array<string, mixed>
	 */
	private static function replacement_item( array $section_item, string $type, string $content ): array {
		if ( self::TYPE_PREAMBLE === ( $section_item['type'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'invalid_markdown_section_item: preamble cannot be replaced as a section' );
		}

		$metadata = is_array( $section_item['metadata'] ?? null ) ? $section_item['metadata'] : array();
		if ( '' === (string) ( $metadata['heading_line'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'invalid_markdown_section_item: section item missing heading line' );
		}

		return array(
			'schema'   => self::ITEM_SCHEMA,
			'version'  => self::ITEM_VERSION,
			'id'       => (string) ( $section_item['id'] ?? 'section' ) . ':' . str_replace( 'markdown_section_', '', $type ),
			'type'     => $type,
			'content'  => $content,
			'metadata' => array_merge(
				$metadata,
				array(
					'source_item_id' => $section_item['id'] ?? '',
				)
			),
		);
	}

	/**
	 * Convert a heading path into a stable key.
	 *
	 * @param array<int, string> $heading_path Heading path.
	 * @return string
	 */
	private static function heading_key( array $heading_path ): string {
		$segments = array();
		foreach ( $heading_path as $segment ) {
			$segment    = strtolower( trim( $segment ) );
			$segment    = (string) preg_replace( '/[^a-z0-9]+/', '-', $segment );
			$segment    = trim( $segment, '-' );
			$segments[] = '' === $segment ? 'untitled' : $segment;
		}

		return implode( '/', $segments );
	}
}
