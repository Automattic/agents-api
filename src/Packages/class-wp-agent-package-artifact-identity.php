<?php
/**
 * WP_Agent_Package_Artifact_Identity normalizer.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Artifact_Identity' ) ) {
	/**
	 * Shared normalizer for package artifact identifiers and source paths.
	 *
	 * Every place that derives a package-local key or path routes through here so
	 * the traversal/absolute-path rules stay uniform across the update planner,
	 * installed-artifact snapshot, artifact declaration, and adoption orchestrator.
	 */
	final class WP_Agent_Package_Artifact_Identity {

		/**
		 * Normalizes a package-local artifact identifier.
		 *
		 * Rejects empty, absolute (leading slash), and parent-directory traversal
		 * segments. Backslashes are normalized to forward slashes so Windows-style
		 * separators cannot smuggle a traversal past the segment check.
		 *
		 * @param mixed $value Raw artifact identifier.
		 * @return string Package-local identifier.
		 * @throws InvalidArgumentException When the identifier is empty, absolute, or traverses.
		 */
		public static function normalize_id( mixed $value ): string {
			$id = self::normalize_separators( $value );
			if ( '' === $id || str_starts_with( $id, '/' ) || self::has_traversal_segment( $id ) ) {
				throw new InvalidArgumentException( 'Agent package artifact identifier must be a non-empty package-local path without parent directory traversal.' );
			}

			return $id;
		}

		/**
		 * Normalizes a package-relative source path.
		 *
		 * An empty source is allowed (the artifact declares no payload location).
		 * A non-empty source must be relative (no leading slash, no drive letter)
		 * and must not contain a parent-directory segment. Empty segments are
		 * collapsed so `a//b` normalizes to `a/b`.
		 *
		 * @param mixed $value Raw source path.
		 * @return string Package-relative source path, or an empty string.
		 * @throws InvalidArgumentException When the source is absolute, drive-anchored, or traverses.
		 */
		public static function normalize_source( mixed $value ): string {
			$source = self::normalize_separators( $value );
			if ( '' === $source ) {
				return '';
			}

			if ( str_starts_with( $source, '/' ) || preg_match( '/^[A-Za-z]:\//', $source ) ) {
				throw new InvalidArgumentException( 'Agent package artifact source must be relative to the package.' );
			}

			$parts = array_values(
				array_filter(
					explode( '/', $source ),
					static function ( string $part ): bool {
						return '' !== $part;
					}
				)
			);
			if ( in_array( '..', $parts, true ) ) {
				throw new InvalidArgumentException( 'Agent package artifact source cannot contain parent directory segments.' );
			}

			return implode( '/', $parts );
		}

		/**
		 * Determines whether any path segment is a parent-directory traversal.
		 *
		 * `..` is only dangerous as a whole segment; `a..b` is a legitimate name.
		 *
		 * @param string $path Slash-separated path.
		 * @return bool
		 */
		private static function has_traversal_segment( string $path ): bool {
			return in_array( '..', explode( '/', $path ), true );
		}

		/**
		 * Trims and converts backslashes to forward slashes.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		private static function normalize_separators( mixed $value ): string {
			return trim( str_replace( '\\', '/', self::string_value( $value ) ) );
		}

		/**
		 * Convert scalar/Stringable input to a string.
		 *
		 * @param mixed $value Raw value.
		 * @return string String value, or empty string for non-stringable input.
		 */
		private static function string_value( mixed $value ): string {
			if ( null === $value ) {
				return '';
			}

			return is_scalar( $value ) || $value instanceof Stringable ? (string) $value : '';
		}

		/**
		 * Prevents construction.
		 */
		private function __construct() {}
	}
}
