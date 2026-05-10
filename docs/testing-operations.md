# Testing, CI, and Operational Workflows

Agents API is tested through focused PHP smoke tests. The tests exercise contract behavior without requiring a full product runtime, provider SDK, or persistent production store.

## Requirements

The package requires PHP 8.1 or newer. Action Scheduler is suggested, not required. Workflow and routine bridges detect Action Scheduler at runtime and no-op cleanly when unavailable.

## Composer test suite

Run the full smoke suite with:

```bash
composer test
```

The script runs tests for bootstrap, message envelopes, registry, execution principals, effective agent resolution, caller context, authorization, action policy, consent, tool policy, tool runtime, pending actions, approvals, identity, memory metadata, workspace scope, compaction, conversation runner/loop behavior, channels, webhook safety, remote bridge, context authority, guidelines, workflows, routines, subagents, and product-boundary checks.

## CI

Repository CI runs Homeboy lint and test jobs. Docs Agent workflow changes are validated by those same CI jobs plus targeted script checks when edited locally.

## Docs Agent workflow

`.github/workflows/docs-agent.yml` provides manual `workflow_dispatch` documentation maintenance. It checks out Agents API plus Docs Agent, Homeboy, Homeboy Extensions, Data Machine, Data Machine Code, and the OpenAI provider. The workflow imports the reusable Docs Agent bundle and runs the selected docs flow.

The consumer wrapper constrains writes to `README.md` and `docs/**`. Bootstrap runs require a PR and force a predictable docs branch. After a bootstrap PR opens, `tests/playground-ci/scripts/validate-docs-links.php` validates relative Markdown links against the generated branch tree so incomplete scaffolds with missing linked pages fail mechanically.

## Generated docs validation

The link validator checks Markdown files under `README.md` and `docs/**`. It ignores external URLs, anchors, absolute paths, and protocol links. Relative links must resolve to a file in the generated Git tree, with support for exact targets, `.md` inference, and `README.md` directory targets.

## Operational boundaries

Agents API is substrate code. Operationally, that means:

- provider calls belong to `wp-ai-client` or consumer adapters.
- product storage, queues, dashboards, settings, and analytics live outside this repository.
- scheduled workflows and routines require a scheduler such as Action Scheduler or a custom listener.
- bridge delivery is best-effort until acked by the client.
- auth, token, caller-chain, schema, and value-object validation fail closed.
- observer failures are swallowed when observation must not affect runtime execution.

## Product-boundary guard

`tests/no-product-imports-smoke.php` protects the package boundary by checking that product-specific imports do not leak into the substrate. Keep generic contracts generic and add product behavior in consumers.

## Related pages

- [Architecture overview](architecture.md)
- [Bootstrap and lifecycle](bootstrap-lifecycle.md)
- [Extension points and public surface](extension-points.md)
