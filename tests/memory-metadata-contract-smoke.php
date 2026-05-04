<?php
/**
 * Pure-PHP smoke test for memory metadata contracts.
 *
 * Run with: php tests/memory-metadata-contract-smoke.php
 *
 * @package AgentsAPI\Tests
 */

use AgentsAPI\Core\FilesRepository\AgentMemoryListEntry;
use AgentsAPI\Core\FilesRepository\AgentMemoryMetadata;
use AgentsAPI\Core\FilesRepository\AgentMemoryQuery;
use AgentsAPI\Core\FilesRepository\AgentMemoryReadResult;
use AgentsAPI\Core\FilesRepository\AgentMemoryScope;
use AgentsAPI\Core\FilesRepository\AgentMemoryStoreCapabilities;
use AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface;
use AgentsAPI\Core\FilesRepository\AgentMemoryValidationResult;
use AgentsAPI\Core\FilesRepository\AgentMemoryValidatorInterface;
use AgentsAPI\Core\FilesRepository\AgentMemoryWriteResult;
use AgentsAPI\Core\Workspace\AgentWorkspaceScope;

$failures = array();
$passes   = 0;

echo "agents-api-memory-metadata-contract-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

final class Agents_API_Metadata_Smoke_Store implements AgentMemoryStoreInterface {

	/** @var array<string,array{content:string,metadata:AgentMemoryMetadata|null}> */
	private array $records = array();

	public function capabilities(): AgentMemoryStoreCapabilities {
		return new AgentMemoryStoreCapabilities(
			array( 'source_type', 'confidence', 'authority_tier', 'validator' ),
			array( 'source_type', 'confidence', 'authority_tier', 'validator' ),
			array( 'source_type', 'confidence', 'authority_tier', 'validator' ),
			array( 'confidence', 'authority_tier' ),
			array( 'workspace_state' ),
		);
	}

	public function read( AgentMemoryScope $scope, array $metadata_fields = AgentMemoryMetadata::FIELDS ): AgentMemoryReadResult {
		$record = $this->records[ $scope->key() ] ?? null;
		if ( null === $record ) {
			return AgentMemoryReadResult::not_found();
		}

		return new AgentMemoryReadResult(
			true,
			$record['content'],
			sha1( $record['content'] ),
			strlen( $record['content'] ),
			$record['metadata']?->updated_at,
			$record['metadata'],
			$this->capabilities()->unsupported_metadata_fields( $metadata_fields, 'read' ),
		);
	}

	public function write( AgentMemoryScope $scope, string $content, ?string $if_match = null, ?AgentMemoryMetadata $metadata = null ): AgentMemoryWriteResult {
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

		return AgentMemoryWriteResult::ok( sha1( $content ), strlen( $content ), $metadata, $unsupported );
	}

	public function exists( AgentMemoryScope $scope ): bool {
		return isset( $this->records[ $scope->key() ] );
	}

	public function delete( AgentMemoryScope $scope ): AgentMemoryWriteResult {
		unset( $this->records[ $scope->key() ] );
		return AgentMemoryWriteResult::ok( '', 0 );
	}

	public function list_layer( AgentMemoryScope $scope_query, ?AgentMemoryQuery $query = null ): array {
		unset( $scope_query );
		$query   = $query ?? new AgentMemoryQuery();
		$entries = array();

		foreach ( $this->records as $record ) {
			$metadata = $record['metadata'];
			if ( $query->source_types && ! in_array( $metadata?->source_type, $query->source_types, true ) ) {
				continue;
			}
			if ( null !== $query->min_confidence && ( null === $metadata || null === $metadata->confidence || $metadata->confidence < $query->min_confidence ) ) {
				continue;
			}

			$entries[] = new AgentMemoryListEntry(
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

	public function list_subtree( AgentMemoryScope $scope_query, string $prefix, ?AgentMemoryQuery $query = null ): array {
		unset( $prefix );
		return $this->list_layer( $scope_query, $query );
	}
}

final class Agents_API_Metadata_Smoke_Validator implements AgentMemoryValidatorInterface {

	public function id(): string {
		return 'workspace_state';
	}

	public function validate( AgentMemoryScope $scope, string $content, AgentMemoryMetadata $metadata, array $workspace_context = array() ): AgentMemoryValidationResult {
		unset( $scope, $content, $metadata );
		return ! empty( $workspace_context['current'] )
			? AgentMemoryValidationResult::valid( 0.95, 'Workspace fact still matches.' )
			: AgentMemoryValidationResult::stale( 'Workspace fact changed.', 0.2 );
	}
}

$workspace = AgentWorkspaceScope::from_parts( 'code_workspace', 'Automattic/agents-api@chubes-memory-provenance' );
$scope     = new AgentMemoryScope( 'user', $workspace->workspace_type, $workspace->workspace_id, 7, 11, 'MEMORY.md' );
$store     = new Agents_API_Metadata_Smoke_Store();
$validator = new Agents_API_Metadata_Smoke_Validator();

$agent_inferred = ( new AgentMemoryMetadata( source_type: AgentMemoryMetadata::SOURCE_AGENT_INFERRED ) )->with_defaults( 100 );
$curated        = ( new AgentMemoryMetadata( source_type: AgentMemoryMetadata::SOURCE_CURATED ) )->with_defaults( 100 );

agents_api_smoke_assert_equals( 0.5, $agent_inferred->confidence, 'agent-inferred memories default to lower confidence', $failures, $passes );
agents_api_smoke_assert_equals( AgentMemoryMetadata::AUTHORITY_LOW, $agent_inferred->authority_tier, 'agent-inferred memories default to low authority', $failures, $passes );
agents_api_smoke_assert_equals( 1.0, $curated->confidence, 'curated memories default to full confidence', $failures, $passes );
agents_api_smoke_assert_equals( AgentMemoryMetadata::AUTHORITY_CANONICAL, $curated->authority_tier, 'curated memories default to canonical authority', $failures, $passes );

$metadata = new AgentMemoryMetadata(
	source_type: AgentMemoryMetadata::SOURCE_WORKSPACE_EXTRACTED,
	source_ref: 'repo:abc123',
	workspace: $workspace,
	validator: $validator->id(),
	confidence: 0.86,
);
$write    = $store->write( $scope, 'Memory content', null, $metadata );

agents_api_smoke_assert_equals( true, $write->success, 'write accepts metadata', $failures, $passes );
agents_api_smoke_assert_equals( array( 'source_ref', 'workspace', 'created_at', 'updated_at' ), $write->unsupported_metadata_fields, 'write reports unsupported metadata fields', $failures, $passes );
agents_api_smoke_assert_equals( AgentMemoryMetadata::SOURCE_WORKSPACE_EXTRACTED, $write->metadata?->source_type, 'write returns persisted metadata shape', $failures, $passes );
agents_api_smoke_assert_equals( null, $write->metadata?->workspace, 'write omits unsupported metadata from persisted shape', $failures, $passes );

$read = $store->read( $scope, array( 'source_type', 'workspace', 'confidence' ) );
agents_api_smoke_assert_equals( true, $read->exists, 'read returns memory content', $failures, $passes );
agents_api_smoke_assert_equals( array( 'workspace' ), $read->unsupported_metadata_fields, 'read reports unsupported requested metadata fields', $failures, $passes );
agents_api_smoke_assert_equals( 0.86, $read->metadata?->confidence, 'read carries metadata confidence', $failures, $passes );
agents_api_smoke_assert_equals( null, $read->metadata?->workspace, 'read metadata omits unsupported field values', $failures, $passes );

$query   = new AgentMemoryQuery(
	source_types: array( AgentMemoryMetadata::SOURCE_WORKSPACE_EXTRACTED ),
	min_confidence: 0.8,
	authority_tiers: array( AgentMemoryMetadata::AUTHORITY_LOW ),
	metadata_fields: array( 'source_type', 'workspace', 'confidence' ),
	order_by: 'confidence',
);
$entries = $store->list_layer( new AgentMemoryScope( 'user', $workspace->workspace_type, $workspace->workspace_id, 7, 11, '' ), $query );

agents_api_smoke_assert_equals( array( 'source_type', 'confidence', 'authority_tier' ), $query->filter_fields(), 'query exposes metadata filter fields', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $entries ), 'list query can filter by provenance and confidence', $failures, $passes );
agents_api_smoke_assert_equals( array( 'workspace' ), $entries[0]->unsupported_metadata_fields, 'list reports unsupported metadata fields', $failures, $passes );

$valid = $validator->validate( $scope, 'Memory content', $metadata, array( 'current' => true ) );
$stale = $validator->validate( $scope, 'Memory content', $metadata, array( 'current' => false ) );
agents_api_smoke_assert_equals( true, $valid->valid, 'validator can confirm current workspace facts', $failures, $passes );
agents_api_smoke_assert_equals( 'stale', $stale->status, 'validator can report stale workspace facts', $failures, $passes );

agents_api_smoke_finish( 'memory metadata contract smoke', $failures, $passes );
