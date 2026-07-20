# Docs Agent Workflow

Agents API publishes its canonical documentation updates through the repository-local Docs Agent consumer workflow in `.github/workflows/docs-agent.yml`. This page documents the consumer contract and the pinned native producer chain that `tests/docs-agent-native-workflow-contract.php` verifies.

## Consumer Workflow Contract

The consumer workflow is named **Docs Agent** and dispatches the reusable Docs Agent workflow from Automattic/docs-agent at the exact pinned revision:

```text
Automattic/docs-agent/.github/workflows/maintain-docs.yml@7b67d341eea16b75b8e055507d36a8d8e69a1fe3
```

The workflow contract is intentionally narrow:

- `audience: technical`
- `run_kind: maintenance`
- `base_ref: main`
- `docs_branch: docs-agent/agents-api-docs-upkeep-v2`
- `writable_paths: README.md,docs/**`
- `source_delta`: the satisfied documentation record for Agents API PR #422, bounded to the workflow run awaiter implementation and smoke test
- `verification_commands`: `composer install --no-interaction --prefer-dist --no-progress`, `composer test`, then `php tests/no-product-imports-smoke.php`
- `drift_checks`: `git diff --check`

The workflow prompt tells the technical lane to maintain source-grounded developer documentation for the public, product-neutral Agents API substrate. Documentation updates are expected to stay inside `README.md` and `docs/**`.

## Producer Chain

`tests/docs-agent-native-workflow-contract.php` validates the consumer against the native producer chain instead of only checking copied strings in this repository. Run it with:

```sh
DOCS_AGENT_DIR=/path/to/docs-agent WP_CODEBOX_DIR=/path/to/wp-codebox php tests/docs-agent-native-workflow-contract.php
```

The required producer checkouts are:

- Docs Agent revision `7b67d341eea16b75b8e055507d36a8d8e69a1fe3`.
- WP Codebox reusable-workflow and helper revision `a6fe2d208e990a8d04104aa74aacbb8d1539fbc1`; packaged runtime release `v0.12.29` resolves to `bc982947ec33c78160125026e16d357b7ece3ea1`.

At that Docs Agent revision, the `technical:maintenance` lane maps to the native package:

```text
bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json
```

with agent slug `technical-docs-maintenance-agent`, package-source revision `a39d9db230eb9e0b72ed84465f4d61bd8dda1bab`, package digest `sha256-bytes-v1:975c7b0a0a7aff52897c52be5ac903a7fb110ea3c33e16227f8694c74c932519`, and `lane_requires_pr=false`.

Docs Agent then calls WP Codebox's reusable workflow:

```text
Automattic/wp-codebox/.github/workflows/run-agent-task.yml@a6fe2d208e990a8d04104aa74aacbb8d1539fbc1
```

The contract test verifies that this WP Codebox producer exposes the `wp-codebox/reusable-workflow-interface/v1` schema and preserves the release, external-package, runtime-source, target repository, writable path, verification, drift-check, publication, access-repository, and allowed-repository chain. The host generates the completion report from the bounded `workflow-run-awaiter` source record, Git diff, and writable scope; because that source is now documented, a clean workspace is a valid no-change outcome. WP Codebox returns the reviewer-safe result projection and stages the generated completion report as a declared command artifact.

## Secrets And Publication Credentials

The consumer workflow forwards exactly these repository secrets to Docs Agent:

- `OPENAI_API_KEY`
- `EXTERNAL_PACKAGE_SOURCE_POLICY`

`ACCESS_TOKEN` is **not** configured by the Agents API consumer workflow and must not be added there. The native contract test asserts that the consumer does not reference `secrets.ACCESS_TOKEN` or define an `ACCESS_TOKEN:` secret mapping.

The producer chain still uses a publication token: at the pinned Docs Agent revision, Docs Agent forwards `ACCESS_TOKEN: ${{ github.token }}` to WP Codebox along with `OPENAI_API_KEY` and `EXTERNAL_PACKAGE_SOURCE_POLICY`. The consumer supports that built-in-token publication path by granting workflow permissions for `contents: write`, `pull-requests: write`, and `issues: write`.

## Verification

For normal documentation maintenance runs, the configured consumer verification is the same command chain in `.github/workflows/docs-agent.yml`:

```sh
composer install --no-interaction --prefer-dist --no-progress
composer test
php tests/no-product-imports-smoke.php
git diff --check
```

The native producer-chain contract is separate because it needs local checkouts of Automattic/docs-agent and Automattic/wp-codebox at the pinned revisions. Use `tests/docs-agent-native-workflow-contract.php` when changing the Docs Agent pin, WP Codebox pin, reusable workflow inputs, secret forwarding, writable paths, or publication branch.
