# Agents API

WordPress-shaped backend substrate for durable agent runtime behavior.

Agents API is maintained by Automattic as a standalone WordPress substrate package.

Agents API owns generic contracts and value objects for registering agents, describing runtime messages/results, declaring package artifacts, and persisting memory/transcripts. Product plugins own UI, workflows, orchestration, and domain behavior.

## Boundary

```text
Abilities API  -> actions and tools
wp-ai-client   -> provider/model prompt execution
Agents API     -> durable agent runtime behavior
Data Machine   -> automation product built on those substrates
```

Agents API v1 is intentionally backend-only:

- no admin pages
- no REST controllers
- no Data Machine flows, pipelines, jobs, handlers, queues, retention, pending actions, or content operations
- no Intelligence wiki/briefing/domain-brain vocabulary

## Public Surface

- `wp_agents_api_init`
- `wp_register_agent()` / `wp_get_agent()` / `wp_get_agents()` / `wp_has_agent()` / `wp_unregister_agent()`
- `WP_Agent`
- `WP_Agents_Registry`
- `WP_Agent_Package*` value objects and artifact registry helpers
- `AgentsAPI\AI\AgentMessageEnvelope`
- `AgentsAPI\AI\AgentConversationResult`
- `AgentsAPI\AI\Tools\RuntimeToolDeclaration`
- `AgentsAPI\Core\Database\Chat\ConversationTranscriptStoreInterface`
- `AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface` and memory value objects

## Tests

```bash
composer test
```
