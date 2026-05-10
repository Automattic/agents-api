#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
COMPONENT_PATH="$REPO_ROOT/tests/playground-ci/component"

EXTENSION_PATH="${HOMEBOY_EXTENSION_PATH:-/Users/chubes/Developer/homeboy-extensions/wordpress}"
AGENTS_API_PATH="${AGENTS_API_PATH:-$REPO_ROOT}"
DM_PATH="${DM_PATH:-/Users/chubes/Developer/data-machine}"
DMC_PATH="${DMC_PATH:-/Users/chubes/Developer/data-machine-code}"
OPENAI_PROVIDER_PATH="${OPENAI_PROVIDER_PATH:-/Users/chubes/Studio/intelligence-chubes4/wp-content/plugins/ai-provider-for-openai}"
STUDIO_SITE_PATH="${STUDIO_SITE_PATH:-/Users/chubes/Studio/intelligence-chubes4}"
DOCS_AGENT_OPENAI_MODEL="${DOCS_AGENT_OPENAI_MODEL:-gpt-5.5}"
DOCS_AGENT_TARGET_REPO="${DOCS_AGENT_TARGET_REPO:-Automattic/agents-api}"
DOCS_AGENT_REF="${DOCS_AGENT_REF:-main}"
DOCS_AGENT_PROMPT="${DOCS_AGENT_PROMPT:-Inspect Agents API docs and open a documentation PR only if needed.}"

if [ ! -f "$EXTENSION_PATH/scripts/bench/bench-runner.sh" ]; then
    echo "ERROR: Homeboy WordPress extension not found at $EXTENSION_PATH" >&2
    exit 1
fi
if [ ! -d "$AGENTS_API_PATH" ] || [ ! -d "$DM_PATH" ] || [ ! -d "$DMC_PATH" ]; then
    echo "ERROR: Agents API, Data Machine, and Data Machine Code checkouts are required" >&2
    exit 1
fi
if [ ! -d "$OPENAI_PROVIDER_PATH" ]; then
    echo "ERROR: AI Provider for OpenAI plugin not found at $OPENAI_PROVIDER_PATH" >&2
    exit 1
fi
if ! command -v jq >/dev/null 2>&1; then
    echo "ERROR: jq required" >&2
    exit 1
fi

GITHUB_TOKEN="${GITHUB_TOKEN:-${GH_TOKEN:-}}"
if [ -z "$GITHUB_TOKEN" ] && command -v gh >/dev/null 2>&1; then
    GITHUB_TOKEN="$(gh auth token 2>/dev/null || true)"
fi
if [ -z "$GITHUB_TOKEN" ]; then
    echo "ERROR: GITHUB_TOKEN or GH_TOKEN is required, or gh must be authenticated" >&2
    exit 1
fi

OPENAI_API_KEY="${OPENAI_API_KEY:-}"
if [ -z "$OPENAI_API_KEY" ] && command -v studio >/dev/null 2>&1 && [ -d "$STUDIO_SITE_PATH" ]; then
    OPENAI_API_KEY="$(cd "$STUDIO_SITE_PATH" && studio wp option get connectors_ai_openai_api_key 2>/dev/null || true)"
fi
if [ -z "$OPENAI_API_KEY" ]; then
    echo "ERROR: OPENAI_API_KEY is required, or the local Studio site must store connectors_ai_openai_api_key" >&2
    exit 1
fi

CONFIG_TMPFILE=$(mktemp "${TMPDIR:-/tmp}/docs-agent-config.XXXXXX.json")
RESULTS_TMPFILE=$(mktemp "${TMPDIR:-/tmp}/docs-agent-results.XXXXXX.json")
TRANSCRIPT_ARTIFACT_DIR="$COMPONENT_PATH/artifacts/docs-agent"

cleanup() {
    rm -f "$CONFIG_TMPFILE" "$RESULTS_TMPFILE"
}
trap cleanup EXIT

rm -rf "$TRANSCRIPT_ARTIFACT_DIR"
mkdir -p "$TRANSCRIPT_ARTIFACT_DIR"

HE_AGENT_RUNNER="$EXTENSION_PATH/scripts/agent/run-datamachine-agent.sh"
if [ ! -f "$HE_AGENT_RUNNER" ]; then
    echo "ERROR: generic Data Machine agent runner not found at $HE_AGENT_RUNNER" >&2
    exit 1
fi

EVENT_NAME="${GITHUB_EVENT_NAME:-manual}"
TARGET_REF="${GITHUB_REF_NAME:-main}"
HEAD_SHA="${GITHUB_SHA:-}"
RUN_URL=""
if [ -n "${GITHUB_SERVER_URL:-}" ] && [ -n "${GITHUB_REPOSITORY:-}" ] && [ -n "${GITHUB_RUN_ID:-}" ]; then
    RUN_URL="${GITHUB_SERVER_URL}/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}"
fi

PROMPT=$(cat <<EOF
${DOCS_AGENT_PROMPT}

Target repository: ${DOCS_AGENT_TARGET_REPO}
Event name: ${EVENT_NAME}
Target ref: ${TARGET_REF}
Head SHA: ${HEAD_SHA}
Workflow run: ${RUN_URL}
Writable documentation paths: README.md, docs/**

Inspect repository docs and changed context. If no documentation update is needed, finish cleanly without opening a pull request. If an update is needed, write only allowed documentation paths and open one pull request.
EOF
)

jq -n \
    --arg componentPath "$COMPONENT_PATH" \
    --arg agentsApi "$AGENTS_API_PATH" \
    --arg dm "$DM_PATH" \
    --arg dmc "$DMC_PATH" \
    --arg openaiProvider "$OPENAI_PROVIDER_PATH" \
    --arg githubToken "$GITHUB_TOKEN" \
    --arg openaiKey "$OPENAI_API_KEY" \
    --arg model "$DOCS_AGENT_OPENAI_MODEL" \
    --arg targetRepo "$DOCS_AGENT_TARGET_REPO" \
    --arg docsAgentRef "$DOCS_AGENT_REF" \
    --arg prompt "$PROMPT" \
    '{
        component_id: "agents-api-docs-agent-ci-driver",
        component_path: $componentPath,
        workload_id: "docs-agent-maintenance",
        workload_label: "Run Docs Agent",
        validation_dependencies: [$agentsApi, $dm, $dmc, $openaiProvider],
        playground_wordpress_version: "7.0",
        bundle_repo: "https://github.com/Extra-Chill/docs-agent.git",
        bundle_ref: $docsAgentRef,
        bundle_path_in_repo: "bundles/docs-agent",
        agent_slug: "docs-agent",
        pipeline_slug: "docs-agent-pipeline",
        flow_slug: "docs-maintenance-flow",
        provider: "openai",
        model: $model,
        provider_register_function: "WordPress\\OpenAiAiProvider\\register_provider",
        provider_credentials: {
            connectors_ai_openai_api_key: "OPENAI_API_KEY"
        },
        github_token_env: "GITHUB_TOKEN",
        github_profile_id: "docs-agent-ci",
        target_repo: $targetRepo,
        allowed_repos: [$targetRepo],
        success_requires_pr: false,
        max_turns: 12,
        prompt: $prompt,
        step_budget: 16,
        time_budget_ms: 600000,
        transcript_dir: "/wordpress/wp-content/plugins/agents-api-docs-agent-ci-driver/artifacts/docs-agent",
        required_abilities: [
            "datamachine/import-agent",
            "datamachine/run-flow",
            "datamachine/drain-job",
            "datamachine/create-or-update-github-file"
        ],
        tool_recorders: [
            {
                tool: "create_or_update_github_file",
                forced_parameters: {
                    allowed_file_paths: ["README.md", "docs/**"]
                },
                record: {
                    engine_key: "docs_agent",
                    tool_results_key: "github_tool_results"
                }
            },
            {
                tool: "create_github_pull_request",
                record: {
                    engine_key: "docs_agent",
                    tool_results_key: "github_tool_results",
                    event: {
                        key: "pr",
                        type: "pull_request",
                        only_if_success: true
                    }
                }
            }
        ],
        bench_env: {
            GITHUB_TOKEN: $githubToken,
            OPENAI_API_KEY: $openaiKey
        }
    }' > "$CONFIG_TMPFILE"

echo "============================================"
echo "Docs Agent maintenance"
echo "============================================"
echo "Target repo:  $DOCS_AGENT_TARGET_REPO"
echo "OpenAI model: $DOCS_AGENT_OPENAI_MODEL"
echo "Docs ref:     $DOCS_AGENT_REF"
echo "Prompt:       $DOCS_AGENT_PROMPT"
echo ""

GITHUB_TOKEN="$GITHUB_TOKEN" \
OPENAI_API_KEY="$OPENAI_API_KEY" \
HOMEBOY_BENCH_RESULTS_FILE="$RESULTS_TMPFILE" \
HOMEBOY_EXTENSION_PATH="$EXTENSION_PATH" \
    bash "$HE_AGENT_RUNNER" "$CONFIG_TMPFILE"

if [ ! -s "$RESULTS_TMPFILE" ]; then
    echo "ERROR: results file empty or missing at $RESULTS_TMPFILE" >&2
    exit 1
fi

cat "$RESULTS_TMPFILE"

scenario='.scenarios[] | select(.id == "docs-agent-maintenance")'
job_status=$(jq -r "$scenario | .metadata.job_status // \"unknown\"" "$RESULTS_TMPFILE")
success_status=$(jq -r "$scenario | .metadata.success_status // \"unknown\"" "$RESULTS_TMPFILE")
docs_agent_pr_url=$(jq -r "$scenario | .metadata.engine_data.docs_agent.pr.url // \"\"" "$RESULTS_TMPFILE")

echo "============================================"
echo "Docs Agent summary"
echo "============================================"
printf '%-24s %s\n' "Persisted job status:" "$job_status"
printf '%-24s %s\n' "Success status:" "$success_status"
printf '%-24s %s\n' "Docs Agent PR URL:" "$docs_agent_pr_url"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
    {
        echo "job_status=$job_status"
        echo "success_status=$success_status"
        echo "docs_agent_pr_url=$docs_agent_pr_url"
    } >> "$GITHUB_OUTPUT"
fi

if [ "$job_status" = "completed" ] && { [ "$success_status" = "pr_opened" ] || [ "$success_status" = "no_changes" ]; }; then
    echo "Docs Agent maintenance PASSED - $success_status"
    exit 0
fi

echo "Docs Agent maintenance FAILED - see envelope above" >&2
exit 1
