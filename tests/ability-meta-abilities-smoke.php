<?php
/**
 * Pure-PHP smoke test for canonical ability discovery meta-abilities.
 *
 * Run with: php tests/ability-meta-abilities-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! class_exists( 'WP_Ability_Category' ) ) {
	class WP_Ability_Category {}
}

if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
		public function __construct( private string $name, private array $args ) {}
		public function get_name(): string { return $this->name; }
		public function get_label(): string { return (string) ( $this->args['label'] ?? '' ); }
		public function get_description(): string { return (string) ( $this->args['description'] ?? '' ); }
		public function get_category(): string { return (string) ( $this->args['category'] ?? '' ); }
		public function get_input_schema(): array { return isset( $this->args['input_schema'] ) && is_array( $this->args['input_schema'] ) ? $this->args['input_schema'] : array(); }
		public function get_output_schema(): array { return isset( $this->args['output_schema'] ) && is_array( $this->args['output_schema'] ) ? $this->args['output_schema'] : array(); }
		public function get_meta_item( string $key, $default = null ) { return $this->args['meta'][ $key ] ?? $default; }
		public function execute( $input = null ) {
			$permission = $this->args['permission_callback'] ?? null;
			if ( is_callable( $permission ) && true !== call_user_func( $permission, is_array( $input ) ? $input : array() ) ) {
				return new WP_Error( 'ability_invalid_permissions', 'Permission denied.' );
			}

			$callback = $this->args['execute_callback'] ?? null;
			return is_callable( $callback ) ? call_user_func( $callback, is_array( $input ) ? $input : array() ) : null;
		}
	}
}

$failures = array();
$passes   = 0;

echo "agents-api-ability-meta-abilities-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$GLOBALS['__agents_api_smoke_abilities']          = array();
$GLOBALS['__agents_api_smoke_ability_categories'] = array();
$GLOBALS['__agents_api_smoke_can']                = true;

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		unset( $capability );
		return (bool) $GLOBALS['__agents_api_smoke_can'];
	}
} else {
	add_filter(
		'user_has_cap',
		static function ( array $allcaps ): array {
			$allcaps['manage_options'] = (bool) ( $GLOBALS['__agents_api_smoke_can'] ?? false );
			return $allcaps;
		}
	);
}

if ( ! function_exists( 'wp_has_ability_category' ) ) {
	function wp_has_ability_category( string $slug ): bool {
		return isset( $GLOBALS['__agents_api_smoke_ability_categories'][ $slug ] );
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $slug, array $args ): ?WP_Ability_Category {
		$GLOBALS['__agents_api_smoke_ability_categories'][ $slug ] = $args;
		return null;
	}
}

if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( string $name ): bool {
		return isset( $GLOBALS['__agents_api_smoke_abilities'][ $name ] );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): ?WP_Ability {
		$ability = new WP_Ability( $name, $args );
		$GLOBALS['__agents_api_smoke_abilities'][ $name ] = $ability;
		return $ability;
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?WP_Ability {
		return $GLOBALS['__agents_api_smoke_abilities'][ $name ] ?? null;
	}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	function wp_get_abilities(): array {
		return array_values( $GLOBALS['__agents_api_smoke_abilities'] );
	}
}

agents_api_smoke_require_module();

// Ability categories must be registered on wp_abilities_api_categories_init and
// must exist before an ability can be assigned to them under real WordPress.
add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		foreach ( array( 'demo-tools', 'content' ) as $category_slug ) {
			if ( ! wp_has_ability_category( $category_slug ) ) {
				wp_register_ability_category(
					$category_slug,
					array(
						'label'       => ucfirst( str_replace( '-', ' ', $category_slug ) ),
						'description' => 'Smoke test category.',
					)
				);
			}
		}
	}
);

// Abilities must be registered on the wp_abilities_api_init action under real
// WordPress; register the demo abilities there so the host backend keeps them,
// then fire the init actions.
add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'demo/weather-forecast',
			array(
				'label'               => 'Weather Forecast',
				'description'         => 'Fetch a local weather forecast for a city.',
				'category'            => 'demo-tools',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'city' ),
					'properties' => array(
						'city' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( array $input ): array {
					return array( 'forecast' => 'sunny in ' . ( $input['city'] ?? '' ) );
				},
				'permission_callback' => static function (): bool {
					return true;
				},
				'meta'                => array(
					'sensitive_parameters' => array( 'token' ),
				),
			)
		);

		wp_register_ability(
			'demo/publish-post',
			array(
				'label'               => 'Publish Post',
				'description'         => 'Publish a draft post by ID.',
				'category'            => 'content',
				'input_schema'        => array(
					'type'     => 'object',
					'required' => array( 'post_id' ),
				),
				'execute_callback'    => static function ( array $input ): array {
					return array( 'published' => (int) ( $input['post_id'] ?? 0 ) );
				},
				'permission_callback' => static function (): bool {
					return true;
				},
			)
		);
	}
);

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );

echo "\n[1] Meta-abilities register in canonical namespace:\n";
agents_api_smoke_assert_equals( true, wp_has_ability( 'agents/ability-search' ), 'ability-search is registered', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_ability( 'agents/ability-call' ), 'ability-call is registered', $failures, $passes );

echo "\n[2] ability-search returns compact entries by name and keyword:\n";
$name_search = AgentsAPI\AI\Tools\agents_ability_search( array( 'query' => 'weather' ) );
agents_api_smoke_assert_equals( 'demo/weather-forecast', $name_search['abilities'][0]['name'] ?? '', 'search by name substring finds weather ability', $failures, $passes );
agents_api_smoke_assert_equals( array( 'city' ), $name_search['abilities'][0]['required_fields'] ?? array(), 'search result includes required-field hint', $failures, $passes );

$keyword_search = AgentsAPI\AI\Tools\agents_ability_search( array( 'query' => '+forecast' ) );
agents_api_smoke_assert_equals( 'demo/weather-forecast', $keyword_search['abilities'][0]['name'] ?? '', 'search by required keyword finds forecast ability', $failures, $passes );

$category_search = AgentsAPI\AI\Tools\agents_ability_search( array( 'category' => 'content' ) );
agents_api_smoke_assert_equals( 'demo/publish-post', $category_search['abilities'][0]['name'] ?? '', 'search by category finds content ability', $failures, $passes );

$select_search = AgentsAPI\AI\Tools\agents_ability_search( array( 'query' => 'select:demo/publish-post,demo/weather-forecast' ) );
agents_api_smoke_assert_equals( 2, $select_search['count'], 'select query returns explicitly selected abilities', $failures, $passes );

echo "\n[3] ability-call dispatches through the registered target ability:\n";
$call = AgentsAPI\AI\Tools\agents_ability_call(
	array(
		'name'       => 'demo/weather-forecast',
		'parameters' => array(
			'city'  => 'Portland',
			'token' => 'secret-token',
		),
	)
);
agents_api_smoke_assert_equals( 'demo/weather-forecast', $call['name'] ?? '', 'ability-call returns target ability name', $failures, $passes );
agents_api_smoke_assert_equals( 'sunny in Portland', $call['result']['forecast'] ?? '', 'ability-call returns target result', $failures, $passes );
agents_api_smoke_assert_equals( '[redacted]', $call['parameters']['token'] ?? '', 'ability-call redacts sensitive parameters in its response envelope', $failures, $passes );
agents_api_smoke_assert_equals( true, $call['parameters_redacted'] ?? false, 'ability-call marks response parameters redacted', $failures, $passes );

$missing = AgentsAPI\AI\Tools\agents_ability_call( array( 'name' => 'demo/missing' ) );
agents_api_smoke_assert_equals( true, $missing instanceof WP_Error, 'missing ability returns WP_Error', $failures, $passes );
agents_api_smoke_assert_equals( 'agents_ability_call_not_found', $missing instanceof WP_Error ? $missing->get_error_code() : '', 'missing ability error code is stable', $failures, $passes );

$recursive = AgentsAPI\AI\Tools\agents_ability_call( array( 'name' => 'agents/ability-call' ) );
agents_api_smoke_assert_equals( true, $recursive instanceof WP_Error, 'ability-call refuses recursion', $failures, $passes );

echo "\n[4] Meta-ability permission gates are filterable:\n";
$GLOBALS['__agents_api_smoke_can'] = false;
agents_api_smoke_assert_equals( false, AgentsAPI\AI\Tools\agents_ability_search_permission( array() ), 'search permission denies by default without manage_options', $failures, $passes );
add_filter( 'agents_ability_search_permission', static fn() => true );
agents_api_smoke_assert_equals( true, AgentsAPI\AI\Tools\agents_ability_search_permission( array() ), 'search permission filter can allow', $failures, $passes );

agents_api_smoke_finish( 'Agents API ability meta-abilities', $failures, $passes );
