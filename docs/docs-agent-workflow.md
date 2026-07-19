# Docs Agent Workflow

Agents API publishes its canonical documentation updates through the repository-local Docs Agent consumer workflow in `.github/workflows/docs-agent.yml`. This page documents the consumer contract and the pinned native producer chain that `tests/docs-agent-native-workflow-contract.php` verifies.

## Consumer Workflow Contract

The consumer workflow is named **Docs Agent** and dispatches the reusable Docs Agent workflow from Automattic/docs-agent at the exact pinned revision:

```text
Automattic/docs-agent/.github/workflows/maintain-docs.yml@06a7e92e0f4d265d09bbdb6dae1ec78fd8e7c825
```

The workflow contract is intentionally narrow:

- `audience: technical`
- `run_kind: maintenance`
- `base_ref: main`
- `docs_branch: docs-agent/agents-api-docs-upkeep`
- `writable_paths: README.md,docs/**`
- `verification_commands`: `composer test`, then `php tests/no-product-imports-smoke.php`
- `drift_checks`: `git diff --check`

The workflow prompt tells the technical lane to maintain source-grounded developer documentation for the public, product-neutral Agents API substrate. Documentation updates are expected to stay inside `README.md` and `docs/**`.

## Producer Chain

`tests/docs-agent-native-workflow-contract.php` validates the consumer against the native producer chain instead of only checking copied strings in this repository. Run it with:

```sh
DOCS_AGENT_DIR=/path/to/docs-agent WP_CODEBOX_DIR=/path/to/wp-codebox php tests/docs-agent-native-workflow-contract.php
```

The required producer checkouts are:

- Docs Agent revision `06a7e92e0f4d265d09bbdb6dae1ec78fd8e7c825`.
- WP Codebox ref `v0.12.29`, revision `bc982947ec33c78160125026e16d357b7ece3ea1`.

At that Docs Agent revision, the `technical:maintenance` lane maps to the native package:

```text
bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json
```

with agent slug `technical-docs-maintenance-agent`, package-source revision `85443eb91c12b2759d8e207f1ae4421407b4cc5e`, package digest `sha256-bytes-v1:78fef9f8d787866c7b48b8f044769d38c0528778c8e2a82af816f9f8ea65014f`, and `lane_requires_pr=false`.

Docs Agent then calls WP Codebox's reusable workflow:

```text
Automattic/wp-codebox/.github/workflows/run-agent-task.yml@v0.12.29
```

The contract test verifies that this WP Codebox producer exposes the `wp-codebox/reusable-workflow-interface/v1` schema and preserves the release, external-package, runtime-source, target repository, writable path, verification, drift-check, publication, access-repository, and allowed-repository chain. In that producer path, WP Codebox v0.12.29 runs the agent task and returns the reviewer-safe result projection used by the reusable workflow publication path. Successful publication verification returns `{ valid: true }` without a failure-only `error`; repository mismatches retain their exact diagnostic.

## Secrets And Publication Credentials

The consumer workflow forwards exactly these repository secrets to Docs Agent:

- `OPENAI_API_KEY`
- `EXTERNAL_PACKAGE_SOURCE_POLICY`

`ACCESS_TOKEN` is **not** configured by the Agents API consumer workflow and must not be added there. The native contract test asserts that the consumer does not reference `secrets.ACCESS_TOKEN` or define an `ACCESS_TOKEN:` secret mapping.

The producer chain still uses a publication token: at the pinned Docs Agent revision, Docs Agent forwards `ACCESS_TOKEN: ${{ github.token }}` to WP Codebox along with `OPENAI_API_KEY` and `EXTERNAL_PACKAGE_SOURCE_POLICY`. The consumer supports that built-in-token publication path by granting workflow permissions for `contents: write`, `pull-requests: write`, and `issues: write`.

## Verification

For normal documentation maintenance runs, the configured consumer verification is the same command chain in `.github/workflows/docs-agent.yml`:

```sh
composer test
php tests/no-product-imports-smoke.php
git diff --check
```

The native producer-chain contract is separate because it needs local checkouts of Automattic/docs-agent and Automattic/wp-codebox at the pinned revisions. Use `tests/docs-agent-native-workflow-contract.php` when changing the Docs Agent pin, WP Codebox pin, reusable workflow inputs, secret forwarding, writable paths, or publication branch.
