<?php
/**
 * Immutable value object representing a parsed and validated workflow spec.
 *
 * The spec is the wire-level shape of a workflow:
 *
 *     [
 *       'id'       => 'my-plugin/triage-comments',
 *       'version'  => '1.0.0',
 *       'inputs'   => [ 'comment_id' => [ 'type' => 'integer', 'required' => true ] ],
 *       'steps'    => [
 *         [ 'id' => 'classify',     'type' => 'agent',   'agent' => 'ops-triage', ... ],
 *         [ 'id' => 'create-issue', 'type' => 'ability', 'ability' => 'linear/create-issue', ... ],
 *       ],
 *       'triggers' => [
 *         [ 'type' => 'on_demand' ],
 *         [ 'type' => 'wp_action', 'hook' => 'comment_post' ],
 *       ],
 *     ]
 *
 * Construct via {@see from_array()}, which validates and freezes the input.
 * Direct construction is allowed for callers that have already validated
 * the array elsewhere (e.g. the registry restoring a previously-validated
 * spec from its in-memory map).
 *
 * Specs are deliberately storage-agnostic — they have no opinion about
 * where they came from (PHP file, CPT, custom table, REST upload) or how
 * they're serialised. Storage adapters live in
 * {@see WP_Agent_Workflow_Store} implementations.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Spec {

	/**
	 * @since 0.103.0
	 *
	 * @param string $id        Stable, namespaced workflow identifier (`my-plugin/triage-comments`).
	 * @param string $version   Caller-defined version string (semver suggested but not enforced).
	 * @param array  $inputs    Map of `<input_name> => <schema>`. Each schema is a JSON-Schema-like
	 *                          fragment with `type`, optional `required`, optional `default`.
	 * @param array  $steps     Ordered list of step definitions. v0 supports `ability` and `agent`
	 *                          types; consumers may register additional types via a step handler map.
	 * @param array  $triggers  List of trigger definitions. v0 supports `on_demand`, `wp_action`, `cron`.
	 * @param array  $meta      Free-form metadata for the registering plugin. Opaque to the runner.
	 * @param array  $raw       The full raw array the spec was constructed from. Used for round-tripping
	 *                          a spec back to its caller-supplied shape.
	 */
	public function __construct(
		private string $id,
		private string $version,
		private array $inputs,
		private array $steps,
		private array $triggers,
		private array $meta,
		private array $raw
	) {}

	/**
	 * Build a Spec from a raw array, returning a `WP_Error` on validation
	 * failure. Use {@see WP_Agent_Workflow_Spec_Validator::validate()} when
	 * you need detailed structured errors instead of just the first one.
	 *
	 * @since 0.103.0
	 *
	 * @param array $raw Raw spec input.
	 * @return self|WP_Error
	 */
	public static function from_array( array $raw ) {
		$errors = WP_Agent_Workflow_Spec_Validator::validate( $raw );
		if ( ! empty( $errors ) ) {
			$first = $errors[0];
			return new WP_Error(
				'workflow_spec_invalid',
				$first['message'],
				array(
					'errors' => $errors,
					'path'   => $first['path'],
					'code'   => $first['code'],
				)
			);
		}

		return new self(
			(string) $raw['id'],
			(string) ( $raw['version'] ?? '0.0.0' ),
			(array) ( $raw['inputs'] ?? array() ),
			(array) ( $raw['steps'] ?? array() ),
			(array) ( $raw['triggers'] ?? array() ),
			(array) ( $raw['meta'] ?? array() ),
			$raw
		);
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_version(): string {
		return $this->version;
	}

	/**
	 * @return array<string,array> Input schemas keyed by input name.
	 */
	public function get_inputs(): array {
		return $this->inputs;
	}

	/**
	 * @return array<int,array> Step definitions in order.
	 */
	public function get_steps(): array {
		return $this->steps;
	}

	/**
	 * @return array<int,array> Trigger definitions.
	 */
	public function get_triggers(): array {
		return $this->triggers;
	}

	public function get_meta(): array {
		return $this->meta;
	}

	/**
	 * @return array The raw spec array as supplied to {@see from_array()}.
	 */
	public function to_array(): array {
		return $this->raw;
	}
}
