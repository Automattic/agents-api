<?php
/**
 * Pure-PHP smoke test for the runtime package execution contract.
 *
 * Run with: php tests/runtime-package-run-contract-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "runtime-package-run-contract-smoke\n";

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return ! empty( $GLOBALS['__agents_api_smoke_caps'][ $capability ] );
	}
}

$GLOBALS['__agents_api_smoke_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return $GLOBALS['__agents_api_smoke_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['__agents_api_smoke_options'][ $option ] = $value;
		return true;
	}
}

require_once __DIR__ . '/agents-api-smoke-helpers.php';

if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
		/** @param array<string,mixed> $args Ability registration arguments. */
		public function __construct( private string $name, private array $args ) {}

		/** @param array<mixed> $input Ability input. */
		public function execute( array $input ) {
			$callback = $this->args['execute_callback'] ?? null;
			return is_callable( $callback ) ? call_user_func( $callback, $input ) : null;
		}

		public function get_name(): string {
			return $this->name;
		}
	}
}

if ( ! class_exists( 'WP_Ability_Category' ) ) {
	class WP_Ability_Category {
		/** @param array<string,mixed> $args Category registration arguments. */
		public function __construct( private string $slug, private array $args ) {}

		public function get_slug(): string {
			return $this->slug;
		}
	}
}

if ( ! class_exists( 'WP_Ability_Categories_Registry' ) ) {
	class WP_Ability_Categories_Registry {
		private static ?self $instance = null;

		/** @var array<string,WP_Ability_Category> */
		private array $categories = array();

		public static function get_instance(): ?self {
			if ( ! did_action( 'init' ) ) {
				_doing_it_wrong( __METHOD__, 'Ability API should not be initialized before init.', '6.9.0' );
				return null;
			}

			if ( null === self::$instance ) {
				self::$instance = new self();
				do_action( 'wp_abilities_api_categories_init', self::$instance );
			}

			return self::$instance;
		}

		/** @param array<string,mixed> $args Category registration arguments. */
		public function register( string $category, array $args ): ?WP_Ability_Category {
			if ( $this->is_registered( $category ) ) {
				return null;
			}

			$this->categories[ $category ] = new WP_Ability_Category( $category, $args );
			return $this->categories[ $category ];
		}

		public function is_registered( string $category ): bool {
			return isset( $this->categories[ $category ] );
		}

		public static function reset_for_smoke(): void {
			self::$instance = null;
		}
	}
}

if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
	class WP_Abilities_Registry {
		private static ?self $instance = null;

		/** @var array<string,WP_Ability> */
		private array $abilities = array();

		public static function get_instance(): ?self {
			if ( ! did_action( 'init' ) ) {
				_doing_it_wrong( __METHOD__, 'Ability API should not be initialized before init.', '6.9.0' );
				return null;
			}

			if ( null === self::$instance ) {
				self::$instance = new self();
				WP_Ability_Categories_Registry::get_instance();
				do_action( 'wp_abilities_api_init', self::$instance );
			}

			return self::$instance;
		}

		/** @param array<string,mixed> $args Ability registration arguments. */
		public function register( string $ability, array $args ): ?WP_Ability {
			if ( $this->is_registered( $ability ) || ! wp_has_ability_category( (string) ( $args['category'] ?? '' ) ) ) {
				return null;
			}

			$this->abilities[ $ability ] = new WP_Ability( $ability, $args );
			return $this->abilities[ $ability ];
		}

		public function is_registered( string $ability ): bool {
			return isset( $this->abilities[ $ability ] );
		}

		public function get_registered( string $ability ): ?WP_Ability {
			return $this->abilities[ $ability ] ?? null;
		}

		public function reset_registered_for_smoke(): void {
			$this->abilities = array();
		}
	}
}

if ( ! function_exists( 'wp_has_ability_category' ) ) {
	function wp_has_ability_category( string $category ): bool {
		$registry = WP_Ability_Categories_Registry::get_instance();
		return null !== $registry && $registry->is_registered( $category );
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $category, array $args ): ?WP_Ability_Category {
		if ( ! doing_action( 'wp_abilities_api_categories_init' ) ) {
			_doing_it_wrong( __FUNCTION__, 'Ability categories must be registered on wp_abilities_api_categories_init.', '6.9.0' );
			return null;
		}

		$registry = WP_Ability_Categories_Registry::get_instance();
		return null === $registry ? null : $registry->register( $category, $args );
	}
}

if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( string $ability ): bool {
		$registry = WP_Abilities_Registry::get_instance();
		return null !== $registry && $registry->is_registered( $ability );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $ability, array $args ): ?WP_Ability {
		if ( ! doing_action( 'wp_abilities_api_init' ) ) {
			_doing_it_wrong( __FUNCTION__, 'Abilities must be registered on wp_abilities_api_init.', '6.9.0' );
			return null;
		}

		$registry = WP_Abilities_Registry::get_instance();
		return null === $registry ? null : $registry->register( $ability, $args );
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $ability ): ?WP_Ability {
		$registry = WP_Abilities_Registry::get_instance();
		return null === $registry ? null : $registry->get_registered( $ability );
	}
}

require_once __DIR__ . '/../src/Runtime/class-wp-agent-runtime-package-run-request.php';
require_once __DIR__ . '/../src/Runtime/class-wp-agent-runtime-package-run-result.php';
require_once __DIR__ . '/../src/Runtime/register-runtime-package-run-ability.php';
require_once __DIR__ . '/../src/Abilities/functions-ability-dispatch.php';

use AgentsAPI\AI\WP_Agent_Runtime_Package_Run_Request;
use AgentsAPI\AI\WP_Agent_Runtime_Package_Run_Result;

echo "\n[0] Runtime package ability resolves in normal and late Abilities API lifecycles:\n";
do_action( 'init' );
$registry = WP_Abilities_Registry::get_instance();
agents_api_smoke_assert_equals( true, wp_get_ability( AgentsAPI\AI\AGENTS_RUN_RUNTIME_PACKAGE_ABILITY ) instanceof WP_Ability, 'runtime package ability registers through wp_abilities_api_init', $failures, $passes );
agents_api_smoke_assert_equals( 0, count( $GLOBALS['__agents_api_smoke_wrong'] ), 'normal registration path does not call public helpers outside their actions', $failures, $passes );

if ( $registry instanceof WP_Abilities_Registry ) {
	$registry->reset_registered_for_smoke();
}

AgentsAPI\AI\agents_register_runtime_package_run_abilities();
$late_ability = wp_get_ability( AgentsAPI\AI\AGENTS_RUN_RUNTIME_PACKAGE_ABILITY );
agents_api_smoke_assert_equals( true, $late_ability instanceof WP_Ability, 'runtime package ability resolves through wp_get_ability after abilities init already fired', $failures, $passes );

add_filter(
	'wp_agent_runtime_package_run_handler',
	static function ( $handler, WP_Agent_Runtime_Package_Run_Request $handler_request ) {
		unset( $handler );
		return static function () use ( $handler_request ): array {
			return array(
				'status' => 'succeeded',
				'result' => array( 'workflow_id' => $handler_request->get_workflow()['id'] ?? '' ),
			);
		};
	},
	10,
	2
);

$late_dispatch = $late_ability instanceof WP_Ability ? $late_ability->execute(
	array(
		'package'  => array( 'slug' => 'site-builder' ),
		'workflow' => array( 'id' => 'late-load' ),
	)
) : null;
agents_api_smoke_assert_equals( false, is_wp_error( $late_dispatch ), 'late-resolved ability executes through the canonical ability object', $failures, $passes );
agents_api_smoke_assert_equals( 'late-load', is_array( $late_dispatch ) ? $late_dispatch['result']['workflow_id'] ?? '' : '', 'late-resolved ability uses the runtime package handler filter', $failures, $passes );

if ( $registry instanceof WP_Abilities_Registry ) {
	$registry->reset_registered_for_smoke();
}

$helper_dispatch = wp_agent_run_runtime_package(
	array(
		'package'  => array( 'slug' => 'site-builder' ),
		'workflow' => array( 'id' => 'helper-late-load' ),
	)
);
agents_api_smoke_assert_equals( false, is_wp_error( $helper_dispatch ), 'runtime package helper resolves the ability after abilities init already fired', $failures, $passes );
agents_api_smoke_assert_equals( 'helper-late-load', is_array( $helper_dispatch ) ? $helper_dispatch['result']['workflow_id'] ?? '' : '', 'runtime package helper dispatches through the canonical ability', $failures, $passes );
$GLOBALS['__agents_api_smoke_actions']['wp_agent_runtime_package_run_handler'] = array();

echo "\n[1] Request validates package and workflow selectors:\n";
$request = WP_Agent_Runtime_Package_Run_Request::from_array(
	array(
		'package'  => array(
			'source' => 'bundles/site-builder',
			'slug'   => 'site-builder',
		),
		'workflow' => array( 'id' => 'build-site' ),
		'input'    => array( 'prompt' => 'Build a site.' ),
		'options'  => array( 'max_turns' => 8 ),
	)
);
agents_api_smoke_assert_equals( true, $request instanceof WP_Agent_Runtime_Package_Run_Request, 'valid request normalizes to value object', $failures, $passes );
agents_api_smoke_assert_equals( 'site-builder', $request instanceof WP_Agent_Runtime_Package_Run_Request ? $request->get_package()['slug'] ?? '' : '', 'package slug is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'Build a site.', $request instanceof WP_Agent_Runtime_Package_Run_Request ? $request->get_input()['prompt'] ?? '' : '', 'runtime input is preserved', $failures, $passes );

$missing_package = WP_Agent_Runtime_Package_Run_Request::from_array( array( 'workflow' => array( 'id' => 'build-site' ) ) );
agents_api_smoke_assert_equals( true, is_wp_error( $missing_package ), 'package is required', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_runtime_package_run_missing_package', is_wp_error( $missing_package ) ? $missing_package->get_error_code() : '', 'missing package error is stable', $failures, $passes );

$missing_workflow = WP_Agent_Runtime_Package_Run_Request::from_array( array( 'package' => array( 'slug' => 'site-builder' ) ) );
agents_api_smoke_assert_equals( true, is_wp_error( $missing_workflow ), 'workflow is required', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_runtime_package_run_missing_workflow', is_wp_error( $missing_workflow ) ? $missing_workflow->get_error_code() : '', 'missing workflow error is stable', $failures, $passes );

echo "\n[2] Result envelope normalizes status, result, and evidence refs:\n";
$result = WP_Agent_Runtime_Package_Run_Result::from_array(
	array(
		'status'        => 'succeeded',
		'run_id'        => 'run-123',
		'result'        => array( 'summary' => 'created' ),
		'evidence_refs' => array(
			array(
				'type'  => 'artifact',
				'label' => 'transcript',
				'url'   => 'https://example.com/artifacts/run-123/transcript.json',
			),
		),
		'metadata'      => array( 'runtime' => 'consumer-owned' ),
	)
);
agents_api_smoke_assert_equals( 'succeeded', $result->get_status(), 'status is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'run-123', $result->get_run_id(), 'run id is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'created', $result->get_result()['summary'] ?? '', 'result output is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'transcript', $result->get_evidence_refs()[0]['label'] ?? '', 'evidence refs are preserved', $failures, $passes );

$default_status = WP_Agent_Runtime_Package_Run_Result::from_array( array( 'status' => 'unknown' ) );
agents_api_smoke_assert_equals( 'succeeded', $default_status->get_status(), 'unknown status defaults to succeeded for legacy arrays', $failures, $passes );

echo "\n[3] Dispatcher requires a handler and normalizes handler output:\n";
$GLOBALS['__runtime_package_handler_called'] = null;
add_filter(
	'wp_agent_runtime_package_run_handler',
	static function ( $handler, WP_Agent_Runtime_Package_Run_Request $handler_request, array $raw_input ) {
		unset( $handler, $raw_input );
		return static function () use ( $handler_request ): WP_Agent_Runtime_Package_Run_Result {
			$GLOBALS['__runtime_package_handler_called'] = $handler_request->to_array();
			return new WP_Agent_Runtime_Package_Run_Result(
				WP_Agent_Runtime_Package_Run_Result::STATUS_SUCCEEDED,
				'run-dispatch',
				array( 'workflow_id' => $handler_request->get_workflow()['id'] ?? '' ),
				array(),
				array( array( 'type' => 'log', 'label' => 'runtime log' ) )
			);
		};
	},
	10,
	3
);

$dispatch = AgentsAPI\AI\agents_runtime_package_run_dispatch(
	array(
		'package'  => array( 'slug' => 'site-builder' ),
		'workflow' => array( 'id' => 'build-site' ),
	)
);
agents_api_smoke_assert_equals( false, is_wp_error( $dispatch ), 'dispatcher returns handler output', $failures, $passes );
agents_api_smoke_assert_equals( 'succeeded', is_array( $dispatch ) ? $dispatch['status'] ?? '' : '', 'dispatcher normalizes result status', $failures, $passes );
agents_api_smoke_assert_equals( 'build-site', is_array( $dispatch ) ? $dispatch['result']['workflow_id'] ?? '' : '', 'dispatcher passes workflow to handler', $failures, $passes );
agents_api_smoke_assert_equals( 'runtime log', is_array( $dispatch ) ? $dispatch['evidence_refs'][0]['label'] ?? '' : '', 'dispatcher preserves evidence refs', $failures, $passes );

$observer_run_id = 'observer-runtime-run';
AgentsAPI\AI\WP_Agent_Run_Control::save_run(
	AgentsAPI\AI\AGENTS_RUNTIME_PACKAGE_RUN_CONTROL_STORE,
	array(
		'run_id'   => $observer_run_id,
		'status'   => 'succeeded',
		'metadata' => array(
			'package'  => array( 'slug' => 'site-builder' ),
			'workflow' => array( 'id' => 'build-site' ),
		),
	)
);
$GLOBALS['__agents_api_smoke_caps'] = array( 'read' => true );
agents_api_smoke_assert_equals( false, AgentsAPI\AI\agents_runtime_package_run_read_permission( array( 'run_id' => $observer_run_id ) ), 'runtime package read defaults to operators', $failures, $passes );
$observer_run = AgentsAPI\AI\agents_get_runtime_package_run( array( 'run_id' => $observer_run_id ) );
agents_api_smoke_assert_equals( 'succeeded', $observer_run['status'] ?? '', 'runtime package get-run still returns observer status when called directly', $failures, $passes );
agents_api_smoke_assert_equals( true, $observer_run['metadata']['package']['redacted'] ?? false, 'runtime package observer envelope redacts nested package metadata', $failures, $passes );
$GLOBALS['__agents_api_smoke_caps']['manage_options'] = true;
$operator_run = AgentsAPI\AI\agents_get_runtime_package_run( array( 'run_id' => $observer_run_id ) );
agents_api_smoke_assert_equals( 'site-builder', $operator_run['metadata']['package']['slug'] ?? '', 'runtime package manager get-run preserves operator metadata', $failures, $passes );

echo "\n[4] Public host helper invokes the canonical runtime package boundary:\n";
$helper_dispatch = wp_agent_run_runtime_package(
	array(
		'package'  => array( 'slug' => 'site-builder' ),
		'workflow' => array( 'id' => 'build-site' ),
	)
);
agents_api_smoke_assert_equals( false, is_wp_error( $helper_dispatch ), 'public helper returns handler output', $failures, $passes );
agents_api_smoke_assert_equals( 'succeeded', is_array( $helper_dispatch ) ? $helper_dispatch['status'] ?? '' : '', 'public helper preserves result status', $failures, $passes );
agents_api_smoke_assert_equals( 'build-site', is_array( $helper_dispatch ) ? $helper_dispatch['result']['workflow_id'] ?? '' : '', 'public helper passes workflow to handler', $failures, $passes );

agents_api_smoke_finish( 'Agents API runtime package run contract', $failures, $passes );
