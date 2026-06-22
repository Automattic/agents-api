<?php
/**
 * Pure-PHP smoke test for WP_Agent_Workflow_Bindings.
 *
 * Run with: php tests/workflow-bindings-smoke.php
 *
 * @package AgentsAPI\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$failures = array();
$passes   = 0;

echo "workflow-bindings-smoke\n";

function smoke_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS {$name}\n";
		return;
	}
	$failures[] = $name;
	echo "  FAIL {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

require_once __DIR__ . '/../src/Workflows/class-wp-agent-workflow-bindings.php';

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Bindings;

$context = array(
	'inputs' => array(
		'comment_id' => 42,
		'subject'    => 'Welcome',
		'meta'       => array( 'priority' => 'high', 'tags' => array( 'urgent', 'vip' ) ),
	),
	'steps'  => array(
		'classify' => array(
			'output' => array(
				'category' => 'spam',
				'score'    => 0.92,
				'reasons'  => array( 'first' => 'too short', 'second' => 'all caps' ),
			),
		),
	),
	'vars'   => array(
		'item' => array(
			'id'    => 7,
			'title' => 'Uruguay',
		),
	),
);

// Whole-string atomic substitution preserves type.
smoke_assert(
	42,
	WP_Agent_Workflow_Bindings::expand( '${inputs.comment_id}', $context ),
	'whole-string template returns native int',
	$failures,
	$passes
);

smoke_assert(
	0.92,
	WP_Agent_Workflow_Bindings::expand( '${steps.classify.output.score}', $context ),
	'whole-string template returns native float from step output',
	$failures,
	$passes
);

smoke_assert(
	array( 'urgent', 'vip' ),
	WP_Agent_Workflow_Bindings::expand( '${inputs.meta.tags}', $context ),
	'whole-string template returns native array',
	$failures,
	$passes
);

// Mixed-content templates render to string with type coercion.
smoke_assert(
	'comment 42 is spam',
	WP_Agent_Workflow_Bindings::expand( 'comment ${inputs.comment_id} is ${steps.classify.output.category}', $context ),
	'mixed template stringifies inline values',
	$failures,
	$passes
);

// Recursion into arrays.
smoke_assert(
	array(
		'title' => 'Welcome',
		'meta'  => array( 'priority' => 'high', 'cat' => 'spam' ),
	),
	WP_Agent_Workflow_Bindings::expand(
		array(
			'title' => '${inputs.subject}',
			'meta'  => array(
				'priority' => '${inputs.meta.priority}',
				'cat'      => '${steps.classify.output.category}',
			),
		),
		$context
	),
	'expand walks nested arrays',
	$failures,
	$passes
);

// Missing path → null in atomic mode, empty string in mixed mode.
smoke_assert(
	null,
	WP_Agent_Workflow_Bindings::expand( '${inputs.does_not_exist}', $context ),
	'atomic miss returns null',
	$failures,
	$passes
);

smoke_assert(
	'prefix:',
	WP_Agent_Workflow_Bindings::expand( 'prefix:${inputs.does_not_exist}', $context ),
	'mixed miss collapses to empty string',
	$failures,
	$passes
);

// Non-string scalars pass through untouched.
smoke_assert(
	123,
	WP_Agent_Workflow_Bindings::expand( 123, $context ),
	'integer passes through expand unchanged',
	$failures,
	$passes
);

smoke_assert(
	true,
	WP_Agent_Workflow_Bindings::expand( true, $context ),
	'boolean passes through expand unchanged',
	$failures,
	$passes
);

// Unknown root token resolves to null (atomic).
smoke_assert(
	null,
	WP_Agent_Workflow_Bindings::expand( '${unknown.token}', $context ),
	'unknown root segment resolves to null',
	$failures,
	$passes
);

// Missing step in steps map.
smoke_assert(
	null,
	WP_Agent_Workflow_Bindings::expand( '${steps.unseen.output.value}', $context ),
	'reference to missing step returns null',
	$failures,
	$passes
);

// `steps.<id>.foo` (without `output`) is not supported.
smoke_assert(
	null,
	WP_Agent_Workflow_Bindings::expand( '${steps.classify.input.something}', $context ),
	'non-output step path returns null (substrate is output-only)',
	$failures,
	$passes
);

// Numeric path segments resolve list elements.
smoke_assert(
	'urgent',
	WP_Agent_Workflow_Bindings::expand( '${inputs.meta.tags.0}', $context ),
	'numeric segment indexes lists',
	$failures,
	$passes
);

smoke_assert(
	7,
	WP_Agent_Workflow_Bindings::expand( '${vars.item.id}', $context ),
	'vars root resolves scoped runtime values',
	$failures,
	$passes
);

smoke_assert(
	'team Uruguay',
	WP_Agent_Workflow_Bindings::expand( 'team ${vars.item.title}', $context ),
	'mixed vars template stringifies inline values',
	$failures,
	$passes
);

echo "Passed: {$passes}, Failed: " . count( $failures ) . "\n";
exit( count( $failures ) > 0 ? 1 : 0 );
