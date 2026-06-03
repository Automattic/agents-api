<?php
/**
 * Runtime agent bundle importer.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wp_agent_runtime_import_bundle',
	static function ( $result, array $spec, array $input = array(), int $index = 0 ) {
		if ( null !== $result ) {
			return $result;
		}

		$string_value = static function ( mixed ...$values ): string {
			foreach ( $values as $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return trim( (string) $value );
				}
			}

			return '';
		};

		$bundle = is_array( $spec['bundle'] ?? null ) ? $spec['bundle'] : array();
		$agent  = is_array( $bundle['agent'] ?? null ) ? $bundle['agent'] : array();
		if ( empty( $agent ) ) {
			return null;
		}

		$slug = sanitize_title( $string_value( $input['slug'] ?? null, $spec['slug'] ?? null, $agent['agent_slug'] ?? null, $bundle['bundle_slug'] ?? null ) );
		if ( '' === $slug ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_missing_agent_slug',
				'Runtime agent bundle imports require an agent slug.',
				array( 'index' => $index )
			);
		}

		$registry = WP_Agents_Registry::get_instance();
		if ( ! $registry instanceof WP_Agents_Registry ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_registry_unavailable',
				'The Agents API registry is unavailable for runtime bundle import.',
				array( 'index' => $index )
			);
		}

		$on_conflict = $string_value( $input['on_conflict'] ?? null, $spec['on_conflict'] ?? null, 'upgrade' );
		if ( ! in_array( $on_conflict, array( 'error', 'skip', 'upgrade' ), true ) ) {
			$on_conflict = 'upgrade';
		}

		if ( $registry->is_registered( $slug ) ) {
			if ( 'skip' === $on_conflict ) {
				return array(
					'success'    => true,
					'status'     => 'skipped',
					'agent_slug' => $slug,
				);
			}

			if ( 'error' === $on_conflict ) {
				return new WP_Error(
					'wp_agent_runtime_bundle_agent_exists',
					'Runtime agent bundle import would replace an existing agent.',
					array(
						'index'      => $index,
						'agent_slug' => $slug,
					)
				);
			}

			$registry->unregister( $slug );
		}

		$config = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
		$meta   = is_array( $agent['meta'] ?? null ) ? $agent['meta'] : array();
		if ( is_array( $config['datamachine_bundle'] ?? null ) ) {
			$meta = array_merge(
				array(
					'source_type'    => 'runtime-agent-bundle',
					'source_package' => $string_value( $config['datamachine_bundle']['bundle_slug'] ?? null, $bundle['bundle_slug'] ?? null ),
					'source_version' => $string_value( $config['datamachine_bundle']['bundle_version'] ?? null, $bundle['bundle_version'] ?? null ),
				),
				$meta
			);
		}

		$registered = $registry->register(
			$slug,
			array(
				'label'          => $string_value( $agent['agent_name'] ?? null, $agent['label'] ?? null, $slug ),
				'description'    => $string_value( $agent['description'] ?? null ),
				'default_config' => $config,
				'meta'           => $meta,
			)
		);

		if ( ! $registered instanceof WP_Agent ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_register_failed',
				'Runtime agent bundle import failed to register the agent.',
				array(
					'index'      => $index,
					'agent_slug' => $slug,
				)
			);
		}

		return array(
			'success'    => true,
			'status'     => 'registered',
			'agent_slug' => $slug,
		);
	},
	10,
	4
);
