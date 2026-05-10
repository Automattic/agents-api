# Bootstrap and Lifecycle

Agents API boots as a regular WordPress plugin from `agents-api.php`. The bootstrap file is deliberately explicit: it defines plugin constants, requires each source file, and wires only the shared registration hooks that the substrate owns.

## Plugin load guard and constants

`agents-api.php` starts with the usual WordPress guard and exits if `ABSPATH` is not defined. It then prevents double loading with `AGENTS_API_LOADED` and defines:

- `AGENTS_API_LOADED` — boolean load sentinel.
- `AGENTS_API_PATH` — absolute plugin directory path with trailing slash.
- `AGENTS_API_PLUGIN_FILE` — plugin main file path.

The file then `require_once`s modules in dependency order. Value objects and interfaces are loaded before modules that reference them. The source tree is not autoloaded through Composer; the plugin bootstrap owns class availability.

## Init-time hooks

The bootstrap attaches two init callbacks:

```php
add_action( 'init', array( 'WP_Agents_Registry', 'init' ), 10 );
add_action( 'init', array( 'WP_Guidelines_Substrate', 'register' ), 9 );
```

That means the optional guideline substrate registers before the agent registry fires its public registration window.

## Agent registration lifecycle

Agent definitions are registered during `wp_agents_api_init`:

```php
add_action(
    'wp_agents_api_init',
    static function () {
        wp_register_agent( 'example-agent', array( 'label' => 'Example Agent' ) );
    }
);
```

`WP_Agents_Registry::init()` creates the singleton, marks the registry initialized, and fires `wp_agents_api_init`. `wp_register_agent()` enforces that callers are inside that action. Reads such as `wp_get_agent()`, `wp_get_agents()`, and `wp_has_agent()` resolve the registry after WordPress `init` has fired.

## Ability registration lifecycle

Agents API registers canonical Abilities API entries from two modules:

- `src/Channels/register-agents-chat-ability.php` registers `agents/chat`.
- `src/Workflows/register-agents-workflow-abilities.php` registers `agents/run-workflow`, `agents/validate-workflow`, and `agents/describe-workflow`.

Both files hook `wp_abilities_api_categories_init` to register an `agents-api` category if missing, then hook `wp_abilities_api_init` to register ability definitions. The ability execute callbacks are dispatchers; concrete runtimes are provided by filters:

- `wp_agent_chat_handler` for `agents/chat`.
- `wp_agent_workflow_handler` for `agents/run-workflow`.

## Optional Action Scheduler integrations

Workflow and routine scheduling are optional. `composer.json` suggests `woocommerce/action-scheduler`, but the plugin does not require it. The bridge classes detect the `as_*` functions at runtime and no-op cleanly when Action Scheduler is unavailable:

- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Bridge`
- `AgentsAPI\AI\Routines\WP_Agent_Routine_Action_Scheduler_Bridge`

Both bridges fire schedule-requested WordPress actions so custom schedulers can observe or replace Action Scheduler behavior.

## Guideline substrate lifecycle

`WP_Guidelines_Substrate::register()` registers the `wp_guideline` post type and `wp_guideline_type` taxonomy only when enabled and not already provided upstream. Hosts can disable the polyfill with:

```php
add_filter( 'wp_guidelines_substrate_enabled', '__return_false' );
```

The substrate adds save, term-label, and `map_meta_cap` hooks used by the guideline helpers in `src/Guidelines/guidelines.php`.

## Test lifecycle

The Composer `test` script runs pure PHP smoke files in `tests/**`. Tests include small WordPress shims where needed and exercise value-object validation, registry lifecycle, ability dispatch contracts, workflow/routine behavior, tool policy, authorization, channels, context, memory metadata, and bootstrap behavior.

## Related pages

- [Architecture overview](architecture.md)
- [Registry, agents, packages, and artifacts](registry-packages.md)
- [Testing, CI, and operational workflows](testing-operations.md)
