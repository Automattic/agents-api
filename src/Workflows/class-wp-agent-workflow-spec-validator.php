<?php
/**
 * Structural validator for workflow specs.
 *
 * Walks a raw spec array and returns a list of structured errors:
 *
 *     [
 *       [
 *         'path'    => 'steps.1.ability',
 *         'code'    => 'missing_required',
 *         'message' => 'ability step is missing required `ability` field',
 *       ],
 *       ...
 *     ]
 *
 * An empty list means the spec is well-formed enough to construct a
 * {@see WP_Agent_Workflow_Spec}. Validators are deliberately separated
 * from the value object so consumers can use them on partial specs in
 * editor surfaces (linting in-progress JSON, REST validate endpoints,
 * `agents/validate-workflow` ability) without having to construct or
 * discard a Spec each call.
 *
 * The validator does NOT verify that referenced abilities or agents
 * actually exist — that's a runtime concern handled by the runner.
 * This pass is structural only.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Spec_Validator {

	/** @since 0.103.0 */
	public const KNOWN_STEP_TYPES = array( 'ability', 'agent' );

	/** @since 0.103.0 */
	public const KNOWN_TRIGGER_TYPES = array( 'on_demand', 'wp_action', 'cron' );

	/**
	 * Validate a raw workflow spec.
	 *
	 * @since 0.103.0
	 *
	 * @param array $spec Raw spec.
	 * @return array<int,array{path:string,code:string,message:string}> Empty when valid.
	 */
	public static function validate( array $spec ): array {
		$errors = array();

		// id
		if ( empty( $spec['id'] ) || ! is_string( $spec['id'] ) ) {
			$errors[] = array(
				'path'    => 'id',
				'code'    => 'missing_required',
				'message' => 'workflow spec is missing a non-empty `id`',
			);
		}

		// inputs (optional but if present must be a map)
		if ( isset( $spec['inputs'] ) && ! is_array( $spec['inputs'] ) ) {
			$errors[] = array(
				'path'    => 'inputs',
				'code'    => 'invalid_type',
				'message' => '`inputs` must be a map of input_name => schema',
			);
		}

		// steps
		if ( ! isset( $spec['steps'] ) || ! is_array( $spec['steps'] ) || empty( $spec['steps'] ) ) {
			$errors[] = array(
				'path'    => 'steps',
				'code'    => 'missing_required',
				'message' => 'workflow spec must declare at least one step',
			);
		} else {
			$step_errors = self::validate_steps( $spec['steps'] );
			$errors      = array_merge( $errors, $step_errors );
		}

		// triggers (optional but if present must be a list)
		if ( isset( $spec['triggers'] ) ) {
			if ( ! is_array( $spec['triggers'] ) || array_values( $spec['triggers'] ) !== $spec['triggers'] ) {
				$errors[] = array(
					'path'    => 'triggers',
					'code'    => 'invalid_type',
					'message' => '`triggers` must be a list of trigger definitions',
				);
			} else {
				$trigger_errors = self::validate_triggers( $spec['triggers'] );
				$errors         = array_merge( $errors, $trigger_errors );
			}
		}

		return $errors;
	}

	/**
	 * @param array<int,mixed> $steps
	 * @return array<int,array>
	 */
	private static function validate_steps( array $steps ): array {
		$errors = array();
		$seen   = array();

		foreach ( $steps as $idx => $step ) {
			$path = "steps.{$idx}";

			if ( ! is_array( $step ) ) {
				$errors[] = array(
					'path'    => $path,
					'code'    => 'invalid_type',
					'message' => 'step entry must be an array',
				);
				continue;
			}

			if ( empty( $step['id'] ) || ! is_string( $step['id'] ) ) {
				$errors[] = array(
					'path'    => "{$path}.id",
					'code'    => 'missing_required',
					'message' => 'step is missing a non-empty `id`',
				);
			} elseif ( isset( $seen[ $step['id'] ] ) ) {
				$errors[] = array(
					'path'    => "{$path}.id",
					'code'    => 'duplicate_id',
					'message' => sprintf( 'step id `%s` is reused at index %d (first seen at index %d)', $step['id'], $idx, $seen[ $step['id'] ] ),
				);
			} else {
				$seen[ $step['id'] ] = $idx;
			}

			if ( empty( $step['type'] ) || ! is_string( $step['type'] ) ) {
				$errors[] = array(
					'path'    => "{$path}.type",
					'code'    => 'missing_required',
					'message' => 'step is missing a non-empty `type`',
				);
				continue; // type is needed for the per-type checks below
			}

			if ( ! in_array( $step['type'], self::KNOWN_STEP_TYPES, true ) ) {
				/**
				 * Allow consumer-extended step types. Agents-api ships only
				 * `ability` and `agent`; extra types (`branch`, `parallel`,
				 * `workflow`) get registered by consumers via a filter on
				 * `wp_agent_workflow_known_step_types`. Filtered list wins.
				 *
				 * @since 0.103.0
				 *
				 * @param array<string> $known_types Default v0 set.
				 */
				$known = (array) apply_filters( 'wp_agent_workflow_known_step_types', self::KNOWN_STEP_TYPES );
				if ( ! in_array( $step['type'], $known, true ) ) {
					$errors[] = array(
						'path'    => "{$path}.type",
						'code'    => 'unknown_step_type',
						'message' => sprintf(
							'unknown step type `%s` (known: %s)',
							$step['type'],
							implode( ', ', $known )
						),
					);
					continue;
				}
			}

			if ( 'ability' === $step['type'] ) {
				if ( empty( $step['ability'] ) || ! is_string( $step['ability'] ) ) {
					$errors[] = array(
						'path'    => "{$path}.ability",
						'code'    => 'missing_required',
						'message' => 'ability step is missing a non-empty `ability`',
					);
				}
			}

			if ( 'agent' === $step['type'] ) {
				if ( empty( $step['agent'] ) || ! is_string( $step['agent'] ) ) {
					$errors[] = array(
						'path'    => "{$path}.agent",
						'code'    => 'missing_required',
						'message' => 'agent step is missing a non-empty `agent`',
					);
				}
				if ( empty( $step['message'] ) || ! is_string( $step['message'] ) ) {
					$errors[] = array(
						'path'    => "{$path}.message",
						'code'    => 'missing_required',
						'message' => 'agent step is missing a non-empty `message`',
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * @param array<int,mixed> $triggers
	 * @return array<int,array>
	 */
	private static function validate_triggers( array $triggers ): array {
		$errors = array();

		foreach ( $triggers as $idx => $trigger ) {
			$path = "triggers.{$idx}";

			if ( ! is_array( $trigger ) ) {
				$errors[] = array(
					'path'    => $path,
					'code'    => 'invalid_type',
					'message' => 'trigger entry must be an array',
				);
				continue;
			}

			if ( empty( $trigger['type'] ) || ! is_string( $trigger['type'] ) ) {
				$errors[] = array(
					'path'    => "{$path}.type",
					'code'    => 'missing_required',
					'message' => 'trigger is missing a non-empty `type`',
				);
				continue;
			}

			$known = (array) apply_filters( 'wp_agent_workflow_known_trigger_types', self::KNOWN_TRIGGER_TYPES );
			if ( ! in_array( $trigger['type'], $known, true ) ) {
				$errors[] = array(
					'path'    => "{$path}.type",
					'code'    => 'unknown_trigger_type',
					'message' => sprintf(
						'unknown trigger type `%s` (known: %s)',
						$trigger['type'],
						implode( ', ', $known )
					),
				);
				continue;
			}

			if ( 'wp_action' === $trigger['type'] && empty( $trigger['hook'] ) ) {
				$errors[] = array(
					'path'    => "{$path}.hook",
					'code'    => 'missing_required',
					'message' => 'wp_action trigger is missing a non-empty `hook`',
				);
			}

			if ( 'cron' === $trigger['type'] && empty( $trigger['expression'] ) && empty( $trigger['interval'] ) ) {
				$errors[] = array(
					'path'    => $path,
					'code'    => 'missing_required',
					'message' => 'cron trigger needs either `expression` (cron string) or `interval` (seconds)',
				);
			}
		}

		return $errors;
	}
}
