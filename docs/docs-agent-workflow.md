# Docs Agent Workflow

This guide documents the repository-native Docs Agent workflow in `.github/workflows/docs-agent.yml`. It is intended for maintainers who run or review automated documentation upkeep for Agents API.

## Purpose

The workflow keeps developer-facing documentation aligned with the public, product-neutral Agents API substrate. Its default prompt tells the agent to "Generate or update Agents API developer documentation from repository source" and to open a documentation pull request only when changes are needed. The maintained scope is developer documentation for contracts, setup, tests, and contributor workflows, grounded in repository source rather than product-specific imports or downstream application behavior.

## Manual trigger

`docs-agent.yml` is manual-only: it is registered under `workflow_dispatch` and does not run on push or pull request events. Maintainers can optionally pass a `prompt` input when dispatching the workflow. That input is appended to the built-in maintenance task; when it is omitted, the default instruction asks for source-grounded Agents API developer documentation and pull-request publication only when changes are needed.

The workflow also sets a repository-scoped concurrency group, `docs-agent-${{ github.repository }}`, with `cancel-in-progress: false`. This prevents overlapping Docs Agent maintenance runs from racing each other while allowing an already-running documentation pass to finish.

## Reusable workflow pin

The job delegates execution to the reusable Docs Agent producer workflow:

```yaml
uses: Automattic/docs-agent/.github/workflows/maintain-docs.yml@662ebbfcfe929673fd8b260c685fbc9dc5807842
```

That full commit SHA is the immutable producer pin for this repository. The CI workflow checks out the same `Automattic/docs-agent` revision and runs `php tests/docs-agent-native-workflow-contract.php` to validate that the consumer workflow still points at the expected producer chain. The contract test names the expected Docs Agent revision as `662ebbfcfe929673fd8b260c685fbc9dc5807842` and verifies that the pinned producer preserves the technical maintenance lane, package digest, WP Codebox handoff, writable-path forwarding, verification forwarding, drift-check forwarding, publication behavior, and credential chain.

## Maintenance lane and target branch

The workflow passes these lane settings to the reusable workflow:

- `audience: technical`
- `run_kind: maintenance`
- `base_ref: main`
- `docs_branch: docs-agent/agents-api-docs-upkeep`

Together, these settings mean a run performs technical documentation maintenance against `main` and publishes documentation changes from the stable branch `docs-agent/agents-api-docs-upkeep`. The same branch name is asserted by `tests/docs-agent-native-workflow-contract.php`, so changing it requires updating the source workflow and the contract test together.

## Writable documentation boundary

The reusable workflow receives a narrow writable boundary:

```yaml
writable_paths: README.md,docs/**
```

The boundary is intentionally limited to the repository documentation surfaces used by Agents API: the top-level `README.md` and the topic-oriented `docs/` tree. The contract test verifies this exact value. Source files, tests, workflow files, and release metadata are evidence for documentation updates, but they are outside the Docs Agent write boundary for this workflow.

## Verification and drift checks

The workflow forwards two verification commands:

```json
[
  "composer test",
  "php tests/no-product-imports-smoke.php"
]
```

`composer test` expands to the smoke-test suite declared in `composer.json`, covering the public substrate contracts across registry, packages, runtime, tools, auth, consent, context, memory, channels, workflows, routines, approvals, transcripts, remote bridge behavior, and product-boundary checks. The explicit `php tests/no-product-imports-smoke.php` command re-runs the product-neutral boundary check that prevents repository code from depending on product-specific imports.

The workflow also forwards one drift check:

```json
[
  "git diff --check"
]
```

That check catches whitespace errors in the documentation diff before publication. `tests/docs-agent-native-workflow-contract.php` asserts that the verification command list includes the test chain and that the drift check includes `git diff --check`.

## Permissions and secrets

The workflow grants `contents: write`, `pull-requests: write`, and `issues: write`. Those permissions support built-in-token documentation publication by the reusable workflow. The consumer workflow forwards `[REDACTED:configured-secret-name]` and `EXTERNAL_PACKAGE_SOURCE_POLICY`; it does not require or forward an `ACCESS_TOKEN`. The contract test checks both the expected permissions and the absence of an `ACCESS_TOKEN` secret in the consumer workflow.

## Contributor workflow

When you need a documentation maintenance pass:

1. Open the **Docs Agent** workflow in GitHub Actions and choose **Run workflow**.
2. Leave the optional prompt empty for the standard maintenance task, or add a focused instruction such as a changed API, module, or workflow that needs source-grounded coverage.
3. Review the resulting pull request from `docs-agent/agents-api-docs-upkeep` when the run finds changes. If the run reports no changes, no pull request is expected.
4. Check that any generated documentation stays inside `README.md` or `docs/**`, links into the existing docs index, and cites repository-native source behavior rather than downstream product assumptions.
5. Expect the workflow verification commands and `git diff --check` drift check to pass before merging.

For local validation of the workflow contract itself, CI runs `php tests/docs-agent-native-workflow-contract.php` with `DOCS_AGENT_DIR` and `WP_CODEBOX_DIR` pointing at the pinned producer checkouts. Run that test locally only when you have those producer repositories checked out at the revisions named in the test file.
