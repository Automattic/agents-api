<?php
/**
 * Pure-PHP smoke test for memory metadata contracts.
 *
 * Run with: php tests/memory-metadata-contract-smoke.php
 *
 * @package AgentsAPI\Tests
 */

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_List_Entry;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Validation_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Validator;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

$failures = array();
$passes   = 0;

echo "agents-api-memory-metadata-contract-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

final class Agents_API_Metadata_Smoke_Store implements WP_Agent_Memory_Store {

	/** @var array<string,array{content:string,metadata:WP_Agent_Memory_Metadata|null}> */
	private array $records = array();

	public function capabilities(): WP_Agent_Memory_Store_Capabilities {
		return new WP_Agent_Memory_Store_Capabilities(
			array( 'source_type', 'confidence', 'authority_tier', 'validator' ),
			array( 'source_type', 'confidence', 'authority_tier', 'validator' ),
			array( 'source_type', 'confidence', 'authority_tier', 'validator' ),
			array( 'confidence', 'authority_tier' ),
			array( 'workspace_state' ),
		);
	}

	public function read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Read_Result {
		$record = $this->records[ $scope->key() ] ?? null;
		if ( null === $record ) {
			return WP_Agent_Memory_Read_Result::not_found();
		}

		return new WP_Agent_Memory_Read_Result(
			true,
			$record['content'],
			sha1( $record['content'] ),
			strlen( $record['content'] ),
			$record['metadata']?->updated_at,
			$record['metadata'],
			$this->capabilities()->unsupported_metadata_fields( $metadata_fields, 'read' ),
		);
	}

	public function write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?WP_Agent_Memory_Metadata $metadata = null ): WP_Agent_Memory_Write_Result {
		unset( $if_match );

		$metadata              = $metadata?->with_defaults( 123 );
		$unsupported           = array();
		if ( null !== $metadata ) {
			$unsupported = $this->capabilities()->unsupported_metadata_fields( array_keys( $metadata->to_array() ), 'persist' );
			$metadata    = $metadata->only_fields( $this->capabilities()->persisted_metadata_fields );
		}
		$this->records[ $scope->key() ] = array(
			'content'  => $content,
			'metadata' => $metadata,
		);

		return WP_Agent_Memory_Write_Result::ok( sha1( $content ), strlen( $content ), $metadata, $unsupported );
	}

	public function exists( WP_Agent_Memory_Scope $scope ): bool {
		return isset( $this->records[ $scope->key() ] );
	}

	public function delete( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Write_Result {
		unset( $this->records[ $scope->key() ] );
		return WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	public function list_layer( WP_Agent_Memory_Scope $scope_query, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $scope_query );
		$query   = $query ?? new WP_Agent_Memory_Query();
		$entries = array();

		foreach ( $this->records as $record ) {
			$metadata = $record['metadata'];
			if ( $query->source_types && ! in_array( $metadata?->source_type, $query->source_types, true ) ) {
				continue;
			}
			if ( null !== $query->min_confidence && ( null === $metadata || null === $metadata->confidence || $metadata->confidence < $query->min_confidence ) ) {
				continue;
			}

			$entries[] = new WP_Agent_Memory_List_Entry(
				'MEMORY.md',
				'user',
				strlen( $record['content'] ),
				$metadata?->updated_at,
				$metadata,
				$this->capabilities()->unsupported_metadata_fields( $query->metadata_fields, 'read' ),
			);
		}

		return $entries;
	}

	public function list_subtree( WP_Agent_Memory_Scope $scope_query, string $prefix, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $prefix );
		return $this->list_layer( $scope_query, $query );
	}
}

final class Agents_API_Metadata_Smoke_Validator implements WP_Agent_Memory_Validator {

	public function id(): string {
		return 'workspace_state';
	}

	public function validate( WP_Agent_Memory_Scope $scope, string $content, WP_Agent_Memory_Metadata $metadata, array $workspace_context = array() ): WP_Agent_Memory_Validation_Result {
		unset( $scope, $content, $metadata );
		return ! empty( $workspace_context['current'] )
			? WP_Agent_Memory_Validation_Result::valid( 0.95, 'Workspace fact still matches.' )
			: WP_Agent_Memory_Validation_Result::stale( 'Workspace fact changed.', 0.2 );
	}
}

$workspace = WP_Agent_Workspace_Scope::from_parts( 'code_workspace', 'Automattic/agents-api@chubes-memory-provenance' );
$scope     = new WP_Agent_Memory_Scope( 'user', $workspace->workspace_type, $workspace->workspace_id, 7, 11, 'MEMORY.md' );
$store     = new Agents_API_Metadata_Smoke_Store();
$validator = new Agents_API_Metadata_Smoke_Validator();

$agent_inferred = ( new WP_Agent_Memory_Metadata( source_type: WP_Agent_Memory_Metadata::SOURCE_AGENT_INFERRED ) )->with_defaults( 100 );
$curated        = ( new WP_Agent_Memory_Metadata( source_type: WP_Agent_Memory_Metadata::SOURCE_CURATED ) )->with_defaults( 100 );

agents_api_smoke_assert_equals( 0.5, $agent_inferred->confidence, 'agent-inferred memories default to lower confidence', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Memory_Metadata::AUTHORITY_LOW, $agent_inferred->authority_tier, 'agent-inferred memories default to low authority', $failures, $passes );
agents_api_smoke_assert_equals( 1.0, $curated->confidence, 'curated memories default to full confidence', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Memory_Metadata::AUTHORITY_CANONICAL, $curated->authority_tier, 'curated memories default to canonical authority', $failures, $passes );

$metadata = new WP_Agent_Memory_Metadata(
	source_type: WP_Agent_Memory_Metadata::SOURCE_WORKSPACE_EXTRACTED,
	source_ref: 'repo:abc123',
	workspace: $workspace,
	validator: $validator->id(),
	confidence: 0.86,
);
$write    = $store->write( $scope, 'Memory content', null, $metadata );

agents_api_smoke_assert_equals( true, $write->success, 'write accepts metadata', $failures, $passes );
agents_api_smoke_assert_equals( array( 'source_ref', 'workspace', 'created_at', 'updated_at' ), $write->unsupported_metadata_fields, 'write reports unsupported metadata fields', $failures, $passes );
agents_api_smoke_assert_equals( WP_Agent_Memory_Metadata::SOURCE_WORKSPACE_EXTRACTED, $write->metadata?->source_type, 'write returns persisted metadata shape', $failures, $passes );
agents_api_smoke_assert_equals( null, $write->metadata?->workspace, 'write omits unsupported metadata from persisted shape', $failures, $passes );

$read = $store->read( $scope, array( 'source_type', 'workspace', 'confidence' ) );
agents_api_smoke_assert_equals( true, $read->exists, 'read returns memory content', $failures, $passes );
agents_api_smoke_assert_equals( array( 'workspace' ), $read->unsupported_metadata_fields, 'read reports unsupported requested metadata fields', $failures, $passes );
agents_api_smoke_assert_equals( 0.86, $read->metadata?->confidence, 'read carries metadata confidence', $failures, $passes );
agents_api_smoke_assert_equals( null, $read->metadata?->workspace, 'read metadata omits unsupported field values', $failures, $passes );

$query   = new WP_Agent_Memory_Query(
	source_types: array( WP_Agent_Memory_Metadata::SOURCE_WORKSPACE_EXTRACTED ),
	min_confidence: 0.8,
	authority_tiers: array( WP_Agent_Memory_Metadata::AUTHORITY_LOW ),
	metadata_fields: array( 'source_type', 'workspace', 'confidence' ),
	order_by: 'confidence',
);
$entries = $store->list_layer( new WP_Agent_Memory_Scope( 'user', $workspace->workspace_type, $workspace->workspace_id, 7, 11, '' ), $query );

agents_api_smoke_assert_equals( array( 'source_type', 'confidence', 'authority_tier' ), $query->filter_fields(), 'query exposes metadata filter fields', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $entries ), 'list query can filter by provenance and confidence', $failures, $passes );
agents_api_smoke_assert_equals( array( 'workspace' ), $entries[0]->unsupported_metadata_fields, 'list reports unsupported metadata fields', $failures, $passes );

$valid = $validator->validate( $scope, 'Memory content', $metadata, array( 'current' => true ) );
$stale = $validator->validate( $scope, 'Memory content', $metadata, array( 'current' => false ) );
agents_api_smoke_assert_equals( true, $valid->valid, 'validator can confirm current workspace facts', $failures, $passes );
agents_api_smoke_assert_equals( 'stale', $stale->status, 'validator can report stale workspace facts', $failures, $passes );

agents_api_smoke_finish( 'memory metadata contract smoke', $failures, $passes );
