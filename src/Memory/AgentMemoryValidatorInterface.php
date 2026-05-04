<?php
/**
 * Agent Memory Validator Interface
 *
 * Generic contract for re-checking memory against a current workspace
 * substrate, such as options, theme settings, source content, repo state,
 * documents, channels, or other caller-owned facts.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

interface AgentMemoryValidatorInterface {

	/**
	 * Stable validator identifier stored in AgentMemoryMetadata::$validator.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Re-check a memory record against the caller-provided workspace context.
	 *
	 * @param AgentMemoryScope    $scope             Memory identity.
	 * @param string              $content           Memory content.
	 * @param AgentMemoryMetadata $metadata          Memory metadata.
	 * @param array<string,mixed> $workspace_context Current substrate facts supplied by the consumer.
	 * @return AgentMemoryValidationResult
	 */
	public function validate( AgentMemoryScope $scope, string $content, AgentMemoryMetadata $metadata, array $workspace_context = array() ): AgentMemoryValidationResult;
}
