<?php
/**
 * Plugin Name: Agents API
 * Description: WordPress-shaped agent runtime substrate.
 * Requires at least: 6.8
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
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agents-registry.php';
require_once AGENTS_API_PATH . 'src/Registry/register-agents.php';
require_once AGENTS_API_PATH . 'src/Packages/register-agent-package-artifacts.php';
require_once AGENTS_API_PATH . 'src/Identity/AgentIdentityScope.php';
require_once AGENTS_API_PATH . 'src/Identity/MaterializedAgentIdentity.php';
require_once AGENTS_API_PATH . 'src/Identity/MaterializedAgentIdentityStoreInterface.php';
require_once AGENTS_API_PATH . 'src/Transcripts/ConversationTranscriptStoreInterface.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentMessageEnvelope.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentExecutionPrincipal.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationCompaction.php';
require_once AGENTS_API_PATH . 'src/Runtime/AgentConversationResult.php';
require_once AGENTS_API_PATH . 'src/Tools/RuntimeToolDeclaration.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryScope.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryListEntry.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryReadResult.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryWriteResult.php';
require_once AGENTS_API_PATH . 'src/Memory/AgentMemoryStoreInterface.php';

add_action( 'init', array( 'WP_Agents_Registry', 'init' ), 10 );
