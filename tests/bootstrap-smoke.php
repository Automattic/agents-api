<?php
/**
 * Pure-PHP smoke test for the Agents API standalone plugin boundary.
 *
 * Run with: php tests/bootstrap-smoke.php
 *
 * @package AgentsAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

echo "agents-api-bootstrap-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

$namespace_map = array(
	'DataMachine\\Engine\\AI\\WP_Agent_Message'                         => 'AgentsAPI\\AI\\WP_Agent_Message',
	'DataMachine\\Engine\\AI\\WP_Agent_Execution_Principal'                      => 'AgentsAPI\\AI\\WP_Agent_Execution_Principal',
	'DataMachine\\Engine\\AI\\WP_Agent_Conversation_Request'                    => 'AgentsAPI\\AI\\WP_Agent_Conversation_Request',
	'DataMachine\\Engine\\AI\\WP_Agent_Conversation_Runner'            => 'AgentsAPI\\AI\\WP_Agent_Conversation_Runner',
	'DataMachine\\Engine\\AI\\WP_Agent_Conversation_Completion_Decision'         => 'AgentsAPI\\AI\\WP_Agent_Conversation_Completion_Decision',
	'DataMachine\\Engine\\AI\\WP_Agent_Conversation_Completion_Policy'  => 'AgentsAPI\\AI\\WP_Agent_Conversation_Completion_Policy',
	'DataMachine\\Engine\\AI\\WP_Agent_Transcript_Persister' => 'AgentsAPI\\AI\\WP_Agent_Transcript_Persister',
	'DataMachine\\Engine\\AI\\WP_Agent_Null_Transcript_Persister'     => 'AgentsAPI\\AI\\WP_Agent_Null_Transcript_Persister',
	'DataMachine\\Engine\\AI\\WP_Agent_Conversation_Compaction'                  => 'AgentsAPI\\AI\\WP_Agent_Conversation_Compaction',
	'DataMachine\\Engine\\AI\\WP_Agent_Conversation_Result'                      => 'AgentsAPI\\AI\\WP_Agent_Conversation_Result',
	'DataMachine\\Core\\Database\\Chat\\WP_Agent_Conversation_Lock' => 'AgentsAPI\\Core\\Database\\Chat\\WP_Agent_Conversation_Lock',
	'DataMachine\\Engine\\AI\\Tools\\WP_Agent_Tool_Declaration'                => 'AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Declaration',
	'DataMachine\\Engine\\AI\\Tools\\WP_Agent_Tool_Call'                              => 'AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Call',
	'DataMachine\\Engine\\AI\\Tools\\WP_Agent_Tool_Parameters'                         => 'AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Parameters',
	'DataMachine\\Engine\\AI\\Tools\\Execution\\WP_Agent_Tool_Execution_Core'           => 'AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Execution_Core',
	'DataMachine\\Engine\\AI\\Tools\\Execution\\WP_Agent_Tool_Executor'      => 'AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Executor',
	'DataMachine\\Engine\\AI\\Tools\\WP_Agent_Tool_Source_Registry'                     => 'AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Source_Registry',
	'DataMachine\\Core\\Database\\Chat\\WP_Agent_Conversation_Store' => 'AgentsAPI\\Core\\Database\\Chat\\WP_Agent_Conversation_Store',
	'DataMachine\\Core\\Identity\\WP_Agent_Identity_Scope'                         => 'AgentsAPI\\Core\\Identity\\WP_Agent_Identity_Scope',
	'DataMachine\\Core\\Identity\\WP_Agent_Materialized_Identity'                  => 'AgentsAPI\\Core\\Identity\\WP_Agent_Materialized_Identity',
	'DataMachine\\Core\\Identity\\WP_Agent_Identity_Store'     => 'AgentsAPI\\Core\\Identity\\WP_Agent_Identity_Store',
	'DataMachine\\Core\\Workspace\\WP_Agent_Workspace_Scope'                       => 'AgentsAPI\\Core\\Workspace\\WP_Agent_Workspace_Scope',
	'DataMachine\\Core\\FilesRepository\\WP_Agent_Memory_Store'           => 'AgentsAPI\\Core\\FilesRepository\\WP_Agent_Memory_Store',
	'DataMachine\\Core\\FilesRepository\\WP_Agent_Memory_Scope'                    => 'AgentsAPI\\Core\\FilesRepository\\WP_Agent_Memory_Scope',
	'DataMachine\\Core\\FilesRepository\\WP_Agent_Memory_Metadata'                 => 'AgentsAPI\\Core\\FilesRepository\\WP_Agent_Memory_Metadata',
	'DataMachine\\Core\\FilesRepository\\WP_Agent_Memory_Query'                    => 'AgentsAPI\\Core\\FilesRepository\\WP_Agent_Memory_Query',
	'DataMachine\\Core\\FilesRepository\\WP_Agent_Memory_Store_Capabilities'        => 'AgentsAPI\\Core\\FilesRepository\\WP_Agent_Memory_Store_Capabilities',
	'DataMachine\\Core\\FilesRepository\\WP_Agent_Memory_Validator'        => 'AgentsAPI\\Core\\FilesRepository\\WP_Agent_Memory_Validator',
);

$context_contracts = array(
	'AgentsAPI\\AI\\Context\\WP_Agent_Context_Authority_Tier',
	'AgentsAPI\\AI\\Context\\WP_Agent_Context_Conflict_Kind',
	'AgentsAPI\\AI\\Context\\WP_Agent_Context_Item',
	'AgentsAPI\\AI\\Context\\WP_Agent_Context_Conflict_Resolution',
	'AgentsAPI\\AI\\Context\\WP_Agent_Context_Conflict_Resolver',
	'AgentsAPI\\AI\\Context\\WP_Agent_Default_Context_Conflict_Resolver',
);

echo "\n[1] Module bootstrap exposes registration facade without Data Machine product code:\n";
agents_api_smoke_assert_equals( true, defined( 'AGENTS_API_LOADED' ), 'module marks itself loaded', $failures, $passes );
agents_api_smoke_assert_equals( true, defined( 'AGENTS_API_PATH' ), 'module path constant is available', $failures, $passes );
agents_api_smoke_assert_equals( realpath( __DIR__ . '/..' ) . '/', AGENTS_API_PATH, 'module path points at plugin root directory', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_register_agent' ), 'wp_register_agent helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_get_agent' ), 'wp_get_agent helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_get_agents' ), 'wp_get_agents helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_has_agent' ), 'wp_has_agent helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_unregister_agent' ), 'wp_unregister_agent helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_register_agent_package_artifact_type' ), 'wp_register_agent_package_artifact_type helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_get_agent_package_artifact_type' ), 'wp_get_agent_package_artifact_type helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_get_agent_package_artifact_types' ), 'wp_get_agent_package_artifact_types helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_has_agent_package_artifact_type' ), 'wp_has_agent_package_artifact_type helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_unregister_agent_package_artifact_type' ), 'wp_unregister_agent_package_artifact_type helper is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent' ), 'WP_Agent value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agents_Registry' ), 'WP_Agents_Registry facade is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Package_Artifact' ), 'WP_Agent_Package_Artifact value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Package_Artifact_Type' ), 'WP_Agent_Package_Artifact_Type value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Package_Artifacts_Registry' ), 'WP_Agent_Package_Artifacts_Registry facade is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Access_Grant' ), 'WP_Agent_Access_Grant value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Access_Store' ), 'WP_Agent_Access_Store contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Token' ), 'WP_Agent_Token value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Token_Store' ), 'WP_Agent_Token_Store contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Token_Authenticator' ), 'WP_Agent_Token_Authenticator service is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Authorization_Policy' ), 'WP_Agent_Authorization_Policy contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_WordPress_Authorization_Policy' ), 'WP_Agent_WordPress_Authorization_Policy service is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Capability_Ceiling' ), 'WP_Agent_Capability_Ceiling value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Memory_Registry' ), 'WP_Agent_Memory_Registry facade is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Memory_Layer' ), 'WP_Agent_Memory_Layer vocabulary is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Context_Section_Registry' ), 'WP_Agent_Context_Section_Registry facade is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Context_Injection_Policy' ), 'WP_Agent_Context_Injection_Policy vocabulary is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Composable_Context' ), 'WP_Agent_Composable_Context value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, defined( 'AGENTS_API_PLUGIN_FILE' ), 'plugin file constant is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\WP_Agent_Markdown_Section_Compaction_Adapter' ), 'AgentsAPI\\AI\\WP_Agent_Markdown_Section_Compaction_Adapter contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\WP_Agent_Conversation_Loop' ), 'WP_Agent_Conversation_Loop facade is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Store' ), 'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Store contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Status' ), 'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Status vocabulary is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Consent_Policy' ), 'WP_Agent_Consent_Policy contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Default_Consent_Policy' ), 'WP_Agent_Default_Consent_Policy implementation is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\Consent\\WP_Agent_Consent_Operation' ), 'WP_Agent_Consent_Operation vocabulary is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\\AI\\Consent\\WP_Agent_Consent_Decision' ), 'WP_Agent_Consent_Decision value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Tool_Policy' ), 'WP_Agent_Tool_Policy contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Tool_Policy_Filter' ), 'WP_Agent_Tool_Policy_Filter contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Tool_Access_Policy' ), 'WP_Agent_Tool_Access_Policy contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'WP_Agent_Action_Policy_Resolver' ), 'WP_Agent_Action_Policy_Resolver contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'WP_Agent_Action_Policy_Provider' ), 'WP_Agent_Action_Policy_Provider contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\AI\Channels\WP_Agent_External_Message' ), 'WP_Agent_External_Message value object is available', $failures, $passes );
agents_api_smoke_assert_equals( true, interface_exists( 'AgentsAPI\AI\Channels\WP_Agent_Channel_Session_Store' ), 'WP_Agent_Channel_Session_Store contract is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\AI\Channels\WP_Agent_Option_Channel_Session_Store' ), 'WP_Agent_Option_Channel_Session_Store implementation is available', $failures, $passes );
agents_api_smoke_assert_equals( true, class_exists( 'AgentsAPI\AI\Channels\WP_Agent_Channel_Session_Map' ), 'WP_Agent_Channel_Session_Map facade is available', $failures, $passes );
foreach ( $namespace_map as $legacy_class => $target_class ) {
	agents_api_smoke_assert_equals( true, class_exists( $target_class ) || interface_exists( $target_class ), $target_class . ' contract is available', $failures, $passes );
	agents_api_smoke_assert_equals( false, class_exists( $legacy_class, false ) || interface_exists( $legacy_class, false ), $legacy_class . ' compatibility alias is not loaded', $failures, $passes );
}
foreach ( $context_contracts as $context_contract ) {
	agents_api_smoke_assert_equals( true, class_exists( $context_contract ) || interface_exists( $context_contract ), $context_contract . ' contract is available', $failures, $passes );
}
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Engine\\Agents\\AgentRegistry', false ), 'Data Machine registry is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Core\\Database\\Jobs\\Jobs', false ), 'Data Machine jobs repository is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Engine\\AI\\AIConversationLoop', false ), 'Data Machine compatibility loop is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Engine\\AI\\BuiltInAgentConversationRunner', false ), 'Data Machine built-in runner is not loaded by module bootstrap', $failures, $passes );
agents_api_smoke_assert_equals( false, class_exists( 'DataMachine\\Core\\FilesRepository\\DiskAgentMemoryStore', false ), 'Data Machine disk memory store is not loaded by module bootstrap', $failures, $passes );

echo "\n[2] Module source keeps Data Machine vocabulary out of agents-api contracts:\n";
$agents_api_files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( AGENTS_API_PATH . 'src', FilesystemIterator::SKIP_DOTS )
);
foreach ( $agents_api_files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	$contents = file_get_contents( $file->getPathname() );
	agents_api_smoke_assert_equals(
		0,
		preg_match( '/^\s*(namespace|use)\s+DataMachine\\\\/m', is_string( $contents ) ? $contents : '' ),
		'agents-api source has no Data Machine namespace declaration/import: ' . str_replace( AGENTS_API_PATH, '', $file->getPathname() ),
		$failures,
		$passes
	);
	agents_api_smoke_assert_equals(
		false,
		false !== strpos( is_string( $contents ) ? $contents : '', 'Data Machine' ),
		'agents-api source has no Data Machine prose coupling: ' . str_replace( AGENTS_API_PATH, '', $file->getPathname() ),
		$failures,
		$passes
	);
}

$bootstrap_source = (string) file_get_contents( AGENTS_API_PLUGIN_FILE );
agents_api_smoke_assert_equals( 0, preg_match( '/^\s*(namespace|use)\s+DataMachine\\\\/m', $bootstrap_source ), 'plugin bootstrap has no Data Machine namespace declaration/import', $failures, $passes );
agents_api_smoke_assert_equals( false, false !== strpos( $bootstrap_source, 'Data Machine' ), 'plugin bootstrap has no Data Machine prose coupling', $failures, $passes );

echo "\n[3] Module source tree uses Agents API vocabulary:\n";
$expected_source_directories = array(
	'Approvals',
	'Auth',
	'Channels',
	'Consent',
	'Context',
	'Guidelines',
	'Identity',
	'Memory',
	'Packages',
	'Registry',
	'Runtime',
	'Tools',
	'Transcripts',
	'Workspace',
);
$actual_source_directories   = array();
$source_directory_iterator   = new DirectoryIterator( AGENTS_API_PATH . 'src' );
foreach ( $source_directory_iterator as $source_directory ) {
	if ( $source_directory->isDir() && ! $source_directory->isDot() ) {
		$actual_source_directories[] = $source_directory->getFilename();
	}
}
sort( $actual_source_directories );

agents_api_smoke_assert_equals( $expected_source_directories, $actual_source_directories, 'module source directories are agent-native', $failures, $passes );
agents_api_smoke_assert_equals( false, is_dir( AGENTS_API_PATH . 'inc/Core' ), 'copied inc/Core tree is absent', $failures, $passes );
agents_api_smoke_assert_equals( false, is_dir( AGENTS_API_PATH . 'inc/AI' ), 'copied inc/AI tree is absent', $failures, $passes );
agents_api_smoke_assert_equals( false, is_dir( AGENTS_API_PATH . 'inc' ), 'legacy inc source root is absent', $failures, $passes );

echo "\n[4] Module owns the generic guideline substrate polyfill:\n";
agents_api_smoke_assert_equals( true, class_exists( 'WP_Guidelines_Substrate' ), 'guideline substrate class is available', $failures, $passes );
agents_api_smoke_assert_equals( true, function_exists( 'wp_guideline_types' ), 'wp_guideline_types helper is available', $failures, $passes );

do_action( 'init' );
agents_api_smoke_assert_equals( true, post_type_exists( 'wp_guideline' ), 'wp_guideline post type is registered on init', $failures, $passes );
agents_api_smoke_assert_equals( true, taxonomy_exists( 'wp_guideline_type' ), 'wp_guideline_type taxonomy is registered on init', $failures, $passes );
agents_api_smoke_assert_equals( 'guidelines', $GLOBALS['__agents_api_smoke_post_types']['wp_guideline']['rest_base'] ?? null, 'guideline post type exposes the shared REST base', $failures, $passes );
agents_api_smoke_assert_equals( array( 'artifact', 'content' ), array_keys( wp_guideline_types() ), 'default guideline types match the shared substrate', $failures, $passes );

agents_api_smoke_finish( 'Agents API bootstrap', $failures, $passes );
