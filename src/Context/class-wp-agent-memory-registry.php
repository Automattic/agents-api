<?php
/**
 * Generic agent memory/context source registry.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Memory_Registry' ) ) {
	/**
	 * Registers memory and context sources without prescribing storage shape.
	 */
	final class WP_Agent_Memory_Registry {

		public const MODE_ALL = 'all';

		/**
		 * Registered sources keyed by source ID.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private static array $sources = array();

		/**
		 * Whether extension hooks have been fired.
		 *
		 * @var bool
		 */
		private static bool $hooks_fired = false;

		/**
		 * Register a memory or context source.
		 *
		 * @param string $source_id Source identifier, e.g. `workspace/instructions`.
		 * @param array  $args      Registration metadata.
		 * @return array<string, mixed>|null Normalized source metadata, or null on invalid ID.
		 */
		public static function register( string $source_id, array $args = array() ): ?array {
			$source_id = self::sanitize_source_id( $source_id );
			if ( '' === $source_id ) {
				return null;
			}

			$composable = (bool) ( $args['composable'] ?? false );
			$editable   = $composable ? false : ( $args['editable'] ?? true );
			if ( ! is_bool( $editable ) && ! is_string( $editable ) ) {
				$editable = true;
			}

			$metadata = array(
				'id'                         => $source_id,
				'layer'                      => WP_Agent_Memory_Layer::normalize( $args['layer'] ?? null ),
				'priority'                   => (int) ( $args['priority'] ?? 50 ),
				'protected'                  => (bool) ( $args['protected'] ?? false ),
				'editable'                   => $editable,
				'capability'                 => isset( $args['capability'] ) && is_string( $args['capability'] ) ? $args['capability'] : '',
				'modes'                      => self::normalize_modes( $args['modes'] ?? array( self::MODE_ALL ) ),
				'retrieval_policy'           => WP_Agent_Context_Injection_Policy::normalize( $args['retrieval_policy'] ?? null ),
				'composable'                 => $composable,
				'context_slug'               => self::sanitize_source_id( (string) ( $args['context_slug'] ?? $source_id ) ),
				'convention_path'            => self::normalize_relative_path( $args['convention_path'] ?? '' ),
				'external_projection_target' => is_string( $args['external_projection_target'] ?? null ) ? $args['external_projection_target'] : '',
				'label'                      => is_string( $args['label'] ?? null ) ? $args['label'] : self::id_to_label( $source_id ),
				'description'                => is_string( $args['description'] ?? null ) ? $args['description'] : '',
				'meta'                       => is_array( $args['meta'] ?? null ) ? $args['meta'] : array(),
			);

			self::$sources[ $source_id ] = $metadata;

			return $metadata;
		}

		/**
		 * Remove a registered source.
		 *
		 * @param string $source_id Source identifier.
		 * @return void
		 */
		public static function unregister( string $source_id ): void {
			unset( self::$sources[ self::sanitize_source_id( $source_id ) ] );
		}

		/**
		 * Return a single registered source.
		 *
		 * @param string $source_id Source identifier.
		 * @return array<string, mixed>|null
		 */
		public static function get( string $source_id ): ?array {
			$sources = self::get_all();
			return $sources[ self::sanitize_source_id( $source_id ) ] ?? null;
		}

		/**
		 * Return all registered sources sorted by priority.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public static function get_all(): array {
			return self::get_resolved();
		}

		/**
		 * Return sources for a layer.
		 *
		 * @param string $layer Layer value.
		 * @return array<string, array<string, mixed>>
		 */
		public static function get_by_layer( string $layer ): array {
			$layer = WP_Agent_Memory_Layer::normalize( $layer );

			return array_filter(
				self::get_resolved(),
				static function ( array $source ) use ( $layer ): bool {
					return $layer === $source['layer'];
				}
			);
		}

		/**
		 * Return sources applicable to a runtime mode and retrieval policy.
		 *
		 * @param string      $mode             Runtime mode slug.
		 * @param string|null $retrieval_policy Optional policy filter.
		 * @return array<string, array<string, mixed>>
		 */
		public static function get_for_mode( string $mode, ?string $retrieval_policy = null ): array {
			$mode             = self::sanitize_slug( $mode );
			$retrieval_policy = null === $retrieval_policy ? null : WP_Agent_Context_Injection_Policy::normalize( $retrieval_policy );

			return array_filter(
				self::get_resolved(),
				static function ( array $source ) use ( $mode, $retrieval_policy ): bool {
					$modes = $source['modes'] ?? array( self::MODE_ALL );
					if ( '' !== $mode && ! in_array( self::MODE_ALL, $modes, true ) && ! in_array( $mode, $modes, true ) ) {
						return false;
					}

					return null === $retrieval_policy || $retrieval_policy === $source['retrieval_policy'];
				}
			);
		}

		/**
		 * Return sources that are injected without dynamic retrieval.
		 *
		 * @param string $mode Runtime mode slug.
		 * @return array<string, array<string, mixed>>
		 */
		public static function get_always_injected( string $mode = '' ): array {
			return self::get_for_mode( $mode, WP_Agent_Context_Injection_Policy::ALWAYS );
		}

		/**
		 * Return composable sources.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public static function get_composable(): array {
			return array_filter(
				self::get_resolved(),
				static function ( array $source ): bool {
					return ! empty( $source['composable'] );
				}
			);
		}

		/**
		 * Reset registry state. Intended for tests.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$sources     = array();
			self::$hooks_fired = false;
		}

		/**
		 * Resolve hooks and return sorted sources.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private static function get_resolved(): array {
			if ( ! self::$hooks_fired ) {
				if ( function_exists( 'do_action' ) ) {
					do_action( 'agents_api_memory_sources', self::$sources );
				}
				self::$hooks_fired = true;
			}

			$sources = self::$sources;
			uasort(
				$sources,
				static function ( array $a, array $b ): int {
					$priority_order = $a['priority'] <=> $b['priority'];
					return 0 !== $priority_order ? $priority_order : strcmp( $a['id'], $b['id'] );
				}
			);

			return $sources;
		}

		/**
		 * @param mixed $modes Raw modes.
		 * @return string[]
		 */
		private static function normalize_modes( $modes ): array {
			if ( ! is_array( $modes ) || empty( $modes ) ) {
				return array( self::MODE_ALL );
			}

			$normalized = array_values( array_unique( array_filter( array_map( array( self::class, 'sanitize_slug' ), $modes ) ) ) );
			return empty( $normalized ) ? array( self::MODE_ALL ) : $normalized;
		}

		/**
		 * @param mixed $path Raw relative path.
		 * @return string
		 */
		private static function normalize_relative_path( $path ): string {
			return is_string( $path ) ? ltrim( $path, '/' ) : '';
		}

		/**
		 * @param string $source_id Source identifier.
		 * @return string
		 */
		private static function sanitize_source_id( string $source_id ): string {
			$source_id = strtolower( $source_id );
			$source_id = preg_replace( '/[^a-z0-9_\.\-\/]+/', '-', $source_id );

			return trim( is_string( $source_id ) ? $source_id : '', '-/' );
		}

		/**
		 * @param string $slug Raw slug.
		 * @return string
		 */
		private static function sanitize_slug( string $slug ): string {
			$slug = strtolower( $slug );
			$slug = preg_replace( '/[^a-z0-9_\-]+/', '-', $slug );

			return trim( is_string( $slug ) ? $slug : '', '-' );
		}

		/**
		 * @param string $source_id Source identifier.
		 * @return string
		 */
		private static function id_to_label( string $source_id ): string {
			return ucwords( str_replace( array( '-', '_', '/', '.' ), ' ', $source_id ) );
		}
	}
}
