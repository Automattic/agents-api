<?php
/**
 * Consumer fixture proving Composer exposes guarded package contracts to PHPStan.
 *
 * @package AgentsAPI\Tests
 */

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
