<?php
/**
 * Plugin Name: Agents API
 * Description: WordPress-shaped agent runtime substrate.
 * Version: 0.1.0
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
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-adopter.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-capability-ceiling.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-access-grant.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-access-store.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-token.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-token-store.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-caller-context.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-authorization-policy.php';
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
require_once AGENTS_API_PATH . 'src/Workspace/class-wp-agent-workspace-scope.php';
require_once AGENTS_API_PATH . 'src/Identity/class-wp-agent-identity-scope.php';
require_once AGENTS_API_PATH . 'src/Identity/class-wp-agent-materialized-identity.php';
require_once AGENTS_API_PATH . 'src/Identity/class-wp-agent-identity-store.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-conversation-store.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-conversation-lock.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-null-conversation-lock.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action-store.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action-status.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-approval-decision.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action-handler.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action-resolver.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-consent-operation.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-consent-decision.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-consent-policy.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-default-consent-policy.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-message.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-execution-principal.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-effective-agent-resolver.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-compaction-item.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-declaration.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-action-policy.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-access-policy.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-action-policy-provider.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-policy-filter.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-policy.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-action-policy-resolver.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-request.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-runner.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-completion-decision.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-completion-policy.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-transcript-persister.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-null-transcript-persister.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-compaction.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-markdown-section-compaction-adapter.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-iteration-budget.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-result.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-loop.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-call.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-parameters.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-result.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-executor.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-execution-core.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-source-registry.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-authority-tier.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-conflict-kind.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-item.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-conflict-resolution.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-conflict-resolver.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-default-context-conflict-resolver.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-scope.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-metadata.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-store-capabilities.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-query.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-validation-result.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-validator.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-list-entry.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-read-result.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-write-result.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-store.php';
require_once AGENTS_API_PATH . 'src/Guidelines/guidelines.php';
require_once AGENTS_API_PATH . 'src/Guidelines/class-wp-guidelines-substrate.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-external-message.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-channel-session-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-option-channel-session-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-channel-session-map.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-webhook-signature.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-message-idempotency-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-transient-message-idempotency-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-message-idempotency.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-bridge-client.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-bridge-queue-item.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-bridge-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-option-bridge-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-bridge.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-channel.php';
require_once AGENTS_API_PATH . 'src/Channels/register-agents-chat-ability.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-bindings.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-spec.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-run-result.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-store.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-run-recorder.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-runner.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-registry.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once AGENTS_API_PATH . 'src/Workflows/register-workflows.php';
require_once AGENTS_API_PATH . 'src/Workflows/register-agents-workflow-abilities.php';

add_action( 'init', array( 'WP_Agents_Registry', 'init' ), 10 );
add_action( 'init', array( 'WP_Guidelines_Substrate', 'register' ), 9 );
