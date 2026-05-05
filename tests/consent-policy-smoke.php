<?php
/**
 * Pure-PHP smoke test for generic consent policy contracts.
 *
 * Run with: php tests/consent-policy-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-consent-policy-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$operation_class = AgentsAPI\AI\Consent\WP_Agent_Consent_Operation::class;

echo "\n[1] Consent operation vocabulary is stable and separate:\n";
agents_api_smoke_assert_equals(
	array( 'store_memory', 'use_memory', 'store_transcript', 'share_transcript', 'escalate_to_human' ),
	$operation_class::all(),
	'consent policy exposes stable generic operations',
	$failures,
	$passes
);
agents_api_smoke_assert_equals( 'store_memory', $operation_class::normalize( ' STORE_MEMORY ' ), 'operation normalizes store_memory', $failures, $passes );
agents_api_smoke_assert_equals( 'share_transcript', $operation_class::normalize( 'share_transcript' ), 'operation normalizes share_transcript', $failures, $passes );
agents_api_smoke_assert_equals( null, $operation_class::normalize( 'share_memory' ), 'operation rejects invented sharing values', $failures, $passes );
agents_api_smoke_assert_equals( true, $operation_class::isValid( 'escalate_to_human' ), 'operation validates escalation', $failures, $passes );

echo "\n[2] Consent decisions carry audit metadata:\n";
$decision = AgentsAPI\AI\Consent\WP_Agent_Consent_Decision::allowed(
	$operation_class::STORE_MEMORY,
	'Explicit Consent',
	array(
		'Agent ID' => 'example-agent',
		'object'   => new stdClass(),
		'nested'   => array( 'Transcript ID' => 'transcript-1' ),
	)
);
agents_api_smoke_assert_equals( true, $decision->is_allowed(), 'decision records allowed state', $failures, $passes );
agents_api_smoke_assert_equals( 'store_memory', $decision->operation(), 'decision records operation', $failures, $passes );
agents_api_smoke_assert_equals( 'explicit_consent', $decision->reason(), 'decision normalizes reason code', $failures, $passes );
agents_api_smoke_assert_equals(
	array(
		'allowed'        => true,
		'operation'      => 'store_memory',
		'reason'         => 'explicit_consent',
		'audit_metadata' => array(
			'agent_id' => 'example-agent',
			'nested'   => array( 'transcript_id' => 'transcript-1' ),
		),
	),
	$decision->to_array(),
	'decision exposes JSON-friendly audit metadata',
	$failures,
	$passes
);

echo "\n[3] Default policy is conservative for non-interactive modes:\n";
$policy = new WP_Agent_Default_Consent_Policy();
agents_api_smoke_assert_equals( true, $policy instanceof WP_Agent_Consent_Policy, 'default policy implements public interface', $failures, $passes );

$non_interactive_context = array(
	'mode'    => 'pipeline',
	'consent' => array(
		'store_memory'      => true,
		'use_memory'        => true,
		'store_transcript'  => true,
		'share_transcript'  => true,
		'escalate_to_human' => true,
	),
);
agents_api_smoke_assert_equals( false, $policy->can_store_memory( $non_interactive_context )->is_allowed(), 'non-interactive store memory is denied', $failures, $passes );
agents_api_smoke_assert_equals( false, $policy->can_use_memory( $non_interactive_context )->is_allowed(), 'non-interactive use memory is denied', $failures, $passes );
agents_api_smoke_assert_equals( false, $policy->can_store_transcript( $non_interactive_context )->is_allowed(), 'non-interactive store transcript is denied', $failures, $passes );
agents_api_smoke_assert_equals( false, $policy->can_share_transcript( $non_interactive_context )->is_allowed(), 'non-interactive share transcript is denied', $failures, $passes );
agents_api_smoke_assert_equals( false, $policy->can_escalate_to_human( $non_interactive_context )->is_allowed(), 'non-interactive escalation is denied', $failures, $passes );

echo "\n[4] Memory and transcript consent are independently controllable:\n";
$interactive_context = array(
	'mode'    => 'chat',
	'user_id' => 123,
	'consent' => array(
		'store_memory'      => true,
		'use_memory'        => true,
		'store_transcript'  => false,
		'share_transcript'  => true,
		'escalate_to_human' => true,
	),
);
agents_api_smoke_assert_equals( true, $policy->can_store_memory( $interactive_context )->is_allowed(), 'interactive explicit memory storage is allowed', $failures, $passes );
agents_api_smoke_assert_equals( true, $policy->can_use_memory( $interactive_context )->is_allowed(), 'interactive explicit memory use is allowed', $failures, $passes );
agents_api_smoke_assert_equals( false, $policy->can_store_transcript( $interactive_context )->is_allowed(), 'transcript storage remains independently denied', $failures, $passes );
agents_api_smoke_assert_equals( true, $policy->can_share_transcript( $interactive_context )->is_allowed(), 'transcript sharing can be separately allowed', $failures, $passes );
agents_api_smoke_assert_equals( true, $policy->can_escalate_to_human( $interactive_context )->is_allowed(), 'escalation can be separately allowed', $failures, $passes );
agents_api_smoke_assert_equals( 'explicit_consent_missing', $policy->can_store_transcript( array( 'mode' => 'chat' ) )->reason(), 'interactive default still requires explicit transcript consent', $failures, $passes );

agents_api_smoke_finish( 'Agents API consent policy', $failures, $passes );
