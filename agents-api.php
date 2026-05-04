<?php
/**
 * Plugin Name: Agents API
 * Description: WordPress-shaped agent runtime substrate.
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: Automattic
 * License: GPL-2.0-or-later
 * Text Domain: agents-api
 *
 * Agents API bootstrap.
 *
 * WordPress-shaped agent substrate.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'AGENTS_API_LOADED' ) ) {
	return;
}

define( 'AGENTS_API_LOADED', true );
define( 'AGENTS_API_PATH', __DIR__ . '/' );
define( 'AGENTS_API_PLUGIN_FILE', __FILE__ );

require_once AGENTS_API_PATH . 'src/Registry/class-wp-agent.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifact.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifact-type.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifacts-registry.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-adoption-diff.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-adoption-result.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-adopter-interface.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-capability-ceiling.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-access-grant.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-access-store-interface.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-token.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-token-store-interface.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-authorization-policy-interface.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-token-authenticator.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-wordpress-authorization-policy.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-injection-policy.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-memory-layer.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-memory-registry.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-composable-context.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-section-registry.php';
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agents-registry.php';
require_once AGENTS_API_PATH . 'src/Registry/register-agents.php';
require_once AGENTS_API_PATH . 'src/Packages/register-agent-package-artifacts.php';
require_once AGENTS_API_PATH . 'src/Workspace/AgentWorkspaceScope.php';
require_once AGENTS_API_PATH . 'src/Identity/AgentIdentityScope.php';
require_once AGENTS_API_PATH . 'src/Identity/MaterializedAgentIdentity.php';
require_once AGENTS_API_PATH . 'src/Identity/MaterializedAgentIdentityStoreInterface.php';
require_once AGENTS_API_PATH . 'src/Transcripts/ConversationTranscriptStoreInterface.php';
require_once AGENTS_API_PATH . 'src/Transcripts/ConversationTranscriptLockInterface.php';
require_once AGENTS_API_PATH . 'src/Transcripts/NullConversationTranscriptLock.php';
require_once AGENTS_API_PATH . 'src/Approvals/PendingActionStoreInterface.php';
require_once AGENTS_API_PATH . 'src/Approvals/PendingActionStatus.php';
require_once AGENTS_API_PATH . 'src/Approvals/PendingAction.php';
require_once AGENTS_API_PATH . 'src/Approvals/ApprovalDecision.php';
require_once AGENTS_API_PATH . 'src/Approvals/PendingActionHandlerInterface.php';
require_once AGENTS_API_PATH . 'src/Approvals/PendingActionResolverInterface.php';
require_once AGENTS_API_PATH . 'src/Consent/AgentConsentOperation.php';
require_once AGENTS_API_PATH . 'src/Consent/AgentConsentDecision.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-consent-policy-interface.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-default-consent-policy.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentMessageEnvelope.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentExecutionPrincipal.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentCompactionItem.php';
require_once AGENTS_API_PATH . 'src/Tools/RuntimeToolDeclaration.php';
require_once AGENTS_API_PATH . 'src/Tools/ActionPolicy.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-access-policy-interface.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-action-policy-provider-interface.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-policy-filter.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-policy.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-action-policy-resolver.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationRequest.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationRunnerInterface.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationCompletionDecision.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationCompletionPolicyInterface.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationTranscriptPersisterInterface.php';
require_once AGENTS_API_PATH . 'src/Runtime/NullAgentConversationTranscriptPersister.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationCompaction.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentMarkdownSectionCompactionAdapter.php';
require_once AGENTS_API_PATH . 'src/Runtime/IterationBudget.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationResult.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationLoop.php';
require_once AGENTS_API_PATH . 'src/Tools/ToolCall.php';
require_once AGENTS_API_PATH . 'src/Tools/ToolParameters.php';
require_once AGENTS_API_PATH . 'src/Tools/ToolExecutionResult.php';
require_once AGENTS_API_PATH . 'src/Tools/ToolExecutorInterface.php';
require_once AGENTS_API_PATH . 'src/Tools/ToolExecutionCore.php';
require_once AGENTS_API_PATH . 'src/Tools/ToolSourceRegistry.php';
require_once AGENTS_API_PATH . 'src/Context/ContextAuthorityTier.php';
require_once AGENTS_API_PATH . 'src/Context/ContextConflictKind.php';
require_once AGENTS_API_PATH . 'src/Context/RetrievedContextItem.php';
require_once AGENTS_API_PATH . 'src/Context/ContextConflictResolution.php';
require_once AGENTS_API_PATH . 'src/Context/ContextConflictResolverInterface.php';
require_once AGENTS_API_PATH . 'src/Context/DefaultContextConflictResolver.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryScope.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryMetadata.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryStoreCapabilities.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryQuery.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryValidationResult.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryValidatorInterface.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryListEntry.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryReadResult.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryWriteResult.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryStoreInterface.php';
require_once AGENTS_API_PATH . 'src/Guidelines/guidelines.php';
require_once AGENTS_API_PATH . 'src/Guidelines/class-wp-guidelines-substrate.php';

add_action( 'init', array( 'WP_Agents_Registry', 'init' ), 10 );
add_action( 'init', array( 'WP_Guidelines_Substrate', 'register' ), 9 );
