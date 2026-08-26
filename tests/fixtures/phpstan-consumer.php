<?php
/**
 * Consumer fixture proving Composer exposes guarded package contracts to PHPStan.
 *
 * @package AgentsAPI\Tests
 */

use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
use AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy;
use AgentsAPI\AI\WP_Agent_Run_Outcome;
use AgentsAPI\AI\WP_Agent_Runtime_Tool_Request;
use AgentsAPI\AI\WP_Agent_Transcript_Persister;

/**
 * Exercise package types without loading the WordPress runtime bootstrap.
 *
 * @param WP_Agent_Package $package Package contract.
 * @return array<int,mixed>
 */
function agents_api_phpstan_consume_package( WP_Agent_Package $package ): array {
	$report = WP_Agent_Package_Capability_Checker::check( $package, array() );

	return array(
		$package->get_slug(),
		$report->to_array(),
		WP_Agent_Package_Artifact_Status::classify( 'installed', 'current' ),
		WP_Agent_Package_Artifact_Hasher::hash( array() ),
	);
}

/**
 * Exercise conversation/runtime contracts from a Composer consumer.
 *
 * @param WP_Agent_Conversation_Completion_Policy $policy    Completion policy.
 * @param WP_Agent_Transcript_Persister           $persister Transcript persister.
 * @param WP_Agent_Tool_Executor                  $executor  Tool executor.
 * @return array<int,mixed>
 */
function agents_api_phpstan_consume_runtime(
	WP_Agent_Conversation_Completion_Policy $policy,
	WP_Agent_Transcript_Persister $persister,
	WP_Agent_Tool_Executor $executor
): array {
	return array(
		$policy,
		$persister,
		$executor,
		WP_Agent_Run_Outcome::normalize( null, array( 'completed' => true ) ),
		WP_Agent_Runtime_Tool_Request::normalize(
			array(
				'id'           => 'request-1',
				'tool_call_id' => 'call-1',
				'tool_name'    => 'example/tool',
				'parameters'   => array(),
				'status'       => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
			)
		),
	);
}
